<?php

namespace Payarc\WordPress\Admin;

use Payarc\GatewayClient;
use Payarc\GatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\WordPress\Gateway;
use Payarc\WordPress\Reconcile;
use Payarc\WordPress\Settings;

/**
 * Every charge or refund whose answer was never recorded: the markers the
 * modules leave behind (see Reconcile) on orders, entries, donations and
 * submissions, gathered in one list for Settings > PayArc, each with a
 * "check now" action that asks PayArc what happened.
 *
 * The list is read-only on purpose. Each module resolves its own marker the
 * next time its flow runs (a resubmitted checkout, the hourly renewal worker,
 * a refund tried again); this page tells the administrator what PayArc
 * holds so they know whether that retry is safe or whether to record a
 * payment by hand.
 */
final class Unresolved {

  private Gateway $gateway;

  private Settings $settings;

  public function __construct(Gateway $gateway, Settings $settings) {
    $this->gateway = $gateway;
    $this->settings = $settings;
  }

  /**
   * @return array<int, array{key: string, module: string, record: string, url: string, kind: string, orderid: string, sent_at: int, amount: ?string, types: string[], exclude: string[], mode: string, account: string, hint: string}>
   */
  public function items(): array {
    $plugin = \Payarc\WordPress\Plugin::instance();
    $items = array_merge(
      $plugin->moduleEnabled('woocommerce') ? $this->woocommerce() : [],
      $plugin->moduleEnabled('gravityforms') ? $this->gravityForms() : [],
      $plugin->moduleEnabled('givewp') ? $this->giveWP() : [],
      $plugin->moduleEnabled('gravityforms') ? $this->submissions() : [],
      $this->extensions()
    );
    usort($items, static fn(array $a, array $b) => $b['sent_at'] <=> $a['sent_at']);
    return $items;
  }

