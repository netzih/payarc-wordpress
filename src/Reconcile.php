<?php

namespace Payarc\WordPress;

use Payarc\AmbiguousGatewayException;
use Payarc\Charge;
use Payarc\GatewayClient;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;

/**
 * Runs a gateway call at most once per stored marker.
 *
 * The marker ({orderid, key, sent_at, amount, kind, ...}) is written by the
 * caller's store BEFORE the request goes out and stays there until the answer
 * was conclusive and, for an approval, until the caller has recorded it.
 *
 * Every request carries the marker's key as PayArc's Idempotency-Key, and
 * PayArc answers a repeated key with the original result, whatever the new
 * body says (verified in the sandbox, 2026-09-24). So:
 * - A lost answer is recovered by sending the same request again at once.
 * - A later attempt that finds a recent marker sends again with the stored
 *   key: an earlier charge comes back instead of a second one being made.
 * - An older marker (key retention is not documented) is settled by looking
 *   the charge up by its reference; a refund marker by comparing the sale's
 *   refunded amount with what it was before the refund was sent.
 * A definitive answer (decline, refusal) clears the marker, so the next
 * attempt gets a new key: PayArc would otherwise replay the old decline.
 *
 * Reading the marker, writing it and sending the request happen under a lock
 * on the orderid (Lock, whose INSERT is the test), so two requests made at the
 * same moment cannot both pass the read; the second one is told to wait. The
 * marker write is read back before anything is sent: a store that did not
 * keep it would leave the request unguarded.
 */
final class Reconcile {

  public const CHARGE = 'charge';

  public const REFUND = 'refund';

  /**
   * How long an idempotency key is trusted to be replayed. PayArc does not
   * document how long it keeps them; older markers are looked up instead.
   */
  public const REPLAY_WINDOW = 3600;

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
   * @param string $orderId
   *   Our reference for the record being charged or refunded; the key is
   *   derived from it.
   * @param callable(GatewayClient, string): array $call
   *   The gateway request; the second argument is the idempotency key to
   *   send as its 'reference'.
   * @param string $kind
   *   self::CHARGE, or self::REFUND with $chargeId set to the sale.
   *
   * @return array{response: array, reconciled: bool, amount: ?string}
   *   'reconciled' is TRUE when the response is an earlier request's result
   *   rather than the answer to a request made now; 'amount' is then the
   *   amount that earlier request was made for.
   *
   * @throws BusyException
   *   Another request for the same orderid is in progress; nothing was sent.
   * @throws ReconciliationInconclusiveException
   *   PayArc could not be asked whether an earlier request went through;
   *   nothing was sent. The caller must not retry blindly.
   * @throws AmbiguousGatewayException
   *   The request was sent and no conclusive answer came; the marker stays
   *   for next time.
   * @throws GatewayException
   */
  public static function once(GatewayClient $client, callable $read, callable $write, string $orderId, ?string $amount, callable $call, string $kind = self::CHARGE, ?string $chargeId = NULL): array {
    if ($kind === self::REFUND && ($chargeId === NULL || trim($chargeId) === '')) {
      throw new \InvalidArgumentException('A refund needs the charge it refunds.');
    }
    $lockName = 'reconcile_' . md5($orderId);
    $lock = Lock::acquire($lockName, self::LOCK_TTL);
    if ($lock === NULL) {
      throw new BusyException(sprintf('Another request for %s is still in progress.', $orderId));
    }
    try {
      return self::onceLocked($client, $read, $write, $orderId, $amount, $call, $kind, $chargeId);
    }
    finally {
      Lock::release($lockName, $lock);
    }
  }

