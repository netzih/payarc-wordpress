<?php

namespace Payarc;

/**
 * Slows card testing: bots that run stolen cards through a payment form, one
 * small charge after another, most of them declined.
 *
 * Callers ask check() before every payer-initiated charge or card save, and
 * call recordFailure() after each one that failed. Two limits apply to
 * failures within the last hour (the window):
 *
 * - Per IP address: after `ip_limit` failures that address is refused until
 *   its oldest failure leaves the window.
 * - Site-wide: after `site_limit` failures from any addresses, card payments
 *   pause for `pause_minutes` (the circuit breaker). Testers rotate
 *   addresses, so only this limit stops a large attack. recordFailure()
 *   returns TRIPPED once per pause so the caller can alert someone.
 *
 * An optional `min_amount` refuses charges below it (testers use small
 * amounts). Card saves with no amount of their own (a $1 verification) are
 * not held to it.
 *
 * Merchant-initiated charges (renewals) must not go through the guard: they
 * are neither refused nor counted. A limit set to 0 is off.
 */
final class VelocityGuard {

  public const PAUSED = 'paused';

  public const IP_BLOCKED = 'ip';

  public const BELOW_MINIMUM = 'amount';

  public const TRIPPED = 'tripped';

  public const DEFAULTS = [
    'ip_limit' => 5,
    'site_limit' => 20,
    'window_minutes' => 60,
    'pause_minutes' => 60,
    'min_amount' => '0',
  ];

  private const SITE = 'site';

  private const PAUSE = 'pause';

  private VelocityStore $store;

  private array $config;

  /** @var callable(): int */
  private $clock;

  /**
   * @param array $config
   *   Keys of DEFAULTS; missing keys take the default.
   * @param callable|null $clock
   *   fn(): int, the current Unix time (for tests).
   */
  public function __construct(VelocityStore $store, array $config = [], ?callable $clock = NULL) {
    $this->store = $store;
    $config += self::DEFAULTS;
    $this->config = [
      'ip_limit' => max(0, (int) $config['ip_limit']),
      'site_limit' => max(0, (int) $config['site_limit']),
      'window_minutes' => max(1, (int) $config['window_minutes']),
      'pause_minutes' => max(1, (int) $config['pause_minutes']),
      'min_amount' => self::cents((string) $config['min_amount']),
    ];
    $this->clock = $clock ?? static fn(): int => time();
  }

  /**
   * Whether a charge may be sent now.
   *
   * @param string $ip
   *   The payer's address; '' when unknown (the per-IP limit then does not
   *   apply).
   * @param string|null $amount
   *   Decimal amount; NULL for a card save or verification.
   *
   * @return string|null
   *   NULL to go ahead, or PAUSED, IP_BLOCKED or BELOW_MINIMUM.
   */
  public function check(string $ip, ?string $amount = NULL): ?string {
    if ($this->pausedUntil() !== NULL) {
      return self::PAUSED;
    }
    if ($ip !== '' && $this->config['ip_limit'] > 0 && count($this->recent($this->ipKey($ip))) >= $this->config['ip_limit']) {
      return self::IP_BLOCKED;
    }
    if ($amount !== NULL && $this->config['min_amount'] > 0 && self::cents($amount) < $this->config['min_amount']) {
      return self::BELOW_MINIMUM;
    }
    return NULL;
  }

  /**
   * Count a declined or refused charge or card save.
   *
   * @return string|null
   *   TRIPPED when this failure paused card payments, else NULL.
   */
  public function recordFailure(string $ip): ?string {
    if ($ip !== '' && $this->config['ip_limit'] > 0) {
      $this->append($this->ipKey($ip), $this->config['ip_limit']);
    }
    if ($this->config['site_limit'] <= 0 || $this->pausedUntil() !== NULL) {
      return NULL;
    }
    $site = $this->append(self::SITE, $this->config['site_limit']);
    if (count($site) < $this->config['site_limit']) {
      return NULL;
    }
    $until = $this->now() + 60 * $this->config['pause_minutes'];
    $this->store->set(self::PAUSE, ['until' => $until, 'since' => $this->now()], 60 * $this->config['pause_minutes']);
    $this->store->delete(self::SITE);
    return self::TRIPPED;
  }

  /**
   * The Unix time card payments resume, or NULL when they are not paused.
   */
  public function pausedUntil(): ?int {
    $until = (int) ($this->store->get(self::PAUSE)['until'] ?? 0);
    return $until > $this->now() ? $until : NULL;
  }

  /**
   * Lift a pause now (an administrator's "Resume" button).
   */
  public function resume(): void {
    $this->store->delete(self::PAUSE);
    $this->store->delete(self::SITE);
  }

  /**
   * Site-wide failures in the current window, for status displays.
   */
  public function siteFailures(): int {
    return count($this->recent(self::SITE));
  }

  public function config(): array {
    return ['min_amount' => number_format($this->config['min_amount'] / 100, 2, '.', '')] + $this->config;
  }

  /**
   * Payer wording for a reason check() returned. Says nothing about limits,
   * so a tester learns little from it.
   */
  public static function donorText(string $reason): string {
    return match ($reason) {
      self::BELOW_MINIMUM => DonorMessage::translate('The amount is below the minimum for card payments. Please enter a larger amount.'),
      self::PAUSED => DonorMessage::translate('Online card payments are temporarily unavailable. Please try again later or contact us.'),
      default => DonorMessage::translate('The payment could not be processed right now. Please try again later or contact us.'),
    };
  }

  private function append(string $key, int $keep): array {
    $times = $this->recent($key);
    $times[] = $this->now();
    $times = array_slice($times, -$keep);
    $this->store->set($key, $times, 60 * $this->config['window_minutes']);
    return $times;
  }

  /**
   * @return int[]
   *   Failure times still inside the window, oldest first.
   */
  private function recent(string $key): array {
    $since = $this->now() - 60 * $this->config['window_minutes'];
    return array_values(array_filter(array_map('intval', $this->store->get($key) ?? []), static fn(int $t): bool => $t > $since));
  }

  private function ipKey(string $ip): string {
    return 'ip:' . substr(hash('sha256', strtolower(trim($ip))), 0, 32);
  }

  private function now(): int {
    return (int) ($this->clock)();
  }

  private static function cents(string $amount): int {
    return (int) round(100 * (float) $amount);
  }

}
