@extends('core::layouts.app')

@section('title', 'Plans — ' . config('app.name'))
@section('page_pretitle', 'Billing')
@section('page_title', 'Choose a plan')

@section('page_actions')
    <a href="{{ route('billing.index') }}" class="btn">
        <i class="ti ti-arrow-left me-1"></i>Back to billing
    </a>
@endsection

@section('content')
    @unless ($billingEnabled)
        {{-- Billing is off until Paystack keys are configured. Say so plainly
             rather than offering buttons that cannot work. --}}
        <div class="alert alert-info" role="alert">
            <div class="d-flex">
                <i class="ti ti-info-circle me-2 mt-1"></i>
                <div>
                    <h4 class="alert-title">Paid plans are not switched on yet</h4>
                    <div class="text-secondary">
                        Everything below is what will be available. Your workspace stays on the
                        free plan in the meantime — nothing is limited beyond the free allowance.
                    </div>
                </div>
            </div>
        </div>
    @endunless

    <div class="row row-cards row-deck">
        @foreach ($plans as $plan)
            <div class="col-md-6 col-lg-3">
                @include('billing::partials.plan-card', [
                    'plan' => $plan,
                    'current' => $subscription?->plan_code === $plan->code,
                    'actionUrl' => route('billing.checkout', $plan->code),
                    'actionMethod' => 'post',
                    'actionLabel' => $plan->isFree() ? 'Downgrade to Free' : 'Choose ' . $plan->name,
                    // A paid plan with no Paystack plan code cannot be billed
                    // recurringly, so the button is disabled with the reason rather
                    // than failing after the click.
                    'disabled' => ! $billingEnabled || ($plan->isPaid() && ! $plan->isBillable()),
                    'disabledNote' => $plan->isPaid() && ! $plan->isBillable() && $billingEnabled
                        ? 'Not yet available.'
                        : null,
                ])
            </div>
        @endforeach
    </div>

    <div class="row mt-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <h3 class="card-title">How the allowance works</h3>
                    <ul class="text-secondary mb-0">
                        <li>
                            One "set of minutes" is one generation from one transcript — all nine
                            sections, in the same structure every time.
                        </li>
                        <li>
                            Editing a section by hand costs nothing. Asking the model to
                            <em>regenerate</em> a single section does not spend a full credit either.
                        </li>
                        <li>
                            A generation that fails is never charged.
                        </li>
                        <li>
                            Your allowance resets on your billing date, not on the 1st of the month.
                        </li>
                        <li>
                            Cancel whenever you like — you keep the paid features until the end of the
                            period you have already paid for, then move to Free. Your minutes are
                            never deleted.
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h3 class="card-title">Payment</h3>
                    <p class="text-secondary">
                        Card payments are processed by Paystack. Your card details are entered on
                        Paystack's own secure page and never reach our servers.
                    </p>
                    {{-- Card-brand logos come from Tabler's payments set, vendored
                         locally like everything else. --}}
                    <div class="d-flex gap-2 align-items-center">
                        <img src="{{ mn_asset('vendor/tabler/img/payments/visa.svg') }}"
                             alt="Visa" height="24">
                        <img src="{{ mn_asset('vendor/tabler/img/payments/mastercard.svg') }}"
                             alt="Mastercard" height="24">
                        <img src="{{ mn_asset('vendor/tabler/img/payments/americanexpress.svg') }}"
                             alt="American Express" height="24">
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
