<?php

namespace Payarc\WordPress\Admin;

use Payarc\GatewayException;
use Payarc\WordPress\Gateway;
use Payarc\WordPress\Settings;

/**
 * Settings > PayArc: credentials for live and sandbox, mode switch, wallets
 * and a "Check credentials" action that proves the credentials work without
 * charging.
 */
final class SettingsPage {

  public const PAGE = 'payarc-payments';

  private const NOTICE_TRANSIENT = 'payarc_payments_notice';

  private Settings $settings;

  private Gateway $gateway;

  public function __construct(Settings $settings, Gateway $gateway) {
    $this->settings = $settings;
    $this->gateway = $gateway;
  }

  public function register(): void {
    add_action('admin_menu', [$this, 'addMenu']);
    add_action('admin_init', [$this, 'registerSetting']);
    add_action('admin_post_payarc_check_credentials', [$this, 'checkCredentials']);
    add_action('admin_post_payarc_check_marker', [$this, 'checkMarker']);
    add_action('admin_post_payarc_run_renewals', [$this, 'runRenewals']);
    add_action('admin_post_payarc_velocity_resume', [$this, 'resumePayments']);
    add_action('admin_notices', [$this, 'showNotice']);
    add_filter('plugin_action_links_' . plugin_basename(\Payarc\WordPress\Plugin::instance()->file()), [$this, 'actionLinks']);
  }

  public function addMenu(): void {
    add_options_page(
      __('PayArc Payments', 'payarc-payments'),
      __('PayArc', 'payarc-payments'),
      'manage_options',
      self::PAGE,
      [$this, 'render']
    );
  }

  public function registerSetting(): void {
    register_setting('payarc_payments', Settings::OPTION, [
      'type' => 'array',
      'sanitize_callback' => [$this->settings, 'sanitize'],
      'default' => Settings::defaults(),
    ]);
  }

