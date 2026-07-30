<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing v1 — plans, subscriptions, usage metering and the payment audit trail.
 *
 * Money tables have stricter rules than the rest of the schema, and three
 * choices below are deliberate enough to spell out:
 *
 *   1. **Amounts are integer cents, never floats.** Binary floating point cannot
 *      represent 0.1 exactly, so float arithmetic on money drifts. Every amount
 *      here is `unsignedBigInteger` in the currency's minor unit.
 *
 *   2. **The plan is snapshotted onto the subscription.** `subscriptions` copies
 *      the price, quota and seat limit that were in force when the customer
 *      subscribed. Editing a plan in the admin UI must not retroactively change
 *      what an existing customer is entitled to or being charged — that would be
 *      both a billing bug and, arguably, fraud. This is the one place we
 *      deliberately break the "pointers over copies" rule, because the copy IS
 *      the agreement.
 *
 *   3. **Webhook events are recorded before they are acted on.** Paystack
 *      retries, and duplicate delivery is normal. A unique index on the
 *      provider's event id turns idempotency into a database guarantee rather
 *      than something the handler has to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Stable machine identifier ('free', 'starter', 'team', 'business').
            // Code refers to plans by this, never by name or UUID, so the admin
            // can rename "Team" to "Growth" without breaking anything.
            $table->string('code')->unique();

            $table->string('name');
            $table->string('tagline')->nullable();

            // Minor units (cents). See note 1 above.
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('ZAR');

            // 'monthly' | 'annually' | 'none' (the free plan never renews).
            $table->string('interval')->default('monthly');

            // Metering: successful generations allowed per billing period.
            // NULL = unlimited. Zero would mean "none at all", so the two must
            // never be conflated in code.
            $table->unsignedInteger('generation_quota')->nullable();

            // NULL = unlimited members.
            $table->unsignedInteger('seat_limit')->nullable();

            // Feature switches read by FeatureGate, e.g.
            // {"exports": ["md","docx","pdf"], "custom_prompts": true, "api": false}
            $table->json('features')->nullable();

            // Paystack's own plan code (PLN_xxxx) for recurring subscriptions.
            // Nullable: the free plan has none, and a newly created plan has
            // none until it is pushed to Paystack.
            $table->string('paystack_plan_code')->nullable()->index();

            $table->boolean('is_public')->default(true);   // shown on /pricing
            $table->boolean('is_active')->default(true);    // can be subscribed to
            $table->integer('sort')->default(100);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->uuid('plan_id')->index();

            // active | past_due | cancelled | expired — see Subscription::STATUS_*.
            $table->string('status')->default('active');

            // Snapshot of the plan at subscribe time. See note 2 above.
            $table->string('plan_code');
            $table->string('plan_name');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->unsignedInteger('generation_quota')->nullable();
            $table->unsignedInteger('seat_limit')->nullable();
            $table->json('features')->nullable();

            // The metering window. Quota resets when current_period_end passes;
            // for the free plan these roll monthly from the signup date.
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end')->index();

            // Paystack identifiers, absent on the free plan.
            $table->string('paystack_subscription_code')->nullable()->index();
            $table->string('paystack_customer_code')->nullable()->index();
            $table->string('paystack_email_token')->nullable();

            // Set when the customer cancels; access continues until the paid
            // period ends, which is what this records.
            $table->timestamp('cancelled_at')->nullable();

            // First failed renewal. The grace period runs from here, after which
            // the organisation drops to free rather than losing its data.
            $table->timestamp('past_due_since')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The hot query on every generation: "this org's live subscription".
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->uuid('subscription_id')->nullable()->index();

            // Our reference, generated before redirecting to Paystack, so an
            // abandoned checkout is still traceable.
            $table->string('reference')->unique();

            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('ZAR');

            // pending | success | failed | abandoned — see Payment::STATUS_*.
            $table->string('status')->default('pending');

            $table->string('paystack_reference')->nullable()->index();
            $table->string('channel')->nullable();          // card, eft, ussd…
            $table->string('card_last4', 4)->nullable();
            $table->string('card_brand')->nullable();

            // Verified server-side response, kept whole. When a customer
            // disputes a charge months later, the raw provider payload is the
            // only thing that settles it.
            $table->json('provider_payload')->nullable();

            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('provider')->default('paystack');

            // Idempotency key. Unique index = the database refuses a duplicate,
            // so a retried delivery cannot double-apply. See note 3 above.
            $table->string('event_id')->nullable();

            $table->string('event_type')->index();
            $table->json('payload');

            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });

        Schema::create('generation_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->uuid('subscription_id')->nullable()->index();
            $table->uuid('meeting_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();

            // Which billing window this consumption belongs to. Denormalised
            // from the subscription so counting a period is one indexed range
            // scan and stays correct after the subscription's window moves on.
            $table->timestamp('period_start')->index();
            $table->timestamp('period_end');

            // Almost always 1. An int rather than a row count so a future
            // "long transcript costs 2 credits" rule needs no migration.
            $table->unsignedInteger('credits')->default(1);

            // Cost observability — which model was used and how many tokens, so
            // margin per plan can actually be measured.
            $table->string('model_used')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The quota check on every generation attempt.
            $table->index(['organisation_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_usages');
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
