# Handoff: `payarcjs`, the PayArc CiviCRM extension (Phase 3)

Written 2026-09-24 at the end of the session that built `payarc-php` and
`payarc-wordpress`. Read this first, then `docs/PLAN.md` (decisions, and
every PayArc behaviour verified in the sandbox). After that, read the
`payarc-wordpress` README ("How PayArc differs" and "Charge at most once").
It is the reference implementation of every pattern below.

## Goal

A PayArc payment processor for CiviCRM 6.18. It is a fork of
`~/dev/usaepayjs`, the USAePay Pay.js extension, and should offer the same
features:
- one-time card payments;
- CiviCRM-managed recurring, charged by cron against saved cards;
- Change Billing Details (card replacement);
- full and partial refunds;
- Apple Pay / Google Pay for one-time gifts;
- the System Status checks;
- the failed-payment email.

The **transaction importer is out of scope** for v1; it is deferred by
decision.

Create it at `~/dev/payarcjs`:
- Extension key `org.chabadrichmond.payarcjs`, file `payarcjs`, class
  `CRM_Core_Payment_Payarcjs`, processor type name `PayArcHostedFields`.
- Rename every `usaepayjs`, `Usaepayjs`, `USAePay` and `usaepay` name,
  setting, template, job and message template, so both extensions can be
  enabled on one site.
- Start from the tracked files only (`git archive HEAD`). Commit the
  mechanical rename on its own first, the way `payarc-wordpress` did
  (commit `3f01a63` there).

## Environment

- Local CiviCRM Standalone at http://localhost:8000. It is served by
  `php -S localhost:8000` from `~/dev/civicrm-standalone`, with MariaDB
  running. Admin credentials are in `~/.config/usaepayjs/standalone.env`.
  `cv` is `php ~/bin/cv`, run from `~/dev/civicrm-standalone`.
- Extensions live in `~/dev/civicrm-standalone/ext/` as symlinks:
  `usaepayjs -> ~/dev/usaepayjs`, plus `mjwshared`, which is required.
  Symlink `payarcjs` there the same way.
- PayArc sandbox credentials are in `~/.config/payarc/sandbox.env`
  (`PAYARC_BEARER_TOKEN`, `PAYARC_CLIENT_ID`). The sandbox API is
  `https://testapi.payarc.net/v1`; the portal (Hosted Fields) is
  `https://testportal.payarc.net`.
- The USAePay extension's browser tests are in `~/.config/usaepayjs/browser/`
  (`browser-test.mjs` drives the Standalone site, logging in through
  `cv url --login`). The PayArc WordPress tests are in
  `~/.config/payarc/browser/`; `lib.mjs` has `fillCard()` for PayArc's four
  iframes and the TSYS test cards. Put the CiviCRM ones in
  `~/.config/payarc/browser/civi-*.mjs`.
- Production is CiviCRM on WordPress at jewish-richmond.com. The PayArc
  WordPress plugin 0.1.1 is live there (GiveWP), and Apple Pay was confirmed
  working on 2026-09-24.

## Library

Bundle `payarc-php` rather than copying classes. `usaepayjs` kept its own
copy of the client, and the copy drifted from `usaepay-php`. Options:
- `composer.json` with a path or VCS repository and a committed `vendor/`,
  loaded from `payarcjs.php`;
