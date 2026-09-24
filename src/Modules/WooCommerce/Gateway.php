<?php

namespace Payarc\WordPress\Modules\WooCommerce;

use Payarc\AmbiguousGatewayException;
use Payarc\DonorMessage;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\WordPress\Gateway as Shared;
use Payarc\WordPress\Lock;
use Payarc\WordPress\Plugin;
use Payarc\WordPress\BusyException;
use Payarc\WordPress\Reconcile;

/**
 * WooCommerce payment gateway. Works on the block checkout (via
 * BlocksSupport) and the classic checkout, Pay for Order and Add Payment
 * Method pages, all through the same process_payment(): the block checkout
 * copies its payment data into $_POST before calling it.
 *
 * Saved cards are WC_Payment_Token_CC rows holding the PayArc saved-card
 * reference. WooCommerce Subscriptions renewals charge that reference from the
 * woocommerce_scheduled_subscription_payment_payarc hook.
 */
final class Gateway extends \WC_Payment_Gateway {

  public const ID = 'payarc';

  public const INTEGRATION = 'WooCommerce';

  public const META_TRANSACTION = '_payarc_transaction_key';

  public const META_REFNUM = '_payarc_refnum';

  public const META_MODE = '_payarc_mode';

  public const META_CARD_REFERENCE = '_payarc_card_reference';

  public const META_CARD_SUMMARY = '_payarc_card_summary';

  public function __construct() {
    $this->id = self::ID;
    $this->method_title = __('PayArc', 'payarc-payments');
    $this->method_description = __('Card payments through PayArc with hosted card fields (Pay.js). Credentials and sandbox/live mode are set under Settings > PayArc.', 'payarc-payments');
    $this->has_fields = TRUE;
    $this->supports = [
      'products',
      'refunds',
      'tokenization',
      'add_payment_method',
      'subscriptions',
      'multiple_subscriptions',
      'subscription_cancellation',
      'subscription_suspension',
      'subscription_reactivation',
      'subscription_amount_changes',
      'subscription_date_changes',
      'subscription_payment_method_change',
      'subscription_payment_method_change_customer',
      'subscription_payment_method_change_admin',
    ];

    $this->init_form_fields();
    $this->init_settings();
    $this->title = $this->get_option('title', __('Credit Card', 'payarc-payments'));
    $this->description = $this->get_option('description', '');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    add_action('woocommerce_scheduled_subscription_payment_' . $this->id, [$this, 'scheduledSubscriptionPayment'], 10, 2);
    add_filter('woocommerce_get_customer_payment_tokens', [$this, 'filterTokensByMode'], 10, 3);
    add_action('wp_enqueue_scripts', [$this, 'enqueueClassicAssets']);
  }

  public function init_form_fields() {
    $settingsUrl = admin_url('options-general.php?page=' . \Payarc\WordPress\Admin\SettingsPage::PAGE);
    $this->form_fields = [
      'enabled' => [
        'title' => __('Enable/Disable', 'payarc-payments'),
        'type' => 'checkbox',
        'label' => __('Enable PayArc', 'payarc-payments'),
        'default' => 'no',
      ],
      'title' => [
        'title' => __('Title', 'payarc-payments'),
        'type' => 'safe_text',
        'description' => __('Shown to the customer at checkout.', 'payarc-payments'),
        'default' => __('Credit Card', 'payarc-payments'),
        'desc_tip' => TRUE,
      ],
      'description' => [
        'title' => __('Description', 'payarc-payments'),
        'type' => 'textarea',
        'description' => __('Optional text above the card fields.', 'payarc-payments'),
        'default' => '',
        'desc_tip' => TRUE,
      ],
      'saved_cards' => [
        'title' => __('Saved cards', 'payarc-payments'),
        'type' => 'checkbox',
        'label' => __('Let logged-in customers save a card for next time (stored at PayArc; this site keeps only a reference).', 'payarc-payments'),
        'default' => 'yes',
      ],
      'credentials' => [
        'title' => __('Credentials', 'payarc-payments'),
        'type' => 'title',
        'description' => sprintf(
          /* translators: %s: settings URL */
          __('API key, PIN, Pay.js public key and the sandbox/live switch are shared with the other PayArc integrations and live under <a href="%s">Settings &gt; PayArc</a>.', 'payarc-payments'),
          esc_url($settingsUrl)
        ),
      ],
    ];
  }

  private function shared(): Shared {
    return Plugin::instance()->gateway();
  }

  private function settings(): \Payarc\WordPress\Settings {
    return Plugin::instance()->settings();
  }

  public function savedCardsEnabled(): bool {
    return $this->get_option('saved_cards', 'yes') === 'yes';
  }

  public function is_available() {
    return parent::is_available() && $this->settings()->isConfigured();
  }

  public function needs_setup() {
    return !$this->settings()->isConfigured();
  }

  /**
   * Data shared by the block and classic front ends.
   */
  public function frontendConfig(): array {
    $settings = $this->settings();
    return [
      'publicKey' => $settings->publicKey(),
      'payJsUrl' => $settings->payJsUrl(),
      'configured' => $settings->isConfigured(),
      'sandbox' => $settings->isSandbox(),
      'applePay' => [
        'enabled' => $settings->applePayEnabled() && !$this->cardOnFileRequired(),
        'displayName' => $settings->applePayDisplayName(),
        'countryCode' => WC()->countries ? WC()->countries->get_base_country() : 'US',
        'currencyCode' => get_woocommerce_currency(),
      ],
      'i18n' => [
        'notConfigured' => __('The payment form is not configured correctly, so no charge was made. Please contact us.', 'payarc-payments'),
        'secureNote' => __('Card details are entered securely in a form hosted by PayArc.', 'payarc-payments'),
        'orCard' => __('or enter card details', 'payarc-payments'),
        'sandboxNote' => __('Sandbox mode: use test card 4000100011112224.', 'payarc-payments'),
      ],
    ];
  }

