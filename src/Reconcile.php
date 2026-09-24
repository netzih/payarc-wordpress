<?php

namespace Payarc\WordPress;

use Payarc\AmbiguousGatewayException;
use Payarc\GatewayClient;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;

/**
 * Runs a gateway call at most once per stored marker.
 *
 * The marker ({orderid, sent_at, amount, exclude}) is written by the caller's
 * store BEFORE the request goes out and stays there until the answer was
 * conclusive and, for an approval, until the caller has recorded it. A later
 * call with the same store finds the marker, looks the orderid up (bounded by
 * when it was sent, so a miss is conclusive) and returns the earlier
 * transaction instead of sending a second one. When PayArc cannot say,
 * nothing is sent.
 *
 * Reading the marker, writing it and sending the request happen under a lock
 * on the orderid (Lock, whose INSERT is the test), so two requests made at the
 * same moment cannot both pass the read; the second one is told to wait. The
 * marker write is read back before anything is sent: a store that did not
 * keep it would leave the request unguarded.
 */
final class Reconcile {

  /**
   * Longer than a request plus the lookups that may follow it. A holder that
   * died keeps the orderid blocked for this long, then the marker it wrote
   * takes over the protection.
   */
  private const LOCK_TTL = 5 * 60;

  /**
   * How long a stored marker for a submission without a record of its own
   * (see optionStore()) is kept: long enough for any resubmission, short
   * enough that abandoned attempts do not pile up.
   */
  public const OPTION_MARKER_TTL = 7 * 24 * 3600;

  private const OPTION_PREFIX = 'payarc_marker_';

  /**
   * @param callable(): mixed $read
   *   Returns the stored marker array, or anything else when none.
   * @param callable(?array): void $write
   *   Stores a marker, or removes it when given NULL.
   * @param callable(GatewayClient): array $call
   *   The gateway request.
   * @param string[] $types
   *   Transaction types that count as "this request went through":
   *   GatewayClient::TYPES_CHARGE or TYPES_REFUND. Refunds inherit the sale's
   *   orderid, so for them $orderId is the sale's, and the refunds the sale
   *   already had are listed before sending and excluded from later lookups:
   *   only a refund that was not there before can be the one sent now.
   *
   * @return array{response: array, reconciled: bool, amount: ?string}
   *   'reconciled' is TRUE when the response is an earlier transaction found
   *   at PayArc rather than the answer to a request made now; 'amount' is
   *   then the amount that earlier request was made for.
   *
   * @throws BusyException
   *   Another request for the same orderid is in progress; nothing was sent.
   * @throws ReconciliationInconclusiveException
   *   PayArc could not be asked whether an earlier request went through;
   *   nothing was sent. The caller must not retry blindly.
   * @throws AmbiguousGatewayException
   *   The request was sent and no answer came; the marker stays for next time.
   * @throws GatewayException
   */
  public static function once(GatewayClient $client, callable $read, callable $write, string $orderId, ?string $amount, callable $call, array $types = GatewayClient::TYPES_CHARGE): array {
    $lockName = 'reconcile_' . md5($orderId);
    $lock = Lock::acquire($lockName, self::LOCK_TTL);
    if ($lock === NULL) {
      throw new BusyException(sprintf('Another request for %s is still in progress.', $orderId));
    }
    try {
      return self::onceLocked($client, $read, $write, $orderId, $amount, $call, $types);
    }
    finally {
      Lock::release($lockName, $lock);
    }
  }

  private static function onceLocked(GatewayClient $client, callable $read, callable $write, string $orderId, ?string $amount, callable $call, array $types): array {
    $marker = $read();
    if (is_array($marker) && !empty($marker['orderid'])) {
      // Look for what was actually sent then, not what is asked for now.
      $earlierAmount = isset($marker['amount']) && $marker['amount'] !== '' ? (string) $marker['amount'] : $amount;
      $exclude = isset($marker['exclude']) && is_array($marker['exclude']) ? $marker['exclude'] : [];
      $found = self::lookup($client, (string) $marker['orderid'], ((int) ($marker['sent_at'] ?? 0)) ?: NULL, $earlierAmount, $types, $exclude);
      if ($found && Gateway::approved($found)) {
        return ['response' => $found, 'reconciled' => TRUE, 'amount' => $earlierAmount];
      }
      // Declined, or provably never received: a fresh request is safe.
    }
    $exclude = [];
    if ($types === GatewayClient::TYPES_REFUND) {
      // Every refund of the sale carries its orderid; remember the ones that
      // exist now so a later lookup accepts only one that appeared after this.
      try {
        $exclude = array_values(array_filter(array_map(static fn(array $row) => (string) ($row['key'] ?? ''), self::lookupAll($client, $orderId, time(), NULL, $types))));
      }
      catch (ReconciliationInconclusiveException $e) {
        // Too many newer transactions to list them all. A later lookup for
        // this marker would run into the same wall and be inconclusive too,
        // so the marker without the list protects as much as it can.
        $exclude = NULL;
      }
    }
    $marker = ['orderid' => $orderId, 'sent_at' => time(), 'amount' => $amount, 'exclude' => $exclude];
    $write($marker);
    $stored = $read();
    if (!is_array($stored) || (string) ($stored['orderid'] ?? '') !== $orderId || (int) ($stored['sent_at'] ?? 0) !== $marker['sent_at']) {
      throw new \RuntimeException(sprintf('The record of the request for %s could not be stored, so it was not sent.', $orderId));
    }
    try {
      $response = $call($client);
    }
    catch (AmbiguousGatewayException $e) {
      try {
        $found = self::lookup($client, $orderId, time(), $amount, $types, $exclude ?? []);
      }
      catch (ReconciliationInconclusiveException $lookup) {
        $found = NULL;
      }
      if ($found && Gateway::approved($found)) {
        return ['response' => $found, 'reconciled' => TRUE, 'amount' => $amount];
      }
      throw $e;
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      // The gateway answered (or the request never left): nothing happened.
      $write(NULL);
      throw $e;
    }
    if (!Gateway::approved($response)) {
      $write(NULL);
    }
    return ['response' => $response, 'reconciled' => FALSE, 'amount' => $amount];
  }

