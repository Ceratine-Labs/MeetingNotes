<?php

namespace Modules\Site\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Billing\Models\Plan;

/**
 * The public marketing pages.
 *
 * Rendered for signed-out visitors and signed-in customers alike — the shared
 * marketing layout swaps the nav CTA for a link back into the app, so someone who
 * follows a pricing link from an email is not asked to register again.
 *
 * There is no tenancy here. These routes deliberately sit outside the
 * `organisation` middleware, which is why nothing on them may touch a
 * tenant-owned model. `Plan` is safe: plans are global product configuration, not
 * customer data.
 */
class SiteController extends Controller
{
    /**
     * Landing page.
     *
     * The plan strip at the bottom reads from the database so a price change in
     * the admin editor shows on the public site immediately — there is no
     * hardcoded pricing anywhere in the views.
     */
    public function home(): View
    {
        return view('site::home', [
            'plans' => Plan::query()->public()->get(),
        ]);
    }

    /**
     * "How it works" — the nine canonical sections and the workflow around them.
     *
     * Content is drawn from docs/PRODUCT_SPEC.md, which is the received third-party
     * requirements document and the source of truth for what the generator
     * produces. If the section list ever changes there, it must change here too.
     */
    public function features(): View
    {
        return view('site::features');
    }

    /**
     * Pricing.
     *
     * Public plans only — a bespoke or grandfathered plan stays subscribable for
     * the customers already on it without appearing in the price list.
     */
    public function pricing(): View
    {
        return view('site::pricing', [
            'plans' => Plan::query()->public()->get(),
        ]);
    }

    /**
     * Terms of service.
     */
    public function terms(): View
    {
        return view('site::legal.terms');
    }

    /**
     * Privacy policy.
     */
    public function privacy(): View
    {
        return view('site::legal.privacy');
    }
}
