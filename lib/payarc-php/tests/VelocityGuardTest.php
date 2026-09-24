<?php

namespace Payarc\Tests;

use PHPUnit\Framework\TestCase;
use Payarc\ArrayVelocityStore;
use Payarc\VelocityGuard;

final class VelocityGuardTest extends TestCase {

  private int $now = 1_800_000_000;

  private function guard(array $config = [], ?ArrayVelocityStore $store = NULL): VelocityGuard {
    return new VelocityGuard($store ?? new ArrayVelocityStore(), $config, fn(): int => $this->now);
  }

  public function testAnAddressIsRefusedAfterItsLimitUntilTheOldestFailureLeavesTheWindow(): void {
    $guard = $this->guard(['ip_limit' => 3, 'site_limit' => 0]);
    foreach ([0, 600, 1200] as $offset) {
      self::assertNull($guard->check('203.0.113.9', '10.00'));
      $this->now += $offset ? 600 : 0;
      $guard->recordFailure('203.0.113.9');
    }
    self::assertSame(VelocityGuard::IP_BLOCKED, $guard->check('203.0.113.9', '10.00'));
    self::assertNull($guard->check('198.51.100.4', '10.00'), 'Other addresses are not affected.');

    // The first failure was 1200 s ago; it leaves the 60-minute window 2400 s from now.
    $this->now += 2399;
    self::assertSame(VelocityGuard::IP_BLOCKED, $guard->check('203.0.113.9'));
    $this->now += 2;
    self::assertNull($guard->check('203.0.113.9'));
  }

  public function testSiteWideFailuresFromManyAddressesTripThePauseOnce(): void {
    $guard = $this->guard(['ip_limit' => 5, 'site_limit' => 4, 'pause_minutes' => 30]);
    $results = [];
    for ($i = 1; $i <= 4; $i++) {
      $results[] = $guard->recordFailure('192.0.2.' . $i);
    }
    self::assertSame([NULL, NULL, NULL, VelocityGuard::TRIPPED], $results);
    self::assertSame(VelocityGuard::PAUSED, $guard->check('198.51.100.4', '50.00'));
    self::assertSame($this->now + 1800, $guard->pausedUntil());

    // Failures during the pause do not trip it again.
    self::assertNull($guard->recordFailure('192.0.2.99'));

    $this->now += 1801;
    self::assertNull($guard->pausedUntil());
    self::assertNull($guard->check('198.51.100.4', '50.00'));
    self::assertSame(0, $guard->siteFailures(), 'The count starts over after a pause.');
  }

  public function testResumeLiftsThePause(): void {
    $guard = $this->guard(['site_limit' => 1]);
    self::assertSame(VelocityGuard::TRIPPED, $guard->recordFailure(''));
    self::assertSame(VelocityGuard::PAUSED, $guard->check(''));
    $guard->resume();
    self::assertNull($guard->check(''));
  }

  public function testTheStateIsSharedThroughTheStore(): void {
    $store = new ArrayVelocityStore();
    $this->guard(['site_limit' => 2], $store)->recordFailure('192.0.2.1');
    self::assertSame(VelocityGuard::TRIPPED, $this->guard(['site_limit' => 2], $store)->recordFailure('192.0.2.2'));
    self::assertSame(VelocityGuard::PAUSED, $this->guard(['site_limit' => 2], $store)->check('192.0.2.3'));
  }

  public function testMinimumAmountAppliesOnlyToCharges(): void {
    $guard = $this->guard(['min_amount' => '5']);
    self::assertSame(VelocityGuard::BELOW_MINIMUM, $guard->check('192.0.2.1', '4.99'));
    self::assertNull($guard->check('192.0.2.1', '5.00'));
    self::assertNull($guard->check('192.0.2.1', NULL), 'A card save has no amount of its own.');
  }

  public function testZeroLimitsAreOff(): void {
    $guard = $this->guard(['ip_limit' => 0, 'site_limit' => 0]);
    for ($i = 0; $i < 100; $i++) {
      self::assertNull($guard->recordFailure('192.0.2.1'));
    }
    self::assertNull($guard->check('192.0.2.1', '1.00'));
  }

  public function testAnUnknownAddressCountsOnlySiteWide(): void {
    $guard = $this->guard(['ip_limit' => 1, 'site_limit' => 3]);
    $guard->recordFailure('');
    $guard->recordFailure('');
    self::assertNull($guard->check(''));
    self::assertSame(2, $guard->siteFailures());
  }

  public function testDonorTextDoesNotMentionLimits(): void {
    foreach ([VelocityGuard::IP_BLOCKED, VelocityGuard::PAUSED, VelocityGuard::BELOW_MINIMUM] as $reason) {
      self::assertDoesNotMatchRegularExpression('/limit|attempt|block|fraud/i', VelocityGuard::donorText($reason));
    }
  }

}