  private static function onceLocked(GatewayClient $client, callable $read, callable $write, string $orderId, ?string $amount, callable $call, string $kind, ?string $chargeId): array {
    $marker = $read();
    if (is_array($marker) && !empty($marker['key'])) {
      $earlierAmount = isset($marker['amount']) && $marker['amount'] !== '' ? (string) $marker['amount'] : $amount;
      $state = self::earlier($client, $marker, $kind);
      if ($state['state'] === 'done') {
        return ['response' => $state['response'], 'reconciled' => TRUE, 'amount' => $earlierAmount];
      }
      if ($state['state'] === 'replay') {
        // Same key: PayArc returns the earlier result, or makes this request
        // now if the earlier one never arrived.
        $result = self::send($client, $write, (string) $marker['key'], $call, $kind);
        $replayed = self::isEarlier($result, (int) ($marker['sent_at'] ?? 0));
        return ['response' => $result, 'reconciled' => $replayed, 'amount' => $replayed ? $earlierAmount : $amount];
      }
      // 'absent': the earlier request provably did nothing.
    }

    $marker = [
      'orderid' => $orderId,
      'key' => self::newKey($orderId),
      'sent_at' => time(),
      'amount' => $amount,
      'kind' => $kind,
    ];
    if ($kind === self::REFUND) {
      // What the sale looked like before this refund, so a lost answer can
      // be settled later by comparing (see earlier()).
      $before = $client->getCharge((string) $chargeId);
      $marker['charge_id'] = (string) $chargeId;
      $marker['refunded_before'] = (int) ($before['amount_refunded'] ?? 0);
      $marker['remaining_before'] = Charge::remainingCents($before);
    }
    $write($marker);
    $stored = $read();
    if (!is_array($stored) || (string) ($stored['key'] ?? '') !== $marker['key']) {
      throw new \RuntimeException(sprintf('The record of the request for %s could not be stored, so it was not sent.', $orderId));
    }
    return ['response' => self::send($client, $write, $marker['key'], $call, $kind), 'reconciled' => FALSE, 'amount' => $amount];
  }

  /**
   * Send with the key; resend once at once if the answer was lost.
   */
  private static function send(GatewayClient $client, callable $write, string $key, callable $call, string $kind): array {
    try {
      try {
        $response = $call($client, $key);
      }
      catch (AmbiguousGatewayException $e) {
        $response = $call($client, $key);
      }
    }
    catch (AmbiguousGatewayException $e) {
      throw $e;
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      // PayArc answered (or the request never left): nothing happened.
      $write(NULL);
      throw $e;
    }

    $outcome = Charge::outcome($response);
    if ($kind === self::REFUND) {
      if (in_array($outcome, [Charge::REVERSED, Charge::APPROVED], TRUE)) {
        return $response;
      }
      throw new AmbiguousGatewayException(sprintf('PayArc answered the refund with status "%s".', (string) ($response['status'] ?? '')), 0, $response);
    }
    switch ($outcome) {
      case Charge::APPROVED:
        return $response;

      case Charge::DECLINED:
        $write(NULL);
        return $response;

      case Charge::PARTIAL:
        // Approved for less than asked: give it back and treat as declined.
        try {
          $client->void(Charge::id($response), 'other', 'Partially approved');
          $write(NULL);
        }
        catch (\Throwable $e) {
          // The marker stays; staff see it under Unresolved requests.
        }
        return ['failure_code' => 'PARTIAL', 'failure_message' => 'Partially approved, voided'] + $response;

      default:
        throw new AmbiguousGatewayException(sprintf('PayArc answered with status "%s" (%s), which does not say whether the card was charged.', (string) ($response['status'] ?? ''), Charge::failureCode($response)), 0, $response);
    }
  }

  /**
   * What became of the request a marker was written for.
   *
   * @return array{state: 'done', response: array}|array{state: 'replay'}|array{state: 'absent'}
   *
   * @throws ReconciliationInconclusiveException
   */
  private static function earlier(GatewayClient $client, array $marker, string $kind): array {
    $sentAt = (int) ($marker['sent_at'] ?? 0);
    if ($kind === self::REFUND || ($marker['kind'] ?? '') === self::REFUND) {
      return self::earlierRefund($client, $marker);
    }
    if ($sentAt >= time() - self::REPLAY_WINDOW) {
      return ['state' => 'replay'];
    }
    $found = self::lookup($client, (string) $marker['key'], $sentAt ?: NULL, isset($marker['amount']) && $marker['amount'] !== '' ? (string) $marker['amount'] : NULL);
    if ($found && in_array(Charge::outcome($found), [Charge::APPROVED, Charge::REVERSED], TRUE)) {
      return ['state' => 'done', 'response' => $found];
    }
    return ['state' => 'absent'];
  }

