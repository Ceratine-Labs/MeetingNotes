@extends('admin::layouts.app')

@section('title', 'Payment ' . $payment->reference . ' — back office')
@section('page_pretitle', 'Payment')
@section('page_title', $payment->reference)

@section('page_actions')
    <a href="{{ route('admin.payments.index') }}" class="btn">
        <i class="ti ti-arrow-left me-1"></i>All payments
    </a>
@endsection

@section('content')
    <div class="row row-cards">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Payment</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5 text-secondary">Status</dt>
                        <dd class="col-7">
                            @php
                                $badge = match ($payment->status) {
                                    'success' => 'bg-green-lt',
                                    'failed' => 'bg-red-lt',
                                    'pending' => 'bg-yellow-lt',
                                    default => 'bg-secondary-lt',
                                };
                            @endphp
                            <span class="badge {{ $badge }}">{{ ucfirst($payment->status) }}</span>
                        </dd>

                        <dt class="col-5 text-secondary">Amount</dt>
                        <dd class="col-7">{{ $payment->currency }} {{ $payment->formattedAmount() }}</dd>

                        <dt class="col-5 text-secondary">Our reference</dt>
                        <dd class="col-7"><code class="small">{{ $payment->reference }}</code></dd>

                        <dt class="col-5 text-secondary">Paystack reference</dt>
                        <dd class="col-7">
                            <code class="small">{{ $payment->paystack_reference ?? '—' }}</code>
                        </dd>

                        <dt class="col-5 text-secondary">Workspace</dt>
                        <dd class="col-7">
                            @if ($payment->organisation)
                                <a href="{{ route('admin.organisations.show', $payment->organisation) }}">
                                    {{ $payment->organisation->name }}
                                </a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-5 text-secondary">Method</dt>
                        <dd class="col-7">
                            {{ $payment->channel ?? '—' }}
                            @if ($payment->card_brand)
                                <div class="small text-secondary">
                                    {{ ucfirst($payment->card_brand) }}
                                    &middot;&middot;&middot;&middot;{{ $payment->card_last4 }}
                                </div>
                            @endif
                        </dd>

                        <dt class="col-5 text-secondary">Created</dt>
                        <dd class="col-7">{{ $payment->created_at->toDayDateTimeString() }}</dd>

                        <dt class="col-5 text-secondary">Paid</dt>
                        <dd class="col-7 mb-0">{{ $payment->paid_at?->toDayDateTimeString() ?? '—' }}</dd>
                    </dl>

                    @if ($payment->failure_reason)
                        <div class="alert alert-danger mt-3 mb-0" role="alert">
                            <strong>Reason:</strong> {{ $payment->failure_reason }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Provider payload</h3>
                </div>
                <div class="card-body">
                    {{-- Kept whole and shown raw: when a charge is disputed months
                         later, the provider's own response is the evidence that
                         settles it. --}}
                    <p class="text-secondary small">
                        The verified response from Paystack, stored exactly as received.
                    </p>
                    @if ($payment->provider_payload)
                        <pre class="mn-transcript mb-0">{{ json_encode($payment->provider_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        <div class="text-secondary">
                            No payload — this payment never reached verification.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
