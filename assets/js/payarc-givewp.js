/* global PayarcHostedFields, React */
/**
 * GiveWP visual donation form gateway. Registers with window.givewp.gateways:
 * Fields() mounts the Pay.js hosted card fields, beforeCreatePayment()
 * tokenizes them into a single-use payment key posted as
 * gatewayData[payarcPaymentKey].
 */
(function (window) {
  'use strict';

  var h = React.createElement;
  var settings = {};
  var mounted = null;   // {client, cardEntry}
  var mountPromise = null;
  var CONTAINER_ID = 'payarc-givewp-card';
  var ERRORS_ID = 'payarc-givewp-errors';
  var APPLE_ID = 'payarc-givewp-apple-pay';

  function t(key, fallback) {
    return (settings.i18n && settings.i18n[key]) || fallback;
  }

  function showError(text) {
    var el = document.getElementById(ERRORS_ID);
    if (el) {
      el.textContent = text || '';
    }
  }

  function mount() {
    var container = document.getElementById(CONTAINER_ID);
    if (!container) {
      return Promise.reject(new Error('container missing'));
    }
    if (mounted && container.dataset.payarcMounted === '1') {
      return Promise.resolve(mounted);
    }
    container.dataset.payarcMounted = '1';
    mountPromise = PayarcHostedFields.mount({
      publicKey: settings.publicKey,
      payJsUrl: settings.payJsUrl,
      container: container,
      onFieldError: showError
    }).then(function (result) {
      mounted = result;
      return result;
    }).catch(function (error) {
      showError(PayarcHostedFields.errorText(error));
      mounted = null;
      throw error;
    });
    return mountPromise;
  }

  function Fields() {
    var hooks = window.givewp.form.hooks;
    var formData = hooks.useFormData ? hooks.useFormData() : {};
    var isRecurring = !!formData.isRecurring;
    var amount = Number(formData.amount || 0);
    var context = hooks.useFormContext();
    var applePayRef = React.useRef(null);

    React.useEffect(function () {
      if (!settings.configured) {
        showError(t('notConfigured', 'The payment form is not configured.'));
        return;
      }
      mount().then(function (result) {
        if (!settings.applePay || !settings.applePay.enabled || applePayRef.current) {
          return;
        }
        PayarcHostedFields.applePay({
          client: result.client,
          targetDiv: APPLE_ID + '-button',
          displayName: settings.applePay.displayName,
          countryCode: settings.applePay.countryCode,
          currencyCode: formData.currency || 'USD',
          buttonType: 'donate',
          getAmount: function () {
            var el = document.getElementById(CONTAINER_ID);
            var current = Number(el && el.dataset.amount ? el.dataset.amount : 0);
            return current > 0 ? current.toFixed(2) : '0.00';
          },
          onKey: function (key) {
            var el = document.getElementById(CONTAINER_ID);
            if (el) {
              el.dataset.applePayKey = key;
            }
            var form = el && el.closest('form');
            if (form && form.requestSubmit) {
              form.requestSubmit();
            }
          },
          onError: showError,
          onCancel: function () { showError(''); }
        }).then(function (entry) {
          applePayRef.current = entry;
          var wrapper = document.getElementById(APPLE_ID);
          if (wrapper && entry) {
            wrapper.hidden = false;
          }
        });
      }).catch(function () { /* shown inline */ });
      return function () {
        mounted = null;
        applePayRef.current = null;
      };
    }, []);

    // Apple Pay is single-use: hide it for recurring gifts.
    var applePayHidden = isRecurring || !settings.applePay || !settings.applePay.enabled;

    return h('div', { className: 'payarc-givewp-fields' },
      h('div', { id: APPLE_ID, className: 'payarc-apple-pay', hidden: true, style: applePayHidden ? { display: 'none' } : undefined },
        h('div', { id: APPLE_ID + '-button', className: 'payarc-apple-pay-button' }),
        h('div', { className: 'payarc-apple-pay-divider' }, h('span', null, t('orCard', 'or enter card details')))
      ),
      h('div', { id: CONTAINER_ID, className: 'payarc-card-element', 'data-amount': amount, 'aria-label': 'Secure card details' }),
      h('div', { id: ERRORS_ID, className: 'payarc-card-errors', role: 'alert', 'aria-live': 'polite' }),
      h('p', { className: 'payarc-card-note' }, t('secureNote', 'Card details are entered securely in a form hosted by PayArc.')),
      settings.sandbox ? h('p', { className: 'payarc-card-note' }, t('sandboxNote', 'Sandbox mode.')) : null
    );
  }

  var gateway = {
    id: 'payarc',
    initialize: function () {
      settings = this.settings || {};
    },
    Fields: Fields,
    beforeCreatePayment: async function () {
      if (!settings.configured) {
        throw new Error(t('notConfigured', 'The payment form is not configured.'));
      }
      var pending = document.getElementById(CONTAINER_ID);
      var applePayKey = pending && pending.dataset.applePayKey;
      if (applePayKey) {
        pending.dataset.applePayKey = '';
        return { payarcPaymentKey: applePayKey };
      }
      var handles = await mount();
      var key = await PayarcHostedFields.tokenize(handles);
      showError('');
      return { payarcPaymentKey: key };
    }
  };

  window.givewp.gateways.register(gateway);
}(window));