  // ---------------------------------------------------------------------
  // Classic checkout / pay page / add payment method
  // ---------------------------------------------------------------------

  public function enqueueClassicAssets(): void {
    if (!is_checkout() && !is_add_payment_method_page() && !is_wc_endpoint_url('order-pay')) {
      return;
    }
    $plugin = Plugin::instance();
    wp_enqueue_style('payarc-payments', $plugin->url('assets/css/payarc-payments.css'), [], $plugin->assetVersion('assets/css/payarc-payments.css'));
    wp_enqueue_script('payarc-hostedfields', $plugin->url('assets/js/payarc-hostedfields.js'), [], $plugin->assetVersion('assets/js/payarc-hostedfields.js'), TRUE);
    wp_enqueue_script('payarc_woocommerce', $plugin->url('assets/js/payarc-woocommerce.js'), ['jquery', 'payarc-hostedfields'], $plugin->assetVersion('assets/js/payarc-woocommerce.js'), TRUE);
    wp_localize_script('payarc_woocommerce', 'payarc_woocommerce_params', $this->frontendConfig());
  }

  public function payment_fields() {
    if ($this->description) {
      echo '<p>' . wp_kses_post(wpautop($this->description)) . '</p>';
    }
    $showSaved = $this->savedCardsEnabled() && is_user_logged_in() && (is_checkout() || is_add_payment_method_page());
    if ($showSaved && !is_add_payment_method_page()) {
      $this->tokenization_script();
      $this->saved_payment_methods();
    }
    echo '<div class="wc-payment-form payarc-wc-fields">';
    if ($this->settings()->applePayEnabled() && !$this->cardOnFileRequired()) {
      echo '<div class="payarc-apple-pay" id="payarc-wc-apple-pay" hidden><div class="payarc-apple-pay-button" id="payarc-wc-apple-pay-button"></div><div class="payarc-apple-pay-divider"><span>' . esc_html__('or enter card details', 'payarc-payments') . '</span></div></div>';
    }
    echo '<div id="payarc-wc-card" class="payarc-card-element" aria-label="' . esc_attr__('Secure card details', 'payarc-payments') . '"></div>';
    echo '<div id="payarc-wc-errors" class="payarc-card-errors" role="alert" aria-live="polite"></div>';
    echo '<input type="hidden" id="payarc_payment_key" name="payarc_payment_key" value="" autocomplete="off">';
    echo '<p class="payarc-card-note">' . esc_html__('Card details are entered securely in a form hosted by PayArc.', 'payarc-payments') . '</p>';
    if ($this->settings()->isSandbox()) {
      echo '<p class="payarc-card-note">' . esc_html__('Sandbox mode: use test card 4000100011112224.', 'payarc-payments') . '</p>';
    }
    echo '</div>';
    if ($showSaved && !is_add_payment_method_page() && !$this->cartForcesSavedCard()) {
      $this->save_payment_method_checkbox();
    }
  }

  /**
   * A cart with a subscription always saves the card (renewals need it).
   */
  private function cartForcesSavedCard(): bool {
    return class_exists('WC_Subscriptions_Cart') && \WC_Subscriptions_Cart::cart_contains_subscription();
  }

