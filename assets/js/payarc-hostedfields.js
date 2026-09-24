/* global initPayarcTokenizer, getPayarcToken, initWalletPayment */
/**
 * Framework-free helper around PayArc Hosted Fields (iframeprocess.js). Each
 * module (Gravity Forms, GiveWP, WooCommerce) uses it to load the script
 * once, mount the hosted card fields, turn them into a single-use token on
 * submit, and offer Apple Pay / Google Pay for one-time payments. Nothing
 * here knows about the host plugin.
 *
 * PayArc's script is a set of globals around one shared XMLHttpRequest, so:
 * - it is loaded once per page (it declares top-level constants);
 * - one card form is active at a time: mounting another re-initializes the
 *   tokenizer for the new fields;
 * - it needs a <style id="payarc-styles"> (the CSS it hands the iframes) and
 *   an element holding the session uuid, both created here;
 * - its wallet popup adds a message listener on every attempt and never
 *   removes it, so each attempt here only accepts the first token of its own.
 */
(function (window, document) {
  'use strict';

  var loading = null;
  var active = null;
  var pending = null;
  var walletAttempt = 0;

  var FIELDS = [
    { type: 'CARD_NUMBER', key: 'number', placeholder: 'cardNumber', fallback: 'Card number' },
    { type: 'EXP', key: 'exp', placeholder: 'expiry', fallback: 'MM/YY' },
    { type: 'CVV', key: 'cvv', placeholder: 'cvv', fallback: 'CVV' },
    { type: 'ZIP', key: 'zip', placeholder: 'zip', fallback: 'ZIP' }
  ];

  var DEFAULT_CSS = [
    '.payarc-label { display: none; }',
    '.payarc-all { box-sizing: border-box; }',
    '.payarc-input { width: 100%; height: 40px; padding: 8px 10px; font-size: 16px; color: #2c3338;',
    '  border: 1px solid #8c8f94; border-radius: 4px; background: #fff; box-sizing: border-box; }',
    '.payarc-input:focus { outline: 2px solid #2271b1; outline-offset: -1px; }',
    '.payarc-input-error { border-color: #b32d2e; color: #b32d2e; }',
    '.payarc-input-success { border-color: #8c8f94; }',
    '.payarc-container, .payarc-row, .payarc-input-container { margin: 0; padding: 0; background: transparent; }'
  ].join('\n');

  function t(key, fallback) {
    var strings = (window.PayarcHostedFieldsConfig && window.PayarcHostedFieldsConfig.i18n) || {};
    return strings[key] || fallback;
  }

  /**
   * Reword PayArc's field and tokenization errors for a payer.
   */
  function payerWording(message) {
    var m = String(message || '').toLowerCase();
    if (!m) {
      return t('unableToValidate', 'Unable to validate the card.');
    }
    if (/expir|exp\b|date/.test(m)) {
      return t('checkExpiry', 'Please check the expiration date (MM/YY).');
    }
    if (/cvv|cvc|security|card code/.test(m)) {
      return t('checkCvv', 'Please check the security code (the 3 or 4 digit CVV).');
    }
    if (/zip|postal/.test(m)) {
      return t('checkZip', 'Please check the billing ZIP code.');
    }
    if (/required|length|invalid|card number|luhn|information/.test(m)) {
      return t('checkCard', 'Please check the card number, expiration date and security code.');
    }
    if (/client id|forbidden|403|unauthori|not allowed/.test(m)) {
      return t('misconfigured', 'The payment form is not configured correctly, so no charge was made. Please contact us.');
    }
    if (/expired|session|419|408/.test(m)) {
      return t('sessionExpired', 'The card form timed out. Please enter your card details again.');
    }
    if (/network|timeout|failed to fetch|unavailable|gateway/.test(m)) {
      return t('noResponse', 'The card processor did not respond. Please wait a moment and try again.');
    }
    return String(message);
  }

  function errorText(error) {
    if (!error) {
      return '';
    }
    if (typeof error === 'string') {
      return error;
    }
    return typeof error.message === 'string' ? error.message : String(error);
  }

  function ready() {
    return typeof window.initPayarcTokenizer === 'function' && typeof window.getPayarcToken === 'function';
  }

  /**
   * Load iframeprocess.js once. It reads its own src to find the portal, so
   * it must arrive as a classic script tag.
   */
  function load(url) {
    if (ready()) {
      return Promise.resolve();
    }
    if (loading) {
      return loading;
    }
    loading = new Promise(function (resolve, reject) {
      var script = document.querySelector('script[data-payarc-hostedfields]') || document.querySelector('script[src="' + url + '"]');
      if (!script) {
        script = document.createElement('script');
        script.src = url;
        script.dataset.payarcHostedfields = '1';
        document.head.appendChild(script);
      }
      var attempts = 0;
      var poll = window.setInterval(function () {
        if (ready()) {
          window.clearInterval(poll);
          resolve();
        } else if (++attempts > 150) {
          window.clearInterval(poll);
          reject(new Error(t('loadFailed', 'The secure PayArc card form could not be loaded.')));
        }
      }, 100);
      script.addEventListener('error', function () {
        window.clearInterval(poll);
        reject(new Error(t('loadFailed', 'The secure PayArc card form could not be loaded.')));
      });
    });
    loading.catch(function () { loading = null; });
    return loading;
  }

  /**
   * The <style id="payarc-styles"> PayArc sends into its iframes. Module CSS
   * (options.css) is appended to the defaults.
   */
  function ensureStyles(css) {
    var style = document.getElementById('payarc-styles');
    if (!style) {
      style = document.createElement('style');
      style.id = 'payarc-styles';
      document.head.appendChild(style);
    }
    style.textContent = DEFAULT_CSS + (css ? '\n' + css : '');
  }

  function settle(result, error) {
    var waiting = pending;
    pending = null;
    if (!waiting) {
      return;
    }
    window.clearTimeout(waiting.timer);
    if (error) {
      waiting.reject(error);
    } else {
      waiting.resolve(result);
    }
  }

  function tokenError(obj) {
    var status = obj && obj.status;
    var message = '';
    try {
      var body = JSON.parse(obj.response || obj.responseText || '{}');
      message = body && (body.message || body.error) || '';
    } catch (ignored) {
      message = '';
    }
    var wording;
    if (status === 403) {
      wording = t('misconfigured', 'The payment form is not configured correctly, so no charge was made. Please contact us.');
    } else if (status === 408 || status === 419) {
      wording = t('sessionExpired', 'The card form timed out. Please enter your card details again.');
    } else if (status === 409) {
      wording = message ? payerWording(message) : t('noResponse', 'The card processor did not respond. Please wait a moment and try again.');
    } else {
      wording = payerWording(message || 'invalid');
    }
    var error = new Error(wording);
    error.raw = status + ' ' + (message || (obj && obj.statusText) || '');
    error.expired = status === 408 || status === 419;
    return error;
  }

  /**
   * Mount the hosted card fields into a container.
   *
   * @param {Object} options
   *   clientId, scriptUrl, container (element or id), onFieldError(text|''),
   *   css (optional extra CSS for the iframes), placeholders (optional).
   * @return {Promise<Object>} handles for tokenize()
   */
  function mount(options) {
    var container = typeof options.container === 'string' ? document.getElementById(options.container) : options.container;
    if (!container) {
      return Promise.reject(new Error('PayArc: card container not found.'));
    }
    if (!options.clientId) {
      return Promise.reject(new Error(t('misconfigured', 'The payment form is not configured correctly, so no charge was made. Please contact us.')));
    }
    var base = 'payarc-f' + Math.random().toString(36).slice(2, 8);
    var placeholders = options.placeholders || {};

    return load(options.scriptUrl).then(function () {
      ensureStyles(options.css);
      container.innerHTML = '';
      container.id = container.id || base;
      var fields = document.createElement('div');
      fields.id = base + '-fields';
      fields.className = 'payarc-fields';
      var ids = {};
      FIELDS.forEach(function (field) {
        var el = document.createElement('div');
        el.id = base + '-' + field.key;
        el.className = 'payarc-field payarc-field-' + field.key;
        el.setAttribute('data-payarc', field.type);
        el.setAttribute('data-placeholder', placeholders[field.key] || t(field.placeholder, field.fallback));
        fields.appendChild(el);
        ids[field.key] = el.id;
      });
      var status = document.createElement('div');
      status.id = base + '-status';
      status.hidden = true;
      var initiate = document.createElement('button');
      initiate.type = 'button';
      initiate.id = base + '-initiate';
      initiate.hidden = true;
      initiate.tabIndex = -1;
      container.appendChild(fields);
      container.appendChild(status);
      container.appendChild(initiate);

      var handles = { container: container, fields: fields, initiate: initiate, ids: ids, card: null };

      // Field state arrives as data-validation="error|success" on each field.
      var observer = new window.MutationObserver(function () {
        if (!options.onFieldError) {
          return;
        }
        var bad = FIELDS.filter(function (field) {
          return document.getElementById(ids[field.key]).dataset.validation === 'error';
        });
        if (!bad.length) {
          options.onFieldError('');
          return;
        }
        var key = bad[0].key;
        options.onFieldError(key === 'exp' ? payerWording('expiry') : key === 'cvv' ? payerWording('cvv') : key === 'zip' ? payerWording('zip') : payerWording('card number'));
      });
      FIELDS.forEach(function (field) {
        observer.observe(document.getElementById(ids[field.key]), { attributes: true, attributeFilter: ['data-validation'] });
      });

      window.initPayarcTokenizer(options.clientId, {
        FORM_STATUS: status.id,
        INITIATE_PAYMENT: initiate.id,
        FIELDS_CONTAINER: fields.id,
        TOKEN_CALLBACK: {
          success: function (obj) {
            var body;
            try {
              body = JSON.parse(obj.response);
            } catch (e) {
              body = null;
            }
            if (!body || !body.token) {
              settle(null, new Error(t('noKey', 'PayArc did not return a card token.')));
              return;
            }
            handles.card = body.card || null;
            settle(String(body.token));
          },
          error: function (obj) {
            settle(null, tokenError(obj));
          }
        }
      });
      active = handles;
      return handles;
    });
  }

  /**
   * Turn the entered card into a single-use token.
   *
   * @return {Promise<string>}
   */
  function tokenize(handles) {
    if (!handles || handles !== active) {
      return Promise.reject(new Error(t('reload', 'The card form was reset. Please enter your card details again.')));
    }
    if (!handles.initiate.dataset.uuid) {
      return Promise.reject(new Error(t('enterCard', 'Please enter your card details.')));
    }
    if (pending) {
      return Promise.reject(new Error(t('busy', 'Please wait, your card is being checked.')));
    }
    return new Promise(function (resolve, reject) {
      pending = {
        resolve: resolve,
        reject: reject,
        timer: window.setTimeout(function () {
          settle(null, new Error(t('noResponse', 'The card processor did not respond. Please wait a moment and try again.')));
        }, 30000)
      };
      window.getPayarcToken(handles.initiate);
    });
  }

  /**
   * Apple Pay / Google Pay buttons for one-time payments. PayArc opens its
   * own window for the wallet sheet (no Apple merchant setup on this site)
   * and hands back a single-use token.
   *
   * @param {Object} options
   *   clientId, targetDiv (element or id), wallets (['apple-pay',
   *   'google-pay']), getAmount() -> "12.34" or "0.00", onKey(token),
   *   onError(text), onCancel(), onOpen().
   * @return {Promise<HTMLElement|null>} the button row, or null if none.
   */
  function wallets(options) {
    var target = typeof options.targetDiv === 'string' ? document.getElementById(options.targetDiv) : options.targetDiv;
    var wanted = (options.wallets || []).filter(function (wallet) {
      return wallet === 'google-pay' || (wallet === 'apple-pay' && window.ApplePaySession);
    });
    if (!target || !wanted.length || !options.clientId) {
      return Promise.resolve(null);
    }
    return load(options.scriptUrl).then(function () {
      if (typeof window.initWalletPayment !== 'function') {
        return null;
      }
      target.innerHTML = '';
      var row = document.createElement('div');
      row.className = 'payarc-wallets';
      wanted.forEach(function (wallet) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'payarc-wallet-button payarc-wallet-' + wallet;
        button.textContent = wallet === 'apple-pay' ? t('applePay', 'Pay with Apple Pay') : t('googlePay', 'Pay with Google Pay');
        button.addEventListener('click', function (event) {
          event.preventDefault();
          var amount = String(options.getAmount() || '0.00');
          var cents = Math.round(parseFloat(amount) * 100);
          if (!cents || cents < 0) {
            options.onError(t('chooseAmount', 'Please choose an amount before paying with a wallet.'));
            return;
          }
          var attempt = ++walletAttempt;
          var delivered = false;
          window.initWalletPayment({
            amount: cents,
            api_key: options.clientId,
            selectedWallet: wallet,
            enabledWallets: [wallet],
            windowWidth: 420,
            windowHeight: 520,
            onWindowOpened: function () {
              if (attempt === walletAttempt && options.onOpen) { options.onOpen(); }
            },
            onWindowClosed: function () {
              if (attempt === walletAttempt && !delivered && options.onCancel) { options.onCancel(); }
            },
            onTokenReceived: function (token) {
              // Older attempts' listeners fire too; only this one's first token counts.
              if (attempt !== walletAttempt || delivered || !token) {
                return;
              }
              delivered = true;
              options.onKey(String(token));
            }
          });
        });
        row.appendChild(button);
      });
      target.appendChild(row);
      return row;
    }, function () {
      return null;
    });
  }

  window.PayarcHostedFields = {
    load: load,
    mount: mount,
    tokenize: tokenize,
    wallets: wallets,
    payerWording: payerWording,
    errorText: errorText
  };
}(window, document));
