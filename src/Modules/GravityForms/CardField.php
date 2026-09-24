<?php

namespace Payarc\WordPress\Modules\GravityForms;

use Payarc\WordPress\Plugin;

/**
 * "PayArc Card" form field: a container the PayArc Hosted Fields are mounted
 * into, plus a hidden input carrying the single-use card token. Card data
 * never reaches this server; the entry stores only "Visa ending in 5439"
 * after the charge.
 */
final class CardField extends \GF_Field {

  public const TYPE = 'payarc_card';

  public $type = self::TYPE;

  public $duplicatable = FALSE;

  public $repeatable = FALSE;

  /**
   * Excludes the field from Save & Continue drafts (GF 2.9.23+).
   */
  public $is_payment = TRUE;

  public function get_form_editor_field_title() {
    return esc_attr__('PayArc Card', 'payarc-payments');
  }

  public function get_form_editor_field_description() {
    return esc_attr__('Secure card entry hosted by PayArc. Add a PayArc feed under Form Settings to charge the card.', 'payarc-payments');
  }

  public function get_form_editor_field_icon() {
    return 'gform-icon--credit-card';
  }

  public function get_form_editor_button() {
    return [
      'group' => 'pricing_fields',
      'text' => $this->get_form_editor_field_title(),
      'description' => $this->get_form_editor_field_description(),
    ];
  }

  public function get_form_editor_field_settings() {
    return [
      'conditional_logic_field_setting',
      'error_message_setting',
      'label_setting',
      'label_placement_setting',
      'admin_label_setting',
      'rules_setting',
      'description_setting',
      'css_class_setting',
    ];
  }

  public function is_conditional_logic_supported() {
    return TRUE;
  }

  public function get_field_input($form, $value = '', $entry = NULL) {
    $form_id = (int) ($form['id'] ?? 0);
    $id = (int) $this->id;
    $is_entry_detail = $this->is_entry_detail();
    $is_form_editor = $this->is_form_editor();

    if ($is_entry_detail) {
      return '<div class="ginput_container">' . esc_html((string) $value) . '</div>';
    }

    $base = 'payarc-' . $form_id . '-' . $id;
    $note = esc_html__('Card details are entered securely in a form hosted by PayArc.', 'payarc-payments');

    if ($is_form_editor) {
      $warning = '';
      if ($this->notOnLastPage($form)) {
        $warning = '<p class="payarc-card-note payarc-card-note--warning">' . esc_html__('Move this field to the last page. Card details are tokenized when the form is submitted, so the card fields must be on the page that submits it.', 'payarc-payments') . '</p>';
      }
      return '<div class="ginput_container ginput_container_payarc_card">'
        . '<div class="payarc-card-element payarc-card-element--preview" aria-hidden="true"><span>' . esc_html__('Card number', 'payarc-payments') . '</span><span>' . esc_html__('MM/YY', 'payarc-payments') . '</span><span>' . esc_html__('CVV', 'payarc-payments') . '</span><span>' . esc_html__('ZIP', 'payarc-payments') . '</span></div>'
        . '<p class="payarc-card-note">' . $note . '</p>' . $warning . '</div>';
    }

    $wallets = $this->walletsAllowed($form) ? '1' : '0';
    $input_id = 'input_' . $form_id . '_' . $id;

    return '<div class="ginput_container ginput_container_payarc_card" data-payarc-form="' . $form_id . '" data-payarc-field="' . $id . '">'
      . '<div class="payarc-wallets-wrapper" id="' . esc_attr($base) . '-wallets" hidden>'
      . '<div class="payarc-wallet-buttons"></div>'
      . '<div class="payarc-wallet-divider"><span>' . esc_html__('or enter card details', 'payarc-payments') . '</span></div>'
      . '</div>'
      . '<div class="payarc-card-element" id="' . esc_attr($base) . '-card" data-form-id="' . $form_id . '" data-field-id="' . $id . '" data-wallets="' . $wallets . '" aria-label="' . esc_attr__('Secure card details', 'payarc-payments') . '"></div>'
      . '<div class="payarc-card-errors" id="' . esc_attr($base) . '-errors" role="alert" aria-live="polite"></div>'
      . '<input type="hidden" class="payarc-payment-key" name="input_' . $id . '" id="' . esc_attr($input_id) . '" value="" autocomplete="off">'
      . '<p class="payarc-card-note">' . $note . '</p>'
      . '</div>';
  }

  /**
   * The single-use token is minted when the form is submitted, so on a
   * multi-page form the field has to sit on the last page.
   */
  private function notOnLastPage(array $form): bool {
    $pages = 1;
    foreach ((array) ($form['fields'] ?? []) as $field) {
      if (is_object($field) && $field->type === 'page') {
        $pages++;
      }
    }
    return $pages > 1 && (int) $this->pageNumber > 0 && (int) $this->pageNumber < $pages;
  }

  /**
   * Wallet tokens cannot be saved for later charges, so the Apple Pay and
   * Google Pay buttons are offered only when every active PayArc feed on the
   * form is a one-time payment.
   */
  private function walletsAllowed(array $form): bool {
    if (!Plugin::instance()->settings()->walletsEnabled() || !class_exists(AddOn::class)) {
      return FALSE;
    }
    $feeds = AddOn::get_instance()->get_active_feeds((int) ($form['id'] ?? 0));
    if (!$feeds) {
      return FALSE;
    }
    foreach ($feeds as $feed) {
      if (rgars($feed, 'meta/transactionType') !== 'product') {
        return FALSE;
      }
    }
    return TRUE;
  }

  public function validate($value, $form) {
    if ($this->isRequired && trim((string) $value) === '') {
      $this->failed_validation = TRUE;
      $this->validation_message = $this->errorMessage ?: esc_html__('Please enter your card details.', 'payarc-payments');
    }
  }

  /**
   * The posted value is a single-use card token; it must never be stored.
   * After a successful charge the add-on supplies "Visa ending in 5439".
   */
  public function get_value_save_input($value, $form, $input_name, $entry_id, $entry, $repeater_index = '') {
    $summary = class_exists(AddOn::class) ? AddOn::get_instance()->cardSummaryForEntry() : '';
    return $this->sanitize_entry_value($summary, $form['id']);
  }

  public function get_value_entry_list($value, $entry, $field_id, $columns, $form) {
    return esc_html((string) $value);
  }

  public function get_value_export($entry, $input_id = '', $use_text = FALSE, $is_csv = FALSE) {
    return (string) rgar($entry, $input_id ?: (string) $this->id);
  }

  public function allow_html() {
    return FALSE;
  }

}
