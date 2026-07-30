<?php

/*
|--------------------------------------------------------------------------
| Billing configuration
|--------------------------------------------------------------------------
|
| Merged under the `billing` key by BillingServiceProvider.
|
| Paystack credentials are read from the environment ONLY. They are not stored
| in the settings table alongside the LLM keys, and that difference is
| deliberate: the LLM provider is meant to be swappable from the admin UI at
| runtime, whereas payment credentials should require a deploy to change. A
| back-office account compromise must not be enough to redirect our money.
|
*/

return [

    /*
    | Whether billing is enabled at all.
    |
    | With this off (the default until keys are configured) the product runs
    | entirely on the free plan: registration, generation and every screen works,
    | and the upgrade paths are hidden rather than broken. That is what lets the
    | app be deployed and used before Paystack is set up.
    */
    'enabled' => env('BILLING_ENABLED', false),

    'paystack' => [
        // Secret key (sk_live_… / sk_test_…). Server-side only — this value must
        // never reach a Blade template or a JS bundle.
        'secret_key' => env('PAYSTACK_SECRET_KEY'),

        // Public key. Safe to expose; only needed if we ever move to Paystack's
        // inline JS checkout instead of the redirect flow.
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),

        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

        // Seconds. Paystack is normally fast; a long timeout here just makes a
        // customer stare at a spinner during an outage.
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 20),
    ],

    /*
    | Currency for all charges. Paystack settles ZAR for South African accounts.
    | Changing this does NOT convert existing plan prices — they are stored in
    | minor units of whatever currency they were created in.
    */
    'currency' => env('BILLING_CURRENCY', 'ZAR'),

    /*
    | Days a subscription keeps working after a renewal charge fails, before the
    | organisation is downgraded to free.
    |
    | Long enough to cover an expired card being replaced over a weekend. The
    | downgrade never deletes anything — the customer keeps their minutes and
    | simply returns to free-tier limits.
    */
    'grace_period_days' => (int) env('BILLING_GRACE_PERIOD_DAYS', 7),

    /*
    | Warn a customer in the UI when they have this fraction of their generation
    | quota left. 0.2 = the last 20%.
    */
    'quota_warning_threshold' => (float) env('BILLING_QUOTA_WARNING_THRESHOLD', 0.2),

];
