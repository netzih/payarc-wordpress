# PayArc Payments for WordPress

One plugin, one PayArc account, three integrations: **Gravity Forms**,
**GiveWP** and **WooCommerce**. Card details are entered in PayArc's hosted
Pay.js fields; this site only ever handles single-use payment keys and
saved-card references.

Built on [`chabadrichmond/payarc-php`](lib/payarc-php), the framework-free
client extracted from the CiviCRM `payarcjs` extension, so gateway behaviour
(void unsettled / refund settled, no top-level `email`, reconciliation by
`orderid`) is shared and unit tested once.

## Installation

Download `payarc-payments-<version>.zip` from the
[latest release](https://github.com/netzih/payarc-wordpress/releases/latest).
In WordPress go to **Plugins > Add New Plugin > Upload Plugin**, choose the
zip, press **Install Now**, then **Activate**. The zip carries everything the
plugin runs on, including the bundled PayArc client library; no Composer or
shell access is needed on the site. To upgrade, upload the newer zip the same
way and confirm **Replace current with uploaded** when WordPress asks.

With wp-cli, install the release asset straight from GitHub (`--force`
upgrades over an installed copy):

```
wp plugin install https://github.com/netzih/payarc-wordpress/releases/download/v0.1.2/payarc-payments-0.1.2.zip --force --activate
```

A source archive of the repository is not installable: `vendor/` is not
committed, so only the release zip (or `bin/build-zip.sh`) carries the
dependencies.

Requirements: WordPress 6.4+, PHP 8.1+ with curl and json, and at least one of
Gravity Forms 2.9+, GiveWP 4 or WooCommerce 8 (WooCommerce Subscriptions for
recurring WooCommerce payments).

From a clone instead of a release: run `composer install` in the plugin
directory, or `bin/build-zip.sh` to make the same zip a release ships.

## Setup

1. Install and activate **PayArc Payments** (see above).
3. **Settings > PayArc**: choose Sandbox or Live, enter the API key (source
   key), its PIN and the Pay.js public key for that mode, save, then press
   **Check credentials**. The check lists one transaction and mints an unused
   payment key; nothing is charged. Server-side work (charges, refunds,
   renewals) needs only the key and PIN; checkouts also need the public key
   and are offered only when all three are present.
4. On the live source key allow **Sale, Auth Only, Void and Credit (refund)**.
   Auth Only + Void are used to verify a card for a free trial; Credit for refunds.

### Additional accounts

The credentials above are the **default account**, used by Gravity Forms,
GiveWP and WooCommerce. Under **Additional accounts** you can add more
PayArc merchant accounts, each with a name and its own live and sandbox API
key, PIN and Pay.js public key (the mode switch applies to all). Plugins
built on this one can charge to them: Embed Forms lets each form pick an
account. **Check credentials** checks every account.

An account keeps the id it was given when first saved (shown next to its
name), because payments refer to it: renewals, refunds and lookups always go
to the account a payment was made with. Do not remove an account that still
has active recurring payments; a charge for a removed account is refused,
never sent to another one.

For code: `Settings::accounts()` (id => name), and an optional account id
as the last argument of `apiKey()`, `apiPin()`, `publicKey()`,
`hasApiCredentials()`, `isConfigured()` and `Gateway::client()`. `NULL`,
`''` and `'default'` mean the default account. `Admin\Unresolved::makeItem()`
takes the account as an optional last argument so "Check at PayArc" asks
the right one.

### Turning modules off

Each host-plugin module registers only when its plugin is active. To keep one
off anyway (another PayArc gateway already serves that plugin, or the site
only needs the shared settings and gateway for a plugin built on them), filter
`payarc_payments_modules`, for example from a must-use plugin:

```php
add_filter('payarc_payments_modules', fn(array $m) => ['givewp' => FALSE] + $m);
```

Keys: `gravityforms`, `givewp`, `woocommerce`. A module that is off registers
no gateway, settings section or cron event, and its records are left out of
"Unresolved requests".

### Plugins built on this one

Another plugin can charge through the shared services instead of talking to
PayArc itself: `Plugin::instance()->gateway()->client('<name>')` for a
configured `GatewayClient`, `Gateway::metadata()` and `Gateway::orderId()` for
the request conventions below, `Reconcile::once()` for the charge-at-most-once
guard, `Lock` for its own workers and `Schedule` for installment dates. The
browser helper `assets/js/payarc-hostedfields.js` (`window.PayarcHostedFields`) mounts the
Pay.js card fields, mints payment keys and offers Apple Pay.

Two filters let such a plugin appear on Settings > PayArc:

- `payarc_payments_unresolved` (array of items, `Settings`): append a row
  for each in-flight marker, built with `Admin\Unresolved::makeItem()`.
- `payarc_payments_renewal_workers` (array `label => callable`): each callable
  runs that plugin's renewal worker and returns a summary of counts; "Run
  renewal workers now" calls it.

## Gravity Forms

- Add the **PayArc Card** field (Pricing Fields) to a form with a product or
  total field. Then create a **PayArc** feed under Form Settings.
- **Products and Services** feeds charge the form total (or a chosen product)
  when the form is submitted. Declines are shown on the card field in payer
  wording; the gateway's own text goes to the add-on log.
- **Subscription** feeds charge the first installment immediately with
  `save_card` (a free trial verifies the card with a $1 authorization that is
  voided at once) and store a card reference on the entry. Renewals are
  charged by this site from GF's hourly cron (`gravityformspayarc_cron`);
  nothing is scheduled in the PayArc console. Installment dates are anchored
  to the signup day (the 31st stays the 31st, clamped in short months). A
  declined installment is retried every 3 days, three attempts in all, then
  the subscription is cancelled with a note. "Recurring Times" expires the
  entry after that many payments (status **Expired**).
- Renewals cannot double-charge: the `orderid` of each attempt is written to
  the entry before the charge is sent and cleared last, after the outcome
  is recorded. A run that finds one looks it up at PayArc first (bounded by the
  time it was sent, so a miss is conclusive) and records the earlier charge
  instead of sending another. When PayArc cannot be asked, nothing is charged
  and the entry is checked again next hour. Workers take a database lock, so
  two cron runs never charge the same entry.
- **Cancel Subscription** (entry detail) stops further charges; the card
  reference stays on the entry for reference.
- **Refund via PayArc** (entry detail, one-time payments): unsettled sales
  are voided in full, settled ones refunded in full or in part. The entry
  becomes **Refunded** and a refund transaction is recorded. A refund whose
  answer was lost is found at PayArc before it could be sent again.
- On a multi-page form the **PayArc Card** field must sit on the last page:
  the single-use key is minted when the form is submitted, and the editor
  warns when the field is anywhere else.
- Notification events: Payment Completed/Failed/Refunded, Subscription
  Created/Payment Added/Payment Failed/Cancelled/Expired.
- Apple Pay: enable it in Settings > PayArc; the button appears on forms
  whose active PayArc feeds are all one-time, in browsers that support it,
  once the domain is registered with PayArc and Apple's association file is
  served from `/.well-known/`. The amount shown on the sheet is the form total
  as Gravity Forms 3 computes it (`gform.products.getPaymentAmount()`, current
  on every keystroke); on GF 2.9 the Total field is read, or the product
  fields are added up when there is none. Pay.js gives its Apple Pay button
  one fixed element id, so one form per page can offer Apple Pay.

## GiveWP

- Works with the visual donation forms (v3). Enable **PayArc** under
  Donations > Settings > Payment Gateways > Gateways (v3 list); the **PayArc**
  section there only points at Settings > PayArc, where the credentials live.
- **GiveWP Test Mode selects the sandbox credentials**; live mode the live ones.
  Subscriptions remember the mode they were created in and are skipped by the
  renewal worker while the site is in the other mode.
- One-time donations charge the Pay.js key in `createPayment()`; the donation
  gets a note with the PayArc reference, auth code, AVS and CVV results.
  Declines throw a `PaymentGatewayException` with payer wording (shown on the
  form) and leave a note with the gateway text on the pending donation.
- Recurring donations need no add-on for the gateway itself: the form builder
  unlocks recurring because the gateway reports subscription support. The
  first installment is charged with `save_card`; the saved-card reference is
  stored as the subscription's gateway subscription id. An hourly WP-Cron event
  (`payarc_givewp_renewals`) charges subscriptions whose renewal date has
  passed and records each with `Subscription::createRenewal()`, which also
  advances the renewal date. Declines: status **Failing**, retry in 3 days, three
  attempts, then **Cancelled**. Installment limits complete the subscription.
- Refunds: the donation page's Refund action voids unsettled sales and refunds
  settled ones (full amount).
- Cancelling a subscription in GiveWP stops further charges; nothing is
  scheduled at PayArc so there is nothing else to cancel.

## WooCommerce

- Enable **PayArc** under WooCommerce > Settings > Payments. Title, description
  and the saved-cards switch live there; credentials and sandbox/live under
  Settings > PayArc. Compatible with HPOS and the block checkout.
- Block checkout, classic checkout, Pay for Order and My Account > Add Payment
  Method all share one server path: the block checkout copies its payment data
  into `$_POST`, so `process_payment()` reads `payarc_payment_key` and
  `wc-payarc-payment-token` the same way everywhere.
- Saved cards are `WC_Payment_Token_CC` rows holding the PayArc saved-card
  reference (brand, last four and expiry for display). Logged-in customers can
  tick "save card"; a cart containing a subscription always saves it.
- Orders get a note with the PayArc reference, auth code, AVS and CVV results,
  plus meta `_payarc_transaction_key`, `_payarc_refnum`, `_payarc_mode`,
  `_payarc_card_reference`, `_payarc_card_summary`. Declines add a note with
  the gateway text and show payer wording at checkout.
- Refunds from the order screen ("Refund via PayArc"): unsettled sales are
  voided in full, settled ones refunded in full or in part. Partial refunds of
  an unsettled sale are refused with an explanation.
- WooCommerce Subscriptions (add-on, untested here): renewals are charged from
  `woocommerce_scheduled_subscription_payment_payarc` against the card
  reference copied onto the subscription; card changes by customer or admin go
  through the same checkout fields and verify the card with a $1 authorization
  that is voided at once. Free trials verify the card the same way. Renewal
  orders count attempts before each charge and reconcile earlier attempts by
  `orderid` first; an order is left pending, not re-charged, while PayArc
  cannot confirm what happened.
- Apple Pay is offered on plain carts only: keys are single-use and return no
  saved card, so the button is hidden for subscription carts, Pay for Order on
  subscription orders, payment-method changes and Add Payment Method.

### Conventions shared with the CiviCRM import

Every charge sends `custid` = payer email, `invoice` = `GF<form>-<submission>`
(one-time) or `GF-<entry>` (renewals), `GIVE-<donation>` / `GIVE-S<subscription>` for GiveWP, `WC-<order number>` for WooCommerce, `orderid` = an idempotency reference
(`gf-<entry>-<YYYY-MM-DD>-<attempt>` for renewals) and the billing address for
AVS. No top-level `email` is sent, so PayArc does not email its own receipt.

Every `orderid` starts with a six-character prefix derived from the site URL
(filter `payarc_payments_orderid_prefix`), so two sites on one PayArc account
never reconcile each other's charges. PayArc ignores an `orderid` sent with a
refund and gives the refund the sale's `orderid` instead, so refunds are
reconciled by the sale's `orderid`, the transaction type and the amount.

### Charge at most once

Every charge and refund is guarded the same way: a marker (`orderid`, time and
amount) is stored on the record before the request goes out, and a later
attempt for the same record first looks that marker up at PayArc, bounded by
the time it was sent so a miss is conclusive. Found: recorded without charging
again. Missing: charged. PayArc unreachable or the listing window exhausted:
nothing is sent and the admin or payer is told to wait or get in touch. Stores:
WooCommerce order meta (`_payarc_charge_sent`, `_payarc_refund_sent`,
`_payarc_renewal_pending`), GiveWP donation meta (`_payarc_charge_sent`,
`_payarc_refund_sent`), Gravity Forms entry meta (`payarc_reconcile_*`,
`payarc_refund_sent`) and, for a submission that has no entry yet, a row in
`wp_options` (`payarc_marker_*`, written with plain SQL so no object cache can
drop it; purged after seven days by the hourly Gravity Forms cron).

Reading the marker, storing it and sending the request happen under a lock on
the `orderid` (a row inserted into `wp_options`, so the insert itself is the
test), and the stored marker is read back before anything is sent. Two requests
for the same order at the same moment therefore cannot both charge: the second
is told the payment is already being processed. Cron workers hold the same kind
of lock per subscription and per run.

A refund inherits the sale's `orderid`, so two refunds of the same amount look
alike. Before a refund is sent, the refunds the sale already has are listed and
their keys stored with the marker; a later lookup counts only a refund that was
not there before. A listed row that carries the `orderid` but no type or amount
is treated as inconclusive rather than matched, and a voided transaction never
counts.

Renewal success is recorded so that running it twice for the same transaction
changes nothing. Gravity Forms writes one record per approved installment
(`payarc_installment_applied`: transaction key, new count, next date) before
touching the metas the schedule reads; a run that dies half-way derives them
again from that record. GiveWP records the renewal donation first and, when a
run died before the renewal date moved, moves it on the next run instead of
charging the period again (a donation created after the current renewal date is
this period's; one created before it belongs to an earlier period, so the
marker is a leftover and the period is charged).

Settings > PayArc lists every request in that state ("Unresolved requests":
WooCommerce orders and renewal orders, Gravity Forms entries and submissions
without an entry, GiveWP donations and subscriptions) with a "Check at PayArc"
action that reports whether the request went through, was declined or never
arrived, and what to do next; "Run renewal workers now" runs the Gravity Forms
and GiveWP renewal workers immediately. The list is read-only: each module
resolves its own marker on its next attempt.

A signup whose answer was lost is recovered from the listing without the
saved-card key (PayArc's listing and transaction detail carry no
`savedcard`, verified against the sandbox), so a subscription signup recovered
that way is voided and the payer is asked for the card again.

### Entry meta written by the add-on

`payarc_mode`, `payarc_order_id`, `payarc_transaction_key`, `payarc_refnum`,
`payarc_card_brand`, `payarc_card_last4`, `payarc_payer`; for subscriptions
also `payarc_card_reference`, `payarc_interval_length/unit`,
`payarc_recurring_times`, `payarc_payments_made`, `payarc_failed_attempts`,
`payarc_schedule_start`, `payarc_installment_index`, `payarc_scheduled_date`,
`payarc_next_charge`, `payarc_last_transaction_key`,
`payarc_installment_applied`, and while a charge is in flight
`payarc_reconcile_order_id` / `payarc_reconcile_sent_at`. A subscription created in
sandbox mode is skipped by the renewal worker while the plugin is in live mode
(and vice versa).

## Development

```
composer install
vendor/bin/phpunit          # pure classes (schedule math, Reconcile)
composer run build          # vendor/ with the library copied, no dev deps
bin/build-zip.sh            # build/payarc-payments-<version>.zip for a release
```

Releasing: bump `Version:` in `payarc-payments.php` and `Plugin::VERSION`
together (the second is the `?ver=` of the enqueued assets outside WP_DEBUG),
commit, tag `v<version>`, run `bin/build-zip.sh` and attach the zip to the
GitHub release for that tag.

Browser tests live outside the repo (`~/.config/payarcjs/browser/wp-*.mjs`,
Playwright) and run against the local site described in the project notes.

## To do

- Consolidate the charge/exception ladder. The sequence "get a client, run
  the request, catch inconclusive / ambiguous / gateway error / invalid
  argument / busy / not sent, each with its own message and log line" is
  repeated in the Gravity Forms add-on, the GiveWP gateway, the WooCommerce
  gateway and both renewal workers. Move it into one shared method that takes
  the gateway call plus log and note callbacks and returns a typed outcome;
  each module maps the outcome to its own return shape. Pure movement, own
  commit, no behaviour change. Deferred on 2026-09-10.
- Zero- and three-decimal currencies. Amounts are always sent with two
  decimals, which is right for USD and wrong for JPY, KRW or KWD. Left as a
  documented USD-only limit by decision on 2026-09-10.
- Apple Pay in the WooCommerce checkout has not been exercised in a browser.
