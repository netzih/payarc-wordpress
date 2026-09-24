<?php

namespace Payarc\WordPress\Modules\GravityForms;

use Payarc\AmbiguousGatewayException;
use Payarc\ReconciliationInconclusiveException;
use Payarc\WordPress\BusyException;
use Payarc\WordPress\Reconcile;
use Payarc\GatewayException;
use Payarc\WordPress\Gateway;
use Payarc\WordPress\Log;
use Payarc\WordPress\Plugin;

/**
 * Gravity Forms payment add-on. One-time feeds charge the Pay.js key in
 * authorize(); subscription feeds charge the first installment with
 * save_card and the renewal worker (check_status, hourly) charges the saved
 * card reference on schedule. Nothing is scheduled at PayArc.
 */
final class AddOn extends \GFPaymentAddOn {

  public const INTEGRATION = 'Gravity Forms';

  protected $_version = Plugin::VERSION;

  protected $_min_gravityforms_version = '2.9';

  protected $_slug = 'gravityformspayarc';

  protected $_path = 'payarc-payments/payarc-payments.php';

  protected $_full_path = __FILE__;

  protected $_url = 'https://lab.civicrm.org/Chabadrichmond';

  protected $_title = 'PayArc Payments for Gravity Forms';

  protected $_short_title = 'PayArc';

  protected $_requires_credit_card = FALSE;

  protected $_supports_callbacks = FALSE;

  protected $_capabilities = ['gravityforms_payarc', 'gravityforms_payarc_uninstall'];

  protected $_capabilities_settings_page = 'gravityforms_payarc';

  protected $_capabilities_form_settings = 'gravityforms_payarc';

  protected $_capabilities_uninstall = 'gravityforms_payarc_uninstall';

  private static ?AddOn $_instance = NULL;

  /**
   * "Visa ending in 2224" for the card field to store once the charge went through.
   */
  private string $cardSummary = '';

  /**
   * Entry meta to write once the entry exists (entry_post_save).
   */
  private array $pendingMeta = [];

  private array $pendingNotes = [];

  public static function get_instance(): AddOn {
    if (self::$_instance === NULL) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }

  public function __construct() {
    $this->_full_path = Plugin::instance()->file();
    $this->_path = plugin_basename($this->_full_path);
    parent::__construct();
  }

  public function init() {
    parent::init();
    add_filter('gform_payment_statuses', [$this, 'paymentStatuses']);
    add_action('wp_ajax_payarc_gf_refund', [$this, 'ajaxRefund']);
  }

  public function paymentStatuses(array $statuses): array {
    $statuses['Expired'] = __('Expired', 'payarc-payments');
    return $statuses;
  }

  private function gateway(): Gateway {
    return Plugin::instance()->gateway();
  }

  public function cardSummaryForEntry(): string {
    return $this->cardSummary;
  }

  // ---------------------------------------------------------------------
  // Field wiring
  // ---------------------------------------------------------------------

  public function get_credit_card_field($form) {
    $fields = \GFAPI::get_fields_by_type($form, [CardField::TYPE]);
    return empty($fields) ? FALSE : $fields[0];
  }

  public function get_validation_result($validation_result, $authorization_result) {
    $credit_card_page = 0;
    foreach ($validation_result['form']['fields'] as &$field) {
      if ($field->type === CardField::TYPE) {
        $field->failed_validation = TRUE;
        $field->validation_message = $authorization_result['error_message'];
        $credit_card_page = $field->pageNumber;
        break;
      }
    }
    $validation_result['credit_card_page'] = $credit_card_page;
    $validation_result['is_valid'] = FALSE;
    return $validation_result;
  }

  public function can_create_feed() {
    return $this->has_credit_card_field($this->get_current_form());
  }

  public function feed_list_title() {
    if (!$this->has_credit_card_field($this->get_current_form())) {
      return $this->form_settings_title();
    }
    return parent::feed_list_title();
  }

  public function feed_list_message() {
    if (!$this->has_credit_card_field($this->get_current_form())) {
      return $this->requires_credit_card_message();
    }
    return parent::feed_list_message();
  }

