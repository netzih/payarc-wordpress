<?php

namespace Payarc;

/**
 * Converts between the decimal strings callers use ("12.30") and the integer
 * cents PayArc expects. PayArc accepts only USD, so two decimals always.
 */
final class Amount {

  public static function toCents(string|int|float $amount): int {
    $amount = is_string($amount) ? trim($amount) : $amount;
    if (!is_numeric($amount) || (float) $amount < 0) {
      throw new \InvalidArgumentException('Payment amount must be a non-negative number.');
    }
    return (int) round(((float) $amount) * 100);
  }

  public static function fromCents(int|string|null $cents): string {
    if ($cents === NULL || !is_numeric($cents)) {
      return '0.00';
    }
    return number_format(((int) $cents) / 100, 2, '.', '');
  }

}
