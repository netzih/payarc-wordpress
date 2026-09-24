/* global jQuery, PayarcHostedFields, payarc_woocommerce_params */
/**
 * WooCommerce classic checkout, Pay for Order and Add Payment Method pages.
 * Mounts the PayArc Hosted Fields into #payarc-wc-card whenever the
 * payment area is (re)rendered and tokenizes before the form is submitted.
 */
(function ($, window) {
  'use strict';

  var cfg = window.payarc_woocommerce_params || {};
  var mounted = null;

  function t(key, fallback) {
    return (cfg.i18n && cfg.i18n[key]) || fallback;
  }

  function showError(text) {
    $('#payarc-wc-errors').text(text || '');
  }

  function keyInput() {
    return $('#payarc_token');
  }

  function usingSavedCard() {
    var radio = $('input[name="wc-payarc-payment-token"]:checked');
    return radio.length > 0 && radio.val() !== 'new';
  }

  function ourMethodSelected(form) {
    var method = form.find('input[name="payment_method"]:checked').val();
    return !method || method === 'payarc';
  }

  function mount() {
    var container = document.getElementById('payarc-wc-card');
    if (!container) {
      return Promise.reject(new Error('no container'));
    }
    if (container.dataset.payarcMounted === '1' && mounted) {
      return Promise.resolve(mounted);
    }
    container.dataset.payarcMounted = '1';
    keyInput().val('');
    if (!cfg.configured) {
      showError(t('notConfigured', 'The payment form is not configured.'));
      return Promise.reject(new Error('not configured'));
    }
    return PayarcHostedFields.mount({
      clientId: cfg.clientId,
      scriptUrl: cfg.scriptUrl,
      container: container,
      onFieldError: showError
    }).then(function (result) {
      mounted = result;
      mountWallets();
      return result;
    }).catch(function (error) {
      mounted = null;
      showError(PayarcHostedFields.errorText(error));
      throw error;
    });
  }

  function orderTotal() {
    var text = $('.order-total .amount, .woocommerce-Price-amount').last().text() || '';
    var number = parseFloat(text.replace(/[^0-9.,]/g, '').replace(/,(?=\d{3})/g, '').replace(',', '.'));
    return number > 0 ? number.toFixed(2) : '0.00';
  }

  function mountWallets() {
    var wrapper = document.getElementById('payarc-wc-wallets');
    if (!wrapper || !cfg.wallets || !cfg.wallets.length) {
      return;
    }
    PayarcHostedFields.wallets({
      clientId: cfg.clientId,
      scriptUrl: cfg.scriptUrl,
      targetDiv: wrapper.querySelector('.payarc-wallet-buttons'),
      wallets: cfg.wallets,
      getAmount: orderTotal,
      onKey: function (token) {
        keyInput().val(token);
        var form = $('form.checkout, form#order_review').first();
        form.trigger('submit');
      },
      onError: showError,
      onCancel: function () { showError(''); }
    }).then(function (row) {
      if (row) {
        wrapper.hidden = false;
      }
    });
  }

  /**
   * Returns true to let the submit through, false while tokenizing.
   */
  function guard(form, resubmit) {
    if (!ourMethodSelected(form) || !$('#payarc-wc-card').length) {
      return true;
    }
    if (usingSavedCard() || keyInput().val()) {
      return true;
    }
    mount().then(function (handles) {
      return PayarcHostedFields.tokenize(handles);
    }).then(function (token) {
      keyInput().val(token);
      showError('');
      resubmit();
    }).catch(function (error) {
      showError(PayarcHostedFields.errorText(error));
      form.removeClass('processing').unblock();
      $('html, body').animate({ scrollTop: $('#payarc-wc-card').offset().top - 120 }, 300);
    });
    return false;
  }

  // Classic checkout.
  $(document.body).on('updated_checkout', function () {
    mounted = null;
    mount().catch(function () {});
  });
  $('form.checkout').on('checkout_place_order_payarc', function () {
    var form = $(this);
    return guard(form, function () { form.trigger('submit'); });
  });
  $(document.body).on('checkout_error', function () {
    // The token is single-use: after any error the next attempt needs a new one.
    keyInput().val('');
  });

  // Pay for Order and Add Payment Method pages: plain POSTs.
  $('form#order_review, form#add_payment_method').on('submit', function (event) {
    var form = $(this);
    var ok = guard(form, function () { form.off('submit.payarc'); form[0].submit(); });
    if (!ok) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return false;
    }
    return true;
  });
  $(document.body).on('init_add_payment_method', function () {
    mount().catch(function () {});
  });
  $(function () {
    if ($('#payarc-wc-card').length) {
      mount().catch(function () {});
    }
  });
}(jQuery, window));
