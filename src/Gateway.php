<?php

namespace Payarc\WordPress;

use Payarc\CardDetails;
use Payarc\Charge;
use Payarc\DonorMessage;
use Payarc\GatewayClient;
use Payarc\GatewayException;

/**
 * Builds gateway clients from the shared settings and holds the request
 * conventions every module follows (invoice = the host plugin's record
 * number, payer details in metadata, no PayArc receipts).
 */
final class Gateway {

  private Settings $settings;

  public function __construct(Settings $settings) {
    $this->settings = $settings;
  }

  /**
   * @param string $integration
   *   Recorded on each charge (metadata 'software'), e.g. "Gravity Forms".
   * @param string|null $mode
   *   Force live or sandbox; defaults to the configured mode.
   * @param string|null $account
   *   One of Settings::accounts(); defaults to the default account.
   */
  public function client(string $integration, ?string $mode = NULL, ?string $account = NULL): GatewayClient {
    $mode = $mode ?? $this->settings->mode();
    if (!$this->settings->hasAccount($account)) {
      throw new GatewayException(sprintf(__('The PayArc account "%s" no longer exists under Settings > PayArc.', 'payarc-payments'), (string) $account));
    }
    if (!$this->settings->hasApiCredentials($mode, $account)) {
      throw new GatewayException(Settings::isDefault($account)
        ? __('PayArc is not configured. Enter the API bearer token under Settings > PayArc.', 'payarc-payments')
        : sprintf(__('The PayArc account "%s" has no API bearer token for this mode. Enter it under Settings > PayArc.', 'payarc-payments'), $this->settings->accountLabel($account)));
    }
    return new GatewayClient(
      $this->settings->bearerToken($mode, $account),
      $this->settings->apiUrl($mode),
      NULL,
      $this->software($integration)
    );
  }

  public function software(string $integration): string {
    $integration = trim($integration);
    return 'WordPress PayArc Payments/' . Plugin::VERSION . ($integration !== '' ? ' (' . $integration . ')' : '');
  }

  /**
   * Options common to every charge (see GatewayClient::chargeToken()).
   *
   * The payer's email goes into metadata, not the top-level 'email' field:
   * PayArc's own receipts are switched off on every charge, and keeping the
   * address out of the field that drives them is a second guard.
   *
   * @param array $payer
   *   Keys: email, first_name, last_name, address, address2, city, state,
   *   postcode, country, phone.
   * @param array $extra
   *   'recurring' => TRUE for a merchant-initiated installment; 'metadata'
   *   for more key => value pairs.
   */
  public function chargeOptions(string $invoice, string $description, array $payer, array $extra = []): array {
    $name = trim(trim((string) ($payer['first_name'] ?? '')) . ' ' . trim((string) ($payer['last_name'] ?? '')));
    $metadata = array_filter([
      'payer_name' => $name,
      'payer_email' => trim((string) ($payer['email'] ?? '')),
      'payer_zip' => trim((string) ($payer['postcode'] ?? '')),
      'client_ip' => $this->clientIp(),
      'site' => (string) wp_parse_url((string) home_url(), PHP_URL_HOST),
    ] + (array) ($extra['metadata'] ?? []), static fn($v) => $v !== '' && $v !== NULL);

    return array_filter([
      'invoice' => mb_substr($invoice, 0, 50),
      'description' => mb_substr($description, 0, 255),
      'metadata' => $metadata,
      'recurring' => !empty($extra['recurring']),
    ], static fn($v) => $v !== '' && $v !== [] && $v !== FALSE);
  }

  /**
   * The customer record PayArc keeps a saved card under (one per card).
   */
  public static function customer(array $payer, string $description): array {
    return array_filter([
      'email' => trim((string) ($payer['email'] ?? '')),
      'name' => trim(trim((string) ($payer['first_name'] ?? '')) . ' ' . trim((string) ($payer['last_name'] ?? ''))),
      'description' => mb_substr($description, 0, 255),
      'address_1' => (string) ($payer['address'] ?? ''),
      'address_2' => (string) ($payer['address2'] ?? ''),
      'city' => (string) ($payer['city'] ?? ''),
      'state' => (string) ($payer['state'] ?? ''),
      'zip' => (string) ($payer['postcode'] ?? ''),
      'country' => (string) ($payer['country'] ?? ''),
      'phone' => (string) ($payer['phone'] ?? ''),
    ], static fn($v) => trim((string) $v) !== '');
  }