  public function find(string $key): ?array {
    foreach ($this->items() as $item) {
      if ($item['key'] === $key) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Ask PayArc whether the request behind an item went through.
   *
   * @return array{ok: bool, message: string}
   */
  public function check(array $item): array {
    try {
      $client = $this->gateway->client('admin check', $item['mode'], $item['account'] ?? NULL);
      $found = Reconcile::lookup($client, $item['orderid'], $item['sent_at'] ?: NULL, $item['amount'], $item['types'], $item['exclude']);
    }
    catch (ReconciliationInconclusiveException $e) {
      return ['ok' => FALSE, 'message' => sprintf(__('%1$s: PayArc could not say whether %2$s went through (%3$s). Search the PayArc console for orderid %2$s before retrying.', 'payarc-payments'), $item['record'], $item['orderid'], $e->getMessage())];
    }
    catch (GatewayException $e) {
      return ['ok' => FALSE, 'message' => sprintf(__('%1$s: the check failed: %2$s', 'payarc-payments'), $item['record'], $e->getMessage())];
    }
    $what = $item['kind'] === 'refund' ? __('refund', 'payarc-payments') : __('charge', 'payarc-payments');
    if ($found === NULL) {
      return ['ok' => TRUE, 'message' => sprintf(__('%1$s: PayArc has no %2$s with orderid %3$s newer than the request, so it never went through. Trying again is safe; the marker is cleared by that attempt.', 'payarc-payments'), $item['record'], $what, $item['orderid'])];
    }
    $key = Gateway::transactionReference($found);
    $amount = (string) ($found['amount'] ?? '');
    if (!Gateway::approved($found)) {
      return ['ok' => TRUE, 'message' => sprintf(__('%1$s: the %2$s with orderid %3$s was declined at PayArc (transaction %4$s). Nothing was taken; trying again is safe.', 'payarc-payments'), $item['record'], $what, $item['orderid'], $key)];
    }
    return ['ok' => TRUE, 'message' => sprintf(__('%1$s: the %2$s with orderid %3$s WENT THROUGH at PayArc: transaction %4$s for %5$s. %6$s', 'payarc-payments'), $item['record'], $what, $item['orderid'], $key, $amount, $item['hint'])];
  }

  /**
   * @return array[]
   */
  private function woocommerce(): array {
    if (!function_exists('wc_get_orders')) {
      return [];
    }
    $items = [];
    $mode = fn(\WC_Order $order): string => (string) $order->get_meta('_payarc_mode') ?: $this->settings->mode();
    $unpaid = ['wc-pending', 'wc-failed', 'wc-on-hold', 'wc-cancelled'];
    foreach ($this->orders('_payarc_charge_sent', $unpaid) as $order) {
      $marker = $order->get_meta('_payarc_charge_sent');
      if (is_array($marker) && !empty($marker['orderid'])) {
        $items[] = $this->item('WooCommerce', sprintf(__('Order #%s', 'payarc-payments'), $order->get_order_number()), $order->get_edit_order_url(), 'charge', $marker, $mode($order),
          __('The order completes without a second charge when the customer submits the checkout again. To finish it by hand, mark it paid and put the transaction key in the order notes.', 'payarc-payments'));
      }
    }
    foreach ($this->orders('_payarc_refund_sent', []) as $order) {
      $marker = $order->get_meta('_payarc_refund_sent');
      if (is_array($marker) && !empty($marker['orderid'])) {
        $items[] = $this->item('WooCommerce', sprintf(__('Order #%s', 'payarc-payments'), $order->get_order_number()), $order->get_edit_order_url(), 'refund', $marker, $mode($order),
          __('Refund the same amount again from the order screen: it is recorded without refunding twice.', 'payarc-payments'));
      }
    }
    foreach ($this->orders('_payarc_renewal_pending', $unpaid) as $order) {
      $pending = $order->get_meta('_payarc_renewal_pending');
      foreach (is_array($pending) ? $pending : [] as $attempt) {
        if (is_array($attempt) && !empty($attempt['orderid'])) {
          $attempt['amount'] = $attempt['amount'] ?? number_format((float) $order->get_total(), 2, '.', '');
          $items[] = $this->item('WooCommerce', sprintf(__('Renewal order #%s', 'payarc-payments'), $order->get_order_number()), $order->get_edit_order_url(), 'charge', $attempt, $mode($order),
            __('WooCommerce Subscriptions retries the renewal on its own schedule; the retry records this transaction instead of charging again. Or process the renewal from the subscription screen now.', 'payarc-payments'));
        }
      }
    }
    return $items;
  }

  /**
   * @return \WC_Order[]
   */
  private function orders(string $metaKey, array $statuses): array {
    $args = ['limit' => 200, 'type' => 'shop_order', 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'objects', 'meta_query' => [['key' => $metaKey, 'compare' => 'EXISTS']]];
    if ($statuses) {
      $args['status'] = $statuses;
    }
    $orders = wc_get_orders($args);
    return array_values(array_filter(is_array($orders) ? $orders : [], static fn($order) => $order instanceof \WC_Order && $order->get_payment_method() === 'payarc'));
  }

  /**
   * @return array[]
   */
  private function gravityForms(): array {
    if (!class_exists('GFAPI') || !class_exists('GFFormsModel')) {
      return [];
    }
    global $wpdb;
    $table = \GFFormsModel::get_entry_meta_table_name();
    $rows = $wpdb->get_results("SELECT entry_id, meta_key, meta_value FROM {$table} WHERE meta_key IN ('payarc_reconcile_order_id', 'payarc_refund_sent') AND meta_value <> '' AND meta_value <> '0'", ARRAY_A);
    $items = [];
    foreach ((array) $rows as $row) {
      $entryId = (int) $row['entry_id'];
      $entry = \GFAPI::get_entry($entryId);
      if (is_wp_error($entry)) {
        continue;
      }
      $url = admin_url('admin.php?page=gf_entries&view=entry&id=' . (int) $entry['form_id'] . '&lid=' . $entryId);
      $mode = (string) gform_get_meta($entryId, 'payarc_mode') ?: $this->settings->mode();
      $record = sprintf(__('Gravity Forms entry #%d', 'payarc-payments'), $entryId);
      if ($row['meta_key'] === 'payarc_reconcile_order_id') {
        $marker = ['orderid' => (string) $row['meta_value'], 'sent_at' => (int) gform_get_meta($entryId, 'payarc_reconcile_sent_at'), 'amount' => number_format((float) rgar($entry, 'payment_amount'), 2, '.', '')];
        $items[] = $this->item('Gravity Forms', $record, $url, 'charge', $marker, $mode,
          __('The hourly renewal worker records this transaction on its next run without charging again; "Run renewal workers now" below does it immediately.', 'payarc-payments'));
      }
      else {
        $marker = maybe_unserialize((string) $row['meta_value']);
        if (is_array($marker) && !empty($marker['orderid'])) {
          $items[] = $this->item('Gravity Forms', $record, $url, 'refund', $marker, $mode,
            __('Refund the same amount again from the entry screen: it is recorded without refunding twice.', 'payarc-payments'));
        }
      }
    }
    return $items;
  }

  /**
   * @return array[]
   */
  private function giveWP(): array {
    if (!function_exists('give') || !function_exists('give_get_meta')) {
      return [];
    }
    global $wpdb;
    $items = [];
    $table = $wpdb->prefix . 'give_donationmeta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
      $rows = $wpdb->get_results("SELECT donation_id, meta_key, meta_value FROM {$table} WHERE meta_key IN ('_payarc_charge_sent', '_payarc_refund_sent') AND meta_value <> ''", ARRAY_A);
      foreach ((array) $rows as $row) {
        $donationId = (int) $row['donation_id'];
        $status = (string) get_post_status($donationId);
        $marker = maybe_unserialize((string) $row['meta_value']);
        if (!is_array($marker) || empty($marker['orderid']) || $status === '') {
          continue;
        }
        $isRefund = $row['meta_key'] === '_payarc_refund_sent';
        if ($isRefund ? $status === 'refunded' : in_array($status, ['publish', 'give_subscription', 'refunded'], TRUE)) {
          continue;
        }
        $mode = (string) give_get_meta($donationId, '_give_payment_mode', TRUE) === 'live' ? Settings::MODE_LIVE : Settings::MODE_SANDBOX;
        $url = admin_url('edit.php?post_type=give_forms&page=give-payment-history&view=view-payment-details&id=' . $donationId);
        $items[] = $this->item('GiveWP', sprintf(__('Donation #%d', 'payarc-payments'), $donationId), $url, $isRefund ? 'refund' : 'charge', $marker, $mode, $isRefund
          ? __('Refund the donation again from its screen: it is recorded without refunding twice.', 'payarc-payments')
          : __('The donation completes without a second charge when the donor submits the form again. To finish it by hand, mark it complete and note the transaction key.', 'payarc-payments'));
      }
    }
    $state = get_option('payarc_give_renewal_state', []);
    foreach (is_array($state) ? $state : [] as $subscriptionId => $entry) {
      if (is_array($entry) && !empty($entry['reconcile'])) {
        $marker = ['orderid' => (string) $entry['reconcile'], 'sent_at' => (int) ($entry['reconcile_sent_at'] ?? 0), 'amount' => NULL];
        try {
          $subscription = \Give\Subscriptions\Models\Subscription::find((int) $subscriptionId);
          $marker['amount'] = $subscription ? $subscription->amount->formatToDecimal() : NULL;
        }
        catch (\Throwable $e) {
          // Listed without an amount filter.
        }
        $url = admin_url('edit.php?post_type=give_forms&page=give-subscriptions&id=' . (int) $subscriptionId);
        $items[] = $this->item('GiveWP', sprintf(__('Subscription #%d', 'payarc-payments'), (int) $subscriptionId), $url, 'charge', $marker, $this->settings->mode(),
          __('The hourly renewal worker records this transaction on its next run without charging again; "Run renewal workers now" below does it immediately.', 'payarc-payments'));
      }
    }
    return $items;
  }

  /**
   * Gravity Forms submissions that were charged but never became an entry
   * (option-row markers whose orderid no entry carries).
   *
   * @return array[]
   */
  private function submissions(): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('payarc_marker_') . '%'), ARRAY_A);
    $items = [];
    foreach ((array) $rows as $row) {
      $marker = maybe_unserialize((string) $row['option_value']);
      if (!is_array($marker) || empty($marker['orderid'])) {
        continue;
      }
      if (class_exists('GFFormsModel')) {
        $table = \GFFormsModel::get_entry_meta_table_name();
        $entryId = (int) $wpdb->get_var($wpdb->prepare("SELECT entry_id FROM {$table} WHERE meta_key = 'payarc_order_id' AND meta_value = %s LIMIT 1", (string) $marker['orderid']));
        if ($entryId > 0) {
          // The submission became an entry: resolved. The row is purged later.
          continue;
        }
      }
      $items[] = $this->item('Gravity Forms', __('Form submission without an entry', 'payarc-payments'), admin_url('admin.php?page=gf_entries'), 'charge', $marker, $this->settings->mode(),
        __('No entry was saved for this submission. If the transaction went through, find the payer in the PayArc console (orderid above) and either refund it or create the entry by hand.', 'payarc-payments'));
    }
    return $items;
  }

  /**
   * Items from other plugins that charge through this one. Each hooked
   * callback appends items built with Unresolved::makeItem():
   *
   *   add_filter('payarc_payments_unresolved', function (array $items) {
   *     $items[] = Unresolved::makeItem('My plugin', 'Entry #5', $url, 'charge', $marker, $mode, $hint);
   *     return $items;
   *   });
   *
   * @return array[]
   */
  private function extensions(): array {
    $items = apply_filters('payarc_payments_unresolved', [], $this->settings);
    if (!is_array($items)) {
      return [];
    }
    return array_values(array_filter($items, static fn($item) => is_array($item) && !empty($item['key']) && !empty($item['orderid']) && isset($item['types'], $item['mode'])));
  }

  private function item(string $module, string $record, string $url, string $kind, array $marker, string $mode, string $hint): array {
    return self::makeItem($module, $record, $url, $kind, $marker, $mode, $hint);
  }

  /**
   * One list row for a marker left by Reconcile::once().
   *
   * @param string $kind
   *   'charge' or 'refund'.
   * @param array $marker
   *   The stored marker: orderid, sent_at, amount, exclude.
   * @param string $hint
   *   What the administrator should do when the request went through.
   * @param string $account
   *   The account the request went to (Settings::accounts()); empty for the
   *   default account.
   */
  public static function makeItem(string $module, string $record, string $url, string $kind, array $marker, string $mode, string $hint, string $account = ''): array {
    $orderId = (string) $marker['orderid'];
    $sentAt = (int) ($marker['sent_at'] ?? 0);
    return [
      'key' => md5($module . '|' . $record . '|' . $kind . '|' . $orderId . '|' . $sentAt . '|' . $account),
      'module' => $module,
      'record' => $record,
      'url' => $url,
      'kind' => $kind,
      'orderid' => $orderId,
      'sent_at' => $sentAt,
      'amount' => isset($marker['amount']) && $marker['amount'] !== '' && $marker['amount'] !== NULL ? (string) $marker['amount'] : NULL,
      'types' => $kind === 'refund' ? GatewayClient::TYPES_REFUND : GatewayClient::TYPES_CHARGE,
      'exclude' => isset($marker['exclude']) && is_array($marker['exclude']) ? array_map('strval', $marker['exclude']) : [],
      'mode' => $mode,
      'account' => $account,
      'hint' => $hint,
    ];
  }

}
