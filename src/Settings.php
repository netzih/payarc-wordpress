<?php

namespace Payarc\WordPress;

/**
 * Account settings shared by every module. One option row; live and sandbox
 * credentials are both kept so switching modes does not lose either.
 *
 * The top-level credentials are the default account. More accounts can be
 * added under "accounts" (each with its own live and sandbox keys) for
 * plugins that charge different forms to different merchant accounts; they
 * pass the account id to the credential getters and Gateway::client(). The
 * mode is shared by all accounts.
 */
final class Settings {

  public const OPTION = 'payarc_payments';

  public const MODE_LIVE = 'live';

  public const MODE_SANDBOX = 'sandbox';

  public const DEFAULT_ACCOUNT = 'default';

  private const CREDENTIALS = ['live_bearer_token', 'live_client_id', 'sandbox_bearer_token', 'sandbox_client_id'];

  private ?array $values = NULL;

  public static function defaults(): array {
    return [
      'mode' => self::MODE_SANDBOX,
      'live_bearer_token' => '',
      'live_client_id' => '',
      'sandbox_bearer_token' => '',
      'sandbox_client_id' => '',
      'apple_pay' => FALSE,
      'google_pay' => FALSE,
      'debug_log' => FALSE,
      'accounts' => [],
      'velocity_ip_limit' => \Payarc\VelocityGuard::DEFAULTS['ip_limit'],
      'velocity_site_limit' => \Payarc\VelocityGuard::DEFAULTS['site_limit'],
      'velocity_pause_minutes' => \Payarc\VelocityGuard::DEFAULTS['pause_minutes'],
      'velocity_min_amount' => \Payarc\VelocityGuard::DEFAULTS['min_amount'],
      'velocity_alert_email' => '',
    ];
  }

  public function all(): array {
    if ($this->values === NULL) {
      $stored = get_option(self::OPTION, []);
      $this->values = array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }
    return $this->values;
  }

  public function get(string $key): mixed {
    return $this->all()[$key] ?? NULL;
  }

  public function forget(): void {
    $this->values = NULL;
  }

  public function mode(): string {
    return $this->get('mode') === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SANDBOX;
  }

  public function isSandbox(): bool {
    return $this->mode() === self::MODE_SANDBOX;
  }

  /**
   * Every account by id, the default first: id => label.
   *
   * @return array<string, string>
   */
  public function accounts(): array {
    $out = [self::DEFAULT_ACCOUNT => __('Default account', 'payarc-payments')];
    foreach ($this->extraAccounts() as $id => $account) {
      $out[$id] = $account['label'];
    }
    return $out;
  }

  /**
   * The accounts added besides the default, by id.
   *
   * @return array<string, array>
   */
  public function extraAccounts(): array {
    $out = [];
    foreach ((array) $this->get('accounts') as $account) {
      if (is_array($account) && !empty($account['id']) && $account['id'] !== self::DEFAULT_ACCOUNT) {
        $out[(string) $account['id']] = $account + array_fill_keys(self::CREDENTIALS, '') + ['label' => (string) $account['id']];
      }
    }
    return $out;
  }

  public function hasAccount(?string $account): bool {
    return self::isDefault($account) || isset($this->extraAccounts()[$account]);
  }

  public static function isDefault(?string $account): bool {
    return $account === NULL || $account === '' || $account === self::DEFAULT_ACCOUNT;
  }

  public function accountLabel(?string $account): string {
    return $this->accounts()[self::isDefault($account) ? self::DEFAULT_ACCOUNT : $account] ?? (string) $account;
  }

  /**
   * One credential of an account. An account id that no longer exists has
   * no credentials: a charge meant for it is never sent to another account.
   */
  private function credential(string $field, ?string $mode, ?string $account): string {
    $key = ($mode ?? $this->mode()) . '_' . $field;
    if (self::isDefault($account)) {
      return (string) $this->get($key);
    }
    return (string) ($this->extraAccounts()[$account][$key] ?? '');
  }