  /**
   * Apple Pay keys are single-use and return no saved card, so the button is
   * offered only where nothing needs to be charged later: not for
   * subscription carts, saving a card, changing a subscription's card, or
   * paying an order that contains a subscription.
   */
  private function cardOnFileRequired(): bool {
    if (is_add_payment_method_page() || $this->cartForcesSavedCard()) {
      return TRUE;
    }
    if (!empty($_GET['change_payment_method'])) {
      return TRUE;
    }
    if (is_wc_endpoint_url('order-pay')) {
      $order = wc_get_order(absint(get_query_var('order-pay')));
      if ($order && ((function_exists('wcs_is_subscription') && wcs_is_subscription($order)) || $this->orderNeedsCardOnFile($order))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function validate_fields() {
    return TRUE;
  }

  // ---------------------------------------------------------------------
  // Payment
  // ---------------------------------------------------------------------

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
      return $this->failure(__('The order could not be found.', 'payarc-payments'));
    }

    // WooCommerce Subscriptions: changing the card on an existing subscription
    // posts here with the subscription as the "order" and nothing to charge.
    if (function_exists('wcs_is_subscription') && wcs_is_subscription($order)) {
      return $this->changeSubscriptionPaymentMethod($order);
    }

    $tokenId = $this->postedTokenId();
    $paymentKey = trim((string) ($_POST['payarc_payment_key'] ?? ''));
    $saveCard = $this->shouldSaveCard($order);
    $amount = (float) $order->get_total();
    $orderId = Shared::orderId('wc-' . $order->get_id());
    $metadata = $this->metadata($order, $orderId);

    $cardReference = '';
    $token = NULL;
    if ($tokenId > 0) {
      $token = \WC_Payment_Tokens::get($tokenId);
      if (!$token || $token->get_gateway_id() !== $this->id || (int) $token->get_user_id() !== get_current_user_id() || !$this->tokenUsable($token)) {
        return $this->failure(__('The saved card could not be used. Please choose another card.', 'payarc-payments'));
      }
      $cardReference = (string) $token->get_token();
    }
    elseif ($paymentKey === '') {
      return $this->failure(__('Please enter your card details.', 'payarc-payments'));
    }

    if ($amount <= 0) {
      // Free order (e.g. subscription with free trial): store the card only.
      if ($cardReference === '') {
        $outcome = $this->charge(static fn($client) => $client->verifyAndSaveCardWithPaymentKey($paymentKey, $metadata), $orderId, $order, \Payarc\GatewayClient::CARD_VERIFICATION_AMOUNT);
        if (!empty($outcome['error'])) {
          return $this->failure($outcome['error']);
        }
        $cardReference = trim((string) ($outcome['response']['savedcard']['key'] ?? ''));
        if ($cardReference === '') {
          return $this->failure(__('The card could not be saved for future payments. Please try a different card or contact us.', 'payarc-payments'));
        }
        $this->rememberCard($order, $outcome['response'], $cardReference, $saveCard, $token);
      }
      else {
        $this->rememberCard($order, [], $cardReference, FALSE, $token);
      }
      $order->payment_complete();
      $order->add_order_note(__('No charge: order total is zero. Card verified with PayArc for future payments.', 'payarc-payments'));
      $this->emptyCart();
      return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    if ($cardReference !== '') {
      $outcome = $this->charge(static fn($client) => $client->saleWithCardReference($cardReference, self::money($amount), $metadata), $orderId, $order, self::money($amount));
    }
    else {
      $outcome = $this->charge(static fn($client) => $client->saleWithPaymentKey($paymentKey, self::money($amount), $metadata, $saveCard), $orderId, $order, self::money($amount));
    }
    if (!empty($outcome['error'])) {
      return $this->failure($outcome['error']);
    }
    $response = $outcome['response'];
    $reference = Shared::transactionReference($response);
    $newReference = trim((string) ($response['savedcard']['key'] ?? ''));
    if ($newReference !== '') {
      $cardReference = $newReference;
    }
    if ($cardReference === '' && $this->orderNeedsCardOnFile($order)) {
      // Approved, but nothing to charge on renewal: undo the sale rather than
      // start a subscription that can never renew.
      $this->log('Order ' . $order->get_id() . ' contains a subscription but PayArc returned no saved card; voiding.', 'error');
      $this->voidQuietly($reference, $order);
      return $this->failure(__('The card could not be saved for future payments. Please try a different card or contact us.', 'payarc-payments'));
    }
    $card = Shared::card($response);
    if ($token && (empty($card['brand']) || empty($card['last4']))) {
      // Card-on-file sales echo little card data; fall back to the token.
      $card = ['brand' => $card['brand'] ?: ucfirst((string) $token->get_card_type()), 'last4' => $card['last4'] ?: (string) $token->get_last4()];
    }

    $order->update_meta_data(self::META_TRANSACTION, $reference);
    $order->update_meta_data(self::META_REFNUM, (string) ($response['refnum'] ?? ''));
    $order->update_meta_data(self::META_MODE, $this->settings()->mode());
    $order->update_meta_data(self::META_CARD_SUMMARY, self::summary($card));
    $this->rememberCard($order, $response, $cardReference, $saveCard && $newReference !== '', $token);
    $order->add_order_note($this->gatewayNote($response));
    $order->payment_complete($reference);
    $this->emptyCart();
    return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
  }

  /**
   * Save the card reference on the order and its subscriptions, and as a
   * customer token when asked (or when a subscription needs it).
   */
  private function rememberCard(\WC_Order $order, array $response, string $cardReference, bool $saveToken, ?\WC_Payment_Token $existing): void {
    if ($cardReference === '') {
      return;
    }
    $order->update_meta_data(self::META_CARD_REFERENCE, $cardReference);
    $order->save();

    if ($existing) {
      $order->add_payment_token($existing);
    }
    elseif ($saveToken && $order->get_user_id()) {
      $token = $this->createToken($response, $cardReference, (int) $order->get_user_id());
      if ($token) {
        $order->add_payment_token($token);
      }
    }

    if (function_exists('wcs_get_subscriptions_for_order')) {
      foreach (wcs_get_subscriptions_for_order($order, ['order_type' => 'any']) as $subscription) {
        $subscription->update_meta_data(self::META_CARD_REFERENCE, $cardReference);
        $subscription->update_meta_data(self::META_MODE, $this->settings()->mode());
        $subscription->save();
      }
    }
  }

  private function createToken(array $response, string $cardReference, int $userId): ?\WC_Payment_Token_CC {
    $card = Shared::card($response);
    [$month, $year] = self::expiry($response);
    $token = new \WC_Payment_Token_CC();
    $token->set_token($cardReference);
    $token->set_gateway_id($this->id);
    $token->set_user_id($userId);
    $token->set_card_type(strtolower((string) ($card['brand'] ?: 'card')));
    $token->set_last4((string) ($card['last4'] ?: '0000'));
    $token->set_expiry_month($month);
    $token->set_expiry_year($year);
    // A sandbox reference is useless against the live host and vice versa.
    $token->add_meta_data('_payarc_mode', $this->settings()->mode(), TRUE);
    try {
      $token->save();
      return $token;
    }
    catch (\Throwable $e) {
      $this->log('Could not save payment token: ' . $e->getMessage(), 'error');
      return NULL;
    }
  }

  /**
   * @return array{0: string, 1: string} MM and YYYY. PayArc returns MMYY on
   *   savedcard.expiration; when absent the token still needs a value, so a
   *   far-future date is used and the card stays usable.
   */
  public static function expiry(array $response): array {
    $raw = preg_replace('/\D/', '', (string) ($response['savedcard']['expiration'] ?? $response['creditcard']['expiration'] ?? ''));
    if (strlen($raw) === 4) {
      return [substr($raw, 0, 2), '20' . substr($raw, 2, 2)];
    }
    if (strlen($raw) === 6) {
      return [substr($raw, 0, 2), substr($raw, 2, 4)];
    }
    return ['12', (string) ((int) gmdate('Y') + 10)];
  }

  /**
   * Orders that create or renew a subscription must leave a card on file.
   */
  private function orderNeedsCardOnFile(\WC_Order $order): bool {
    return function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order, ['parent', 'renewal', 'resubscribe', 'switch']);
  }

  /**
   * A token saved in the other mode (sandbox vs live) must not be offered or charged.
   */
  private function tokenUsable(\WC_Payment_Token $token): bool {
    $mode = (string) $token->get_meta('_payarc_mode');
    return $mode === '' || $mode === $this->settings()->mode();
  }

  /**
   * woocommerce_get_customer_payment_tokens: hide our tokens from the other mode.
   */
  public function filterTokensByMode($tokens, $customer_id, $gateway_id) {
    if (!is_array($tokens)) {
      return $tokens;
    }
    foreach ($tokens as $key => $token) {
      if ($token instanceof \WC_Payment_Token && $token->get_gateway_id() === $this->id && !$this->tokenUsable($token)) {
        unset($tokens[$key]);
      }
    }
    return $tokens;
  }

  private function voidQuietly(string $reference, ?\WC_Order $order): void {
    if ($reference === '') {
      return;
    }
    try {
      $void = $this->shared()->client(self::INTEGRATION)->void($reference);
      if ($order) {
        $order->add_order_note(Shared::approved($void)
          ? sprintf(__('PayArc sale %s voided: no saved card reference was returned.', 'payarc-payments'), $reference)
          : sprintf(__('PayArc sale %1$s could NOT be voided (%2$s); void it in the console.', 'payarc-payments'), $reference, Shared::failure($void)['gateway']));
      }
    }
    catch (\Throwable $e) {
      $this->log('Void failed for ' . $reference . ': ' . $e->getMessage(), 'error');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc sale %1$s could NOT be voided (%2$s); void it in the console.', 'payarc-payments'), $reference, $e->getMessage()));
      }
    }
  }

