/* global PayarcHostedFields, React */
/**
 * GiveWP visual donation form gateway. Registers with window.givewp.gateways:
 * Fields() mounts the PayArc Hosted Fields, beforeCreatePayment() tokenizes
 * them into a single-use card token posted as gatewayData[payarcToken].
 */
(function (window) {
  'use strict';

  var h = React.createElement;
  var settings = {};
  var mounted = null;   // PayarcHostedFields handles
  var mountPromise = null;
  var CONTAINER_ID = 'payarc-givewp-card';
  var ERRORS_ID = 'payarc-givewp-errors';
  var WALLETS_ID = 'payarc-givewp-wallets';

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
      clientId: settings.clientId,
      scriptUrl: settings.scriptUrl,
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
    var walletsRef = React.useRef(null);
    var wallets = settings.wallets || [];

    React.useEffect(function () {
      if (!settings.configured) {
        showError(t('notConfigured', 'The payment form is not configured.'));
        return;
      }
      mount().then(function () {
        if (!wallets.length || walletsRef.current) {
          return;
        }
        var wrapper = document.getElementById(WALLETS_ID);
        PayarcHostedFields.wallets({
          clientId: settings.clientId,
          scriptUrl: settings.scriptUrl,
          targetDiv: wrapper && wrapper.querySelector('.payarc-wallet-buttons'),
          wallets: wallets,
          getAmount: function () {
            var el = document.getElementById(CONTAINER_ID);
            var current = Number(el && el.dataset.amount ? el.dataset.amount : 0);
            return current > 0 ? current.toFixed(2) : '0.00';
          },
          onKey: function (token) {
            var el = document.getElementById(CONTAINER_ID);
            if (el) {
              el.dataset.walletToken = token;
            }
            var form = el && el.closest('form');
            if (form && form.requestSubmit) {
              form.requestSubmit();
            }
          },
          onError: showError,
          onCancel: function () { showError(''); }
        }).then(function (row) {
          walletsRef.current = row;
          if (wrapper && row) {
            wrapper.hidden = false;
          }
        });
      }).catch(function () { /* shown inline */ });
      return function () {
        mounted = null;
        walletsRef.current = null;
      };
    }, []);

    // Wallet tokens cannot be saved: hide the buttons for recurring gifts.
    var walletsHidden = isRecurring || !wallets.length;

    return h('div', { className: 'payarc-givewp-fields' },
      h('div', { id: WALLETS_ID, className: 'payarc-wallets-wrapper', hidden: true, style: walletsHidden ? { display: 'none' } : undefined },
        h('div', { className: 'payarc-wallet-buttons' }),
        h('div', { className: 'payarc-wallet-divider' }, h('span', null, t('orCard', 'or enter card details')))
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
      var walletToken = pending && pending.dataset.walletToken;
      if (walletToken) {
        pending.dataset.walletToken = '';
        return { payarcToken: walletToken };
      }
      var handles = await mount();
      var token = await PayarcHostedFields.tokenize(handles);
      showError('');
      return { payarcToken: token };
    }
  };

  window.givewp.gateways.register(gateway);
}(window));
