<?php

namespace Payarc\WordPress;

use Payarc\VelocityGuard;
use Payarc\VelocityStore;

/**
 * Card-testing protection for every module (see Payarc\VelocityGuard).
 *
 * Payer-initiated charges and card saves ask refusal() first and report each
 * failure with failed(). Renewals skip both. Users who can manage the site
 * are never refused or counted, so staff can test a form during a pause.
 *
 * When failures across the site reach the limit, card payments pause and the
 * alert address is emailed once. Settings > PayArc shows the pause and has a
 * "Resume card payments" button.
 */
final class Velocity implements VelocityStore {

  private const PREFIX = 'payarc_vg_';

  private Settings $settings;

  private Gateway $gateway;

  private ?VelocityGuard $guard = NULL;

  public function __construct(Settings $settings, Gateway $gateway) {
    $this->settings = $settings;
    $this->gateway = $gateway;
  }

  public function guard(): VelocityGuard {
    return $this->guard ??= new VelocityGuard($this, $this->settings->velocity());
  }

  /**
   * Payer wording when this charge must not be sent, or NULL to go ahead.
   *
   * @param string|null $amount
   *   Decimal amount of a charge; NULL for a card save or verification.
   */
  public function refusal(?string $amount, string $module): ?string {
    if ($this->exempt()) {
      return NULL;
    }
    $reason = $this->guard()->check($this->ip(), $amount);
    if ($reason === NULL) {
      return NULL;
    }
    Log::debug('Velocity guard refused a payment', ['module' => $module, 'reason' => $reason]);
    return VelocityGuard::donorText($reason);
  }

  /**
   * Count a declined or refused payer charge or card save.
   */
  public function failed(string $module): void {
    if ($this->exempt()) {
      return;
    }
    if ($this->guard()->recordFailure($this->ip()) === VelocityGuard::TRIPPED) {
      $this->alert($module);
    }
  }

  private function exempt(): bool {
    $exempt = function_exists('current_user_can') && current_user_can('manage_options');
    return (bool) apply_filters('payarc_payments_velocity_exempt', $exempt);
  }

  private function ip(): string {
    return $this->gateway->clientIp();
  }

  private function alert(string $module): void {
    $config = $this->guard()->config();
    $until = (int) $this->guard()->pausedUntil();
    $when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $until);
    Log::error('Velocity guard paused card payments', ['module' => $module, 'until' => $when, 'site_limit' => $config['site_limit']]);

    $to = $this->settings->velocityAlertEmail();
    if ($to === '') {
      return;
    }
    $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    $subject = sprintf(__('[%s] Card payments paused after repeated declines', 'payarc-payments'), $site);
    $body = implode("\n\n", [
      sprintf(__('%1$d card payments were declined or refused within %2$d minutes on %3$s, the last one through %4$s. This is what card testing looks like: a bot trying stolen cards.', 'payarc-payments'), $config['site_limit'], $config['window_minutes'], home_url(), $module),
      sprintf(__('Online card payments are paused until %s. Donors and customers see a message asking them to try again later; staff who are logged in as administrators can still pay.', 'payarc-payments'), $when),
      sprintf(__('To resume sooner, or to change the limits, open Settings > PayArc: %s', 'payarc-payments'), admin_url('options-general.php?page=' . Admin\SettingsPage::PAGE)),
      __('If the declines are real payers (for example a busy event), raise the site-wide limit there.', 'payarc-payments'),
    ]);
    wp_mail($to, $subject, $body);
  }

  // VelocityStore, on transients (a persistent object cache when the site
  // has one, else wp_options rows that expire).

  public function get(string $key): ?array {
    $value = get_transient(self::PREFIX . $key);
    return is_array($value) ? $value : NULL;
  }

  public function set(string $key, array $value, int $ttl): void {
    set_transient(self::PREFIX . $key, $value, $ttl);
  }

  public function delete(string $key): void {
    delete_transient(self::PREFIX . $key);
  }

}