  private function shouldSaveCard(\WC_Order $order): bool {
    if (!$order->get_user_id()) {
      return FALSE;
    }
    if ($this->orderNeedsCardOnFile($order)) {
      return TRUE;
    }
    if (!$this->savedCardsEnabled()) {
      return FALSE;
    }
    $flag = $_POST['wc-' . $this->id . '-new-payment-method'] ?? '';
    return in_array((string) $flag, ['true', '1', 'yes', 'on'], TRUE);
  }

  private function postedTokenId(): int {
    $raw = (string) ($_POST['wc-' . $this->id . '-payment-token'] ?? '');
    return $raw !== '' && $raw !== 'new' && ctype_digit($raw) ? (int) $raw : 0;
  }

  public function add_payment_method() {
    $paymentKey = trim((string) ($_POST['payarc_payment_key'] ?? ''));
    if ($paymentKey === '' || !is_user_logged_in()) {
      wc_add_notice(__('Please enter your card details.', 'payarc-payments'), 'error');
      return ['result' => 'failure', 'redirect' => wc_get_endpoint_url('payment-methods')];
    }
    $user = wp_get_current_user();
    $metadata = $this->shared()->metadata('WC-user-' . $user->ID, __('Card verification', 'payarc-payments'), [
      'email' => $user->user_email,
      'first_name' => get_user_meta($user->ID, 'billing_first_name', TRUE) ?: $user->first_name,
      'last_name' => get_user_meta($user->ID, 'billing_last_name', TRUE) ?: $user->last_name,
      'address' => get_user_meta($user->ID, 'billing_address_1', TRUE),
      'city' => get_user_meta($user->ID, 'billing_city', TRUE),
      'state' => get_user_meta($user->ID, 'billing_state', TRUE),
      'postcode' => get_user_meta($user->ID, 'billing_postcode', TRUE),
      'country' => get_user_meta($user->ID, 'billing_country', TRUE),
    ]);
    $outcome = $this->charge(static fn($client) => $client->verifyAndSaveCardWithPaymentKey($paymentKey, $metadata), NULL, NULL);
    if (!empty($outcome['error'])) {
      wc_add_notice($outcome['error'], 'error');
      return ['result' => 'failure', 'redirect' => wc_get_endpoint_url('payment-methods')];
    }
    $cardReference = trim((string) ($outcome['response']['savedcard']['key'] ?? ''));
    $token = $cardReference !== '' ? $this->createToken($outcome['response'], $cardReference, (int) $user->ID) : NULL;
    if (!$token) {
      wc_add_notice(__('The card could not be saved. Please try again or contact us.', 'payarc-payments'), 'error');
      return ['result' => 'failure', 'redirect' => wc_get_endpoint_url('payment-methods')];
    }
    return ['result' => 'success', 'redirect' => wc_get_endpoint_url('payment-methods')];
  }

  // ---------------------------------------------------------------------
  // Refunds
  // ---------------------------------------------------------------------

  public function can_refund_order($order) {
    return parent::can_refund_order($order) && $order && trim((string) ($order->get_meta(self::META_TRANSACTION) ?: $order->get_transaction_id())) !== '';
  }

