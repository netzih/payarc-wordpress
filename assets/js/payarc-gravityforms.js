/* global PayarcHostedFields, payarc_gravityforms_strings, gform, gf_global, jQuery */
/**
 * Gravity Forms front end: mounts the hosted card fields into the PayArc
 * Card field, and turns them into a payment key inside GF 3's
 * gform/submission/pre_submission filter (aborting the submit on failure).
 */
(function (window, document) {
  'use strict';

  var cfg = window.payarc_gravityforms_strings || {};
  var handles = {};

  function errorElement(h) {
    return document.getElementById(h.container.id.replace(/-card$/, '-errors'));
  }

  function setError(h, text, raw) {
    var el = errorElement(h);
    if (el) {
      el.textContent = text || '';
      // Raw gateway or Pay.js text for support; the visible text is payer wording.
      el.dataset.rawError = raw ? String(raw) : '';
    }
  }

  /**
   * The number in a Gravity Forms money string ("$1,234.56", "1.234,56 €"),
   * parsed by GF's own Currency class with the site's currency settings, so
   * the decimal and thousands separators are the ones GF wrote. NaN when the
   * text holds no number.
   */
  function moneyToNumber(text) {
    text = String(text == null ? '' : text).trim();
    if (text === '') {
      return NaN;
    }
    var config = window.gf_global && window.gf_global.gf_currency_config;
    if (window.gform && typeof window.gform.Currency === 'function' && config) {
      var parsed = new window.gform.Currency(config).toNumber(text);
      return parsed === false ? NaN : Number(parsed);
    }
    // Without GF's parser: of "." and ",", the one written last is the decimal
    // separator; when only one kind occurs it groups thousands if exactly
    // three digits follow it, and marks decimals otherwise.
    var digits = text.replace(/[^0-9.,-]/g, '');
    var lastDot = digits.lastIndexOf('.');
    var lastComma = digits.lastIndexOf(',');
    var decimal = lastDot > lastComma ? '.' : ',';
    if (lastDot === -1 || lastComma === -1) {
      var only = digits.indexOf(decimal) === -1 ? '' : decimal;
      decimal = only && !/[.,]\d{3}$/.test(digits) ? only : '';
    }
    var normalised = digits.split(decimal === '.' ? ',' : '.').join('');
    if (decimal === ',') {
      normalised = normalised.replace(',', '.');
    } else if (decimal === '') {
      normalised = normalised.replace(/[.,]/g, '');
    }
    return normalised === '' ? NaN : Number(normalised);
  }

  /**
   * What this form's Total field shows, as a number; NaN when the form has no
   * Total field. Scoped to the form, since a page can carry several forms, and
   * skipping totals that belong to a repeater's rows.
   */
  function totalFieldAmount(formId) {
    var form = document.getElementById('gform_' + formId);
    var totals = form ? form.querySelectorAll('.ginput_total_' + formId) : [];
    for (var i = 0; i < totals.length; i++) {
      var el = totals[i];
      if (el.closest && el.closest('.gfield_repeater, .gfield_repeater_template')) {
        continue;
      }
      // Legacy markup shows the money in a span and keeps the plain number in
      // the hidden input after it; the current markup formats the input itself.
      var next = el.tagName !== 'INPUT' ? el.nextElementSibling : null;
      if (next && next.tagName === 'INPUT' && String(next.value).trim() !== '') {
        return moneyToNumber(next.value);
      }
      return moneyToNumber(el.tagName === 'INPUT' ? el.value : el.textContent);
    }
    return NaN;
  }

  /**
   * Gravity Forms before 3.0 on a form without a Total field: add the product
   * fields up the way GF's own total calculation does (that function is
   * debounced and returns nothing, so it cannot be asked). NaN when GF has no
   * price fields registered for the form.
   */
  function legacyTotal(formId) {
    var ids = window._gformPriceFields && window._gformPriceFields[formId];
    if (!ids || typeof window.gformCalculateProductPrice !== 'function') {
      return NaN;
    }
    var total = 0;
    window._anyProductSelected = false;
    for (var i = 0; i < ids.length; i++) {
      total += Number(window.gformCalculateProductPrice(formId, ids[i])) || 0;
    }
    if (window._anyProductSelected && typeof window.gformGetShippingPrice === 'function') {
      total += Number(window.gformGetShippingPrice(formId)) || 0;
    }
    if (typeof window.gform_product_total === 'function') {
      total = window.gform_product_total(formId, total);
    }
    if (window.gform && typeof window.gform.applyFilters === 'function') {
      total = window.gform.applyFilters('gform_product_total', total, formId);
    }
    return Number(total);
  }

  /**
   * The form's current total as Apple Pay wants it: "50.00", or "0.00" when
   * there is nothing to charge yet.
   *
   * Gravity Forms 3 keeps the products of every form in gform.state, updated
   * on each keystroke of a price field, and gform.products.getPaymentAmount()
   * reads the total from there: the same number GF writes into the Total
   * field, without the debounce (GF's older gformCalculateTotalPrice() is
   * debounced and returns undefined, which is why the amount used to read as
   * zero). Earlier GF versions have no such API, so the Total field is read
   * and, failing that, the product fields are added up.
   */
  function formTotal(formId) {
    var total = NaN;
    try {
      var products = window.gform && window.gform.products;
      if (products && typeof products.getPaymentAmount === 'function') {
        total = Number(products.getPaymentAmount(formId));
      }
      if (!isFinite(total)) {
        total = totalFieldAmount(formId);
      }
      if (!isFinite(total)) {
        total = legacyTotal(formId);
      }
    } catch (e) {
      total = NaN;
    }
    return isFinite(total) && total > 0 ? total.toFixed(2) : '0.00';
  }

  function submitForm(formId) {
    var form = document.getElementById('gform_' + formId);
    var button = form && form.querySelector('#gform_submit_button_' + formId);
    if (button) {
      button.click();
    } else if (form && form.requestSubmit) {
      form.requestSubmit();
    }
  }

  function mountApplePay(formId, h) {
    var wrapper = document.getElementById(h.container.id.replace(/-card$/, '-apple-pay'));
    if (!wrapper || h.container.dataset.applePay !== '1' || !cfg.applePay || cfg.applePay.enabled !== '1') {
      return;
    }
    PayarcHostedFields.applePay({
      client: h.client,
      targetDiv: wrapper.querySelector('.payarc-apple-pay-button').id,
      displayName: cfg.applePay.displayName,
      countryCode: cfg.applePay.countryCode,
      currencyCode: cfg.applePay.currencyCode,
      buttonType: 'plain',
      getAmount: function () { return formTotal(formId); },
      onKey: function (key) {
        setError(h, '');
        h.input.value = key;
        submitForm(formId);
      },
      onError: function (text) { setError(h, text); },
      onCancel: function () { setError(h, ''); }
    }).then(function (entry) {
      if (entry) {
        wrapper.hidden = false;
      }
    });
  }

  function mountForm(formId) {
    var form = document.getElementById('gform_' + formId);
    var container = form && form.querySelector('.payarc-card-element');
    if (!container || container.dataset.payarcMounted === '1') {
      return;
    }
    container.dataset.payarcMounted = '1';
    var input = form.querySelector('input.payarc-payment-key');
    if (input) {
      // A key echoed back after a server-side validation error is single-use
      // and possibly expired; always tokenize afresh.
      input.value = '';
    }
    var h = { container: container, input: input, form: form, client: null, cardEntry: null };
    handles[formId] = h;
    if (cfg.configured !== '1' || !cfg.publicKey) {
      setError(h, (cfg.i18n && cfg.i18n.notConfigured) || 'The payment form is not configured.');
      return;
    }
    PayarcHostedFields.mount({
      publicKey: cfg.publicKey,
      payJsUrl: cfg.payJsUrl,
      container: container,
      onFieldError: function (text) { setError(h, text); }
    }).then(function (mounted) {
      h.client = mounted.client;
      h.cardEntry = mounted.cardEntry;
      mountApplePay(formId, h);
    }).catch(function (error) {
      setError(h, PayarcHostedFields.errorText(error));
    });
  }

  function visible(el) {
    return !!(el && el.offsetParent !== null);
  }

  function installSubmissionFilter() {
    if (!window.gform || !window.gform.utils || !window.gform.utils.addAsyncFilter) {
      return;
    }
    window.gform.utils.addAsyncFilter('gform/submission/pre_submission', async function (data) {
      if (data.abort) {
        return data;
      }
      var types = window.gform.submission || {};
      // Only the final submit: a key minted on "Next" would be cleared when the
      // following page renders, so the card field must be on the last page.
      if (data.submissionType !== types.SUBMISSION_TYPE_SUBMIT) {
        return data;
      }
      var formId = parseInt(data.form.dataset.formid, 10);
      var h = handles[formId];
      if (!h || !document.body.contains(h.container) || !h.input) {
        return data;
      }
      // Hidden by conditional logic: nothing to tokenize now.
      if (!visible(h.container)) {
        return data;
      }
      if (h.input.value) {
        return data;
      }
      if (!h.client) {
        setError(h, (cfg.i18n && cfg.i18n.notConfigured) || 'The payment form is not configured.');
        data.abort = true;
        return data;
      }
      try {
        h.input.value = await PayarcHostedFields.tokenize(h);
        setError(h, '');
      } catch (error) {
        setError(h, PayarcHostedFields.errorText(error), error && error.raw ? error.raw : PayarcHostedFields.errorText(error));
        data.abort = true;
      }
      return data;
    });
  }

  document.addEventListener('gform/post_render', function (event) {
    mountForm(parseInt(event.detail.formId, 10));
  });
  if (window.jQuery) {
    jQuery(document).on('gform_post_render', function (event, formId) {
      mountForm(parseInt(formId, 10));
    });
  }
  installSubmissionFilter();
  // Forms already rendered before this script ran.
  document.querySelectorAll('form[id^="gform_"] .payarc-card-element').forEach(function (el) {
    mountForm(parseInt(el.dataset.formId, 10));
  });
}(window, document));
