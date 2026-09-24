<?php

namespace Payarc\WordPress\Modules\GravityForms;

use Payarc\WordPress\Schedule as SharedSchedule;

/**
 * Gravity Forms reference strings for site-managed subscriptions. The date
 * arithmetic lives in Payarc\WordPress\Schedule and is forwarded from here
 * so existing callers keep working.
 */
final class Schedule {

  public const RETRY_DAYS = SharedSchedule::RETRY_DAYS;

  public const MAX_ATTEMPTS = SharedSchedule::MAX_ATTEMPTS;

  public const UNITS = SharedSchedule::UNITS;

  public static function advance(\DateTimeImmutable $from, int $length, string $unit): \DateTimeImmutable {
    return SharedSchedule::advance($from, $length, $unit);
  }

  public static function installmentDate(\DateTimeImmutable $start, int $length, string $unit, int $index): \DateTimeImmutable {
    return SharedSchedule::installmentDate($start, $length, $unit, $index);
  }

  /**
   * @return array{0: int, 1: \DateTimeImmutable}
   */
  public static function nextInstallmentAfter(\DateTimeImmutable $start, \DateTimeImmutable $now, int $length, string $unit, int $fromIndex): array {
    return SharedSchedule::nextInstallmentAfter($start, $now, $length, $unit, $fromIndex);
  }

  /**
   * Idempotency reference sent as PayArc's orderid: one per entry,
   * installment date and attempt, so a double cron run can be reconciled by
   * looking the reference up instead of charging twice.
   */
  public static function orderId(int $entryId, \DateTimeImmutable $scheduled, int $attempt): string {
    return sprintf('gf-%d-%s-%d', $entryId, $scheduled->format('Y-m-d'), max(0, $attempt));
  }

  /**
   * Invoice reference shown in the PayArc console (and copied onto refunds).
   */
  public static function invoice(int $entryId): string {
    return 'GF-' . $entryId;
  }

  public static function describeInterval(int $length, string $unit): string {
    return SharedSchedule::describeInterval($length, $unit);
  }

}
