<?php

namespace Payarc\WordPress\Modules\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Payarc\WordPress\Plugin;

/**
 * Block checkout integration: registers the front-end script and hands it the
 * gateway settings. Payment data posted by the block lands in $_POST for the
 * gateway's process_payment(), so no second server path is needed.
 */
final class BlocksSupport extends AbstractPaymentMethodType {

  protected $name = Gateway::ID;

  public function initialize() {
    $this->settings = get_option('woocommerce_' . Gateway::ID . '_settings', []);
  }

  public function is_active() {
    return filter_var($this->get_setting('enabled', FALSE), FILTER_VALIDATE_BOOLEAN) && Plugin::instance()->settings()->isConfigured();
  }

  public function get_payment_method_script_handles() {
    $plugin = Plugin::instance();
    wp_register_script('payarc-hostedfields', $plugin->url('assets/js/payarc-hostedfields.js'), [], $plugin->assetVersion('assets/js/payarc-hostedfields.js'), TRUE);
    wp_register_script(
      'payarc_woocommerce_blocks',
      $plugin->url('assets/js/payarc-woocommerce-blocks.js'),
      ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n', 'payarc-hostedfields'],
      $plugin->assetVersion('assets/js/payarc-woocommerce-blocks.js'),
      TRUE
    );
    wp_enqueue_style('payarc-payments', $plugin->url('assets/css/payarc-payments.css'), [], $plugin->assetVersion('assets/css/payarc-payments.css'));
    return ['payarc_woocommerce_blocks'];
  }

  public function get_payment_method_data() {
    $gateway = $this->gateway();
    return [
      'title' => $this->get_setting('title', __('Credit Card', 'payarc-payments')),
      'description' => $this->get_setting('description', ''),
      'supports' => $gateway ? array_filter($gateway->supports, [$gateway, 'supports']) : ['products'],
      'showSavedCards' => $gateway ? $gateway->savedCardsEnabled() && is_user_logged_in() : FALSE,
      'showSaveOption' => $gateway ? $gateway->savedCardsEnabled() && is_user_logged_in() : FALSE,
    ] + ($gateway ? $gateway->frontendConfig() : []);
  }

  private function gateway(): ?Gateway {
    $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
    $gateway = $gateways[Gateway::ID] ?? NULL;
    return $gateway instanceof Gateway ? $gateway : NULL;
  }

}