  /**
   * Save a single-use token as a card, at most once per token.
   *
   * A token can be attached only once, and a resubmitted form (after an
   * error further on) carries the same token, so the saved card is
   * remembered per token and handed back instead of failing on the used
   * token. Kept in a wp_options row with the charge markers and purged with
   * them.
   *
   * PayArc requires an email to save a card; a payer without one gets a
   * placeholder at this site's domain, which never receives mail.
   *
   * @return array{reference: string, card: array}
   *
   * @throws GatewayException
   */
  public function saveCard(GatewayClient $client, string $token, array $payer, string $description): array {
    [$read, $write] = Reconcile::optionStore('card:' . hash('sha256', $token));
    $known = $read();
    if (is_array($known) && !empty($known['reference'])) {
      return ['reference' => (string) $known['reference'], 'card' => (array) ($known['card'] ?? [])];
    }
    $customer = self::customer($payer, $description);
    if (empty($customer['email']) || !is_email($customer['email'])) {
      $host = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);
      $customer['email'] = 'no-email@' . ($host !== '' ? $host : 'example.invalid');
    }
    $saved = $client->saveCard($token, $customer);
    $write(['reference' => $saved['reference'], 'card' => $saved['card'], 'sent_at' => time()]);
    return ['reference' => $saved['reference'], 'card' => $saved['card']];
  }

  /**
   * Orderids carry a short site prefix so two sites sharing one PayArc
   * account can never reconcile each other's charges. Every module builds
   * its orderids through here and looks them up by the same string.
   */
  public static function orderId(string $id): string {
    $prefix = apply_filters('payarc_payments_orderid_prefix', substr(md5((string) home_url()), 0, 6));
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix);
    return ($prefix !== '' ? $prefix . '-' : '') . $id;
  }

  /**
   * The payer's address, as recorded on charges and counted by the
   * card-testing limits. REMOTE_ADDR by default; a site behind a proxy that
   * does not restore it (e.g. Cloudflare without its real-IP module) must
   * supply the forwarded address, or every payer counts as the proxy:
   *
   *   add_filter('payarc_payments_client_ip', fn($ip) => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip);
   */
  public function clientIp(): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
    $ip = (string) apply_filters('payarc_payments_client_ip', $ip);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  public static function approved(array $response): bool {
    return Charge::approved($response);
  }

  /**
   * A refund or void came back done: the sale shows money returned.
   */
  public static function reversed(array $response): bool {
    return in_array(Charge::outcome($response), [Charge::REVERSED, Charge::APPROVED], TRUE);
  }

  /**
   * @return array{donor: string, gateway: string}
   */
  public static function failure(array $response): array {
    return DonorMessage::fromResponse($response);
  }

  /**
   * @return array{brand: ?string, last4: ?string, exp_month: ?string, exp_year: ?string, card_id: ?string, verified: ?bool}
   */
  public static function card(array $response): array {
    return CardDetails::fromResponse($response);
  }

  /**
   * One line for an order, entry or donation note: charge id, auth code and
   * the card checks PayArc reports.
   */
  public static function note(array $response, bool $sandbox): string {
    $charge = Charge::unwrap($response);
    $card = CardDetails::card($charge);
    $parts = [sprintf(__('PayArc charge %s', 'payarc-payments'), self::transactionReference($charge))];
    if (Charge::authCode($charge) !== '') {
      $parts[] = sprintf(__('auth code %s', 'payarc-payments'), Charge::authCode($charge));
    }
    $avs = trim((string) ($card['avs_status'] ?? ''));
    if ($avs !== '' && $avs !== '0') {
      $parts[] = sprintf(__('AVS: %s', 'payarc-payments'), $avs . (!empty($card['zip_check_passed']) ? ' (' . __('ZIP matched', 'payarc-payments') . ')' : ''));
    }
    $cvv = trim((string) ($card['cvc_status'] ?? ''));
    if ($cvv !== '') {
      $parts[] = sprintf(__('CVV: %s', 'payarc-payments'), $cvv);
    }
    if ($sandbox) {
      $parts[] = __('SANDBOX transaction', 'payarc-payments');
    }
    return implode(', ', $parts) . '.';
  }

  /**
   * The PayArc charge id (refunds and voids are made against it).
   */
  public static function transactionReference(array $response): string {
    return Charge::id($response);
  }

}
