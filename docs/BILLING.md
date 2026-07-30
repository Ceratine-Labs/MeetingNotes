# Billing

Plans, Paystack, quota metering and the subscription lifecycle. Read this before
touching anything that handles money.

## Ground rules

**1. Money is integer cents. Never floats.**
Binary floating point cannot represent `0.1` exactly, so float arithmetic on money
drifts. Every amount in the database is `unsignedBigInteger` in the currency's minor
unit. Division happens only at the point of display (`formattedPrice()`,
`formattedAmount()`). The single conversion from rand to cents is in
`PlanController::update()`.

**2. Entitlements are snapshotted, not looked up.**
Each `subscriptions` row copies the price, quota, seat limit and feature flags that
were in force when the customer subscribed. Everything asking "what may this customer
do?" reads **the subscription**, never `$subscription->plan`.

Reading through the relation would mean an admin editing the Team plan silently
changes what every existing Team customer gets mid-period — a billing correctness bug,
and the kind that surfaces as an angry support email rather than a failing test. This
is the one place the "pointers over copies" rule is deliberately broken, because the
copy *is* the agreement.

**3. `null` means unlimited. `0` means none.**
They are opposites and the code never conflates them with a falsy check — hence
`hasUnlimitedGenerations()` and `hasUnlimitedSeats()` rather than `if (!$quota)`.

**4. Never trust the browser callback.**
Anyone can visit `/app/billing/callback?reference=whatever`. Only a server-side
`verifyTransaction()` against Paystack's API may mark a payment successful.

**5. Paystack credentials come from the environment only.**
Unlike the LLM keys (which are admin-editable settings), payment credentials require a
deploy to change. A back-office account compromise must not be enough to redirect the
money.

## Tables

| Table | Holds |
|---|---|
| `plans` | The tiers. Editable from the back office; the public pricing page renders from these rows. |
| `subscriptions` | One live per organisation, with its entitlement snapshot. |
| `payments` | Every payment *attempt*, including abandoned ones. |
| `generation_usages` | The metering ledger — one row per consumed credit. |
| `billing_webhook_events` | Every received webhook, for idempotency and replay. |

## The tiers

Shipped defaults from `PlanSeeder` — starting values, not settled pricing. All of it
is editable in the back office, and the public page reads the database.

| | Free | Starter | Team | Business |
|---|---|---|---|---|
| Price / month | R0 | R149 | R449 | R1 299 |
| Generations / period | 3 | 30 | 150 | unlimited |
| Seats | 1 | 3 | 10 | unlimited |
| Exports | MD | MD, DOCX, PDF | MD, DOCX, PDF | MD, DOCX, PDF |
| Custom prompts | | | ✓ | ✓ |
| API | | | | ✓ |

**Markdown export is available on every plan, including free, and must stay that
way.** A customer has to be able to get their own minutes out. Locking every export
behind a paid tier makes the free plan a roach motel — hostile, and awkward under
POPIA/GDPR data-portability rules. Enforced by `FeatureGate::BASELINE_EXPORTS`, which
merges MD in regardless of what the plan row says.

Changing prices is an admin action (`/admin/plans`), not a deploy. Editing
`PlanSeeder` after it has run does nothing — the seed master refuses to re-run a
changed seeder. To change shipped defaults for *future* installs, add a v2 seeder.

## Metering

The unit is **one successful generation** = one credit.

- Hand-editing a section: free, unlimited.
- Regenerating one section: free. Redoing part of a document the customer already paid
  a credit for is finishing that document, not a new generation — charging would push
  people to accept a weak section rather than fix it.
- A failed generation: **never charged.** `recordUsage()` runs only after
  `persist()` succeeds.

### Why a ledger and not a counter

Usage is always `SUM(credits)` over `generation_usages` rows in the period, never a
running total on the subscription. Two reasons:

- A counter drifts. Two concurrent generations that each read-then-increment lose one
  increment; summing append-only rows cannot.
- "Why does it say I used 14?" is answerable. Each row names the meeting, the user,
  the model and the tokens.

### Where it is enforced

`MinutesGenerator::generate()` — the service, immediately before the first LLM call.
**Not the controller**: generation also runs from a queued job and from the retry
path, and a controller-only check would leave both unmetered.

```
QuotaService::assertCanGenerate($org)
  → throws QuotaExceededException (carrying QuotaStatus)
  → GenerateMinutesJob catches it, marks the meeting failed with a message
    naming the limit, the plan and the reset date. Not re-thrown — a retry
    would fail identically, and this must never page anyone.
```

### Period rollover is lazy

`QuotaService::statusFor()` notices an elapsed window and rolls it, rather than a cron
doing it. A scheduled job that silently stopped running would freeze every customer's
quota at last month's usage and lock out paying accounts. Doing it on read means the
failure mode is "nothing happens", not "everyone is blocked".

The new window starts at the old one's *end*, not `now()`, so periods stay contiguous
and an idle customer does not get a shifted billing anniversary. `rollPeriod()` loops
to catch up if several periods elapsed.

## Checkout

```
1. begin()     write a PENDING payment row, then ask Paystack for a checkout URL
2.             redirect the customer to Paystack (no card details touch this app)
3. complete()  VERIFY server-side, check the amount, start the subscription
```