  /**
   * The secret API bearer token (PayArc dashboard > API). Server-side only.
   */
  public function bearerToken(?string $mode = NULL, ?string $account = NULL): string {
    return trim($this->credential('bearer_token', $mode, $account));
  }

  /**
   * The public Client ID that Hosted Fields use in the browser.
   */
  public function clientId(?string $mode = NULL, ?string $account = NULL): string {
    return trim($this->credential('client_id', $mode, $account));
  }

  /**
   * The bearer token is present: server-side calls (charges, refunds,
   * renewals) can be made.
   */
  public function hasApiCredentials(?string $mode = NULL, ?string $account = NULL): bool {
    return $this->bearerToken($mode, $account) !== '';
  }

  /**
   * Everything a checkout needs: the bearer token plus the Client ID the
   * browser uses to tokenize cards.
   */
  public function isConfigured(?string $mode = NULL, ?string $account = NULL): bool {
    return $this->hasApiCredentials($mode, $account) && $this->clientId($mode, $account) !== '';
  }

  public function apiUrl(?string $mode = NULL): string {
    return ($mode ?? $this->mode()) === self::MODE_SANDBOX
      ? \Payarc\GatewayClient::SANDBOX_URL
      : \Payarc\GatewayClient::LIVE_URL;
  }

  /**
   * The Hosted Fields portal: test or live.
   */
  public function portalUrl(?string $mode = NULL): string {
    return ($mode ?? $this->mode()) === self::MODE_SANDBOX
      ? \Payarc\GatewayClient::SANDBOX_PORTAL
      : \Payarc\GatewayClient::LIVE_PORTAL;
  }

  /**
   * PayArc's Hosted Fields script. It works out its own host (test or live
   * portal) from this URL.
   */
  public function hostedFieldsUrl(?string $mode = NULL): string {
    return $this->portalUrl($mode) . '/js/iframeprocess.js';
  }

  public function applePayEnabled(): bool {
    return !empty($this->get('apple_pay'));
  }

  public function googlePayEnabled(): bool {
    return !empty($this->get('google_pay'));
  }

  public function walletsEnabled(): bool {
    return $this->applePayEnabled() || $this->googlePayEnabled();
  }

  /**
   * The enabled wallets as PayArc names them: 'apple-pay', 'google-pay'.
   *
   * @return string[]
   */
  public function wallets(): array {
    return array_values(array_filter([
      $this->applePayEnabled() ? 'apple-pay' : '',
      $this->googlePayEnabled() ? 'google-pay' : '',
    ]));
  }

  public function debugLog(): bool {
    return !empty($this->get('debug_log'));
  }

  /**
   * Card-testing limits for Payarc\VelocityGuard.
   */
  public function velocity(): array {
    return [
      'ip_limit' => (int) $this->get('velocity_ip_limit'),
      'site_limit' => (int) $this->get('velocity_site_limit'),
      'pause_minutes' => (int) $this->get('velocity_pause_minutes'),
      'min_amount' => (string) $this->get('velocity_min_amount'),
    ];
  }

  /**
   * Who is emailed when card payments pause: the setting, else the site's
   * admin email.
   */
  public function velocityAlertEmail(): string {
    $email = trim((string) $this->get('velocity_alert_email'));
    return $email !== '' ? $email : (string) get_option('admin_email');
  }

