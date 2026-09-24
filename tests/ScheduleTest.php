<?php

namespace Payarc\WordPress\Tests;

use PHPUnit\Framework\TestCase;
use Payarc\WordPress\Modules\GravityForms\Schedule;

final class ScheduleTest extends TestCase {

  private function d(string $s): \DateTimeImmutable {
    return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
  }

  public function testMonthlyKeepsDayAndClampsShortMonths(): void {
    self::assertSame('2026-02-28 10:00:00', Schedule::advance($this->d('2026-01-31 10:00:00'), 1, 'month')->format('Y-m-d H:i:s'));
    self::assertSame('2026-03-31 10:00:00', Schedule::advance($this->d('2026-01-31 10:00:00'), 2, 'month')->format('Y-m-d H:i:s'));
    self::assertSame('2028-02-28 00:00:00', Schedule::advance($this->d('2027-02-28 00:00:00'), 12, 'month')->format('Y-m-d H:i:s'));
    self::assertSame('2027-01-15 00:00:00', Schedule::advance($this->d('2026-01-15 00:00:00'), 1, 'year')->format('Y-m-d H:i:s'));
  }

  public function testDaysAndWeeks(): void {
    self::assertSame('2026-09-10', Schedule::advance($this->d('2026-09-09'), 1, 'day')->format('Y-m-d'));
    self::assertSame('2026-09-23', Schedule::advance($this->d('2026-09-09'), 2, 'week')->format('Y-m-d'));
  }

  public function testUnknownUnitFallsBackToMonth(): void {
    self::assertSame('2026-10-09', Schedule::advance($this->d('2026-09-09'), 1, 'fortnight')->format('Y-m-d'));
  }

  public function testInstallmentDatesKeepTheAnchorDay(): void {
    $start = $this->d('2026-01-31');
    self::assertSame('2026-02-28', Schedule::installmentDate($start, 1, 'month', 1)->format('Y-m-d'));
    self::assertSame('2026-03-31', Schedule::installmentDate($start, 1, 'month', 2)->format('Y-m-d'));
    self::assertSame('2026-04-30', Schedule::installmentDate($start, 1, 'month', 3)->format('Y-m-d'));
  }

  public function testNextInstallmentAfterSkipsMissedOnes(): void {
    [$index, $date] = Schedule::nextInstallmentAfter($this->d('2026-01-31'), $this->d('2026-06-15'), 1, 'month', 2);
    self::assertSame(5, $index);
    self::assertSame('2026-06-30', $date->format('Y-m-d'));
    [$index, $date] = Schedule::nextInstallmentAfter($this->d('2026-09-09'), $this->d('2026-09-09 01:00'), 1, 'month', 1);
    self::assertSame(1, $index);
    self::assertSame('2026-10-09', $date->format('Y-m-d'));
  }

  public function testReferences(): void {
    self::assertSame('gf-42-2026-09-09-0', Schedule::orderId(42, $this->d('2026-09-09 13:00'), 0));
    self::assertSame('gf-42-2026-09-09-2', Schedule::orderId(42, $this->d('2026-09-09'), 2));
    self::assertSame('GF-42', Schedule::invoice(42));
  }

  public function testIntervalWording(): void {
    self::assertSame('every month', Schedule::describeInterval(1, 'month'));
    self::assertSame('every 2 weeks', Schedule::describeInterval(2, 'week'));
  }

}
