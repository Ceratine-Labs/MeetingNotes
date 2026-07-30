@extends('admin::layouts.app')

@section('title', 'Plans — back office')
@section('page_pretitle', 'Money')
@section('page_title', 'Plans')

@section('content')
    <div class="alert alert-info" role="alert">
        <div class="d-flex">
            <i class="ti ti-info-circle me-2 mt-1"></i>
            <div>
                <h4 class="alert-title">Edits never apply retroactively</h4>
                <div class="text-secondary">
                    Each subscription stores the price and allowances agreed when the customer
                    signed up, so changing a plan here affects new subscribers and renewals only.
                    Existing customers keep their terms for the current period.
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table card-table table-vcenter">
                <thead>
                    <tr>
                        <th>Plan</th><th class="text-end">Price</th><th>Generations</th>
                        <th>Seats</th><th>Paystack</th><th>Visible</th><th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr class="{{ $plan->is_active ? '' : 'opacity-50' }}">
                            <td>
                                <div>{{ $plan->name }}</div>
                                <div class="text-secondary small"><code>{{ $plan->code }}</code></div>
                            </td>
                            <td class="text-end">
                                R{{ $plan->formattedPrice() }}
                                <div class="text-secondary small">{{ $plan->interval }}</div>
                            </td>
                            <td>{{ $plan->hasUnlimitedGenerations() ? 'Unlimited' : $plan->generation_quota }}</td>
                            <td>{{ $plan->hasUnlimitedSeats() ? 'Unlimited' : $plan->seat_limit }}</td>
                            <td>
                                @if (! $plan->isPaid())
                                    <span class="text-secondary small">Not needed</span>
                                @elseif ($plan->paystack_plan_code)
                                    <span class="badge bg-green-lt" title="{{ $plan->paystack_plan_code }}">
                                        Linked
                                    </span>
                                @else
                                    {{-- Blocking state: a paid plan without a Paystack plan
                                         code cannot be subscribed to recurringly, so the
                                         checkout button is disabled for customers. --}}
                                    <span class="badge bg-red-lt">Not pushed</span>
                                @endif
                            </td>
                            <td>
                                @if ($plan->is_public)
                                    <i class="ti ti-eye text-green" title="Shown on the public pricing page"></i>
                                @else
                                    <i class="ti ti-eye-off text-secondary" title="Hidden from the pricing page"></i>
                                @endif
                            </td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <a href="{{ route('admin.plans.edit', $plan->code) }}" class="btn btn-sm">Edit</a>

                                    @if ($plan->isPaid() && $billingEnabled)
                                        <form method="POST" action="{{ route('admin.plans.push', $plan->code) }}"
                                              data-confirm="This creates a plan at Paystack for R{{ $plan->formattedPrice() }} per {{ $plan->interval === 'annually' ? 'year' : 'month' }}. Customers already subscribed keep their existing amount until they resubscribe."
                                              data-confirm-title="Push this plan to Paystack?"
                                              data-confirm-button="Yes, push">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                {{ $plan->paystack_plan_code ? 'Re-push' : 'Push to Paystack' }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