  /**
   * Marker store in a record's meta through plain get/update/delete callables.
   *
   * @return array{0: callable, 1: callable}
   */
  public static function metaStore(callable $get, callable $update, callable $delete): array {
    return [
      $get,
      static function (?array $marker) use ($update, $delete): void {
        if ($marker === NULL) {
          $delete();
        }
        else {
          $update($marker);
        }
      },
    ];
  }

  /**
   * Text for a customer or donor when another request is in progress.
   */
  public static function busyMessage(): string {
    return __('This payment is already being processed. Please wait a moment, then check before trying again.', 'payarc-payments');
  }

  /**
   * Text for an administrator when a refund cannot safely be sent.
   */
  public static function refundBlockedMessage(\Throwable $e, string $reference): string {
    if ($e instanceof BusyException) {
      return __('Another refund of this transaction is being processed right now. Wait a moment, then reload the page before trying again.', 'payarc-payments');
    }
    if ($e instanceof ReconciliationInconclusiveException) {
      return sprintf(__('An earlier refund of this transaction may have gone through, and PayArc could not confirm it. Nothing was refunded now. Check transaction %1$s in the PayArc console, then try again. (%2$s)', 'payarc-payments'), $reference, $e->getMessage());
    }
    return sprintf(__('PayArc did not answer, so the refund may or may not have gone through. Try again in a moment: the earlier attempt is checked before anything is refunded again. (%s)', 'payarc-payments'), $e->getMessage());
  }

  /**
   * Text when an earlier, unrecorded refund of a different amount turned up.
   */
  public static function refundMismatchMessage(string $earlierAmount, string $reference): string {
    return sprintf(__('An earlier refund of %1$s went through at PayArc (transaction %2$s) but was never recorded here. Record that refund first, using the same amount, before refunding a different amount.', 'payarc-payments'), $earlierAmount, $reference);
  }

  /**
   * The listed transaction for an orderid, or NULL when PayArc provably has
   * none of that amount.
   *
   * @param string[] $excludeKeys
   *
   * @throws ReconciliationInconclusiveException
   *   When the answer is unknown, including when the lookup itself failed.
   */
  public static function lookup(GatewayClient $client, string $orderId, ?int $sentAt, ?string $amount, array $types = GatewayClient::TYPES_CHARGE, array $excludeKeys = []): ?array {
    try {
      return $client->findTransactionByOrderId($orderId, $sentAt, 5, $amount, $types, $excludeKeys);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new ReconciliationInconclusiveException('The lookup for ' . $orderId . ' failed: ' . $e->getMessage(), 0, [], $e);
    }
  }

  /**
   * Every listed transaction for an orderid back to the cutoff.
   *
   * @return array[]
   *
   * @throws ReconciliationInconclusiveException
   */
  public static function lookupAll(GatewayClient $client, string $orderId, ?int $sentAt, ?string $amount, array $types = GatewayClient::TYPES_CHARGE): array {
    try {
      return $client->findTransactionsByOrderId($orderId, $sentAt, 5, $amount, $types);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new ReconciliationInconclusiveException('The lookup for ' . $orderId . ' failed: ' . $e->getMessage(), 0, [], $e);
    }
  }

  /**
   * Marker store in a wp_options row, for callers with no record of their own
   * yet (a Gravity Forms submission has no entry until it is authorized).
   *
   * Written and read with plain SQL, bypassing the option caches: an object
   * cache may drop a transient at any time, and a marker that vanished would
   * let a second charge through. Rows are removed by purgeOptionMarkers()
   * once older than OPTION_MARKER_TTL.
   *
   * @return array{0: callable, 1: callable}
   */
  public static function optionStore(string $key): array {
    $name = self::OPTION_PREFIX . md5($key);
    return [
      static function () use ($name) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        $marker = $raw === NULL ? NULL : maybe_unserialize($raw);
        return is_array($marker) ? $marker : NULL;
      },
      static function (?array $marker) use ($name): void {
        global $wpdb;
        if ($marker === NULL) {
          $wpdb->delete($wpdb->options, ['option_name' => $name]);
        }
        else {
          $wpdb->replace($wpdb->options, ['option_name' => $name, 'option_value' => maybe_serialize($marker), 'autoload' => 'no']);
        }
      },
    ];
  }

  /**
   * Remove option-row markers whose request is older than OPTION_MARKER_TTL.
   *
   * @return int
   *   Rows removed.
   */
  public static function purgeOptionMarkers(?int $now = NULL): int {
    global $wpdb;
    $now = $now ?? time();
    $removed = 0;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::OPTION_PREFIX) . '%'), ARRAY_A);
    foreach ((array) $rows as $row) {
      $marker = maybe_unserialize((string) $row['option_value']);
      $sentAt = is_array($marker) ? (int) ($marker['sent_at'] ?? 0) : 0;
      if ($sentAt < $now - self::OPTION_MARKER_TTL) {
        $removed += (int) $wpdb->delete($wpdb->options, ['option_name' => (string) $row['option_name']]);
      }
    }
    return $removed;
  }

}