Recording the intent *before* redirecting is what makes an abandoned checkout visible.
Without it, a customer who reaches the payment page and closes the tab leaves no trace
and "I'm sure I paid" is unanswerable.

The amount is compared against what we asked for. A mismatch means tampering or a
currency mix-up; the payment is recorded as failed and flagged for review rather than
activating a subscription on a short payment.

A verified payment that cannot be matched to a plan is still recorded as **successful**
with a note for review. Never silently discard money.

## Webhooks

`POST /webhooks/paystack` — unauthenticated, CSRF-exempt, signature-verified.

**Webhooks are the reliable channel, not the callback.** The callback only fires if
the customer's browser makes it back; a closed tab, dead battery or flaky connection
all skip it. Recurring renewals have no browser at all. The callback exists to make
the customer's own screen feel immediate; the webhook is what keeps subscriptions
correct over time.

### Authenticity

HMAC-SHA512 of the **raw request body** with the secret key, compared with
`hash_equals`. Two details that are easy to get wrong:

- The body must be the exact bytes received (`$request->getContent()`). Decoding the
  JSON and re-encoding changes whitespace and key order, and the digest never matches.
- The comparison must be timing-safe. A plain `===` leaks, through response timing,
  how many leading bytes of a forged signature were correct.

An unconfigured secret key rejects webhooks rather than accepting them.

### Idempotency is a database guarantee

Paystack retries; duplicate delivery is normal operation, not an error. `record()`
inserts against a unique index on `(provider, event_id)` and catches the constraint
violation. That is deliberately stronger than a "have I seen this?" `SELECT`, which has
a window between check and write that two concurrent retries can both slip through.

Paystack sends no dedicated event id, so the key is composed as
`{event_type}:{data.id}`. Including the type matters — one transaction produces both
`charge.success` and `subscription.create`, and keying on the id alone would drop the
second.

### Handled events

| Event | Effect |
|---|---|
| `charge.success` | First payment (safety net for a lost callback) or a renewal. |
| `subscription.create` | Store the subscription code and email token — **both are needed to cancel**, and they only arrive here. |
| `subscription.disable` / `not_renew` | Mark cancelled; access continues to period end. |
| `invoice.payment_failed` | Start the grace period. |

Anything else is recorded and marked processed without action. Treating unhandled types
as errors would fill the log with noise and hide the genuinely stuck events.

The endpoint returns **200 once the signature is valid**, even when handling fails. A
non-2xx makes Paystack retry, and for a deterministically failing payload that achieves
nothing — the event row keeps the error and `/admin/webhooks` can replay it after the
cause is fixed.

## Lifecycle

```
 register ──→ free (active) ──→ checkout ──→ paid (active)
                  ↑                              │
                  │                    ┌─────────┼──────────┐
                  │              cancel│         │ renewal  │ renewal
                  │                    │         │ succeeds │ fails
                  │                    ↓         ↓          ↓
                  │              cancelled    active     past_due
                  │           (usable until               (usable for
                  │            period end)                 grace days)
                  └──────────────────┴────────────────────────┘
                        lazily downgraded on next quota check
```

- **Cancel** keeps paid features to `current_period_end` — they paid for that time.
- **Failed renewal** starts a `BILLING_GRACE_PERIOD_DAYS` window (default 7). A card
  that expired over a weekend should not read as churn.
- **Downgrade never deletes anything.** The customer keeps every meeting and every set
  of minutes; free-tier limits apply to new generations.

A Paystack failure during cancellation is logged and swallowed on purpose: the customer
clicked cancel and must see it take effect. Leaving them "still subscribed" because our
API call failed is worse; the orphaned Paystack subscription is reconciled from
`/admin/payments`.

## Setup

```bash
# .env
BILLING_ENABLED=true
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
BILLING_CURRENCY=ZAR
BILLING_GRACE_PERIOD_DAYS=7
```

Then, per paid plan, **push it to Paystack** from `/admin/plans`. Until a plan has a
`paystack_plan_code` it cannot be checked out — without it the charge would be taken
once and never renew, so the button is disabled with the reason shown.

Point Paystack's webhook at `https://your-domain/webhooks/paystack`.

With `BILLING_ENABLED=false` (the default) everything runs on the free plan:
registration, generation and every screen work, and upgrade paths are hidden rather
than broken.

### Changing a price on a live plan

Paystack keeps its own copy of the plan, and that copy is what actually gets billed.
Changing the price here does **not** change it there. Push the plan again — which
creates a *new* Paystack plan for future subscribers. Existing Paystack subscriptions
keep billing their original amount until the customer resubscribes. That is Paystack's
model, not ours; the admin UI says so explicitly, because a silent divergence between
our price and the billed price is the worst kind of billing bug.

## Extending

**A second payment provider**: implement `PaymentGateway`, bind it in
`BillingServiceProvider::register()`. Return the module's own `GatewayTransaction` /
`GatewayPlan` objects — raw provider JSON must not travel beyond the gateway class.

**A new feature flag**: add a constant to `FeatureGate`, add it to the plan editor's
`featureKeys`, gate with `$gate->allows($org, FeatureGate::YOUR_FLAG)`. Never hardcode
a plan code in a feature check.

**Metering something else**: `generation_usages.credits` is an integer, not a row
count, specifically so a "long transcript costs 2 credits" rule needs no migration.
