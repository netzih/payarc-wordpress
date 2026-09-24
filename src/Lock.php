<?php

namespace Payarc\WordPress;

/**
 * A mutual-exclusion lock backed by a row in wp_options.
 *
 * Transients are read-then-write and two cron workers can both pass the read;
 * here the INSERT itself is the test, because option_name is unique. The row
 * is written with plain SQL so the option caches never see it. A holder that
 * died keeps the lock only until its TTL passes; a holder that overran its
 * TTL and lost the lock cannot release the successor's, because release
 * deletes only the exact handle that acquire() returned.
 */
final class Lock {

  private const PREFIX = 'payarc_lock_';

  /**
   * In-process lock table used instead of the database, for unit tests that
   * run without WordPress. NULL in production.
   *
   * @var array<string, string>|null
   */
  private static ?array $memory = NULL;

  /**
   * Keep locks in memory instead of wp_options. Only for tests: an in-process
   * table cannot exclude a second PHP process.
   */
  public static function useMemory(bool $on = TRUE): void {
    self::$memory = $on ? [] : NULL;
  }

  /**
   * @return string|null
   *   A handle to pass to release(), or NULL when someone else holds the lock.
   */
  public static function acquire(string $name, int $ttlSeconds): ?string {
    $option = self::option($name);
    $now = time();
    $handle = $now . ':' . bin2hex(random_bytes(8));
    if (self::$memory !== NULL) {
      $current = self::$memory[$option] ?? '';
      $heldSince = (int) strtok($current, ':');
      if ($current !== '' && ($heldSince <= 0 || $heldSince + $ttlSeconds >= $now)) {
        return NULL;
      }
      self::$memory[$option] = $handle;
      return $handle;
    }
    global $wpdb;
    $suppress = $wpdb->suppress_errors(TRUE);
    try {
      $inserted = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
        $option,
        $handle
      ));
      if ($inserted === 1) {
        return $handle;
      }
      $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
      if ($current === '') {
        return NULL;
      }
      $heldSince = (int) strtok($current, ':');
      if ($heldSince <= 0 || $heldSince + $ttlSeconds >= $now) {
        return NULL;
      }
      // Stale: take it over, but only from the exact value we saw.
      $taken = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        $handle,
        $option,
        $current
      ));
      return $taken === 1 ? $handle : NULL;
    }
    finally {
      $wpdb->suppress_errors($suppress);
    }
  }

  public static function release(string $name, ?string $handle): void {
    if ($handle === NULL || $handle === '') {
      return;
    }
    if (self::$memory !== NULL) {
      if ((self::$memory[self::option($name)] ?? NULL) === $handle) {
        unset(self::$memory[self::option($name)]);
      }
      return;
    }
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => self::option($name), 'option_value' => $handle]);
  }

  private static function option(string $name): string {
    return self::PREFIX . preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
  }

}