  public function actionLinks(array $links): array {
    $url = admin_url('options-general.php?page=' . self::PAGE);
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'payarc-payments') . '</a>');
    return $links;
  }

  public function render(): void {
    if (!current_user_can('manage_options')) {
      return;
    }
    $v = $this->settings->all();
    $checkUrl = wp_nonce_url(admin_url('admin-post.php?action=payarc_check_credentials'), 'payarc_check_credentials');
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('PayArc Payments', 'payarc-payments'); ?></h1>
      <p><?php esc_html_e('The default PayArc account serves every form and checkout on this site; plugins that support it (Embed Forms) can charge chosen forms to one of the additional accounts below. Card details are entered in fields hosted by PayArc (Hosted Fields); this site only ever handles single-use card tokens and saved-card references.', 'payarc-payments'); ?></p>

      <form method="post" action="options.php">
        <?php settings_fields('payarc_payments'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Mode', 'payarc-payments'); ?></th>
            <td>
              <fieldset>
                <label><input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]" value="sandbox" <?php checked($v['mode'], 'sandbox'); ?>> <?php esc_html_e('Sandbox (test) — testapi.payarc.net, no real money', 'payarc-payments'); ?></label><br>
                <label><input type="radio" name="<?php echo esc_attr(Settings::OPTION); ?>[mode]" value="live" <?php checked($v['mode'], 'live'); ?>> <?php esc_html_e('Live — api.payarc.net', 'payarc-payments'); ?></label>
              </fieldset>
            </td>
          </tr>
        </table>

        <?php foreach (['live' => __('Live credentials', 'payarc-payments'), 'sandbox' => __('Sandbox credentials', 'payarc-payments')] as $mode => $title): ?>
          <h2><?php echo esc_html($title); ?></h2>
          <p class="description">
            <?php
            echo $mode === 'live'
              ? esc_html__('From the PayArc dashboard (dashboard.payarc.net): API, then the eye icon, which shows the Client ID and the API bearer token.', 'payarc-payments')
              : esc_html__('From the PayArc test dashboard (testdashboard.payarc.net), same place. Test card 4012 0000 9876 5439, expiry 12/29, CVV 999, ZIP 85284.', 'payarc-payments');
            ?>
          </p>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><label for="payarc-<?php echo esc_attr($mode); ?>-bearer-token"><?php esc_html_e('API bearer token', 'payarc-payments'); ?></label></th>
              <td>
                <input id="payarc-<?php echo esc_attr($mode); ?>-bearer-token" class="large-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_bearer_token]" value="" placeholder="<?php echo $v[$mode . '_bearer_token'] !== '' ? esc_attr__('(saved — leave blank to keep)', 'payarc-payments') : ''; ?>">
                <?php if ($v[$mode . '_bearer_token'] !== ''): ?>
                  <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_clear_token]" value="1"> <?php esc_html_e('Clear saved token', 'payarc-payments'); ?></label>
                <?php endif; ?>
                <p class="description"><?php esc_html_e('Secret: it can charge and refund. It is used only on this server and never sent to browsers.', 'payarc-payments'); ?></p>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="payarc-<?php echo esc_attr($mode); ?>-client-id"><?php esc_html_e('Client ID', 'payarc-payments'); ?></label></th>
              <td><input id="payarc-<?php echo esc_attr($mode); ?>-client-id" class="regular-text code" type="text" autocomplete="off" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($mode); ?>_client_id]" value="<?php echo esc_attr($v[$mode . '_client_id']); ?>">
                <p class="description"><?php esc_html_e('Used in the browser to show the hosted card fields. Safe to expose; it can only create single-use card tokens.', 'payarc-payments'); ?></p></td>
            </tr>
          </table>
        <?php endforeach; ?>

        <?php $this->renderAccounts(); ?>

        <h2><?php esc_html_e('Options', 'payarc-payments'); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('Wallets', 'payarc-payments'); ?></th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[apple_pay]" value="1" <?php checked(!empty($v['apple_pay'])); ?>> <?php esc_html_e('Offer Apple Pay for one-time payments (Safari with a card in Apple Wallet)', 'payarc-payments'); ?></label><br>
              <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[google_pay]" value="1" <?php checked(!empty($v['google_pay'])); ?>> <?php esc_html_e('Offer Google Pay for one-time payments', 'payarc-payments'); ?></label>
              <p class="description"><?php esc_html_e('The wallet sheet opens in a small PayArc window, so no Apple merchant setup or domain file is needed on this site. The wallets must be enabled on the PayArc account. Not offered for recurring payments or saving a card: wallet tokens cannot be charged again.', 'payarc-payments'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Debug log', 'payarc-payments'); ?></th>
            <td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[debug_log]" value="1" <?php checked(!empty($v['debug_log'])); ?>> <?php esc_html_e('Write request summaries to the PHP error log (tokens and credentials are redacted)', 'payarc-payments'); ?></label></td>
          </tr>
        </table>

        <?php $this->renderVelocity($v); ?>

        <p class="submit">
          <?php submit_button(NULL, 'primary', 'submit', FALSE); ?>
          <a class="button" href="<?php echo esc_url($checkUrl); ?>" style="margin-left:8px"><?php esc_html_e('Check credentials', 'payarc-payments'); ?></a>
          <span class="description" style="margin-left:8px"><?php esc_html_e('Save first. The check lists one charge with the bearer token and opens an unused card-field session with the Client ID; nothing is charged.', 'payarc-payments'); ?></span>
        </p>
      </form>
      <?php $this->renderUnresolved(); ?>
    </div>
    <?php
  }

  /**
   * More merchant accounts, each with its own live and sandbox credentials,
   * plus one blank row to add another. The mode above applies to all.
   */
  private function renderAccounts(): void {
    $name = Settings::OPTION . '[accounts]';
    $rows = array_values($this->settings->extraAccounts());
    $rows[] = NULL;
    ?>
    <h2><?php esc_html_e('Additional accounts', 'payarc-payments'); ?></h2>
    <p class="description"><?php esc_html_e('Other PayArc merchant accounts a form can be charged to instead of the default one (Embed Forms: choose the account under the form\'s Settings > Payments). Charges, renewals and refunds always go to the account a payment was made with, so change a form\'s account freely, but do not remove an account that still has active recurring payments.', 'payarc-payments'); ?></p>
    <?php foreach ($rows as $i => $account) :
      $field = static fn(string $key) => esc_attr($name . '[' . $i . '][' . $key . ']');
      $id = 'payarc-account-' . $i;
      ?>
      <table class="form-table payarc-account" role="presentation" style="border-top:1px solid #c3c4c7">
        <tr>
          <th scope="row"><label for="<?php echo esc_attr($id); ?>-label"><?php echo $account ? esc_html__('Account name', 'payarc-payments') : esc_html__('Add an account', 'payarc-payments'); ?></label></th>
          <td>
            <input id="<?php echo esc_attr($id); ?>-label" class="regular-text" type="text" name="<?php echo $field('label'); ?>" value="<?php echo esc_attr($account['label'] ?? ''); ?>" placeholder="<?php echo $account ? '' : esc_attr__('e.g. Camp account', 'payarc-payments'); ?>">
            <?php if ($account) : ?>
              <input type="hidden" name="<?php echo $field('id'); ?>" value="<?php echo esc_attr($account['id']); ?>">
              <code style="margin-left:8px"><?php echo esc_html($account['id']); ?></code>
              <label style="margin-left:12px"><input type="checkbox" name="<?php echo $field('remove'); ?>" value="1"> <?php esc_html_e('Remove this account', 'payarc-payments'); ?></label>
            <?php else : ?>
              <p class="description"><?php esc_html_e('Fill in a name and the credentials, then save. Leave blank to add nothing.', 'payarc-payments'); ?></p>
            <?php endif; ?>
          </td>
        </tr>
        <?php foreach (['live' => __('Live', 'payarc-payments'), 'sandbox' => __('Sandbox', 'payarc-payments')] as $mode => $modeLabel) :
          $bearer = (string) ($account[$mode . '_bearer_token'] ?? '');
          ?>
          <tr>
            <th scope="row"><?php echo esc_html($modeLabel); ?></th>
            <td>
              <p><label><?php esc_html_e('API bearer token', 'payarc-payments'); ?><br><input class="large-text code" type="password" autocomplete="new-password" name="<?php echo $field($mode . '_bearer_token'); ?>" value="" placeholder="<?php echo $bearer !== '' ? esc_attr__('(saved — leave blank to keep)', 'payarc-payments') : ''; ?>"></label>
                <?php if ($bearer !== '') : ?>
                  <label><input type="checkbox" name="<?php echo $field($mode . '_clear_token'); ?>" value="1"> <?php esc_html_e('Clear saved token', 'payarc-payments'); ?></label>
                <?php endif; ?></p>
              <p><label><?php esc_html_e('Client ID', 'payarc-payments'); ?><br><input class="regular-text code" type="text" autocomplete="off" name="<?php echo $field($mode . '_client_id'); ?>" value="<?php echo esc_attr($account[$mode . '_client_id'] ?? ''); ?>"></label></p>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach;
  }

  /**
   * Card-testing limits, and the pause with a resume button when one is on.
   */
  private function renderVelocity(array $v): void {
    $name = static fn(string $key) => esc_attr(Settings::OPTION . '[' . $key . ']');
    $guard = \Payarc\WordPress\Plugin::instance()->velocity()->guard();
    $until = $guard->pausedUntil();
    ?>
    <h2 id="payarc-velocity"><?php esc_html_e('Card-testing protection', 'payarc-payments'); ?></h2>
    <p class="description"><?php esc_html_e('Bots test stolen cards by running many small payments through a form, most of them declined. These limits count declined and refused payments made by payers (renewals are not counted or blocked) over the last 60 minutes. Administrators who are logged in are never blocked. Set a limit to 0 to turn it off.', 'payarc-payments'); ?></p>
    <?php if ($until !== NULL) :
      $resumeUrl = wp_nonce_url(admin_url('admin-post.php?action=payarc_velocity_resume'), 'payarc_velocity_resume');
      ?>
      <div class="notice notice-error inline"><p>
        <strong><?php echo esc_html(sprintf(__('Card payments are paused until %s', 'payarc-payments'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $until))); ?></strong>
        <?php esc_html_e('because too many payments were declined. Payers are asked to try again later.', 'payarc-payments'); ?>
        <a class="button button-small" style="margin-left:8px" href="<?php echo esc_url($resumeUrl); ?>"><?php esc_html_e('Resume card payments now', 'payarc-payments'); ?></a>
      </p></div>
    <?php else : ?>
      <p><?php echo esc_html(sprintf(__('Status: card payments are open. Declines in the last 60 minutes: %d.', 'payarc-payments'), $guard->siteFailures())); ?></p>
    <?php endif; ?>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="payarc-velocity-ip"><?php esc_html_e('Declines per IP address', 'payarc-payments'); ?></label></th>
        <td><input id="payarc-velocity-ip" class="small-text" type="number" min="0" step="1" name="<?php echo $name('velocity_ip_limit'); ?>" value="<?php echo esc_attr((string) $v['velocity_ip_limit']); ?>">
          <p class="description"><?php esc_html_e('After this many, that address is refused until an hour has passed since its first decline.', 'payarc-payments'); ?></p></td>
      </tr>
      <tr>
        <th scope="row"><label for="payarc-velocity-site"><?php esc_html_e('Declines site-wide', 'payarc-payments'); ?></label></th>
        <td><input id="payarc-velocity-site" class="small-text" type="number" min="0" step="1" name="<?php echo $name('velocity_site_limit'); ?>" value="<?php echo esc_attr((string) $v['velocity_site_limit']); ?>">
          <?php esc_html_e('then pause card payments for', 'payarc-payments'); ?>
          <input class="small-text" type="number" min="1" step="1" name="<?php echo $name('velocity_pause_minutes'); ?>" value="<?php echo esc_attr((string) $v['velocity_pause_minutes']); ?>"> <?php esc_html_e('minutes', 'payarc-payments'); ?>
          <p class="description"><?php esc_html_e('Attacks rotate IP addresses, so this is the limit that stops them. Raise it if a busy event could bring this many genuine declines in an hour.', 'payarc-payments'); ?></p></td>
      </tr>
      <tr>
        <th scope="row"><label for="payarc-velocity-email"><?php esc_html_e('Alert email', 'payarc-payments'); ?></label></th>
        <td><input id="payarc-velocity-email" class="regular-text" type="email" name="<?php echo $name('velocity_alert_email'); ?>" value="<?php echo esc_attr((string) $v['velocity_alert_email']); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
          <p class="description"><?php esc_html_e('Emailed once each time card payments pause. Blank uses the site admin email.', 'payarc-payments'); ?></p></td>
      </tr>
      <tr>
        <th scope="row"><label for="payarc-velocity-min"><?php esc_html_e('Minimum card payment', 'payarc-payments'); ?></label></th>
        <td><input id="payarc-velocity-min" class="small-text" type="number" min="0" step="0.01" name="<?php echo $name('velocity_min_amount'); ?>" value="<?php echo esc_attr((string) $v['velocity_min_amount']); ?>">
          <p class="description"><?php esc_html_e('Payments below this amount are refused (card testers use small amounts). 0 allows any amount. Saving a card for a free trial is not affected.', 'payarc-payments'); ?></p></td>
      </tr>
    </table>
    <?php
  }

  /**
   * Lift a card-testing pause.
   */
  public function resumePayments(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'payarc-payments'));
    }
    check_admin_referer('payarc_velocity_resume');
    \Payarc\WordPress\Plugin::instance()->velocity()->guard()->resume();
    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => TRUE, 'messages' => [__('Card payments resumed.', 'payarc-payments')]], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '#payarc-velocity'));
    exit;
  }

  /**
   * Charges and refunds whose answer was never recorded, with a check action.
   */
  private function renderUnresolved(): void {
    $items = (new Unresolved($this->gateway, $this->settings))->items();
    $runUrl = wp_nonce_url(admin_url('admin-post.php?action=payarc_run_renewals'), 'payarc_run_renewals');
    ?>
    <h2><?php esc_html_e('Unresolved requests', 'payarc-payments'); ?></h2>
    <p><?php esc_html_e('A charge or refund is listed here when it was sent to PayArc and its answer was never recorded: the connection dropped, the page was closed, or the site crashed in between. Nothing here is charged or refunded again until PayArc has confirmed what happened to the earlier request; "Check" asks now.', 'payarc-payments'); ?></p>
    <?php if (!$items) : ?>
      <p><em><?php esc_html_e('None. Every request has a recorded answer.', 'payarc-payments'); ?></em></p>
    <?php else : ?>
      <table class="widefat striped" style="max-width: 1100px">
        <thead>
          <tr>
            <th><?php esc_html_e('Record', 'payarc-payments'); ?></th>
            <th><?php esc_html_e('Request', 'payarc-payments'); ?></th>
            <th><?php esc_html_e('Reference at PayArc', 'payarc-payments'); ?></th>
            <th><?php esc_html_e('Sent', 'payarc-payments'); ?></th>
            <th><?php esc_html_e('Amount', 'payarc-payments'); ?></th>
            <th><?php esc_html_e('Mode', 'payarc-payments'); ?></th>
            <th><?php esc_html_e('Account', 'payarc-payments'); ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item) : ?>
            <?php $checkUrl = wp_nonce_url(admin_url('admin-post.php?action=payarc_check_marker&item=' . rawurlencode($item['key'])), 'payarc_check_marker_' . $item['key']); ?>
            <tr>
              <td><a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['module'] . ': ' . $item['record']); ?></a></td>
              <td><?php echo esc_html($item['kind'] === 'refund' ? __('Refund', 'payarc-payments') : __('Charge', 'payarc-payments')); ?></td>
              <td><code><?php echo esc_html($item['reference']); ?></code></td>
              <td><?php echo $item['sent_at'] > 0 ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $item['sent_at'])) : '&mdash;'; ?></td>
              <td><?php echo $item['amount'] !== NULL ? esc_html($item['amount']) : '&mdash;'; ?></td>
              <td><?php echo esc_html($item['mode']); ?></td>
              <td><?php echo esc_html($this->settings->accountLabel($item['account'] ?? '')); ?></td>
              <td><a class="button button-small" href="<?php echo esc_url($checkUrl); ?>"><?php esc_html_e('Check at PayArc', 'payarc-payments'); ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p>
      <a class="button" href="<?php echo esc_url($runUrl); ?>"><?php esc_html_e('Run renewal workers now', 'payarc-payments'); ?></a>
      <span class="description"><?php esc_html_e('Charges every due subscription of Gravity Forms, GiveWP and any other plugin that charges through this one, and records any renewal listed above whose charge went through. WooCommerce Subscriptions renewals run on their own scheduler.', 'payarc-payments'); ?></span>
    </p>
    <?php
  }

  /**
   * Ask PayArc about one unresolved request and report in a notice.
   */
  public function checkMarker(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'payarc-payments'));
    }
    $key = isset($_GET['item']) ? sanitize_text_field(wp_unslash((string) $_GET['item'])) : '';
    check_admin_referer('payarc_check_marker_' . $key);
    $unresolved = new Unresolved($this->gateway, $this->settings);
    $item = $unresolved->find($key);
    if ($item === NULL) {
      $result = ['ok' => TRUE, 'message' => __('That request has been resolved in the meantime and is no longer listed.', 'payarc-payments')];
    }
    else {
      $result = $unresolved->check($item);
    }
    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => $result['ok'], 'messages' => [$result['message']]], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  /**
   * Run the Gravity Forms and GiveWP renewal workers now.
   */
  public function runRenewals(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'payarc-payments'));
    }
    check_admin_referer('payarc_run_renewals');
    $messages = [];
    $plugin = \Payarc\WordPress\Plugin::instance();
    if ($plugin->moduleEnabled('gravityforms') && class_exists('GFAPI') && class_exists(\Payarc\WordPress\Modules\GravityForms\AddOn::class)) {
      $summary = (new \Payarc\WordPress\Modules\GravityForms\Renewals(\Payarc\WordPress\Modules\GravityForms\AddOn::get_instance(), $this->gateway, $this->settings))->run();
      $messages[] = __('Gravity Forms:', 'payarc-payments') . ' ' . self::summaryText($summary);
    }
    if ($plugin->moduleEnabled('givewp') && function_exists('give')) {
      $summary = (new \Payarc\WordPress\Modules\GiveWP\Renewals())->run();
      $messages[] = __('GiveWP:', 'payarc-payments') . ' ' . self::summaryText($summary);
    }
    foreach (self::renewalWorkers() as $label => $worker) {
      $summary = $worker();
      $messages[] = $label . ': ' . self::summaryText(is_array($summary) ? $summary : []);
    }
    if (!$messages) {
      $messages[] = __('No plugin with a renewal worker is active.', 'payarc-payments');
    }
    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => TRUE, 'messages' => $messages], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  /**
   * Renewal workers of other plugins that charge through this one, keyed by
   * the label shown in the notice. Each returns a summary of counts.
   *
   *   add_filter('payarc_payments_renewal_workers', fn(array $w) => $w + ['My plugin' => fn() => $worker->run()]);
   *
   * @return array<string, callable(): array<string, int>>
   */
  private static function renewalWorkers(): array {
    $workers = apply_filters('payarc_payments_renewal_workers', []);
    return is_array($workers) ? array_filter($workers, 'is_callable') : [];
  }

  /**
   * @param array<string, int> $summary
   */
  private static function summaryText(array $summary): string {
    $parts = [];
    foreach ($summary as $name => $count) {
      if ($count > 0 || $name === 'due') {
        $parts[] = $name . ' ' . (int) $count;
      }
    }
    return $parts ? implode(', ', $parts) : __('nothing due', 'payarc-payments');
  }

  /**
   * Prove the saved credentials of every account for the current mode
   * work, without charging.
   */
  public function checkCredentials(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to do that.', 'payarc-payments'));
    }
    check_admin_referer('payarc_check_credentials');

    $mode = $this->settings->mode();
    $messages = [];
    $ok = TRUE;
    $accounts = $this->settings->accounts();
    foreach ($accounts as $account => $accountLabel) {
      $modeLabel = $mode === Settings::MODE_LIVE ? __('Live', 'payarc-payments') : __('Sandbox', 'payarc-payments');
      $label = count($accounts) > 1 ? $accountLabel . ' — ' . $modeLabel : $modeLabel;
      $result = $this->checkAccount($mode, $account, $label);
      $ok = $ok && $result['ok'];
      $messages = array_merge($messages, $result['messages']);
    }

    set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), ['ok' => $ok, 'messages' => $messages], 120);
    wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE));
    exit;
  }

  /**
   * @return array{ok: bool, messages: string[]}
   */
  private function checkAccount(string $mode, string $account, string $label): array {
    $messages = [];
    $ok = TRUE;
    if (!$this->settings->hasApiCredentials($mode, $account)) {
      return ['ok' => FALSE, 'messages' => [sprintf(__('%s credentials are incomplete: the API bearer token is required.', 'payarc-payments'), $label)]];
    }
    $client = $this->gateway->client('settings check', $mode, $account);
    try {
      $list = $client->verifyCredentials();
      $messages[] = !empty($list['rows'])
        ? sprintf(__('%s API bearer token works (the account has charges).', 'payarc-payments'), $label)
        : sprintf(__('%s API bearer token works (no charges on the account yet).', 'payarc-payments'), $label);
    }
    catch (GatewayException $e) {
      $ok = FALSE;
      $messages[] = sprintf(__('%1$s API bearer token rejected: %2$s', 'payarc-payments'), $label, $e->getMessage());
    }
    try {
      if ($this->settings->clientId($mode, $account) === '') {
        throw new GatewayException(__('none entered; the checkout card fields need it.', 'payarc-payments'));
      }
      $client->verifyClientId($this->settings->clientId($mode, $account), $this->settings->portalUrl($mode));
      $messages[] = sprintf(__('%s Client ID accepted.', 'payarc-payments'), $label);
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      $ok = FALSE;
      $messages[] = sprintf(__('%1$s Client ID rejected: %2$s', 'payarc-payments'), $label, $e->getMessage());
    }
    return ['ok' => $ok, 'messages' => $messages];
  }

  public function showNotice(): void {
    $key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
    $notice = get_transient($key);
    if (!is_array($notice)) {
      return;
    }
    delete_transient($key);
    $class = $notice['ok'] ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . implode('<br>', array_map('esc_html', $notice['messages'])) . '</p></div>';
  }

}
