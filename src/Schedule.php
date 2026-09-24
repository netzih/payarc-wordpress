<?php

namespace Payarc\WordPress;

/**
 * Date arithmetic for site-managed subscriptions, shared by every module
 * that charges installments from this site. Pure PHP so it can be unit tested.
 */
final class Schedule {

  /**
   * Days between attempts after a declined installment.
   */
  public const RETRY_DAYS = 3;

  /**
   * Attempts per installment before the subscription is cancelled.
   */
  public const MAX_ATTEMPTS = 3;

  public const UNITS = ['day', 'week', 'month', 'year'];

  /**
   * The next scheduled date. Month and year steps keep the original day of
   * the month, clamped to the last day when the target month is shorter
   * (31 Jan + 1 month = 28/29 Feb), so a "31st" schedule never drifts.
   */
  public static function advance(\DateTimeImmutable $from, int $length, string $unit): \DateTimeImmutable {
    $length = max(1, $length);
    $unit = in_array($unit, self::UNITS, TRUE) ? $unit : 'month';
    if ($unit === 'day' || $unit === 'week') {
      return $from->modify('+' . ($unit === 'week' ? $length * 7 : $length) . ' days');
    }
    $months = $unit === 'year' ? $length * 12 : $length;
    $day = (int) $from->format('j');
    $firstOfTarget = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->modify('+' . $months . ' months');
    $lastDay = (int) $firstOfTarget->format('t');
    return $firstOfTarget->setDate((int) $firstOfTarget->format('Y'), (int) $firstOfTarget->format('n'), min($day, $lastDay));
  }

  /**
   * Date of installment number $index (1-based) counted from the schedule
   * start, so a "31st" schedule returns to the 31st after a short month
   * instead of drifting to the 28th.
   */
  public static function installmentDate(\DateTimeImmutable $start, int $length, string $unit, int $index): \DateTimeImmutable {
    return self::advance($start, max(1, $length) * max(1, $index), $unit);
  }

  /**
   * The first installment index at or after $fromIndex whose date is after
   * $now, for schedules that fell behind (cron not running for a while): at
   * most one installment is charged per run.
   *
   * @return array{0: int, 1: \DateTimeImmutable}
   */
  public static function nextInstallmentAfter(\DateTimeImmutable $start, \DateTimeImmutable $now, int $length, string $unit, int $fromIndex): array {
    $index = max(1, $fromIndex);
    $date = self::installmentDate($start, $length, $unit, $index);
    $guard = 0;
    while ($date <= $now && $guard++ < 1000) {
      $index++;
      $date = self::installmentDate($start, $length, $unit, $index);
    }
    return [$index, $date];
  }

  public static function describeInterval(int $length, string $unit): string {
    $length = max(1, $length);
    switch ($unit) {
      case 'day':
        return $length === 1 ? __('every day', 'payarc-payments') : sprintf(__('every %d days', 'payarc-payments'), $length);

      case 'week':
        return $length === 1 ? __('every week', 'payarc-payments') : sprintf(__('every %d weeks', 'payarc-payments'), $length);

      case 'year':
        return $length === 1 ? __('every year', 'payarc-payments') : sprintf(__('every %d years', 'payarc-payments'), $length);

      default:
        return $length === 1 ? __('every month', 'payarc-payments') : sprintf(__('every %d months', 'payarc-payments'), $length);
    }
  }

}