  public function process_refund($order_id, $amount = NULL, $reason = '') {
    $order = wc_get_order($order_id);
    if (!$order) {
      return new \WP_Error('payarc', __('Order not found.', 'payarc-payments'));
    }
    $reference = trim((string) ($order->get_meta(self::META_TRANSACTION) ?: $order->get_transaction_id()));
    if ($reference === '') {
      return new \WP_Error('payarc', __('This order has no PayArc transaction reference.', 'payarc-payments'));
    }
    // Refund against the host the sale was made on, whatever the site's mode is now.
    $mode = (string) $order->get_meta(self::META_MODE) ?: $this->settings()->mode();
    $amount = $amount === NULL ? (float) $order->get_total() : (float) $amount;
    // wc_create_refund() has already saved this refund, so get_total_refunded()
    // includes it; anything beyond $amount was refunded earlier.
    $previouslyRefunded = max(0.0, (float) $order->get_total_refunded() - $amount);
    $full = abs($amount - (float) $order->get_total()) < 0.005 && $previouslyRefunded < 0.005;

    try {
      $client = $this->shared()->client(self::INTEGRATION, $mode);
      $transaction = $client->getTransaction($reference);
      $status = (string) ($transaction['status_code'] ?? '');
      if ($status === 'P' || $status === 'A') {
        if (!$full) {
          return new \WP_Error('payarc', __('This sale has not settled yet, so it can only be voided in full. Try a partial refund tomorrow.', 'payarc-payments'));
        }
        $response = $client->void($reference);
        $verb = __('voided before settlement', 'payarc-payments');
      }
      else {
        $verb = __('refunded', 'payarc-payments');
        // Refunds inherit the sale's orderid at PayArc. A marker on the order
        // makes sure a refund whose answer was lost is found, not repeated.
        $saleOrderId = trim((string) ($transaction['orderid'] ?? ''));
        if ($saleOrderId === '') {
          $response = $client->refund($reference, self::money($amount));
        }
        else {
          [$read, $write] = Reconcile::metaStore(
            static fn() => $order->get_meta('_payarc_refund_sent'),
            static function (array $marker) use ($order): void { $order->update_meta_data('_payarc_refund_sent', $marker); $order->save(); },
            static function () use ($order): void { $order->delete_meta_data('_payarc_refund_sent'); $order->save(); }
          );
          $result = Reconcile::once($client, $read, $write, $saleOrderId, self::money($amount), static fn($c) => $c->refund($reference, self::money($amount)), \Payarc\GatewayClient::TYPES_REFUND);
          if ($result['reconciled'] && abs((float) $result['amount'] - $amount) >= 0.005) {
            // The refund dialog shows this text raw, so no price markup.
            return new \WP_Error('payarc', Reconcile::refundMismatchMessage(html_entity_decode(wp_strip_all_tags(wc_price((float) $result['amount'], ['currency' => $order->get_currency()])), ENT_QUOTES), Shared::transactionReference($result['response'])));
          }
          $response = $result['response'];
          if ($result['reconciled']) {
            $order->add_order_note(__('PayArc confirms the earlier refund went through; recorded without refunding again.', 'payarc-payments'));
          }
          $clearRefundMarker = $write;
        }
      }
    }
    catch (ReconciliationInconclusiveException | AmbiguousGatewayException | BusyException $e) {
      $this->log('Refund unresolved for order ' . $order_id . ': ' . $e->getMessage(), 'error');
      return new \WP_Error('payarc', Reconcile::refundBlockedMessage($e, $reference));
    }
    catch (GatewayException $e) {
      $this->log('Refund failed for order ' . $order_id . ': ' . $e->getMessage(), 'error');
      return new \WP_Error('payarc', $e->getMessage());
    }
    if (!Shared::approved($response)) {
      $failure = Shared::failure($response);
      $this->log('Refund declined for order ' . $order_id . ': ' . $failure['gateway'], 'error');
      return new \WP_Error('payarc', $failure['gateway']);
    }
    if (isset($clearRefundMarker)) {
      // WooCommerce created the refund record before calling in, so once the
      // gateway has approved there is nothing left that could be lost.
      $clearRefundMarker(NULL);
    }
    $order->add_order_note(sprintf(
      __('%1$s %2$s via PayArc. Reference: %3$s%4$s', 'payarc-payments'),
      wc_price($amount, ['currency' => $order->get_currency()]),
      $verb,
      Shared::transactionReference($response) ?: $reference,
      $reason !== '' ? ' — ' . $reason : ''
    ));
    return TRUE;
  }

  // ---------------------------------------------------------------------
  // WooCommerce Subscriptions
  // ---------------------------------------------------------------------

  /**
   * Renewal: charge the saved card reference copied onto the renewal order.
   */
  public function scheduledSubscriptionPayment($amount, $renewalOrder): void {
    $order = $renewalOrder instanceof \WC_Order ? $renewalOrder : wc_get_order($renewalOrder);
    if (!$order) {
      return;
    }
    // Action Scheduler runs one job at a time, but an admin "Retry payment"
    // can overlap it; only one charge attempt per order at a time.
    $lockName = 'wc_renewal_' . $order->get_id();
    $lock = Lock::acquire($lockName, 10 * MINUTE_IN_SECONDS);
    if ($lock === NULL) {
      $order->add_order_note(__('PayArc: another renewal attempt for this order is still running; nothing was charged.', 'payarc-payments'));
      return;
    }
    try {
      $this->chargeRenewal($order, (float) $amount);
    }
    finally {
      Lock::release($lockName, $lock);
    }
  }

