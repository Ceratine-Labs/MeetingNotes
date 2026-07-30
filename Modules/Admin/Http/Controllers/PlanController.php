<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Admin\Services\AuditLogger;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\GatewayException;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\FeatureGate;

/**
 * Plan editor — prices, allowances, seat limits and feature flags.
 *
 * Two things to understand before changing anything here.
 *
 * **Edits do not apply retroactively.** Each subscription snapshots the price and
 * entitlements agreed when the customer subscribed, so raising the Team price
 * affects new subscribers and renewals, never somebody's current period. That is a
 * correctness requirement, not a nicety — and it means the editor is safe to use on
 * a live plan.
 *
 * **Paystack has its own copy.** A recurring plan exists at Paystack too, and it is
 * what actually gets billed. Changing a price here does NOT change it there: the
 * plan must be pushed again (which creates a new Paystack plan for future
 * subscribers). Existing Paystack subscriptions keep billing their original amount
 * until the customer resubscribes. The view states this plainly, because a silent
 * divergence between our price and the billed price is the worst kind of billing bug.
 */
class PlanController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * All plans, including private and inactive ones.
     */
    public function index(): View
    {
        return view('admin::plans.index', [
            // Not scopePublic: the back office must see hidden and retired plans,
            // which is precisely what the public page must not.
            'plans' => Plan::query()->orderBy('sort')->get(),
            'billingEnabled' => (bool) config('billing.enabled'),
        ]);
    }

    /**
     * Edit form for one plan.
     */
    public function edit(string $plan): View
    {
        return view('admin::plans.edit', [
            'plan' => Plan::query()->where('code', $plan)->firstOrFail(),
            // The flags the app actually reads, so the form cannot invent a flag no
            // code consults.
            'featureKeys' => [
                FeatureGate::CUSTOM_PROMPTS => 'Editable generation prompts',
                FeatureGate::API => 'API access',
            ],
            'exportFormats' => ['md' => 'Markdown', 'docx' => 'Word', 'pdf' => 'PDF'],
        ]);
    }

    /**
     * Save plan changes.
     *
     * Prices arrive from the form in rand (what an admin naturally types) and are
     * converted to integer cents here — the single conversion point, so nothing
     * downstream ever handles a float amount.
     *
     * A blank quota or seat field means unlimited (null), NOT zero. The two are
     * opposites, and the nullable validation plus explicit null below is what keeps
     * an empty input from silently granting nobody any generations at all.
     */
    public function update(Request $request, string $plan): RedirectResponse
    {
        $target = Plan::query()->where('code', $plan)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:255'],

            // In rand, two decimals. Converted below.
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],

            'interval' => ['required', 'in:monthly,annually,none'],

            // Blank = unlimited.
            'generation_quota' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'seat_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],

            'exports' => ['array'],
            'exports.*' => ['in:md,docx,pdf'],

            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $before = $target->only([
            'name', 'price_cents', 'generation_quota', 'seat_limit', 'features', 'is_public', 'is_active',
        ]);

        // Rebuild the features JSON rather than merging into it, so unticking a
        // checkbox actually removes the capability. A merge would leave a stale
        // `true` in place and the flag would never turn off.
        $features = [
            // Markdown is always included — FeatureGate enforces this too, but
            // storing it keeps the plan row honest about what it grants.
            FeatureGate::EXPORTS => array_values(array_unique(array_merge(
                FeatureGate::BASELINE_EXPORTS,
                $validated['exports'] ?? []
            ))),
            FeatureGate::CUSTOM_PROMPTS => $request->boolean('features_custom_prompts'),
            FeatureGate::API => $request->boolean('features_api'),
        ];

        // Preserved rather than exposed on the form: it drives the "recommended"
        // ribbon and is set deliberately, not toggled during a routine price edit.
        if ($target->feature('recommended', false)) {
            $features['recommended'] = true;
        }

        $target->update([
            'name' => $validated['name'],
            'tagline' => $validated['tagline'],
            // The one place rand becomes cents. round() before cast: (int)(1.499*100)
            // truncates to 149 rather than 150 because of float representation.
            'price_cents' => (int) round(((float) $validated['price']) * 100),
            'interval' => $validated['interval'],
            'generation_quota' => $validated['generation_quota'] ?? null,
            'seat_limit' => $validated['seat_limit'] ?? null,
            'features' => $features,
            'is_public' => $request->boolean('is_public'),
            'is_active' => $request->boolean('is_active'),
            'sort' => $validated['sort'],
        ]);

        $this->audit->record(AuditLogger::PLAN_UPDATED, $target, [
            'before' => $before,
            'after' => $target->only([
                'name', 'price_cents', 'generation_quota', 'seat_limit', 'features', 'is_public', 'is_active',
            ]),
        ]);

        $message = 'Plan saved. Existing subscribers keep the terms they signed up on.';

        // Flag the divergence explicitly. An admin who changes a price and does not
        // realise Paystack still bills the old one has a silent billing bug.
        if ($target->isPaid() && $target->paystack_plan_code !== null
            && $before['price_cents'] !== $target->price_cents) {
            $message .= ' The price changed — push the plan to Paystack again so new '
                .'subscribers are billed the new amount.';
        }

        return redirect()->route('admin.plans.index')->with('status', $message);
    }

    /**
     * Create this plan at Paystack and store the returned plan code.
     *
     * Required before a paid plan can be checked out: without a
     * `paystack_plan_code` the checkout would take a single payment and never renew.
     *
     * Pushing again after a price change creates a NEW Paystack plan. Existing
     * Paystack subscriptions stay attached to the old one and keep billing the old
     * amount — Paystack's model, not ours, and the reason the message below spells it
     * out.
     */
    public function pushToGateway(string $plan): RedirectResponse
    {
        $target = Plan::query()->where('code', $plan)->firstOrFail();

        if (! $target->isPaid()) {
            return back()->with('error', 'The free plan does not need a Paystack plan.');
        }

        if (! config('billing.enabled')) {
            return back()->with('error', 'Billing is disabled. Set PAYSTACK_SECRET_KEY and BILLING_ENABLED first.');
        }

        try {
            $gatewayPlan = $this->gateway->createPlan(
                name: config('app.name').' — '.$target->name,
                amountCents: $target->price_cents,
                interval: $target->interval,
                currency: $target->currency,
            );
        } catch (GatewayException $e) {
            report($e);

            // The provider's own message is the useful part here — the admin is
            // technical and it is usually specific ("Currency not supported").
            return back()->with('error', 'Paystack rejected the plan: '.$e->getMessage());
        }

        $previousCode = $target->paystack_plan_code;
        $target->update(['paystack_plan_code' => $gatewayPlan->code]);

        $this->audit->record(AuditLogger::PLAN_PUSHED_TO_GATEWAY, $target, [
            'previous_paystack_plan_code' => $previousCode,
            'paystack_plan_code' => $gatewayPlan->code,
            'amount_cents' => $target->price_cents,
        ]);

        return back()->with('status', sprintf(
            'Plan created at Paystack (%s). New subscribers will be billed R%s per %s. %s',
            $gatewayPlan->code,
            $target->formattedPrice(),
            $target->interval === Plan::INTERVAL_ANNUALLY ? 'year' : 'month',
            $previousCode !== null
                ? 'Customers on the previous Paystack plan keep their existing amount until they resubscribe.'
                : ''
        ));
    }
}
