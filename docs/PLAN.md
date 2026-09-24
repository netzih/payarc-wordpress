# PayArc port: plan and open questions

Plan agreed on 2026-09-23. The goal is PayArc versions of the two USAePay
integrations (`usaepayjs` for CiviCRM and `usaepay-wordpress`).

## Decisions

- **Separate forks.** Three new repositories under `~/dev`, copied from the
  USAePay ones and renamed:
  - `payarc-php`: this library.
  - `payarc-wordpress`: the "PayArc Payments" plugin.
  - `payarcjs`: the CiviCRM extension.

  Every prefix, meta key, hook and gateway id is renamed, so both plugins can
  run on one site.
- **Scope of v1.** The CiviCRM extension plus the WooCommerce, Gravity Forms
  and GiveWP modules. Apple Pay and Google Pay are included, through the
  Hosted Fields wallet buttons. The CiviCRM transaction importer is deferred.
- **Recurring stays site-managed.** Saved cards are charged by cron, as in
  the USAePay versions. PayArc's own plans have a fixed amount, which does not
  suit donor-chosen gifts.
- **Card entry is PayArc Hosted Fields** (`iframeprocess.js`, Client ID in
  the browser). Hosted Checkout, a modal driven by a server-side order, is
  the fallback if Hosted Fields turn out unusable.
- **The CiviCRM extension ships this library** in its own `vendor/`, instead
  of keeping a separate copy of the client (the USAePay extension did that,
  and the copy drifted).

## Phases

1. **This library.** Done: client, response readers, donor wording, unit
   tests, credential check and sandbox probes.
2. **`payarc-wordpress`.** Done 2026-09-24: all three modules pass their
   sandbox runs end to end; see that repo's README, "Tested".
   - Fork and rename.
   - Settings: Client ID and bearer token for live and sandbox, plus extra
     accounts.
   - `assets/js/payarc-hostedfields.js`: the same `{mount, tokenize,
     applePay, errorText}` surface as `UsaepayPayJs`, wrapping PayArc's
     global-function script.
   - Then WooCommerce, Gravity Forms and GiveWP. Wallets last.
   - `Gateway::approved()` and its siblings map onto `Charge::outcome()`.
     `Reconcile` uses the idempotent resend or `findChargeByReference()`,
     depending on what the probe finds.
3. **`payarcjs`.**
   - Fork and rename, then bundle this library.
   - Rewrite `assertApproved`, the card-entry JS, the processor type fields
     (bearer token and Client ID), the status checks and the wallets.
   - The recurring cron, pre-approval, token storage and refunds carry over.
4. **Sandbox gate.** Finish the open questions below, then run the USAePay
   acceptance checklist against PayArc. Finish with a live smoke test: a
   small charge, then a refund.

## Open questions (answered by the probes)

| # | Question | Why it matters | Probe |
|---|---|---|---|
| 1 | Can a Hosted Fields token be attached to a customer, and is the card `is_verified`? | Unverified cards need a CVV on later charges, and recurring charges have no CVV | web harness: save |
| 2 | Does a saved card charge without a CVV, with `eci_indicator` 2? | Recurring depends on it | probe `charge_saved_*`, web harness |
| 3 | Does resending with the same `Idempotency-Key` return the original charge? What happens if the body differs? How long is a key kept? | Decides how `Reconcile` settles lost responses | probe `idempotent_*` |
| 4 | Is a token single-use even across different keys? | Signup flows: save first, then charge the saved card | probe `token_reuse` |
| 5 | How is metadata returned on GET and in the list? What format is `created_at` in, and which zone? | `findChargeByReference()` and time cutoffs | probe `get_charge`, `list_shape`, `find_by_reference` |
| 6 | Is the refund refusal on an unsettled charge exactly "Return Not Allowed."? What does voiding twice return? | `reverse()` depends on the wording | probe `reverse_*`, `refund_raw_unsettled`, `void_twice` |
| 7 | Is `description` accepted and shown as `charge_description`? | Staff find charges in the dashboard | probe `charge_token` |
| 8 | Where does a decline appear: HTTP error or `failure_code`? Which test inputs decline? | `Charge::outcome()` and `DonorMessage` | probe `decline_*` |
| 9 | Do the `do_not_send_*` flags actually stop PayArc's emails and texts? | Donors must not get double receipts | check the probe inbox and dashboard after a run |
| 10 | Does PayArc create or email anything for a customer at `saveCard()`? | Same | same |
| 11 | Does the Hosted Fields script work on `localhost`, or only on registered domains? | Local development and the browser tests | web harness |
| 12 | How does the wallet popup behave on a real HTTPS domain? | Apple Pay and Google Pay in v1 | Phase 4, on the staging site |