  private function chargeRenewal(\WC_Order $order, float $amount): void {
    $cardReference = trim((string) $order->get_meta(self::META_CARD_REFERENCE));
    if ($cardReference === '' && function_exists('wcs_get_subscriptions_for_renewal_order')) {
      foreach (wcs_get_subscriptions_for_renewal_order($order) as $subscription) {
        $cardReference = trim((string) $subscription->get_meta(self::META_CARD_REFERENCE));
        if ($cardReference !== '') {
          break;
        }
      }
    }
    if ($cardReference === '') {
      $order->update_status('failed', __('PayArc: no saved card reference on this subscription. The customer needs to update the payment method.', 'payarc-payments'));
      return;
    }
    $mode = (string) $order->get_meta(self::META_MODE);
    if ($mode !== '' && $mode !== $this->settings()->mode()) {
      $order->update_status('failed', sprintf(__('PayArc: subscription card was saved in %1$s mode but the site is in %2$s mode. Skipped.', 'payarc-payments'), $mode, $this->settings()->mode()));
      return;
    }
    if ($amount <= 0) {
      $order->payment_complete();
      return;
    }
    if ($order->is_paid() || trim((string) $order->get_meta(self::META_TRANSACTION)) !== '') {
      return;
    }
    // Every attempt is recorded (orderid + time) BEFORE its charge is sent
    // and removed only once PayArc gave a conclusive answer. Attempts still
    // listed are looked up before anything new is sent, each within its own
    // time window, so a lost response can never turn into a second charge
    // and an old, settled decline never blocks a retry.
    $attempts = (int) $order->get_meta('_payarc_renewal_attempts');
    $pending = $this->pendingAttempts($order);
    foreach ($pending as $i => $attempt) {
      try {
        $found = $this->findApproved((string) $attempt['orderid'], (int) $attempt['sent_at'] ?: NULL, self::money($amount));
      }
      catch (ReconciliationInconclusiveException $e) {
        $order->add_order_note(sprintf(__('PayArc could not confirm whether the earlier attempt %1$s was charged (%2$s). The order stays pending and nothing new was charged; retry later.', 'payarc-payments'), $attempt['orderid'], $e->getMessage()));
        return;
      }
      if ($found) {
        $order->add_order_note(sprintf(__('PayArc: the earlier attempt %s had already been charged; recorded without charging again.', 'payarc-payments'), $attempt['orderid']));
        unset($pending[$i]);
        $order->update_meta_data('_payarc_renewal_pending', array_values($pending));
        $this->completeRenewal($order, $found);
        return;
      }
      // Provably not charged: nothing more to check for this one.
      unset($pending[$i]);
    }
    $orderId = Shared::orderId('wc-' . $order->get_id() . '-' . $attempts);
    $metadata = $this->metadata($order, $orderId);
    unset($metadata['clientip']);
    $pending[] = ['orderid' => $orderId, 'sent_at' => time()];
    $order->update_meta_data('_payarc_renewal_attempts', $attempts + 1);
    $order->update_meta_data('_payarc_renewal_pending', array_values($pending));
    $order->save();

    $outcome = $this->charge(static fn($client) => $client->saleWithCardReference($cardReference, self::money($amount), $metadata), $orderId, $order);
    if (!empty($outcome['ambiguous'])) {
      // Leave the order pending and the attempt listed: a "failed" status
      // would make Subscriptions retry, and the charge may have gone through.
      // The next run (or an admin "Retry payment") reconciles it first.
      $order->add_order_note(__('PayArc did not answer conclusively; the order is left pending and will be reconciled before any new charge.', 'payarc-payments'));
      return;
    }
    // A conclusive answer: this attempt needs no further lookup.
    $order->update_meta_data('_payarc_renewal_pending', array_values(array_filter($pending, static fn($p) => ($p['orderid'] ?? '') !== $orderId)));
    $order->save();
    if (!empty($outcome['error'])) {
      $order->update_status('failed', sprintf(__('PayArc renewal charge failed: %s', 'payarc-payments'), $outcome['gateway'] ?? $outcome['error']));
      return;
    }
    $this->completeRenewal($order, $outcome['response']);
  }

  /**
   * Renewal attempts without a conclusive answer yet: [{orderid, sent_at}].
   * Orders from before this list existed fall back to every attempt made.
   *
   * @return array<int, array{orderid: string, sent_at: int}>
   */
  private function pendingAttempts(\WC_Order $order): array {
    $pending = $order->get_meta('_payarc_renewal_pending');
    if (is_array($pending)) {
      return array_values(array_filter($pending, static fn($p) => is_array($p) && !empty($p['orderid'])));
    }
    $attempts = (int) $order->get_meta('_payarc_renewal_attempts');
    $sentAt = (int) $order->get_meta('_payarc_renewal_sent_at');
    $legacy = [];
    for ($i = 0; $i < $attempts; $i++) {
      $legacy[] = ['orderid' => 'wc-' . $order->get_id() . '-' . $i, 'sent_at' => $sentAt];
    }
    return $legacy;
  }

  private function completeRenewal(\WC_Order $order, array $response): void {
    $reference = Shared::transactionReference($response);
    $order->update_meta_data(self::META_TRANSACTION, $reference);
    $order->update_meta_data(self::META_REFNUM, (string) ($response['refnum'] ?? ''));
    $order->update_meta_data(self::META_MODE, $this->settings()->mode());
    $order->add_order_note($this->gatewayNote($response));
    $order->payment_complete($reference);
  }

