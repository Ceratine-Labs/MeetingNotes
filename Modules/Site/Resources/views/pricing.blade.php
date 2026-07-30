@extends('core::layouts.marketing')

@section('title', 'Pricing: ' . config('app.name'))
@section('meta_description', config('app.name') . ' pricing. Start free with three sets of minutes a month, no card required. Paid plans from R149 a month for more generations, more seats and Word and PDF export.')
@section('robots', 'index, follow')

@section('content')
    <div class="mn-hero border-bottom">
        <div class="container-xl py-6">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <h1 class="display-6 fw-bold mb-3">Pricing</h1>
                    <p class="fs-4 text-secondary mb-0">
                        Priced on how many sets of minutes you generate, not on how many features
                        we can withhold.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="container-xl py-6">
        <div class="row row-deck g-3">
            @foreach ($plans as $i => $plan)
                <div class="col-md-6 col-lg-3 mn-reveal" style="--mn-reveal-delay: {{ $i * 0.08 }}s;">
                    {{-- Shared with the in-app picker — one definition of each tier. --}}
                    @include('billing::partials.plan-card', [
                        'plan' => $plan,
                        'actionUrl' => auth()->check() ? route('billing.plans') : route('auth.register'),
                        'actionMethod' => 'get',
                        'actionLabel' => $plan->isFree() ? 'Start free' : 'Choose ' . $plan->name,
                    ])
                </div>
            @endforeach
        </div>

        <div class="row justify-content-center mt-6">
            <div class="col-lg-9 mn-reveal">
                <h2 class="h1 mb-4">Questions people actually ask</h2>

                <div class="accordion" id="pricing-faq">
                    @php
                        /*
                         * Kept as data rather than nine hand-written accordion blocks so
                         * the markup stays readable and adding an entry is one line.
                         *
                         * Answers that quote a number (the free allowance, the grace
                         * period) must stay in step with the PlanSeeder defaults and
                         * config('billing.grace_period_days').
                         */
                        $faqs = [
                            [
                                'What counts as one set of minutes?',
                                'One generation from one transcript, all nine sections. Editing a section by hand afterwards is free and unlimited. Asking the model to regenerate a single section does not spend another full credit, and a generation that fails is never charged.',
                            ],
                            [
                                'Do I need a card to try it?',
                                'No. Registration puts you on the permanent free plan with three sets of minutes a month. There is no trial that expires and no card on file until you choose a paid plan.',
                            ],
                            [
                                'When does my allowance reset?',
                                'On your own billing anniversary, not on the 1st of the month. If you subscribed on the 14th, your allowance resets on the 14th.',
                            ],
                            [
                                'What happens if I cancel?',
                                'You keep your paid features until the end of the period you have already paid for, then move to the free plan. Nothing you have created is deleted: your meetings, transcripts and minutes stay exactly where they are, subject to free-plan limits on new generations.',
                            ],
                            [
                                'What if a payment fails?',
                                'Your workspace keeps working for ' . config('billing.grace_period_days') . ' days while you sort the card out. After that it moves to the free plan. You are never locked out of your own data over a payment problem.',
                            ],
                            [
                                'Can I change plans mid-month?',
                                'Yes, at any time. An upgrade takes effect immediately with a fresh allowance.',
                            ],
                            [
                                'How do seats work?',
                                'A seat is one person in your workspace. Members create and edit minutes, admins also manage the workspace and its members, and the owner handles billing. Removing someone frees their seat straight away.',
                            ],
                            [
                                'Is my transcript used to train models?',
                                'No. Your transcripts and minutes belong to your workspace and are not used to train anything. They are sent to the language model provider only to generate your minutes.',
                            ],
                            [
                                'Can I get my data out?',
                                'Always, on every plan including free. Markdown export is never restricted; paid plans add Word and PDF.',
                            ],
                        ];
                    @endphp

                    @foreach ($faqs as $index => [$question, $answer])
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button {{ $index === 0 ? '' : 'collapsed' }}"
                                        type="button" data-bs-toggle="collapse"
                                        data-bs-target="#faq-{{ $index }}"
                                        aria-expanded="{{ $index === 0 ? 'true' : 'false' }}"
                                        aria-controls="faq-{{ $index }}">
                                    {{ $question }}
                                </button>
                            </h3>
                            <div id="faq-{{ $index }}"
                                 class="accordion-collapse collapse {{ $index === 0 ? 'show' : '' }}"
                                 data-bs-parent="#pricing-faq">
                                <div class="accordion-body text-secondary">{{ $answer }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="row justify-content-center mt-6">
            <div class="col-lg-9 mn-reveal">
                <div class="card">
                    <div class="card-body d-flex flex-wrap align-items-center gap-3">
                        <div class="flex-fill">
                            <div class="fw-semibold">Need something else?</div>
                            <div class="text-secondary small">
                                Higher volumes, a bespoke arrangement, or an on-premise deployment:
                                get in touch and we will work it out.
                            </div>
                        </div>
                        <a href="mailto:{{ config('site.contact_email') }}" class="btn btn-primary">
                            <i class="ti ti-mail me-1"></i>{{ config('site.contact_email') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