  /**
   * A refund leaves no row of its own to find, but it changes the sale: its
   * refunded amount grows, or it is voided. Compared with the marker's
   * snapshot this is conclusive at any age.
   */
  private static function earlierRefund(GatewayClient $client, array $marker): array {
    try {
      $sale = $client->getCharge((string) ($marker['charge_id'] ?? ''));
    }
    catch (\Throwable $e) {
      throw new ReconciliationInconclusiveException('The charge for ' . ($marker['orderid'] ?? '?') . ' could not be read: ' . $e->getMessage(), 0, [], $e);
    }
    $refundedNow = (int) ($sale['amount_refunded'] ?? 0);
    $remainingNow = Charge::remainingCents($sale);
    $remainingBefore = $marker['remaining_before'] ?? NULL;
    if ($refundedNow > (int) ($marker['refunded_before'] ?? 0) || ($remainingBefore !== NULL && $remainingNow !== NULL && $remainingNow < (int) $remainingBefore)) {
      return ['state' => 'done', 'response' => $sale];
    }
    return ['state' => 'absent'];
  }

  /**
   * A replayed answer is the earlier charge when it was created before this
   * attempt started (with slack for the clocks).
   */
  private static function isEarlier(array $response, int $sentAt): bool {
    $created = Charge::createdTime($response);
    return $created !== NULL && $created < time() - 60 && $sentAt > 0;
  }

  /**
   * The orderid plus a short random part, so a new attempt after a decline
   * is not answered with the old decline.
   */
  private static function newKey(string $orderId): string {
    return substr($orderId, 0, 80) . '.' . substr(bin2hex(random_bytes(4)), 0, 6);
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
      return sprintf(__('An earlier refund of this transaction may have gone through, and PayArc could not confirm it. Nothing was refunded now. Check charge %1$s in the PayArc dashboard, then try again. (%2$s)', 'payarc-payments'), $reference, $e->getMessage());
    }
    return sprintf(__('PayArc did not answer, so the refund may or may not have gone through. Try again in a moment: the earlier attempt is checked before anything is refunded again. (%s)', 'payarc-payments'), $e->getMessage());
  }

  /**
   * Text when an earlier, unrecorded refund of a different amount turned up.
   */
  public static function refundMismatchMessage(string $earlierAmount, string $reference): string {
    return sprintf(__('An earlier refund of %1$s went through at PayArc (charge %2$s) but was never recorded here. Record that refund first, using the same amount, before refunding a different amount.', 'payarc-payments'), $earlierAmount, $reference);
  }

  /**
   * The charge sent with a reference (idempotency key), or NULL when PayArc
   * provably has none of that amount.
   *
   * @throws ReconciliationInconclusiveException
   *   When the answer is unknown, including when the lookup itself failed.
   */
  public static function lookup(GatewayClient $client, string $reference, ?int $sentAt, ?string $amount): ?array {
    try {
      return $client->findChargeByReference($reference, $sentAt, 5, $amount);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw new ReconciliationInconclusiveException('The lookup for ' . $reference . ' failed: ' . $e->getMessage(), 0, [], $e);
    }
  }

  /**
   * What a stored marker's request came to, for the admin "Check at PayArc"
   * action. Never sends anything.
   *
   * @return array{state: 'done'|'absent'|'pending', response?: array}
   *   'pending': recent enough that the next attempt settles it by replaying
   *   its key.
   *
   * @throws ReconciliationInconclusiveException
   */
  public static function inspect(GatewayClient $client, array $marker): array {
    if (($marker['kind'] ?? '') === self::REFUND) {
      return self::earlierRefund($client, $marker);
    }
    if (empty($marker['key'])) {
      throw new ReconciliationInconclusiveException('The marker has no key.');
    }
    $found = self::lookup($client, (string) $marker['key'], ((int) ($marker['sent_at'] ?? 0)) ?: NULL, isset($marker['amount']) && $marker['amount'] !== '' ? (string) $marker['amount'] : NULL);
    if ($found && in_array(Charge::outcome($found), [Charge::APPROVED, Charge::REVERSED], TRUE)) {
      return ['state' => 'done', 'response' => $found];
    }
    return ['state' => 'absent'];
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
