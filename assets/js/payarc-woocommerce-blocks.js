/* global PayarcHostedFields */
/**
 * WooCommerce block checkout payment method. Mounts the PayArc Hosted Fields
 * and, in onPaymentSetup, tokenizes them into paymentMethodData that
 * the Store API copies into $_POST for the gateway's process_payment().
 */
(function (window) {
  'use strict';

  var registry = window.wc.wcBlocksRegistry;
  var wcSettings = window.wc.wcSettings;
  var element = window.wp.element;
  var h = element.createElement;
  var decode = window.wp.htmlEntities.decodeEntities;
  var settings = wcSettings.getPaymentMethodData ? wcSettings.getPaymentMethodData('payarc', {}) : wcSettings.getSetting('payarc_data', {});
  var CONTAINER_ID = 'payarc-wc-blocks-card';
  var ERRORS_ID = 'payarc-wc-blocks-errors';
  var WALLETS_ID = 'payarc-wc-blocks-wallets';
  var mounted = null;

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
    return PayarcHostedFields.mount({
      clientId: settings.clientId,
      scriptUrl: settings.scriptUrl,
      container: container,
      onFieldError: showError
    }).then(function (result) {
      mounted = result;
      return result;
    }).catch(function (error) {
      mounted = null;
      showError(PayarcHostedFields.errorText(error));
      throw error;
    });
  }

  function Content(props) {
    var eventRegistration = props.eventRegistration;
    var emitResponse = props.emitResponse;
    var billing = props.billing || {};
    var walletToken = element.useRef('');

    element.useEffect(function () {
      if (!settings.configured) {
        showError(t('notConfigured', 'The payment form is not configured.'));
        return;
      }
      mount().then(function () {
        var wrapper = document.getElementById(WALLETS_ID);
        if (!wrapper || !settings.wallets || !settings.wallets.length) {
          return;
        }
        PayarcHostedFields.wallets({
          clientId: settings.clientId,
          scriptUrl: settings.scriptUrl,
          targetDiv: wrapper.querySelector('.payarc-wallet-buttons'),
          wallets: settings.wallets,
          getAmount: function () {
            var total = billing.cartTotal ? Number(billing.cartTotal.value) : 0;
            var minor = billing.currency && typeof billing.currency.minorUnit === 'number' ? billing.currency.minorUnit : 2;
            var amount = total / Math.pow(10, minor);
            return amount > 0 ? amount.toFixed(2) : '0.00';
          },
          onKey: function (token) {
            walletToken.current = token;
            if (props.onSubmit) {
              props.onSubmit();
            }
          },
          onError: showError,
          onCancel: function () { showError(''); }
        }).then(function (row) {
          if (row) {
            wrapper.hidden = false;
          }
        });
      }).catch(function () { /* shown inline */ });
      return function () { mounted = null; };
    }, []);

    element.useEffect(function () {
      var unsubscribe = eventRegistration.onPaymentSetup(async function () {
        try {
          var token = walletToken.current;
          walletToken.current = '';
          if (!token) {
            if (!settings.configured) {
              throw new Error(t('notConfigured', 'The payment form is not configured.'));
            }
            var handles = await mount();
            token = await PayarcHostedFields.tokenize(handles);
          }
          showError('');
          return {
            type: emitResponse.responseTypes.SUCCESS,
            meta: {
              paymentMethodData: {
                payarc_token: token,
                'wc-payarc-payment-token': 'new'
              }
            }
          };
        } catch (error) {
          var text = PayarcHostedFields.errorText(error);
          showError(text);
          return {
            type: emitResponse.responseTypes.ERROR,
            message: text,
            messageContext: emitResponse.noticeContexts.PAYMENTS
          };
        }
      });
      return unsubscribe;
      // Stable references only: the props objects themselves change every render.
    }, [eventRegistration.onPaymentSetup, emitResponse.responseTypes, emitResponse.noticeContexts]);

    return h('div', { className: 'payarc-wc-fields' },
      settings.description ? h('p', null, decode(settings.description)) : null,
      h('div', { id: WALLETS_ID, className: 'payarc-wallets-wrapper', hidden: true },
        h('div', { className: 'payarc-wallet-buttons' }),
        h('div', { className: 'payarc-wallet-divider' }, h('span', null, t('orCard', 'or enter card details')))
      ),
      h('div', { id: CONTAINER_ID, className: 'payarc-card-element', 'aria-label': 'Secure card details' }),
      h('div', { id: ERRORS_ID, className: 'payarc-card-errors', role: 'alert', 'aria-live': 'polite' }),
      h('p', { className: 'payarc-card-note' }, t('secureNote', 'Card details are entered securely in a form hosted by PayArc.')),
      settings.sandbox ? h('p', { className: 'payarc-card-note' }, t('sandboxNote', 'Sandbox mode.')) : null
    );
  }

  function Label(props) {
    var PaymentMethodLabel = props.components.PaymentMethodLabel;
    return h(PaymentMethodLabel, { text: decode(settings.title || 'Credit Card') });
  }

  registry.registerPaymentMethod({
    name: 'payarc',
    paymentMethodId: 'payarc',
    label: h(Label, null),
    ariaLabel: decode(settings.title || 'Credit Card'),
    content: h(Content, null),
    edit: h(Content, null),
    savedTokenComponent: null,
    canMakePayment: function () { return !!settings.configured; },
    supports: {
      features: settings.supports || ['products'],
      showSavedCards: !!settings.showSavedCards,
      showSaveOption: !!settings.showSaveOption
    }
  });
}(window));
