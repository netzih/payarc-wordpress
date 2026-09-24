<?php

namespace Payarc\WordPress;

use Payarc\DonorMessage;

/**
 * Plugin bootstrap: shared settings and gateway factory, plus one module per
 * host plugin (Gravity Forms, GiveWP, WooCommerce) that registers only when
 * that plugin is active.
 */
final class Plugin {

  public const VERSION = '0.2.0';

  public const SLUG = 'payarc-payments';

  private static ?Plugin $instance = NULL;

  private string $file;

  private Settings $settings;

  private Gateway $gateway;

  public static function boot(string $file): void {
    if (self::$instance) {
      return;
    }
    self::$instance = new self($file);
    self::$instance->hooks();
  }

  public static function instance(): Plugin {
    if (!self::$instance) {
      throw new \LogicException('PayArc Payments has not been booted.');
    }
    return self::$instance;
  }

  private function __construct(string $file) {
    $this->file = $file;
    $this->settings = new Settings();
    $this->gateway = new Gateway($this->settings);

  }

  private function hooks(): void {
    DonorMessage::setTranslator(static fn(string $text): string => __($text, 'payarc-payments'));

    add_action('init', [$this, 'loadTextdomain']);
    if (is_admin()) {
      (new Admin\SettingsPage($this->settings, $this->gateway))->register();
    }

    // Gravity Forms add-ons must be registered on gform_loaded.
    add_action('gform_loaded', [$this, 'bootGravityForms'], 5);

    // GiveWP: register the gateway when GiveWP collects gateways, add a
    // pointer section under Donations > Settings > Payment Gateways and
    // run the renewal worker hourly.
    add_action('givewp_register_payment_gateway', [$this, 'bootGiveWP']);
    add_filter('give_get_sections_gateways', [$this, 'giveSettingsSection']);
    add_filter('give_get_settings_gateways', [$this, 'giveSettingsFields']);
    add_action(self::CRON_GIVEWP, [$this, 'runGiveRenewals']);
    add_action('init', [$this, 'scheduleGiveRenewals']);

    // WooCommerce: gateway class, block checkout support, feature compatibility.
    add_filter('woocommerce_payment_gateways', [$this, 'wooGateways']);
    add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'wooBlocks']);
    add_action('before_woocommerce_init', [$this, 'wooCompatibility']);
  }

  public const MODULES = ['gravityforms', 'givewp', 'woocommerce'];

  /**
   * Whether a host-plugin module may register. All are on by default; a site
   * that only needs the shared settings and gateway (for another plugin built
   * on them), or that runs a different PayArc gateway for one host plugin,
   * switches modules off with the payarc_payments_modules filter:
   *
   *   add_filter('payarc_payments_modules', fn($m) => ['givewp' => FALSE] + $m);
   *
   * Read when each hook fires, so the filter can be added by a plugin that
   * loads after this one.
   */
  public function moduleEnabled(string $module): bool {
    $modules = apply_filters('payarc_payments_modules', array_fill_keys(self::MODULES, TRUE));
    return is_array($modules) && !empty($modules[$module]);
  }

  public function wooGateways(array $gateways): array {
    if ($this->moduleEnabled('woocommerce') && class_exists('WC_Payment_Gateway')) {
      $gateways[] = Modules\WooCommerce\Gateway::class;
    }
    return $gateways;
  }

  public function wooBlocks($registry): void {
    if ($this->moduleEnabled('woocommerce') && class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
      $registry->register(new Modules\WooCommerce\BlocksSupport());
    }
  }

  public function wooCompatibility(): void {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $this->file, TRUE);
      \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $this->file, TRUE);
    }
  }

  public const CRON_GIVEWP = 'payarc_givewp_renewals';

  public function bootGiveWP($registrar): void {
    if (!$this->moduleEnabled('givewp') || !class_exists('\Give\Framework\PaymentGateways\PaymentGateway')) {
      return;
    }
    try {
      $registrar->registerGateway(Modules\GiveWP\Gateway::class);
    }
    catch (\OverflowException $e) {
      // Already registered (GiveWP fires the collection more than once).
    }
  }

  public function giveSettingsSection(array $sections): array {
    if (!$this->moduleEnabled('givewp')) {
      return $sections;
    }
    $sections[Modules\GiveWP\Gateway::ID] = __('PayArc', 'payarc-payments');
    return $sections;
  }

  public function giveSettingsFields(array $settings): array {
    if (!$this->moduleEnabled('givewp') || !function_exists('give_get_current_setting_section') || give_get_current_setting_section() !== Modules\GiveWP\Gateway::ID) {
      return $settings;
    }
    $url = admin_url('options-general.php?page=' . Admin\SettingsPage::PAGE);
    return [
      ['type' => 'title', 'id' => 'give_title_payarc'],
      [
        'name' => __('PayArc account', 'payarc-payments'),
        'id' => 'payarc_pointer',
        'type' => 'give_docs_link',
        'url' => $url,
        'title' => __('Open Settings > PayArc', 'payarc-payments'),
        'desc' => __('Credentials are shared with the other PayArc integrations on this site and are managed under Settings > PayArc. GiveWP Test Mode uses the sandbox credentials; live mode uses the live ones. Recurring donations are charged by this site every renewal date (hourly WordPress cron), nothing is scheduled in the PayArc console.', 'payarc-payments'),
      ],
      ['type' => 'sectionend', 'id' => 'give_title_payarc'],
    ];
  }

  public function scheduleGiveRenewals(): void {
    if (!function_exists('give')) {
      return;
    }
    if (!$this->moduleEnabled('givewp')) {
      wp_clear_scheduled_hook(self::CRON_GIVEWP);
      return;
    }
    if (!wp_next_scheduled(self::CRON_GIVEWP)) {
      wp_schedule_event(time() + 300, 'hourly', self::CRON_GIVEWP);
    }
  }

  public function runGiveRenewals(): void {
    if (!$this->moduleEnabled('givewp') || !function_exists('give') || !class_exists('\Give\Subscriptions\Models\Subscription')) {
      return;
    }
    $summary = (new Modules\GiveWP\Renewals())->run();
    Log::debug('GiveWP renewals', $summary);
  }

  public function loadTextdomain(): void {
    load_plugin_textdomain('payarc-payments', FALSE, dirname(plugin_basename($this->file)) . '/languages');
  }

  public function bootGravityForms(): void {
    if (!$this->moduleEnabled('gravityforms') || !method_exists('GFForms', 'include_payment_addon_framework')) {
      return;
    }
    \GFForms::include_payment_addon_framework();
    if (!\GF_Fields::exists(Modules\GravityForms\CardField::TYPE)) {
      \GF_Fields::register(new Modules\GravityForms\CardField());
    }
    \GFAddOn::register(Modules\GravityForms\AddOn::class);
  }

  public function settings(): Settings {
    return $this->settings;
  }

  public function gateway(): Gateway {
    return $this->gateway;
  }

  public function file(): string {
    return $this->file;
  }

  public function url(string $path = ''): string {
    return plugins_url(ltrim($path, '/'), $this->file);
  }

  public function path(string $path = ''): string {
    return plugin_dir_path($this->file) . ltrim($path, '/');
  }

  /**
   * Version string for enqueued assets: file mtime in development so edits
   * bypass browser caches, the plugin version otherwise.
   */
  public function assetVersion(string $relativePath): string {
    $file = $this->path($relativePath);
    if ((defined('WP_DEBUG') && WP_DEBUG) && is_file($file)) {
      return (string) filemtime($file);
    }
    return self::VERSION;
  }

}