  /**
   * The approved transaction carrying this orderid, or NULL when PayArc
   * provably has none (or only a declined one).
   *
   * @throws \Payarc\ReconciliationInconclusiveException
   *   When the answer is unknown: the listing window ran out or the lookup
   *   itself failed. Callers must not charge.
   */
  private function findApproved(string $orderId, ?int $sentAt, string $amount): ?array {
    try {
      $found = $this->shared()->client(self::INTEGRATION)->findTransactionByOrderId($orderId, $sentAt, 5, $amount);
    }
    catch (ReconciliationInconclusiveException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $this->log('Reconciliation lookup failed for ' . $orderId . ': ' . $e->getMessage(), 'error');
      throw new ReconciliationInconclusiveException('The lookup for ' . $orderId . ' failed: ' . $e->getMessage(), 0, [], $e);
    }
    return $found && Shared::approved($found) ? $found : NULL;
  }

  /**
   * Customer or admin changes the card on a subscription: verify the new
   * card (or reuse a saved token) and store its reference.
   */
  private function changeSubscriptionPaymentMethod(\WC_Order $subscription): array {
    $tokenId = $this->postedTokenId();
    $paymentKey = trim((string) ($_POST['payarc_payment_key'] ?? ''));
    $cardReference = '';
    $token = NULL;
    if ($tokenId > 0) {
      $token = \WC_Payment_Tokens::get($tokenId);
      if (!$token || $token->get_gateway_id() !== $this->id || (int) $token->get_user_id() !== (int) $subscription->get_user_id() || !$this->tokenUsable($token)) {
        return $this->failure(__('The saved card could not be used. Please choose another card.', 'payarc-payments'));
      }
      $cardReference = (string) $token->get_token();
    }
    elseif ($paymentKey === '') {
      return $this->failure(__('Please enter your card details.', 'payarc-payments'));
    }
    else {
      $subOrderId = Shared::orderId('wc-sub-' . $subscription->get_id() . '-' . time());
      $metadata = $this->metadata($subscription, $subOrderId);
      $outcome = $this->charge(static fn($client) => $client->verifyAndSaveCardWithPaymentKey($paymentKey, $metadata), $subOrderId, NULL);
      if (!empty($outcome['error'])) {
        return $this->failure($outcome['error']);
      }
      $cardReference = trim((string) ($outcome['response']['savedcard']['key'] ?? ''));
      if ($cardReference === '') {
        return $this->failure(__('The card could not be saved for future payments. Please try a different card or contact us.', 'payarc-payments'));
      }
      if ($subscription->get_user_id()) {
        $token = $this->createToken($outcome['response'], $cardReference, (int) $subscription->get_user_id());
      }
      $subscription->add_order_note(sprintf(__('Card updated: %s', 'payarc-payments'), self::summary(Shared::card($outcome['response']))));
    }
    $subscription->update_meta_data(self::META_CARD_REFERENCE, $cardReference);
    $subscription->update_meta_data(self::META_MODE, $this->settings()->mode());
    $subscription->save();
    if ($token) {
      $subscription->add_payment_token($token);
    }
    return ['result' => 'success', 'redirect' => $this->get_return_url($subscription)];
  }

  // ---------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------

