<?php

namespace Payarc;

/**
 * A saved card as one opaque string: "{customer_id}:{card_id}".
 *
 * PayArc keeps cards under customer records and charges them with both ids.
 * Host applications store a single reference (a CiviCRM PaymentToken, a
 * WooCommerce token, entry meta), so the pair is packed into one value. Each
 * saved card gets its own PayArc customer (see GatewayClient::saveCard), so a
 * reference without a card id charges that customer's only (default) card.
 */
final class CardReference {

  public const SEPARATOR = ':';

  public static function encode(string $customerId, ?string $cardId): string {
    $customerId = self::validId($customerId, 'customer');
    if ($cardId === NULL || trim($cardId) === '') {
      return $customerId;
    }
    return $customerId . self::SEPARATOR . self::validId($cardId, 'card');
  }

  /**
   * @return array{customer_id: string, card_id: ?string}
   */
  public static function parse(string $reference): array {
    $parts = explode(self::SEPARATOR, trim($reference));
    if (count($parts) > 2) {
      throw new \InvalidArgumentException('Invalid PayArc card reference.');
    }
    return [
      'customer_id' => self::validId($parts[0], 'customer'),
      'card_id' => isset($parts[1]) ? self::validId($parts[1], 'card') : NULL,
    ];
  }

  private static function validId(string $id, string $what): string {
    $id = trim($id);
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
      throw new \InvalidArgumentException(sprintf('Invalid PayArc %s id.', $what));
    }
    return $id;
  }

}