  public function requires_credit_card_message() {
    $url = add_query_arg(['view' => NULL, 'subview' => NULL]);
    return sprintf(
      esc_html__('Add a PayArc Card field (Pricing Fields) to this form before creating a feed. %1$sOpen the form editor%2$s.', 'payarc-payments'),
      "<a href='" . esc_url($url) . "'>",
      '</a>'
    );
  }

  public function before_delete_field($form_id, $field_id) {
    $field = \GFAPI::get_field($form_id, $field_id);
    if ($field && $field->type === CardField::TYPE) {
      foreach ($this->get_feeds($form_id) as $feed) {
        $this->update_feed_active($feed['id'], 0);
      }
    }
  }

  // ---------------------------------------------------------------------
  // Feed settings
  // ---------------------------------------------------------------------

  public function feed_settings_fields() {
    $fields = parent::feed_settings_fields();
    $fields = $this->add_field_after('feedName', [
      [
        'name' => 'payarcNotice',
        'type' => 'html',
        'html' => '<p class="description">' . esc_html__('Charges use the PayArc account under Settings > PayArc. Subscriptions are scheduled by this site and charged hourly by WordPress cron; nothing is created in the PayArc console.', 'payarc-payments') . '</p>',
      ],
    ], $fields);
    return $fields;
  }

  public function option_choices() {
    return [];
  }