Record each answer here with the date and the report file it came from.
Then update the code comments that say "assumed", "not documented" or
"INFERRED".

## Answers so far

### 2026-09-24: server tokens

Source: `probe-20260924043923.json`, plus follow-up tests on the same day.

- **Q2: yes.** A saved card charges without a CVV, with `eci_indicator` 2.
  This held for plain server tokens and for `authorize_card` ones. Server
  tokens come back `is_verified=1` either way. Hosted Fields tokens are
  still to test (Q1).
- **Q3: strict replay.** The same `Idempotency-Key` returns the original
  charge, even when the body differs (a different amount still returned
  the $1.01 charge). So `Reconcile` can resend safely. It must send the
  identical request, because a changed amount is ignored, not refused. How
  long keys are kept is still unknown.
- **Q4: yes, single-use.** Reusing a token under a new key gets HTTP 404
  "The requested token_id is not valid or already used".
- **Q5.** With `include=transaction_metadata`, metadata comes back as
  `transaction_metadata.data[] = {object: TransactionMeta, key, value}`,
  both on GET and in the list. The list's `created_at` is a Unix time, not
  the documented string. `findChargeByReference()` works.
- **Q6: the docs are wrong, and it is dangerous.** A refund of an
  **unsettled** charge is not refused. PayArc **voids the whole charge**,
  whatever amount was asked for, and answers HTTP 201 with status `void`.
  In one test, a $0.75 refund of a $2.00 charge left status `void`,
  `net_amount` 0 and `amount_voided` 75. A second $0.25 "refund" was
  accepted too (201, `amount_voided` overwritten to 25). A real void
  afterwards was refused with 409 "Reversal Not Allowed.", which is also
  the answer to voiding twice. So `amount_voided` cannot be trusted.
  - **Library change:** `refund()` reads the charge first. It sends a
    partial amount only when `Charge::isSettled()`; otherwise it throws
    `UnsettledPartialRefundException` and sends nothing. It never sends
    anything for a charge that is already void or refunded.
  - **Still open:** what status a *batched* charge shows. Watch charges
    were left captured on 2026-09-24 (`tests/sandbox/reports/settle-watch.txt`)
    to find out.
- **Q7.** `description` is silently dropped. `charge_description` is stored
  and shown, so the library now sends that.
- **Q8: both ways.** A decline can come as a 201 charge with status
  `Declined` and `failure_code` D2026 ("Do not honor"), or as an HTTP error
  with the same code. The sandbox declines some charges seemingly at random
  (Visa $1.12, Mastercard $1.20 through the library), while the same cards
  and amounts pass minutes later.
- **Other.** The rate limit header shows 90 requests. The account's default
  statement descriptor is "Chabad Jewish Center of R", which is too long, so
  PayArc cuts it off.

### 2026-09-24: Hosted Fields tokens

Source: `hosted-fields.jsonl`, runs hf-20260924044618 and hf-20260924044632.

- **Q1: yes.** A browser token attaches to a customer, and the saved card
  comes back `is_verified=1`.
- **Q2: yes, for browser tokens too.** The saved card charged without a
  CVV, as recurring, and was approved. `verifyCard()` authorized $1.00 and
  voided it without error.
- The token charge was approved and `charge_description` was kept.
- The browser gets `{"token": "...", "card": {"first6", "last4"}}` back.
- **Q11: yes.** Hosted Fields work on `http://localhost`, with no domain
  registration needed in the sandbox.

**Result: the recurring design stands.** Save the card first (one PayArc
customer per card), then charge the saved card, including the first
installment. No $1 authorization is needed at signup.

Still open: Q3 (how long idempotency keys last), the status of a settled
charge (settle-watch), Q9/Q10 (whether any PayArc email or text actually
reaches the payer), and Q12 (wallets on a real domain).

### 2026-09-24: WordPress plugin runs

- A refund description shorter than 5 characters is refused ("The
  description field must be at least 5 characters."). The library now
  prefixes short ones.
- Hosted Fields refuse a wrong CVV at tokenization with HTTP 409 and a bare
  JSON list, `["Invalid CVV"]`.
- After a refusal the payer can correct the field and tokenize again in the
  same session. Every tokenization returns a new token.
- PayArc puts `style="all: inherit"` on its iframes, so our sizing needs
  `!important`. Its default CSS inside the frame floats the input at 55%
  width.
- The portal's `/v1/get-iframe?user=<Client ID>` answers 403 for an unknown
  Client ID. `verifyClientId()` uses this for "Check credentials".
- A replayed renewal key returned the earlier charge, with no new charge at
  PayArc (22 charges before and after).

Next: Phase 3, the `payarcjs` CiviCRM extension. Start from
[HANDOFF-civicrm.md](HANDOFF-civicrm.md).