  /**
   * Run a gateway call with the shared failure handling.
   *
   * With an $orderId, an $order and an $amount the call is made at most once
   * for that order: a marker in the order's meta is stored before the request
   * and a resubmit after a lost response or a crash finds the earlier approval
   * instead of charging again. Renewals pass no $amount and keep their own
   * per-attempt list.
   *
   * @return array{response?: array, error?: string, gateway?: string, ambiguous?: bool}
   */
  private function charge(callable $call, ?string $orderId, ?\WC_Order $order, ?string $amount = NULL): array {
    try {
      $client = $this->shared()->client(self::INTEGRATION);
    }
    catch (GatewayException $e) {
      $this->log('Not configured: ' . $e->getMessage(), 'error');
      return ['error' => __('The payment system is not configured correctly, so no charge was made. Please contact us so we can fix it.', 'payarc-payments'), 'gateway' => $e->getMessage()];
    }
    try {
      if ($orderId !== NULL && $order && $amount !== NULL) {
        $read = static fn() => $order->get_meta('_payarc_charge_sent');
        $write = static function (?array $marker) use ($order): void {
          if ($marker === NULL) {
            $order->delete_meta_data('_payarc_charge_sent');
          }
          else {
            $order->update_meta_data('_payarc_charge_sent', $marker);
          }
          $order->save();
        };
        $result = Reconcile::once($client, $read, $write, $orderId, $amount, $call);
        $response = $result['response'];
        if ($result['reconciled']) {
          $order->add_order_note(sprintf(__('PayArc confirms the earlier charge %s went through; recorded without charging again.', 'payarc-payments'), $orderId));
        }
      }
      else {
        $response = $call($client);
      }
    }
    catch (ReconciliationInconclusiveException $e) {
      $this->log('Reconciliation inconclusive: ' . $e->getMessage(), 'error');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc could not confirm whether an earlier charge for this order went through (%s). Nothing was charged; check the console before retrying.', 'payarc-payments'), $e->getMessage()));
      }
      return ['error' => __('An earlier attempt to make this payment may have gone through, and the card processor could not confirm it. Nothing was charged now. Please contact us before trying again.', 'payarc-payments'), 'gateway' => $e->getMessage(), 'ambiguous' => TRUE];
    }
    catch (AmbiguousGatewayException $e) {
      $this->log('Ambiguous response: ' . $e->getMessage(), 'error');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc did not answer conclusively (%s). Check the console before retrying.', 'payarc-payments'), $e->getMessage()));
      }
      return ['error' => __('The payment could not be completed because the card processor did not respond. Please wait a moment and try again. If the problem continues, contact us.', 'payarc-payments'), 'gateway' => $e->getMessage(), 'ambiguous' => TRUE];
    }
    catch (GatewayException $e) {
      $this->log('Gateway error: ' . $e->getMessage(), 'error');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc error: %s', 'payarc-payments'), $e->getMessage()));
      }
      return ['error' => DonorMessage::donorText($e->getMessage()), 'gateway' => $e->getMessage()];
    }
    catch (\InvalidArgumentException $e) {
      $this->log('Invalid request: ' . $e->getMessage(), 'error');
      return ['error' => __('The payment could not be processed. Please check the card details and try again, or contact us for help.', 'payarc-payments'), 'gateway' => $e->getMessage()];
    }
    catch (BusyException $e) {
      // A second request for the same order while the first is still out.
      $this->log('Busy: ' . $e->getMessage(), 'info');
      return ['error' => Reconcile::busyMessage(), 'gateway' => $e->getMessage()];
    }
    catch (\RuntimeException $e) {
      // The marker could not be stored; nothing was sent.
      $this->log('Not sent: ' . $e->getMessage(), 'error');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc charge not sent: %s', 'payarc-payments'), $e->getMessage()));
      }
      return ['error' => __('The payment could not be processed right now. Please try again in a moment, or contact us for help.', 'payarc-payments'), 'gateway' => $e->getMessage()];
    }
    if (!Shared::approved($response)) {
      $failure = Shared::failure($response);
      $this->log('Declined: ' . $failure['gateway'], 'info');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc declined the card: %s', 'payarc-payments'), $failure['gateway']));
      }
      return ['error' => $failure['donor'], 'gateway' => $failure['gateway']];
    }
    if (!empty($response['void_error'])) {
      // The card was saved, but the $1 verification hold was not released.
      $this->log('Verification hold not voided: ' . $response['void_error'], 'error');
      if ($order) {
        $order->add_order_note(sprintf(__('PayArc saved the card but did not void the %1$s verification hold (%2$s). The hold expires on its own; void it in the console to release it sooner.', 'payarc-payments'), wc_price((float) \Payarc\GatewayClient::CARD_VERIFICATION_AMOUNT), $response['void_error']));
      }
    }
    return ['response' => $response];
  }

  private function failure(string $message): array {
    wc_add_notice($message, 'error');
    return ['result' => 'failure', 'message' => $message, 'redirect' => ''];
  }

  private function emptyCart(): void {
    if (WC()->cart) {
      WC()->cart->empty_cart();
    }
  }

  private function metadata(\WC_Order $order, string $orderId): array {
    $payer = [
      'email' => (string) $order->get_billing_email(),
      'first_name' => (string) $order->get_billing_first_name(),
      'last_name' => (string) $order->get_billing_last_name(),
      'phone' => (string) $order->get_billing_phone(),
      'address' => (string) $order->get_billing_address_1(),
      'address2' => (string) $order->get_billing_address_2(),
      'city' => (string) $order->get_billing_city(),
      'state' => (string) $order->get_billing_state(),
      'postcode' => (string) $order->get_billing_postcode(),
      'country' => (string) $order->get_billing_country(),
    ];
    $metadata = $this->shared()->metadata(
      'WC-' . $order->get_order_number(),
      sprintf(__('%1$s order %2$s', 'payarc-payments'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $order->get_order_number()),
      $payer,
      ['currency' => $order->get_currency(), 'orderid' => $orderId]
    );
    $ip = (string) $order->get_customer_ip_address();
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
      $metadata['clientip'] = $ip;
    }
    return $metadata;
  }

  public function gatewayNote(array $response): string {
    $parts = [sprintf(__('PayArc reference %s', 'payarc-payments'), Shared::transactionReference($response))];
    if (!empty($response['refnum'])) {
      $parts[] = sprintf(__('refnum %s', 'payarc-payments'), $response['refnum']);
    }
    if (!empty($response['authcode'])) {
      $parts[] = sprintf(__('auth code %s', 'payarc-payments'), $response['authcode']);
    }
    if (!empty($response['avs']['result'])) {
      $parts[] = sprintf(__('AVS: %s', 'payarc-payments'), $response['avs']['result']);
    }
    if (!empty($response['cvc']['result'])) {
      $parts[] = sprintf(__('CVV: %s', 'payarc-payments'), $response['cvc']['result']);
    }
    $card = Shared::card($response);
    if ($card['last4']) {
      $parts[] = self::summary($card);
    }
    if ($this->settings()->isSandbox()) {
      $parts[] = __('SANDBOX transaction', 'payarc-payments');
    }
    return implode(', ', $parts) . '.';
  }

  public static function summary(array $card): string {
    if (empty($card['last4'])) {
      return (string) ($card['brand'] ?: __('Card', 'payarc-payments'));
    }
    return sprintf(__('%1$s ending in %2$s', 'payarc-payments'), $card['brand'] ?: __('Card', 'payarc-payments'), $card['last4']);
  }

  public static function money(float $amount): string {
    return number_format($amount, 2, '.', '');
  }

  private function log(string $message, string $level = 'info'): void {
    if (function_exists('wc_get_logger')) {
      wc_get_logger()->log($level, $message, ['source' => 'payarc']);
    }
  }

}
