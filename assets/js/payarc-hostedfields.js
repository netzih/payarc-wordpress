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

  // Sent into every iframe. PayArc's own defaults float the input at 55%
  // width beside a 25% label column; this makes the input fill its frame.
  // The inputs are borderless: the page draws one box around all four
  // fields, with dividers (payarc-payments.css), like a single card input.
  var DEFAULT_CSS = [
    'html, body, .payarc-body { margin: 0; padding: 0; background: transparent; overflow: hidden; }',
    '.payarc-all { box-sizing: border-box; }',
    '.payarc-label, .payarc-label-container { display: none; }',
    '.payarc-container, .payarc-row, .payarc-container-input, .payarc-input-container { float: none; width: 100%; margin: 0; padding: 0; background: transparent; }',
    '.payarc-row:after { content: none; }',
    '.payarc-input, .payarc-input:hover, .payarc-input:focus { display: block; width: 100%; height: 42px; margin: 0; padding: 0 12px; font-size: 16px; color: #2c3338;',
    '  border: 0; border-radius: 0; box-shadow: none; outline: none; background: transparent; box-sizing: border-box; }',
    '.payarc-input::placeholder { color: #8c8f94; }',
    '.payarc-input-error { color: #b32d2e; }',
    '.payarc-input-success, .payarc-input-default { color: #2c3338; }'
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
      // The CSS is for inside PayArc's iframes, which read this element's
      // text. Applied to this page too, its "html, body { overflow: hidden }"
      // stops the page from scrolling, so the page ignores it.
      style.media = 'not all';
      document.head.appendChild(style);
    }
    style.textContent = DEFAULT_CSS + (css ? '\n' + css : '');
  }

  /**
   * Browsers do not match :focus-within on the fields box while focus is
   * inside one of PayArc's cross-origin iframes, so the box gets the class
   * payarc-focused instead. Moving between two iframes fires nothing in
   * this page, so while the page itself has no focus this checks a few
   * times a second.
   */
  function trackFocus(box) {
    var timer = null;
    function update() {
      var active = document.activeElement;
      var inside = !!active && active.tagName === 'IFRAME' && box.contains(active);
      box.classList.toggle('payarc-focused', inside);
      if (!document.body.contains(box) || (document.hasFocus() && !inside)) {
        window.clearInterval(timer);
        timer = null;
      } else if (!timer) {
        timer = window.setInterval(update, 150);
      }
    }
    window.addEventListener('blur', function () { window.setTimeout(update, 0); });
    window.addEventListener('focus', update);
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
    // Seen: {"message": "..."} and, for a declined card check, a bare list
    // such as ["Invalid CVV"] (HTTP 409, sandbox 2026-09-24).
    var message = '';
    try {
      var body = JSON.parse(obj.response || obj.responseText || '{}');
      if (Array.isArray(body)) {
        message = body.filter(function (item) { return typeof item === 'string'; }).join(' ');
      } else if (typeof body === 'string') {
        message = body;
      } else if (body) {
        message = body.message || body.error || '';
      }
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
      trackFocus(fields);

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

  // Styles for the wallet window. PayArc prefixes every selector with its
  // wrapper and rejects CSS containing url(), content: or @import.
  var WALLET_WINDOW_CSS = [
    '.payarc-wallet-window { padding: 28px 20px; text-align: center; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1d2327; }',
    '.payarc-wallet-total { margin: 0 0 6px; font-size: 22px; font-weight: 600; }',
    '.payarc-wallet-hint { margin: 0 0 18px; font-size: 15px; color: #50575e; }',
    '#apple-pay-placeholder, #google-pay-placeholder { width: 100%; min-height: 48px; }'
  ].join('\n');

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * The page shown in PayArc's wallet window: the total, a hint to tap the
   * button, and the placeholder PayArc puts its wallet button into.
   */
  function walletWindowHtml(wallet, amount) {
    var placeholder = wallet === 'apple-pay' ? 'apple-pay-placeholder' : 'google-pay-placeholder';
    var hint = wallet === 'apple-pay'
      ? t('applePayHint', 'Tap the Apple Pay button to pay.')
      : t('googlePayHint', 'Tap the Google Pay button to pay.');
    return '<div class="payarc-wallet-window">'
      + '<p class="payarc-wallet-total">' + escapeHtml(t('walletTotal', 'Total') + ': $' + Number(amount).toFixed(2)) + '</p>'
      + '<p class="payarc-wallet-hint">' + escapeHtml(hint) + '</p>'
      + '<div id="' + placeholder + '"></div>'
      + '</div>';
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
            // PayArc's window shows a wallet button only where this HTML has
            // its placeholder div (id apple-pay-placeholder or
            // google-pay-placeholder); without it the window stays empty.
            html: walletWindowHtml(wallet, amount),
            css: WALLET_WINDOW_CSS,
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