- a git subtree at `lib/payarc-php` plus a small PSR-4 autoloader for
  `Payarc\`. This is what `payarc-wordpress` does:

  ```
  git subtree add --prefix lib/payarc-php ~/dev/payarc-php main --squash
  ```

The library already has everything the extension needs:
- `chargeToken`, `chargeCard`, `saveCard`, `verifyCard`, `refund`,
  `reverse`, `void`, `getCharge`, `findChargeByReference`, `deleteCard`,
  `verifyCredentials` and `verifyClientId`;
- `Charge::outcome()`, `DonorMessage` (inject `ts()`), `CardDetails`,
  `CardReference` and `Amount`.

Change the library in `~/dev/payarc-php` (with tests: `vendor/bin/phpunit`),
then pull it into the subtree.

## What carries over unchanged from `usaepayjs`

These pieces are generic CiviCRM plumbing (file:line refer to `usaepayjs`):
- Pre-approval, which carries the token across the confirm page:
  `doPreApproval` / `getPreApprovalDetails`, `CRM/Core/Payment/Usaepayjs.php:69-83`.
- `paymentTokenFromRequest` :90, the supports flags :329-347,
  `doCancelRecurring` :349 and `changeSubscriptionAmount` :368-387.
- `cardDetailsForCivi` :511; `storeReusableToken` :811 (PaymentToken plus
  recur link), which here stores the `customer_id:card_id` reference;
  `markRecurringUnusable` :862; `resolvePaymentToken` :622.
- The exception builders :732-809: donor wording for everyone, and
  "Gateway response: …" for staff.
- `CRM/Usaepayjs/RecurringProcessor.php`, which is almost entirely generic:
  - locking and the due query;
  - `repeattransaction` before charging, and the deterministic invoice ID
    from `Schedule::invoiceID`;
  - `recordPayment`, and `recordFailure` (3 tries, 3 days apart);
  - `notifyDonor`, `stopSeries` and `advanceSchedule`.
- `Schedule.php`, except the `usaepayjs-` prefix and `expiryDate`, which
  expects MMYY; PayArc gives `exp_month` / `exp_year`.
- The mjwshared `CRM.payment` glue in `js/usaepayjs.js`: script loading for
  AJAX forms, `selectedProcessorIsOurs`, `shouldHandlePayment`, the submit
  handlers, `setPaymentToken` and `submitForm`.
- The managed job and the failed-payment workflow message templates
  (`managed/usaepayjs.mgd.php:5-87, 113`), renamed.

## What must be rewritten, and how

1. **Processor type** (`managed/*.mgd.php`):
   - `user_name` holds the **API bearer token** (secret). `signature` holds
     the **Client ID** (public, sent to the browser). `password` and
     `subject` are unused.
   - `url_site_default` is `https://api.payarc.net/v1`;
     `url_site_test_default` is `https://testapi.payarc.net/v1`.
   - The field help in `templates/CRM/Admin/Page/PaymentProcessor.extra.hlp`
     should say where to find these: PayArc dashboard, API, eye icon.
2. **`doPayment`**:
   - A one-off gift calls `chargeToken($token, $amount, [...])`.
   - A recurring signup calls `saveCard()` **first**, then `chargeCard()`
     on the saved card; a token is single-use. Store the reference with
     `storeReusableToken`.
   - Charging a stored `payment_token_id` is `chargeCard($reference, ...,
     ['recurring' => TRUE])`.
   - Pass the invoice ID as `reference`. It becomes the Idempotency-Key and
     the metadata `reference`. Use `invoice` for the contribution ID, and
     `description` for the page title (it is sent as `charge_description`).
     Put the contact ID in `metadata`.
   - `trxn_id` is the PayArc charge id (`Charge::id()`). There is no refnum.
3. **`assertApproved`**: use `Charge::outcome()`.
   - `APPROVED` means success. `DECLINED` means a donor-facing decline via
     `DonorMessage`.
   - `PARTIAL` means void it and treat it as a decline.
   - `UNKNOWN`, which includes D0001/D0008 "duplicate (approved
     previously)", means `PAYMENT_AMBIGUOUS`. Never treat it as a decline.
   - A decline can come as an HTTP error (`GatewayException`) or as a 2xx
     charge with `failure_code` and status "Declined".
   - Drop the `V` (3-D Secure) handling: there is no equivalent here.
4. **Lost responses** (`RecurringProcessor::reconcile` :215 and
   `findTransactionByOrderId`):
   - Resend with the same invoice ID. PayArc answers a repeated
     Idempotency-Key with the original charge, **even if the body differs**
     (verified). So resend the identical request.
   - Resend within an hour (key retention is not documented). After that,
     use `findChargeByReference($invoiceId, $sentAt, 5, $amount)`, which is
     conclusive back to the send time because `created_at` is a Unix time.
   - The recurring invoice ID already includes the date and attempt, so it
     is the right key as it stands. For one-off gifts, a retry after a
     **decline** needs a new key, or PayArc replays the decline. The WP
     plugin appends a random suffix per attempt and stores it in its marker
     (see `payarc-wordpress/src/Reconcile.php`).
5. **Refunds** (`doRefund` :254). **This is the dangerous one.** PayArc
   does not refuse a refund of an unsettled charge as documented. It
   **voids the whole charge**, whatever amount was asked for. Use
   `GatewayClient::reverse()` or `refund()`:
   - they read the charge first;
   - they send a partial amount only when `Charge::isSettled()`;
   - they throw `UnsettledPartialRefundException` otherwise;
   - they report `action` `'void'` or `'refund'`.

   Show staff the same message as the WP plugin. A refund description must
   be at least 5 characters (the library pads short ones). Record the refund
   in CiviCRM with the sale's charge id: PayArc refunds have no id of their
   own.
6. **Change Billing Details** (`updateSubscriptionBillingInfo` :398):
   `saveCard()` for the new token, optionally `verifyCard()` (a $1
   authorization voided at once), then store the new reference. Saved cards
   are `is_verified=1` and charge without a CVV (verified with both server
   and Hosted Fields tokens).
7. **Card entry JS** (`js/usaepayjs.js`, the `mountCardEntry` and tokenize
   parts):
   - Port `payarc-wordpress/assets/js/payarc-hostedfields.js`. Copy it, or
     share it. It already absorbs PayArc's quirks:
     - the script defines globals and must load once;
     - there is one tokenizer per page;
     - it needs `<style id="payarc-styles">` and a hidden "initiate"
       element;
     - token errors can be a bare JSON list (`409 ["Invalid CVV"]`);
     - wallet windows need placeholder HTML (next item).
   - Keep the CSS from `payarc-wordpress/assets/css/payarc-payments.css`.
     PayArc puts `style="all: inherit"` on its iframes, so our sizing needs
     `!important`.
   - Mark the hidden `payment_token` with a `payarc:` prefix, as
     `usaepayjs` does with `payjs:`.
8. **Apple Pay / Google Pay**:
   - Use `PayarcHostedFields.wallets()`. It opens PayArc's window at
     `checkout-lite.payarc.net/wallets/<Client ID>/`. The window **only
     shows a wallet button if the HTML sent to it contains
     `<div id="apple-pay-placeholder">` or `<div id="google-pay-placeholder">`**
     (this was the live bug fixed in 0.1.1). The amount is sent in cents.
   - Offer wallets on one-time gifts only: their tokens cannot be saved.
   - PayArc's window returns no billing contact, so the USAePay
     `fillBillingFromApplePay` has nothing to fill. Drop it.
   - The Apple Pay sheet says "Payarc" and cannot be changed.
   - Wanted, but not yet done in WP either: when the browser can do Apple
     Pay, show only Apple Pay.
9. **Settings** (`settings/*.setting.php`): drop `import_*` (the importer is
   deferred) and `apple_pay_display_name`. Keep separate Apple Pay and
   Google Pay switches.
10. **System Status** (`payarcjs.php` hook `check`):
    - missing bearer token or Client ID;
    - an API URL that does not match the processor's live/test mode
      (`testapi.` vs `api.`);
    - In Progress series with no stored card;
    - a disabled payment cron job.

    `verifyClientId($clientId, GatewayClient::LIVE_PORTAL|SANDBOX_PORTAL)`
    checks a Client ID without charging (the portal answers 403 for an
    unknown one).
11. **`bin/check-credentials.php`**: can mostly call
    `payarc-php/bin/check-credentials.php`, or reuse its logic.
12. **Receipts**: the library always sends `do_not_send_email_to_customer`
    and `do_not_send_sms_to_customer`. Do not add a top-level `email`;
    CiviCRM sends the receipts.

## Testing

- Unit tests: port the ones in `usaepayjs/tests` to the new client (use the
  fake transport, as in `payarc-php/tests/GatewayClientTest.php`). Keep
  `RecurringProcessor` covered.
- Sandbox: `tests/sandbox/*.php` scripts run with `cv scr`. Mint server-side
  tokens as `payarc-php/tests/sandbox/lib.php` `payarc_test_token()` does.
- Browser: the contribution page with the PayArc processor, covering:
  - one-time approve;
  - bad CVV (refused at tokenization with the CVV wording);
  - monthly signup, then a due installment via
    `php ~/bin/cv api3 Job.run_payment_cron processor_name=PayArcHostedFields mode=test`;
  - Change Billing Details;
  - a partial refund refused before settlement, and a full refund voided.

  Re-run the acceptance checklist in `usaepayjs/README.md`.
- Sandbox quirks:
  - It declines some charges at random with D2026 "Do not honor"; retry
    with another amount or card.
  - Cards: Visa 4012000098765439 CVV 999; MC 5146315000000055 CVV 998;
    Discover 6011000993026909 CVV 996. Exp 12/29, ZIP 85284.

## Still open (from PLAN.md)

- **What status a settled (batched) charge shows.** Until this is known,
  `Charge::isSettled()` is FALSE for everything, so partial refunds are
  always refused, which is safe. Watch charges were left in the sandbox on
  2026-09-24 (`payarc-php/tests/sandbox/reports/settle-watch.txt`). Look
  them up with `getCharge()`, and set `Charge::SETTLED_STATUSES` from what
  they show. Then test a partial refund on one.
- How long PayArc keeps idempotency keys (the one-hour replay window is a
  guess).
- Whether PayArc sends any email or text despite the flags.
