# PayArc Payments for WordPress

One plugin, one PayArc account, three integrations: **Gravity Forms**,
**GiveWP** and **WooCommerce**. Card details are entered in PayArc's Hosted
Fields (one PayArc iframe per field). This site only ever handles single-use
card tokens and saved-card references.

It is a fork of `usaepay-wordpress`, with every name, option, hook, meta key
and gateway id renamed, so both plugins can be active on one site. It is
built on [`chabadrichmond/payarc-php`](lib/payarc-php), the framework-free
PayArc client. That library is kept in `lib/payarc-php` as a git subtree:
update it with

```
git subtree pull --prefix lib/payarc-php ~/dev/payarc-php main --squash
```

Status: all three modules pass their sandbox runs end to end (see
[Tested](#tested)). Nothing has been charged in live mode yet.

## Installation

Build the zip with `bin/build-zip.sh`, then go to **Plugins > Add New Plugin >
Upload Plugin**. The zip carries the bundled client, so the site needs no
Composer. Requirements: WordPress 6.4+, PHP 8.1+ with curl and json, and at
least one of Gravity Forms 2.9+, GiveWP 4 or WooCommerce 8 (WooCommerce
Subscriptions for recurring WooCommerce payments).

## Setup

1. Open **Settings > PayArc** and choose Sandbox or Live.
2. From the PayArc dashboard (**API**, then the eye icon), enter for that mode:
   - the **API bearer token**: secret, server-side only. A blank field keeps
     the stored token.
   - the **Client ID**: public, used by the card fields in the browser.
3. Press **Check credentials**. It lists one charge with the bearer token and
   opens an unused card-field session with the Client ID. Nothing is
   charged. A wrong Client ID is reported as such (the portal answers 403).
4. Optionally tick **Apple Pay** / **Google Pay**. The wallet sheet opens in
   a small PayArc window, so this site needs no Apple merchant setup or
   domain file. Wallets are offered only for one-time payments, because
   their tokens cannot be charged again.

For a sandbox account, create one at
`https://testportal.payarc.net/accounts/create/test`. Test card: 4012 0000
9876 5439, expiry 12/29, CVV 999, ZIP 85284. Mastercard 5146 3150 0000 0055
(CVV 998) and Discover 6011 0009 9302 6909 (CVV 996) work too. The sandbox
now and then declines a charge with D2026 ("Do not honor"); the same card a
minute later passes.

### Additional accounts, turning modules off, plugins built on this one

These work as in the USAePay plugin, with `payarc` names:
- Accounts: `Settings::accounts()`, and the optional account argument of
  `bearerToken()`, `clientId()`, `hasApiCredentials()`, `isConfigured()`
  and `Gateway::client()`.
- Filters: `payarc_payments_modules`, `payarc_payments_unresolved`,
  `payarc_payments_renewal_workers`, `payarc_payments_orderid_prefix`.
- Browser helper: `window.PayarcHostedFields` with `{mount, tokenize,
  wallets, errorText}`.

## How PayArc differs from USAePay (and what the plugin does about it)

- **Saved cards.** PayArc keeps cards under customer records. Saving a card
  creates one PayArc customer and attaches the token; the saved-card
  reference is `customer_id:card_id`. A token can be used once, so a card
  that must be kept is **saved first and the saved card is charged**,
  including a subscription's first payment. That also proves at signup that
  renewals can be charged. Saved cards charge without a CVV (verified with
  server and browser tokens). `Gateway::saveCard()` remembers the saved card
  per token, so a resubmitted form does not fail on the used token.
- **No PayArc receipts.** PayArc emails *and texts* the payer unless told not
  to. Every charge and refund sends `do_not_send_email_to_customer` /
  `do_not_send_sms_to_customer`. The payer's email goes in metadata, not the
  top-level field.
- **Refunds of unsettled charges.** PayArc does not refuse them as its docs
  say. It **voids the whole charge**, whatever amount was asked, and records
  only the asked amount in `amount_voided` (verified in the sandbox). So a
  full refund of an unsettled charge is sent (and reported as a void), and a
  partial refund is sent only for a charge known to have settled. Otherwise
  staff are told to refund in full or wait for settlement. PayArc also
  requires a refund description of at least five characters; short ones are
  prefixed.
- **Declines** come either as an HTTP error or as a 2xx charge with
  `failure_code` set (status "Declined"). Both are read by `Charge::outcome()`.
  "Duplicate request (approved previously)" codes and unknown statuses are
  treated as ambiguous, never as declines.

### Charge at most once

Every charge and refund is still guarded by a marker (orderid, key, time,
amount) stored before the request, under a lock on the orderid. What is new
is the **key**: it is sent as PayArc's `Idempotency-Key`, and PayArc answers
a repeated key with the original result, whatever the new body says. So:

- **A lost answer** is resent at once with the same key.
- **A recent marker** (under an hour, `Reconcile::REPLAY_WINDOW`) is replayed
  with its key. An earlier charge comes back instead of a second one.
- **An older marker** is looked up by its reference in the charge list
  (metadata `reference`).
- **A refund marker** carries a snapshot of the sale (refunded amount,
  remaining amount). The sale is compared with it, which settles the refund
  at any age.
- **A definitive answer** (a decline or a refusal) clears the marker, so the
  next attempt gets a new key. PayArc would otherwise replay the old decline.

One-off keys are the orderid plus a short random suffix. Renewal keys are
deterministic, one per record, installment and attempt, so a crashed cron run
gets the same charge back. This was verified: a replayed Gravity Forms
installment returned the earlier charge, made no new charge at PayArc, and
counted nothing twice.

Markers are stored in:
- WooCommerce order meta: `_payarc_charge_sent`, `_payarc_refund_sent`,
  `_payarc_renewal_pending`.
- GiveWP donation meta: `_payarc_charge_sent`, `_payarc_refund_sent`, and the
  option `payarc_give_renewal_state`.
- Gravity Forms entry meta: `payarc_reconcile_*`, `payarc_refund_sent`.
- `wp_options` rows `payarc_marker_*`, for submissions without an entry and
  for saved cards per token. These are purged after seven days.

**Settings > PayArc > Unresolved requests** lists every open marker with a
"Check at PayArc" action.

## Gravity Forms

- Add the **PayArc Card** field (Pricing Fields) and a **PayArc** feed.
- **Products and Services** feeds charge the token on submit.
- **Subscription** feeds save the card, charge the first installment to it
  (a free trial verifies it with a $1 authorization voided at once), and
  renew from GF's hourly cron. The rules are the same as in the USAePay
  plugin:
  - Installment dates are anchored to the signup date.
  - A declined installment is retried every 3 days, 3 attempts in all.
  - "Recurring Times" expires the entry.
  - Renewals are marked recurring (`eci_indicator` 2).
- **Refund via PayArc** on the entry voids an unsettled sale in full, or
  refunds a settled one in full or in part.
- The card field must be on the last page of a multi-page form.

## GiveWP

- Enable **PayArc** under Donations > Settings > Payment Gateways (v3 list).
  GiveWP Test Mode selects the sandbox credentials.
- **One-time gifts** charge the token.
- **Recurring gifts** save the card and charge it. The saved-card reference
  is the subscription's gateway subscription id. The hourly WP-Cron event
  `payarc_givewp_renewals` charges renewals and records them with
  `Subscription::createRenewal()`.
- **Refunds** use the donation's Refund action and are always for the full
  amount.

## WooCommerce

- Enable **PayArc** under WooCommerce > Settings > Payments. Block checkout,
  classic checkout, Pay for Order and Add Payment Method share one server
  path, posting `payarc_token` or `wc-payarc-payment-token`.
- **Saved cards** are `WC_Payment_Token_CC` rows holding the reference, with
  brand, last four and expiry.
- **Subscriptions:**
  - A cart with a subscription always saves the card.
  - Renewals charge the reference copied onto the subscription.
  - A card change saves the new card and verifies it with a $1 authorization.
- **Order meta:** `_payarc_transaction_key` (the PayArc charge id),
  `_payarc_mode`, `_payarc_card_reference` and `_payarc_card_summary`.

## Tested

Sandbox, local site, 2026-09-24:

| Module | What passed |
|---|---|
| Gravity Forms | One-time payment; CVV refusal at tokenization with the right wording; subscription signup; renewal; crash replay (no second charge); partial refund of an unsettled sale refused; full refund voided |
| GiveWP | One-time and monthly donations; renewal; refund voided; a repeated refund call recognised without sending again |
| WooCommerce | Block and classic checkout; logged-in subscription checkout that saves a token; WooCommerce Subscriptions renewal; partial refund refused, full refund voided |
| Admin | Check credentials (bearer token and Client ID); Unresolved requests, including "Check at PayArc" |

Also verified: after a refused tokenization the payer can correct the field
and submit again, and each tokenization of a session returns a new token.
Checkouts that do not reload the page therefore need no remount.

Apple Pay was confirmed working on the live site (jewish-richmond.com,
GiveWP) with 0.1.1 on 2026-09-24.

Not yet exercised:
- Google Pay on a real site.
- Add Payment Method, and a WooCommerce subscription card change.
- Partial refunds of a settled charge (the sandbox status of a batched
  charge is still being watched).
- Whether PayArc sends any email or text despite the flags.
- How long PayArc keeps idempotency keys.

## Development

```
composer install
vendor/bin/phpunit          # Reconcile against a scripted PayArc, schedule math, accounts
bin/build-zip.sh            # build/payarc-payments-<version>.zip
```

Browser tests (Playwright) live in `~/.config/payarc/browser/` and run
against the local site at http://localhost:8001: `gf-test.mjs`,
`gf-sub-test.mjs`, `gf-refund-test.mjs`, `give-test.mjs`, `wc-test.mjs`
and `settings-check.mjs`.

## To do

- Wallet buttons: when the browser can do Apple Pay (Safari), show only the
  Apple Pay button and hide Google Pay. At present Google Pay shows
  everywhere. A small change in `wallets()` in
  `assets/js/payarc-hostedfields.js`: drop `google-pay` from the list when
  `window.ApplePaySession` exists and Apple Pay is enabled. Requested
  2026-09-24.

- Consolidate the charge/exception ladder repeated across the modules (as
  in the USAePay plugin).
- USD only: PayArc accepts nothing else.
- One PayArc card form per page: PayArc's script keeps its state in globals.