  /**
   * Sanitize callback for register_setting().
   */
  public function sanitize(mixed $input): array {
    $input = is_array($input) ? $input : [];
    $current = $this->all();
    $clean = $current;

    $clean['mode'] = ($input['mode'] ?? '') === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_SANDBOX;
    foreach (['live', 'sandbox'] as $mode) {
      $clean[$mode . '_client_id'] = sanitize_text_field((string) ($input[$mode . '_client_id'] ?? ''));
      // A blank token field keeps the stored token, so re-saving other
      // settings never wipes it; the form shows a placeholder when one is
      // stored.
      $token = trim((string) ($input[$mode . '_bearer_token'] ?? ''));
      if ($token !== '') {
        $clean[$mode . '_bearer_token'] = preg_replace('/\\s+/', '', $token);
      }
      if (!empty($input[$mode . '_clear_token'])) {
        $clean[$mode . '_bearer_token'] = '';
      }
    }
    $clean['apple_pay'] = !empty($input['apple_pay']);
    $clean['google_pay'] = !empty($input['google_pay']);
    $clean['debug_log'] = !empty($input['debug_log']);
    $clean['velocity_ip_limit'] = max(0, (int) ($input['velocity_ip_limit'] ?? $current['velocity_ip_limit']));
    $clean['velocity_site_limit'] = max(0, (int) ($input['velocity_site_limit'] ?? $current['velocity_site_limit']));
    $clean['velocity_pause_minutes'] = max(1, (int) ($input['velocity_pause_minutes'] ?? $current['velocity_pause_minutes']));
    $clean['velocity_min_amount'] = number_format(max(0, (float) ($input['velocity_min_amount'] ?? $current['velocity_min_amount'])), 2, '.', '');
    $email = sanitize_email((string) ($input['velocity_alert_email'] ?? ''));
    $clean['velocity_alert_email'] = is_email($email) ? $email : '';
    $clean['accounts'] = self::sanitizeAccounts($input['accounts'] ?? [], $this->extraAccounts());

    $this->values = $clean;
    return $clean;
  }

  /**
   * Rows of the "Additional accounts" table. A row keeps its id once saved
   * (records that were charged through it refer to it); a new row gets one
   * from its label. A blank bearer token keeps the stored one, as for the
   * default account. Rows marked for removal and rows with no label and no
   * credentials are dropped.
   *
   * @param array<string, array> $current
   *   The stored accounts by id.
   */
  public static function sanitizeAccounts(mixed $rows, array $current): array {
    $out = [];
    $taken = [self::DEFAULT_ACCOUNT => TRUE];
    foreach (is_array($rows) ? $rows : [] as $row) {
      if (!is_array($row) || !empty($row['remove'])) {
        continue;
      }
      $label = sanitize_text_field((string) ($row['label'] ?? ''));
      $id = sanitize_key((string) ($row['id'] ?? ''));
      $existing = $id !== '' && isset($current[$id]) ? $current[$id] : NULL;
      $account = ['id' => '', 'label' => $label];
      foreach (['live', 'sandbox'] as $mode) {
        $account[$mode . '_client_id'] = sanitize_text_field((string) ($row[$mode . '_client_id'] ?? ''));
        $token = preg_replace('/\\s+/', '', (string) ($row[$mode . '_bearer_token'] ?? ''));
        $account[$mode . '_bearer_token'] = $token !== '' ? $token : (string) ($existing[$mode . '_bearer_token'] ?? '');
        if (!empty($row[$mode . '_clear_token'])) {
          $account[$mode . '_bearer_token'] = '';
        }
      }
      if ($label === '' && $account['live_bearer_token'] === '' && $account['sandbox_bearer_token'] === '' && $account['live_client_id'] === '' && $account['sandbox_client_id'] === '') {
        continue;
      }
      if ($existing === NULL) {
        $base = sanitize_key(str_replace(' ', '-', strtolower($label))) ?: 'account';
        // Numeric ids would turn into integer array keys.
        $base = ctype_digit($base) ? 'account-' . $base : $base;
        $id = $base;
        for ($n = 2; isset($taken[$id]) || isset($current[$id]); $n++) {
          $id = $base . '-' . $n;
        }
      }
      if (isset($taken[$id])) {
        continue;
      }
      $taken[$id] = TRUE;
      $account['id'] = $id;
      $account['label'] = $label !== '' ? $label : $id;
      $out[] = $account;
    }
    return $out;
  }

}
