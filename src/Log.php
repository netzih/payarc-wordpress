<?php

namespace Payarc\WordPress;

/**
 * Debug logging to the PHP error log (wp-content/debug.log with WP_DEBUG_LOG)
 * when the "Debug log" setting is on; errors are always logged. Modules with
 * their own logger (Gravity Forms) also mirror into it.
 */
final class Log {

  public static function debug(string $message, array $context = []): void {
    if (Plugin::instance()->settings()->debugLog()) {
      self::write('debug', $message, $context);
    }
  }

  public static function error(string $message, array $context = []): void {
    self::write('error', $message, $context);
  }

  private static function write(string $level, string $message, array $context): void {
    $suffix = $context ? ' ' . wp_json_encode(self::redact($context)) : '';
    error_log('[payarc-payments][' . $level . '] ' . $message . $suffix);
  }

  /**
   * Never let tokens or credentials reach a log file.
   */
  public static function redact(array $context): array {
    foreach ($context as $key => $value) {
      if (is_array($value)) {
        $context[$key] = self::redact($value);
      }
      elseif (preg_match('/payment_key|pin|password|authorization|cardnumber|number|token/i', (string) $key)) {
        $context[$key] = '[redacted]';
      }
    }
    return $context;
  }

}