  public function billing_info_fields() {
    return [
      ['name' => 'first_name', 'label' => __('First Name', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'last_name', 'label' => __('Last Name', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'email', 'label' => __('Email', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'address', 'label' => __('Address', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'address2', 'label' => __('Address 2', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'city', 'label' => __('City', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'state', 'label' => __('State', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'zip', 'label' => __('Zip', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'country', 'label' => __('Country', 'payarc-payments'), 'required' => FALSE],
      ['name' => 'phone', 'label' => __('Phone', 'payarc-payments'), 'required' => FALSE],
    ];
  }

  public function supported_notification_events($form) {
    if (!$this->has_feed($form['id'])) {
      return FALSE;
    }
    return [
      'complete_payment' => __('Payment Completed', 'payarc-payments'),
      'fail_payment' => __('Payment Failed', 'payarc-payments'),
      'refund_payment' => __('Payment Refunded', 'payarc-payments'),
      'create_subscription' => __('Subscription Created', 'payarc-payments'),
      'add_subscription_payment' => __('Subscription Payment Added', 'payarc-payments'),
      'fail_subscription_payment' => __('Subscription Payment Failed', 'payarc-payments'),
      'cancel_subscription' => __('Subscription Cancelled', 'payarc-payments'),
      'expire_subscription' => __('Subscription Expired', 'payarc-payments'),
    ];
  }

  // ---------------------------------------------------------------------
  // Charging
  // ---------------------------------------------------------------------

  /**
   * One-time payment: charge the payment key immediately and hand GF the
   * captured payment, so capture() is never needed.
   */
  public function authorize($feed, $submission_data, $form, $entry) {
    $key = $this->postedPaymentKey($form);
    if ($key === '') {
      return $this->authorization_error(__('Please enter your card details.', 'payarc-payments'));
    }
    $amount = (float) rgar($submission_data, 'payment_amount');
    if ($amount <= 0) {
      return $this->authorization_error(__('The payment amount could not be processed. Please contact us.', 'payarc-payments'));
    }

    $uniqueId = $this->submissionId($form);
    $orderId = Gateway::orderId('gf-' . (int) $form['id'] . '-' . $uniqueId);
    $payer = $this->payer($submission_data);
    $metadata = $this->gateway()->metadata(
      'GF' . (int) $form['id'] . '-' . substr($uniqueId, 0, 12),
      $this->description($form, $feed),
      $payer,
      ['currency' => \GFCommon::get_currency(), 'orderid' => $orderId]
    );

    $outcome = $this->charge(static fn($client) => $client->saleWithPaymentKey($key, self::money($amount), $metadata), $orderId, self::money($amount));
    if (!empty($outcome['error'])) {
      return $this->authorization_error($outcome['error']);
    }
    $response = $outcome['response'];
    $reference = Gateway::transactionReference($response);
    $card = Gateway::card($response);
    $this->cardSummary = self::summary($card);
    $this->pendingMeta = [
      'payarc_mode' => Plugin::instance()->settings()->mode(),
      'payarc_order_id' => $orderId,
      'payarc_transaction_key' => $reference,
      'payarc_refnum' => (string) rgar($response, 'refnum'),
      'payarc_card_brand' => (string) $card['brand'],
      'payarc_card_last4' => (string) $card['last4'],
      'payarc_payer' => $payer,
    ];
    $this->pendingNotes[] = $this->gatewayNote($response);

    return [
      'is_authorized' => TRUE,
      'transaction_id' => $reference,
      'amount' => $amount,
      'captured_payment' => [
        'is_success' => TRUE,
        'transaction_id' => $reference,
        'amount' => $amount,
        'payment_method' => $card['brand'] ?: 'Card',
      ],
    ];
  }

  /**
   * Subscription: charge the first installment (or verify the card for a
   * free trial) with save_card, then schedule the rest locally.
   */
  public function subscribe($feed, $submission_data, $form, $entry) {
    $key = $this->postedPaymentKey($form);
    if ($key === '') {
      return $this->authorization_error(__('Please enter your card details.', 'payarc-payments'));
    }
    $recurring = (float) rgar($submission_data, 'payment_amount');
    if ($recurring <= 0) {
      return $this->authorization_error(__('The payment amount could not be processed. Please contact us.', 'payarc-payments'));
    }
    $setupFee = (float) rgar($submission_data, 'setup_fee');
    $trialEnabled = !empty($feed['meta']['trial_enabled']);
    $trialAmount = (float) rgar($submission_data, 'trial');
    $length = max(1, (int) rgars($feed, 'meta/billingCycle_length'));
    $unit = (string) rgars($feed, 'meta/billingCycle_unit', 'month');
    $times = max(0, (int) rgars($feed, 'meta/recurringTimes'));

    $firstAmount = $trialEnabled ? $trialAmount : $recurring + $setupFee;
    $uniqueId = $this->submissionId($form);
    $subscriptionId = 'gf-sub-' . substr($uniqueId, 0, 16);
    $orderId = Gateway::orderId('gf-' . (int) $form['id'] . '-' . $uniqueId);
    $payer = $this->payer($submission_data);
    $metadata = $this->gateway()->metadata(
      'GF' . (int) $form['id'] . '-' . substr($uniqueId, 0, 12),
      $this->description($form, $feed),
      $payer,
      ['currency' => \GFCommon::get_currency(), 'orderid' => $orderId]
    );

    if ($firstAmount > 0) {
      $outcome = $this->charge(static fn($client) => $client->saleWithPaymentKey($key, self::money($firstAmount), $metadata, TRUE), $orderId, self::money($firstAmount));
    }
    else {
      $outcome = $this->charge(static fn($client) => $client->verifyAndSaveCardWithPaymentKey($key, $metadata), $orderId, \Payarc\GatewayClient::CARD_VERIFICATION_AMOUNT);
    }
    if (!empty($outcome['error'])) {
      return $this->authorization_error($outcome['error']);
    }
    $response = $outcome['response'];
    $cardReference = trim((string) rgars($response, 'savedcard/key'));
    if ($cardReference === '') {
      // Charged (or verified) but nothing to charge later: undo and refuse.
      $this->log_error(__METHOD__ . '(): approved without a saved card reference; voiding.');
      if ($firstAmount > 0) {
        try {
          $this->gateway()->client(self::INTEGRATION)->void(Gateway::transactionReference($response));
        }
        catch (\Throwable $e) {
          $this->log_error(__METHOD__ . '(): void failed: ' . $e->getMessage());
        }
      }
      return $this->authorization_error(__('The card could not be saved for future payments. Please try a different card or contact us.', 'payarc-payments'));
    }

    $card = Gateway::card($response);
    $this->cardSummary = self::summary($card);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $nextDate = Schedule::installmentDate($now, $length, $unit, 1);
    $this->pendingMeta = [
      'payarc_mode' => Plugin::instance()->settings()->mode(),
      'payarc_order_id' => $orderId,
      'payarc_card_reference' => $cardReference,
      'payarc_card_brand' => (string) $card['brand'],
      'payarc_card_last4' => (string) $card['last4'],
      'payarc_payer' => $payer,
      'payarc_interval_length' => $length,
      'payarc_interval_unit' => $unit,
      'payarc_recurring_times' => $times,
      'payarc_payments_made' => $trialEnabled ? 0 : 1,
      'payarc_failed_attempts' => 0,
      'payarc_schedule_start' => $now->format('Y-m-d H:i:s'),
      'payarc_installment_index' => 1,
      'payarc_scheduled_date' => $nextDate->format('Y-m-d H:i:s'),
      'payarc_next_charge' => $nextDate->format('Y-m-d H:i:s'),
      'payarc_form_title' => (string) rgar($form, 'title'),
    ];
    $this->pendingNotes[] = $this->gatewayNote($response);
    $this->pendingNotes[] = sprintf(
      __('Subscription schedule: %1$s %2$s, next charge %3$s (UTC)%4$s. Charged by this site through PayArc; nothing is scheduled in the PayArc console.', 'payarc-payments'),
      \GFCommon::to_money($recurring, \GFCommon::get_currency()),
      Schedule::describeInterval($length, $unit),
      $nextDate->format('Y-m-d'),
      $times > 0 ? sprintf(__(', %d payments in total', 'payarc-payments'), $times) : ''
    );

    $result = [
      'is_success' => TRUE,
      'subscription_id' => $subscriptionId,
      'amount' => $recurring,
    ];
    if ($firstAmount > 0) {
      $result['captured_payment'] = [
        'name' => $trialEnabled ? __('Trial payment', 'payarc-payments') : ($setupFee > 0 ? __('First payment including setup fee', 'payarc-payments') : __('First payment', 'payarc-payments')),
        'is_success' => TRUE,
        'transaction_id' => Gateway::transactionReference($response),
        'amount' => $firstAmount,
        'payment_method' => $card['brand'] ?: 'Card',
      ];
    }
    return $result;
  }

  /**
   * Site-managed: nothing to cancel at the gateway. GF marks the entry
   * Cancelled and the renewal worker skips it from then on.
   */
  public function cancel($entry, $feed) {
    $this->add_note($entry['id'], __('No further charges will be made. The saved card reference stays on the entry for reference.', 'payarc-payments'));
    return TRUE;
  }

  /**
   * Hourly (GF schedules {slug}_cron because this method is overridden).
   */
  public function check_status() {
    $summary = (new Renewals($this, $this->gateway(), Plugin::instance()->settings()))->run();
    $summary['purged_markers'] = Reconcile::purgeOptionMarkers();
    $this->log_debug(__METHOD__ . '(): ' . wp_json_encode($summary));
  }

  public function entry_post_save($entry, $form) {
    $entry = parent::entry_post_save($entry, $form);
    if ($this->is_payment_gateway && !empty($entry['id'])) {
      foreach ($this->pendingMeta as $key => $value) {
        gform_update_meta($entry['id'], $key, $value, $form['id']);
      }
      foreach ($this->pendingNotes as $note) {
        $this->add_note($entry['id'], $note);
      }
    }
    $this->pendingMeta = [];
    $this->pendingNotes = [];
    return $entry;
  }

  // ---------------------------------------------------------------------
  // Refunds (entry detail)
  // ---------------------------------------------------------------------

  public function entry_info($form_id, $entry) {
    parent::entry_info($form_id, $entry);
    if (!$this->is_payment_gateway($entry['id']) || !\GFCommon::current_user_can_any($this->_capabilities_settings_page)) {
      return;
    }
    $refundable = (string) rgar($entry, 'transaction_type') === '1' && rgar($entry, 'payment_status') === 'Paid';
    if (!$refundable) {
      return;
    }
    ?>
    <div class="payarc-refund" style="margin-top:10px">
      <label for="payarc-refund-amount"><?php esc_html_e('Refund amount', 'payarc-payments'); ?></label>
      <input type="number" step="0.01" min="0.01" max="<?php echo esc_attr((string) rgar($entry, 'payment_amount')); ?>" id="payarc-refund-amount" value="<?php echo esc_attr((string) rgar($entry, 'payment_amount')); ?>" style="width:7em">
      <button type="button" class="button" id="payarc-refund-button" data-entry-id="<?php echo (int) $entry['id']; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('payarc_gf_refund')); ?>"><?php esc_html_e('Refund via PayArc', 'payarc-payments'); ?></button>
      <p class="description"><?php esc_html_e('Unsettled (same-day) sales can only be voided in full; settled sales can be refunded in full or in part.', 'payarc-payments'); ?></p>
      <div id="payarc-refund-result"></div>
    </div>
    <?php
  }

  public function ajaxRefund(): void {
    check_ajax_referer('payarc_gf_refund', 'nonce');
    if (!\GFCommon::current_user_can_any($this->_capabilities_settings_page)) {
      wp_send_json_error(['message' => __('Access denied.', 'payarc-payments')]);
    }
    $entry_id = (int) rgpost('entry_id');
    $entry = \GFAPI::get_entry($entry_id);
    if (is_wp_error($entry) || !$this->is_payment_gateway($entry_id)) {
      wp_send_json_error(['message' => __('This entry was not paid through PayArc.', 'payarc-payments')]);
    }
    if (rgar($entry, 'payment_status') !== 'Paid') {
      wp_send_json_error(['message' => __('Only paid entries can be refunded.', 'payarc-payments')]);
    }
    $amount = (float) rgpost('amount');
    $paid = (float) rgar($entry, 'payment_amount');
    if ($amount <= 0 || $amount > $paid + 0.00001) {
      wp_send_json_error(['message' => __('Enter an amount between 0.01 and the amount paid.', 'payarc-payments')]);
    }
    // Refund against the host the sale was made on, whatever the site's mode is now.
    $mode = (string) gform_get_meta($entry_id, 'payarc_mode');
    $reference = (string) (gform_get_meta($entry_id, 'payarc_transaction_key') ?: rgar($entry, 'transaction_id'));

    try {
      $client = $this->gateway()->client(self::INTEGRATION, $mode !== '' ? $mode : NULL);
      $transaction = $client->getTransaction($reference);
      $status = (string) rgar($transaction, 'status_code');
      $full = abs($amount - $paid) < 0.005;
      if ($status === 'P' || $status === 'A') {
        if (!$full) {
          wp_send_json_error(['message' => __('This sale has not settled yet, so it can only be voided in full. Try a partial refund tomorrow.', 'payarc-payments')]);
        }
        $response = $client->void($reference);
        $action = 'void';
      }
      else {
        $action = 'refund';
        // Refunds inherit the sale's orderid at PayArc. A marker on the entry
        // makes sure a refund whose answer was lost is found, not repeated.
        $saleOrderId = trim((string) rgar($transaction, 'orderid'));
        if ($saleOrderId === '') {
          $response = $client->refund($reference, self::money($amount));
        }
        else {
          [$read, $write] = Reconcile::metaStore(
            static fn() => gform_get_meta($entry_id, 'payarc_refund_sent'),
            static function (array $marker) use ($entry_id): void { gform_update_meta($entry_id, 'payarc_refund_sent', $marker); },
            static function () use ($entry_id): void { gform_delete_meta($entry_id, 'payarc_refund_sent'); }
          );
          $result = Reconcile::once($client, $read, $write, $saleOrderId, self::money($amount), static fn($c) => $c->refund($reference, self::money($amount)), \Payarc\GatewayClient::TYPES_REFUND);
          if ($result['reconciled'] && abs((float) $result['amount'] - $amount) >= 0.005) {
            wp_send_json_error(['message' => Reconcile::refundMismatchMessage(\GFCommon::to_money((float) $result['amount'], $entry['currency']), Gateway::transactionReference($result['response']))]);
          }
          $response = $result['response'];
          $clearRefundMarker = $write;
        }
      }
    }
    catch (ReconciliationInconclusiveException | AmbiguousGatewayException | BusyException $e) {
      $this->log_error(__METHOD__ . '(): ' . $e->getMessage());
      wp_send_json_error(['message' => Reconcile::refundBlockedMessage($e, $reference)]);
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      $this->log_error(__METHOD__ . '(): ' . $e->getMessage());
      wp_send_json_error(['message' => $e->getMessage()]);
    }
    if (!Gateway::approved($response)) {
      $failure = Gateway::failure($response);
      $this->log_error(__METHOD__ . '(): ' . $failure['gateway']);
      wp_send_json_error(['message' => $failure['gateway']]);
    }

    $refundReference = Gateway::transactionReference($response) ?: $reference;
    $this->refund_payment($entry, [
      'transaction_id' => $refundReference,
      'amount' => $amount,
      'note' => $action === 'void'
        ? sprintf(__('Sale voided before settlement via PayArc. Reference: %s', 'payarc-payments'), $refundReference)
        : sprintf(__('Refunded %1$s via PayArc. Reference: %2$s', 'payarc-payments'), \GFCommon::to_money($amount, $entry['currency']), $refundReference),
    ]);
    if (isset($clearRefundMarker)) {
      // Cleared only now that the refund is recorded on the entry.
      $clearRefundMarker(NULL);
    }
    wp_send_json_success(['message' => $action === 'void' ? __('Voided.', 'payarc-payments') : __('Refunded.', 'payarc-payments')]);
  }

  // ---------------------------------------------------------------------
  // Assets
  // ---------------------------------------------------------------------

  public function scripts() {
    $plugin = Plugin::instance();
    $settings = $plugin->settings();
    $scripts = [
      [
        'handle' => 'payarc-hostedfields',
        'src' => $plugin->url('assets/js/payarc-hostedfields.js'),
        'version' => $plugin->assetVersion('assets/js/payarc-hostedfields.js'),
        'deps' => [],
        'in_footer' => TRUE,
        'enqueue' => [['field_types' => [CardField::TYPE]]],
      ],
      [
        'handle' => 'payarc_gravityforms',
        'src' => $plugin->url('assets/js/payarc-gravityforms.js'),
        'version' => $plugin->assetVersion('assets/js/payarc-gravityforms.js'),
        'deps' => ['payarc-hostedfields', 'gform_gravityforms'],
        'in_footer' => TRUE,
        'strings' => [
          'publicKey' => $settings->publicKey(),
          'payJsUrl' => $settings->payJsUrl(),
          'configured' => $settings->isConfigured() ? '1' : '0',
          'applePay' => [
            'enabled' => $settings->applePayEnabled() ? '1' : '0',
            'displayName' => $settings->applePayDisplayName(),
            'countryCode' => 'US',
            'currencyCode' => \GFCommon::get_currency(),
          ],
          'i18n' => [
            'notConfigured' => __('The payment form is not configured correctly, so no charge was made. Please contact us.', 'payarc-payments'),
            'chooseAmount' => __('Please choose an amount before paying with Apple Pay.', 'payarc-payments'),
          ],
        ],
        'enqueue' => [['field_types' => [CardField::TYPE]]],
      ],
      [
        'handle' => 'payarc_gravityforms_admin',
        'src' => $plugin->url('assets/js/payarc-gravityforms-admin.js'),
        'version' => $plugin->assetVersion('assets/js/payarc-gravityforms-admin.js'),
        'deps' => ['jquery'],
        'strings' => [
          'ajaxUrl' => admin_url('admin-ajax.php'),
          'confirm' => __('Refund this payment through PayArc? This cannot be undone.', 'payarc-payments'),
          'working' => __('Contacting PayArc…', 'payarc-payments'),
        ],
        'enqueue' => [['admin_page' => ['entry_view']]],
      ],
    ];
    return array_merge(parent::scripts(), $scripts);
  }

  public function styles() {
    $plugin = Plugin::instance();
    $styles = [
      [
        'handle' => 'payarc-payments',
        'src' => $plugin->url('assets/css/payarc-payments.css'),
        'version' => $plugin->assetVersion('assets/css/payarc-payments.css'),
        'enqueue' => [
          ['field_types' => [CardField::TYPE]],
          ['admin_page' => ['form_editor']],
        ],
      ],
    ];
    return array_merge(parent::styles(), $styles);
  }

  // ---------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------

  /**
   * Run a charge with the shared failure handling.
   *
   * With an $orderId the charge is made at most once per submission: a marker
   * keyed by the orderid (which is derived from GF's per-submission unique id,
   * so a resubmit after an error carries the same one) is stored before the
   * request and an earlier approval is found and reused instead of charged
   * again. $amount is what the transaction must show to count as that charge.
   *
   * @return array{response?: array, error?: string, reconciled?: bool}
   */
  public function charge(callable $call, ?string $orderId, ?string $amount = NULL): array {
    try {
      $client = $this->gateway()->client(self::INTEGRATION);
    }
    catch (GatewayException $e) {
      $this->log_error(__METHOD__ . '(): ' . $e->getMessage());
      return ['error' => __('The payment system is not configured correctly, so no charge was made. Please contact us so we can fix it.', 'payarc-payments')];
    }
    $reconciled = FALSE;
    try {
      if ($orderId !== NULL) {
        [$read, $write] = Reconcile::optionStore('gf:' . $orderId);
        $result = Reconcile::once($client, $read, $write, $orderId, $amount, $call);
        $response = $result['response'];
        $reconciled = $result['reconciled'];
        if ($reconciled) {
          $this->log_debug(__METHOD__ . '(): reconciled ' . $orderId . ' to ' . rgar($response, 'key'));
        }
      }
      else {
        $response = $call($client);
      }
    }
    catch (ReconciliationInconclusiveException $e) {
      $this->log_error(__METHOD__ . '(): ' . $e->getMessage());
      return ['error' => __('An earlier attempt to make this payment may have gone through, and the card processor could not confirm it. Nothing was charged now. Please contact us before trying again.', 'payarc-payments')];
    }
    catch (AmbiguousGatewayException $e) {
      $this->log_error(__METHOD__ . '(): ambiguous: ' . $e->getMessage());
      return ['error' => __('The payment could not be completed because the card processor did not respond. Please wait a moment and try again. If the problem continues, contact us.', 'payarc-payments')];
    }
    catch (GatewayException $e) {
      $this->log_error(__METHOD__ . '(): ' . $e->getMessage());
      return ['error' => \Payarc\DonorMessage::donorText($e->getMessage())];
    }
    catch (\InvalidArgumentException $e) {
      $this->log_error(__METHOD__ . '(): ' . $e->getMessage());
      return ['error' => __('The payment could not be processed. Please check the card details and try again, or contact us for help.', 'payarc-payments')];
    }
    catch (BusyException $e) {
      // The same submission sent twice at once (a double click that got past
      // the form's own guard); the first one is still out.
      $this->log_debug(__METHOD__ . '(): busy: ' . $e->getMessage());
      return ['error' => Reconcile::busyMessage()];
    }
    catch (\RuntimeException $e) {
      // The marker could not be stored; nothing was sent.
      $this->log_error(__METHOD__ . '(): not sent: ' . $e->getMessage());
      return ['error' => __('The payment could not be processed right now. Please try again in a moment, or contact us for help.', 'payarc-payments')];
    }
    if (!Gateway::approved($response)) {
      $failure = Gateway::failure($response);
      $this->log_error(__METHOD__ . '(): declined: ' . $failure['gateway']);
      return ['error' => $failure['donor']];
    }
    if (!empty($response['void_error'])) {
      // The card was saved, but the $1 verification hold was not released;
      // it expires on its own. Logged so the console can be checked.
      $this->log_error(__METHOD__ . '(): card saved but the verification hold was not voided: ' . $response['void_error']);
    }
    return ['response' => $response, 'reconciled' => $reconciled];
  }

  private function postedPaymentKey(array $form): string {
    $field = $this->get_credit_card_field($form);
    return $field ? trim((string) rgpost('input_' . $field->id)) : '';
  }

  private function submissionId(array $form): string {
    $unique = (string) rgpost('gform_unique_id');
    if ($unique === '' || !preg_match('/^[a-f0-9]{32}$/i', $unique)) {
      $unique = \GFFormsModel::get_form_unique_id((int) $form['id']);
    }
    return strtolower($unique);
  }

  private function description(array $form, array $feed): string {
    $title = trim((string) rgar($form, 'title'));
    $feedName = trim((string) rgars($feed, 'meta/feedName'));
    return $feedName !== '' && $feedName !== $title ? $title . ' - ' . $feedName : $title;
  }

  /**
   * @return array{email: string, first_name: string, last_name: string, address: string, address2: string, city: string, state: string, postcode: string, country: string, phone: string}
   */
  private function payer(array $submission_data): array {
    return [
      'email' => (string) rgar($submission_data, 'email'),
      'first_name' => (string) rgar($submission_data, 'first_name'),
      'last_name' => (string) rgar($submission_data, 'last_name'),
      'address' => (string) rgar($submission_data, 'address'),
      'address2' => (string) rgar($submission_data, 'address2'),
      'city' => (string) rgar($submission_data, 'city'),
      'state' => (string) rgar($submission_data, 'state'),
      'postcode' => (string) rgar($submission_data, 'zip'),
      'country' => self::countryCode((string) rgar($submission_data, 'country')),
      'phone' => (string) rgar($submission_data, 'phone'),
    ];
  }

  /**
   * The GF address field posts the country NAME ("United States"); PayArc
   * wants an ISO code, and the shared metadata builder drops unknown values.
   */
  public static function countryCode(string $country): string {
    $country = trim($country);
    if ($country === '' || strlen($country) <= 3) {
      return $country;
    }
    if (class_exists('GF_Field_Address')) {
      $code = (string) (new \GF_Field_Address())->get_country_code($country);
      if ($code !== '') {
        return $code;
      }
    }
    return $country;
  }

  public function gatewayNote(array $response): string {
    $parts = [sprintf(__('PayArc reference %s', 'payarc-payments'), Gateway::transactionReference($response))];
    if (rgar($response, 'refnum')) {
      $parts[] = sprintf(__('refnum %s', 'payarc-payments'), rgar($response, 'refnum'));
    }
    if (rgar($response, 'authcode')) {
      $parts[] = sprintf(__('auth code %s', 'payarc-payments'), rgar($response, 'authcode'));
    }
    $avs = rgars($response, 'avs/result');
    if ($avs) {
      $parts[] = sprintf(__('AVS: %s', 'payarc-payments'), $avs);
    }
    $cvc = rgars($response, 'cvc/result');
    if ($cvc) {
      $parts[] = sprintf(__('CVV: %s', 'payarc-payments'), $cvc);
    }
    if (Plugin::instance()->settings()->isSandbox()) {
      $parts[] = __('SANDBOX transaction', 'payarc-payments');
    }
    return implode(', ', $parts) . '.';
  }

  public static function money(float $amount): string {
    return number_format($amount, 2, '.', '');
  }

  public static function summary(array $card): string {
    if (empty($card['last4'])) {
      return $card['brand'] ? (string) $card['brand'] : __('Card', 'payarc-payments');
    }
    return sprintf(__('%1$s ending in %2$s', 'payarc-payments'), $card['brand'] ?: __('Card', 'payarc-payments'), $card['last4']);
  }

}
