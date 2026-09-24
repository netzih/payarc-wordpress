<?php

namespace Payarc;

/**
 * Reads a PayArc charge object (the "data" of a charge response or a row of
 * the charge list). Pure PHP.
 *
 * A declined charge can arrive two ways: as an HTTP error (GatewayException,
 * whose response data DonorMessage reads) or as a 2xx charge whose
 * failure_code is set and whose status carries the reason ("Invalid CVV").
 * Both must be treated as declines; PayArc's own WooCommerce plugin checks
 * both.
 */
final class Charge {

  public const APPROVED = 'approved';

  public const DECLINED = 'declined';

  /**
   * Approved for less than the amount asked (TSYS A0002). Money moved, but
   * not all of it: callers should void it and treat the attempt as declined,
   * or hold it for staff.
   */
  public const PARTIAL = 'partial';

  /**
   * Approved earlier and since voided or refunded (only seen on lookups).
   */
  public const REVERSED = 'reversed';

  /**
   * A status this library does not know. Never guess: a success read as a
   * decline invites the payer to pay twice. Treat like an ambiguous result.
   */
  public const UNKNOWN = 'unknown';

  /**
   * Statuses of a charge that went through and has not been reversed.
   * 'submitted_for_settlement' (captured sale) and 'authorized' (capture=0)
   * are documented; 'settled' and 'captured' are assumed for later states.
   */
  public const APPROVED_STATUSES = ['submitted_for_settlement', 'authorized', 'settled', 'captured'];

  public const REVERSED_STATUSES = ['void', 'voided', 'refunded', 'partial_refund'];

  /**
   * TSYS response codes that mean approved in part.
   */
  private const PARTIAL_CODES = ['A0002', '000002'];

  /**
   * Failure codes that say an earlier identical request was (or may have
   * been) approved: D0001 "Duplicate Request (Approved previously)", D0008
   * "Possible Duplicate Request". Money may have moved, so these are not
   * declines.
   */
  public const DUPLICATE_APPROVED_CODES = ['D0001', 'D0008'];

  public static function outcome(array $charge): string {
    $charge = self::unwrap($charge);
    $failure = strtoupper(self::failureCode($charge));
    if (in_array($failure, self::DUPLICATE_APPROVED_CODES, TRUE)) {
      return self::UNKNOWN;
    }
    if ($failure !== '') {
      return self::DECLINED;
    }
    $status = strtolower(trim((string) ($charge['status'] ?? '')));
    if (in_array($status, self::APPROVED_STATUSES, TRUE)) {
      return self::isPartial($charge) ? self::PARTIAL : self::APPROVED;
    }
    if (in_array($status, self::REVERSED_STATUSES, TRUE)) {
      return self::REVERSED;
    }
    if ($status === 'failed_by_gateway') {
      return self::DECLINED;
    }
    return self::UNKNOWN;
  }

  public static function approved(array $charge): bool {
    return self::outcome($charge) === self::APPROVED;
  }

  public static function id(array $charge): string {
    return trim((string) (self::unwrap($charge)['id'] ?? ''));
  }

  public static function authCode(array $charge): string {
    return trim((string) (self::unwrap($charge)['auth_code'] ?? ''));
  }

  public static function failureCode(array $charge): string {
    return trim((string) (self::unwrap($charge)['failure_code'] ?? ''));
  }

  /**
   * Amount of the charge in cents.
   */
  public static function amountCents(array $charge): ?int {
    $amount = self::unwrap($charge)['amount'] ?? NULL;
    return is_numeric($amount) ? (int) $amount : NULL;
  }

  /**
   * Statuses that prove a charge has been batched (settled), so a partial
   * refund is a real refund. 'partial_refund' can only follow a settled
   * refund. 'settled' is assumed; confirm against a batched sandbox charge.
   */
  public const SETTLED_STATUSES = ['settled', 'partial_refund'];

  /**
   * Whether the charge is known to have settled. FALSE means unsettled or
   * unknown; both must be treated as unsettled.
   */
  public static function isSettled(array $charge): bool {
    return in_array(strtolower(trim((string) (self::unwrap($charge)['status'] ?? ''))), self::SETTLED_STATUSES, TRUE);
  }

