# payarc-php

Framework-free PHP client for the PayArc API v1, shared by the CiviCRM
`payarcjs` extension and the `payarc-wordpress` plugin (Gravity Forms,
GiveWP, WooCommerce). It is the PayArc counterpart of `usaepay-php` and keeps
its conventions: decimal-string amounts, the same exception contract, and
payer-facing wording kept apart from the gateway's text.

Card details are entered in PayArc's Hosted Fields iframes. The browser gets
a single-use token using the public **Client ID**; this library charges or
saves that token server-side using the secret **bearer token**.

- `Payarc\GatewayClient`:
  - `chargeToken()`: charge a Hosted Fields or wallet token.
  - `saveCard()`: save a token as a reusable card, one PayArc customer per
    card. Returns a `CardReference` (`customer_id:card_id`).
  - `chargeCard()`: charge a saved card (`recurring` sets the recurring ECI).
  - `verifyCard()`: $1 authorization, voided at once.
  - `void()` and `refund()`, plus `reverse()`, which refunds a settled
    charge and voids an unsettled one.
  - `getCharge()`, `listCharges()` and `findChargeByReference()` for lost
    responses.
  - `deleteCard()` and `verifyCredentials()`.
- `Payarc\Charge`: reads a charge response:
  - `outcome()` returns approved, declined, partial, reversed or unknown.
  - `id()`, `remainingCents()`, `metadata()` and `createdTime()`.
- `Payarc\DonorMessage`: payer wording for PayArc's TSYS (`D2012`) and Payfac
  (`DECLINED-051`) codes and texts. Inject a translator with
  `setTranslator()`.
- `Payarc\CardDetails`: brand (from PayArc's V/M/X/R/J codes), last four,
  expiry and whether the card is verified.
- `Payarc\CardReference` and `Payarc\Amount`: small value helpers.

## Conventions every caller gets

- **No PayArc receipts.** PayArc emails *and texts* the payer unless told
  not to. Every charge and refund sends `do_not_send_email_to_customer` and
  `do_not_send_sms_to_customer` = `yes`. The host application sends its own
  receipts.
- **Idempotency.** Pass `reference` with every charge. It becomes the
  `Idempotency-Key` header and is stored in metadata as `reference`, so a
  lost response can be settled in two ways:
  - Resend the identical request. PayArc returns the original result.
  - Look it up with `findChargeByReference()`.
- **Exceptions**, the same contract as `usaepay-php`:
  - `AmbiguousGatewayException`: sent, no reliable answer (5xx, timeout,
    408, 429, unreadable). Reconcile; never treat it as a decline.
  - `GatewayException`: a definite refusal. Nothing happened.
  - `ReconciliationInconclusiveException`: a lookup could not prove a miss.
    Do not charge.
  - `UnsettledPartialRefundException`: a partial refund of a charge that has
    not settled yet. Nothing was sent.
- **Declines come two ways.** An HTTP error (`GatewayException`), or a 2xx
  charge with `failure_code` set. Always pass the response through
  `Charge::outcome()`. `unknown` (including duplicate codes D0001/D0008)
  must be handled like an ambiguous result.
- USD only. Amounts go to PayArc as integer cents.

## Verified vs assumed

The PayArc docs leave several behaviours open. `docs/PLAN.md` lists them,
and the sandbox probes settle them:

```sh
# fill PAYARC_BEARER_TOKEN and PAYARC_CLIENT_ID in ~/.config/payarc/sandbox.env
php bin/check-credentials.php                   # lists one charge, charges nothing
php tests/sandbox/probe.php                     # server-token experiments, report in tests/sandbox/reports/
php -S localhost:8765 -t tests/sandbox/web      # Hosted Fields token experiments in a browser
```

## Development

```sh
composer install && vendor/bin/phpunit
```

Sandbox test cards (TSYS cert, exp 12/29): Visa 4012000098765439 CVV 999,
MasterCard 5146315000000055 CVV 998, Amex 371449635392376 CVV 9997, Discover
6011000993026909 CVV 996. AVS: address 8320, ZIP 85284.

API reference: <https://docs.payarc.net/> (append `.md` to any page for the
markdown source; index at `https://docs.payarc.net/llms.txt`).
