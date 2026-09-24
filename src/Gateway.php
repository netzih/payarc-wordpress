<?php

namespace Payarc\WordPress;

use Payarc\CardDetails;
use Payarc\Country;
use Payarc\DonorMessage;
use Payarc\GatewayClient;
use Payarc\GatewayException;

/**
 * Builds gateway clients from the shared settings and holds the request
 * conventions every module follows (custid = payer email, invoice = the
 * host plugin's record number, never a top-level email).
 */
final class Gateway {

  private Settings $settings;

  public function __construct(Settings $settings) {
    $this->settings = $settings;
  }

  /**
   * @param string $integration
   *   Shown in the PayArc console's "software" column, e.g. "Gravity Forms".
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
        ? __('PayArc is not configured. Enter the API key and PIN under Settings > PayArc.', 'payarc-payments')
        : sprintf(__('The PayArc account "%s" has no API key and PIN for this mode. Enter them under Settings > PayArc.', 'payarc-payments'), $this->settings->accountLabel($account)));
    }
    return new GatewayClient(
      $this->settings->apiKey($mode, $account),
      $this->settings->apiPin($mode, $account),
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
   * Metadata common to every charge: identifies the payer to the console and
   * carries the billing address for AVS. No top-level email (PayArc would
   * send its own receipt).
   *
   * @param array $payer
   *   Keys: email, first_name, last_name, address, address2, city, state,
   *   postcode, country (alpha-2 or alpha-3), phone.
   */
  public function metadata(string $invoice, string $description, array $payer, array $extra = []): array {
    $address = [
      'firstname' => $payer['first_name'] ?? '',
      'lastname' => $payer['last_name'] ?? '',
      'street' => $payer['address'] ?? '',
      'street2' => $payer['address2'] ?? '',
      'city' => $payer['city'] ?? '',
      'state' => $payer['state'] ?? '',
      'postalcode' => $payer['postcode'] ?? '',
      'country' => Country::alpha3((string) ($payer['country'] ?? '')),
      'phone' => $payer['phone'] ?? '',
      'email' => $payer['email'] ?? '',
    ];
    $address = array_filter(array_map(static fn($v) => trim((string) $v), $address), static fn($v) => $v !== '');

    $metadata = [
      'invoice' => mb_substr($invoice, 0, 50),
      'description' => mb_substr($description, 0, 255),
      'custid' => mb_substr(trim((string) ($payer['email'] ?? '')), 0, 50),
      'clientip' => $this->clientIp(),
      'currency' => $extra['currency'] ?? 'USD',
      'billing_address' => $address,
    ];
    if (!empty($extra['orderid'])) {
      $metadata['orderid'] = mb_substr((string) $extra['orderid'], 0, 64);
    }
    return array_filter($metadata, static fn($v) => $v !== '' && $v !== []);
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

  public function clientIp(): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  public static function approved(array $response): bool {
    return ($response['result_code'] ?? '') === 'A';
  }

  /**
   * @return array{donor: string, gateway: string}
   */
  public static function failure(array $response): array {
    return DonorMessage::fromResponse($response);
  }

  /**
   * @return array{brand: ?string, last4: ?string}
   */
  public static function card(array $response): array {
    return CardDetails::fromResponse($response);
  }

  /**
   * PayArc's transaction key (preferred for refunds) with the numeric refnum
   * as a fallback for older responses.
   */
  public static function transactionReference(array $response): string {
    $key = trim((string) ($response['key'] ?? ''));
    return $key !== '' ? $key : trim((string) ($response['refnum'] ?? ''));
  }

}