  /**
   * Cents still refundable: the amount minus what was refunded, and nothing
   * once the charge is voided.
   *
   * amount_voided is not used: when a refund is sent for an unsettled charge
   * PayArc voids the whole charge but records only the amount asked for
   * there (verified in the sandbox, 2026-09-24), and overwrites it on the
   * next request.
   */
  public static function remainingCents(array $charge): ?int {
    $charge = self::unwrap($charge);
    $amount = self::amountCents($charge);
    if ($amount === NULL) {
      return NULL;
    }
    if (in_array(strtolower(trim((string) ($charge['status'] ?? ''))), ['void', 'voided', 'refunded'], TRUE)) {
      return 0;
    }
    return max(0, $amount - (int) ($charge['amount_refunded'] ?? 0));
  }

  /**
   * The charge's creation time. Single-charge responses carry a Unix time;
   * the list endpoint shows "2018-11-26 14:06:54" with no zone, read here as
   * UTC (callers widen cutoffs by GatewayClient::CREATED_TIME_SLACK).
   */
  public static function createdTime(array $charge): ?int {
    $created = self::unwrap($charge)['created_at'] ?? NULL;
    if (is_int($created) || (is_string($created) && ctype_digit($created))) {
      return (int) $created;
    }
    if (!is_string($created) || trim($created) === '') {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($created, new \DateTimeZone('UTC')))->getTimestamp();
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Our metadata on the charge, as a flat key => value array.
   *
   * Metadata is sent as a JSON string. How PayArc returns it is not
   * documented beyond the include names (transaction_metadata,
   * extra_metadata), so every plausible shape is accepted: a 'metadata'
   * object or JSON string, and 'transaction_metadata'/'extra_metadata' as an
   * object, a {data: ...} wrapper, or a list of {key, value} rows.
   */
  public static function metadata(array $charge): array {
    $charge = self::unwrap($charge);
    $found = [];
    foreach (['metadata', 'transaction_metadata', 'extra_metadata'] as $field) {
      $found += self::flattenMetadata($charge[$field] ?? NULL);
    }
    return $found;
  }

  /**
   * Whether the charge's metadata could be read at all (see metadata()).
   */
  public static function hasMetadata(array $charge): bool {
    $charge = self::unwrap($charge);
    foreach (['metadata', 'transaction_metadata', 'extra_metadata'] as $field) {
      if (array_key_exists($field, $charge)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Refund rows included with the charge ("refund": {"data": [...]}).
   *
   * @return array[]
   */
  public static function refunds(array $charge): array {
    $refund = self::unwrap($charge)['refund'] ?? NULL;
    $rows = is_array($refund) && array_key_exists('data', $refund) ? $refund['data'] : $refund;
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
  }

  /**
   * The charge object whether given the response or its "data".
   */
  public static function unwrap(array $response): array {
    if (isset($response['data']) && is_array($response['data']) && (($response['data']['object'] ?? '') === 'Charge' || isset($response['data']['id']))) {
      return $response['data'];
    }
    return $response;
  }

  private static function isPartial(array $charge): bool {
    foreach (['tsys_response_code', 'host_response_code'] as $field) {
      if (in_array(strtoupper(trim((string) ($charge[$field] ?? ''))), self::PARTIAL_CODES, TRUE)) {
        return TRUE;
      }
    }
    $approved = $charge['amount_approved'] ?? NULL;
    $amount = $charge['amount'] ?? NULL;
    return is_numeric($approved) && is_numeric($amount) && (int) $approved > 0 && (int) $approved < (int) $amount;
  }

  private static function flattenMetadata(mixed $value): array {
    if (is_string($value)) {
      $decoded = json_decode($value, TRUE);
      return is_array($decoded) ? self::flattenMetadata($decoded) : [];
    }
    if (!is_array($value)) {
      return [];
    }
    if (array_key_exists('data', $value) && count($value) <= 2) {
      return self::flattenMetadata($value['data']);
    }
    $flat = [];
    if (array_is_list($value)) {
      foreach ($value as $row) {
        if (is_array($row) && isset($row['key']) && is_scalar($row['key'])) {
          $flat[(string) $row['key']] = is_scalar($row['value'] ?? NULL) ? (string) $row['value'] : '';
        }
        elseif (is_array($row)) {
          $flat += self::flattenMetadata($row);
        }
      }
      return $flat;
    }
    foreach ($value as $key => $item) {
      if (is_scalar($item)) {
        $flat[(string) $key] = (string) $item;
      }
    }
    return $flat;
  }

}
