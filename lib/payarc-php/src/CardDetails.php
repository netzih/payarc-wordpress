<?php

namespace Payarc;

/**
 * Extracts brand, last four and expiry from a PayArc charge, token, customer
 * or card object. Pure PHP.
 */
class CardDetails {

  /**
   * PayArc's one-letter brand codes plus spelled-out names. 'R' covers
   * Discover and Diners (and UnionPay on the Discover network).
   */
  private const BRANDS = [
    'v' => 'Visa',
    'visa' => 'Visa',
    'm' => 'MasterCard',
    'mc' => 'MasterCard',
    'mastercard' => 'MasterCard',
    'master card' => 'MasterCard',
    'x' => 'Amex',
    'amex' => 'Amex',
    'american express' => 'Amex',
    'r' => 'Discover',
    'discover' => 'Discover',
    'diners' => 'Diners',
    'j' => 'JCB',
    'jcb' => 'JCB',
  ];

  /**
   * @return array{brand: ?string, last4: ?string, exp_month: ?string, exp_year: ?string, card_id: ?string, verified: ?bool}
   */
  public static function fromResponse(array $response): array {
    $card = self::card($response);
    $last4 = preg_match('/(\d{4})\D*$/', (string) ($card['last4digit'] ?? $card['last4'] ?? ''), $m) ? $m[1] : NULL;
    $brand = self::normalizeBrand((string) ($card['brand'] ?? ''));
    if ($brand === NULL && isset($card['first6digit'])) {
      $brand = self::brandFromPrefix((string) $card['first6digit']);
    }
    $month = preg_match('/^\d{1,2}$/', trim((string) ($card['exp_month'] ?? ''))) ? str_pad(trim((string) $card['exp_month']), 2, '0', STR_PAD_LEFT) : NULL;
    $year = preg_match('/^\d{2,4}$/', trim((string) ($card['exp_year'] ?? ''))) ? trim((string) $card['exp_year']) : NULL;
    if ($year !== NULL && strlen($year) === 2) {
      $year = '20' . $year;
    }
    return [
      'brand' => $brand,
      'last4' => $last4,
      'exp_month' => $month,
      'exp_year' => $year,
      'card_id' => isset($card['id']) && is_scalar($card['id']) && $card['id'] !== '' ? (string) $card['id'] : NULL,
      'verified' => isset($card['is_verified']) ? (bool) $card['is_verified'] : NULL,
    ];
  }

  public static function normalizeBrand(string $brand): ?string {
    return self::BRANDS[strtolower(trim($brand))] ?? NULL;
  }

  /**
   * The card object inside a response: {card: {data: {...}}} on charges and
   * tokens, {card: {data: [...]}} on customers (the default card, else the
   * newest), or the card itself.
   */
  public static function card(array $response): array {
    $object = isset($response['data']) && is_array($response['data']) && !array_is_list($response['data']) ? $response['data'] : $response;
    if (($object['object'] ?? '') === 'Card') {
      return $object;
    }
    $card = $object['card'] ?? NULL;
    if (is_array($card) && array_key_exists('data', $card)) {
      $card = $card['data'];
    }
    if (!is_array($card) || $card === []) {
      return [];
    }
    if (!array_is_list($card)) {
      return $card;
    }
    $cards = array_values(array_filter($card, 'is_array'));
    foreach ($cards as $row) {
      if (!empty($row['is_default'])) {
        return $row;
      }
    }
    usort($cards, static fn(array $a, array $b) => (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0));
    return $cards[0] ?? [];
  }

  private static function brandFromPrefix(string $digits): ?string {
    $digits = preg_replace('/\D/', '', $digits);
    if ($digits === '' || $digits === NULL) {
      return NULL;
    }
    $two = (int) substr($digits, 0, 2);
    if ($digits[0] === '4') {
      return 'Visa';
    }
    if ($digits[0] === '5' || ($two >= 22 && $two <= 27)) {
      return 'MasterCard';
    }
    if ($two === 34 || $two === 37) {
      return 'Amex';
    }
    if ($digits[0] === '6') {
      return 'Discover';
    }
    return NULL;
  }

}
