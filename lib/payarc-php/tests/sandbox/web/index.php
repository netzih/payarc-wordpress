<?php
/**
 * Hosted Fields harness: mint a real browser token and run it through the
 * library. Serve with:
 *
 *   php -S localhost:8765 -t tests/sandbox/web
 *
 * and open http://localhost:8765. Sandbox only.
 */
require_once dirname(__DIR__) . '/lib.php';
$env = payarc_env();
$clientId = $env['PAYARC_CLIENT_ID'] ?? '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PayArc Hosted Fields probe</title>
  <style>
    body { font: 15px/1.4 system-ui, sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 16px; }
    #card-token-container > div { height: 60px; margin-bottom: 8px; }
    button { padding: .6rem 1rem; margin: .25rem .25rem .25rem 0; }
    pre { background: #f4f4f4; padding: 1rem; overflow: auto; white-space: pre-wrap; }
  </style>
  <style id="payarc-styles">
    .payarc-label { display: none; }
    .payarc-input { width: 100%; padding: 10px; border: 1px solid #999; border-radius: 4px; font-size: 15px; }
    .payarc-input-error { border-color: #c00; }
    .payarc-input-success { border-color: #080; }
  </style>
  <script src="https://testportal.payarc.net/js/iframeprocess.js" defer></script>
</head>
<body>
  <h1>Hosted Fields probe</h1>
  <p>Sandbox card: 4012 0000 9876 5439, 12/29, CVV 999, ZIP 85284.</p>
  <?php if ($clientId === ''): ?><p><strong>PAYARC_CLIENT_ID is empty in the env file.</strong></p><?php endif; ?>
  <div id="card-token-container">
    <div id="credit-card-number" data-payarc="CARD_NUMBER" data-placeholder="Card Number"></div>
    <div id="credit-card-exp" data-payarc="EXP" data-placeholder="MM/YY"></div>
    <div id="credit-card-cvv" data-payarc="CVV" data-placeholder="CVV"></div>
    <div id="credit-card-zip" data-payarc="ZIP" data-placeholder="ZIP"></div>
  </div>
  <div id="form-status"></div>
  <p>
    <button type="button" data-action="charge">Token → charge (then void)</button>
    <button type="button" data-action="save">Token → save card → charge saved card without CVV</button>
  </p>
  <button type="button" id="initiate-payment" hidden>tokenize</button>
  <pre id="out">Waiting…</pre>
  <script>
    const out = document.getElementById('out');
    let pendingAction = null;
    const log = (label, value) => { out.textContent = label + '\n' + (typeof value === 'string' ? value : JSON.stringify(value, null, 2)); };

    const PAYARC_SETTINGS = {
      FORM_STATUS: 'form-status',
      INITIATE_PAYMENT: 'initiate-payment',
      FIELDS_CONTAINER: 'card-token-container',
      TOKEN_CALLBACK: {
        success: async (obj) => {
          let parsed;
          try { parsed = JSON.parse(obj.response); } catch (e) { parsed = obj.response; }
          log('Token response (running ' + pendingAction + '…)', parsed);
          const res = await fetch('token.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: pendingAction, tokenResponse: parsed }),
          });
          log('Result of ' + pendingAction, await res.text());
        },
        error: (obj) => log('Tokenization error', { status: obj.status, statusText: obj.statusText, response: obj.response }),
      },
    };

    window.addEventListener('load', () => {
      initPayarcTokenizer(<?= json_encode($clientId) ?>, PAYARC_SETTINGS);
      log('Ready', 'Enter the card, then press a button.');
    });

    document.querySelectorAll('button[data-action]').forEach((button) => {
      button.addEventListener('click', () => {
        pendingAction = button.dataset.action;
        getPayarcToken(document.getElementById('initiate-payment'));
      });
    });
  </script>
</body>
</html>
