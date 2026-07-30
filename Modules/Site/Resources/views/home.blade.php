@extends('core::layouts.marketing')

@section('title', config('app.name') . ' — professional meeting minutes from your transcript')
@section('meta_description', 'Paste a meeting transcript and get complete, professional minutes back: attendance, discussion, numbered decisions, action items with owners and due dates, parking lot and next steps. Same nine-section structure every time.')

{{-- Public pages are the only indexable ones; head.blade.php defaults the rest to noindex. --}}
@section('robots', 'index, follow')

@section('content')
    {{-- Hero --}}
    <div class="mn-hero border-bottom">
        <div class="container-xl py-6">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <div class="badge bg-primary-lt mb-3">
                        <i class="ti ti-sparkles me-1"></i>Free to try — no card
                    </div>

                    <h1 class="display-6 fw-bold mb-3">
                        Turn a meeting transcript into minutes somebody can actually use.
                    </h1>

                    <p class="fs-4 text-secondary mb-4">
                        Paste your transcript. Get back complete, professional minutes — who
                        attended, what was discussed, every decision numbered, every action item
                        with an owner and a due date. In the same structure every single time.
                    </p>

                    <div class="d-flex flex-wrap gap-2 mb-4">
                        @auth
                            <a href="{{ route('core.dashboard') }}" class="btn btn-primary btn-lg">
                                Open the app<i class="ti ti-arrow-right ms-1"></i>
                            </a>
                        @else
                            <a href="{{ route('auth.register') }}" class="btn btn-primary btn-lg">
                                Start free<i class="ti ti-arrow-right ms-1"></i>
                            </a>
                            <a href="{{ route('site.features') }}" class="btn btn-lg">
                                See what you get back
                            </a>
                        @endauth
                    </div>

                    <div class="text-secondary small">
                        <i class="ti ti-check text-green me-1"></i>Three sets of minutes free every month
                        <span class="mx-2">·</span>
                        <i class="ti ti-check text-green me-1"></i>No card required
                        <span class="mx-2">·</span>
                        <i class="ti ti-check text-green me-1"></i>Export to Word, PDF or Markdown
                    </div>
                </div>

                <div class="col-lg-6">
                    {{--
                        A mock of the output rather than a stock photograph. The
                        product's entire value is the shape of what comes back, so
                        showing that shape does more selling than any illustration.
                        Static markup on purpose — it must not depend on a real
                        record existing.
                    --}}
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <div class="d-flex align-items-center w-100">
                                <i class="ti ti-file-text text-primary me-2"></i>
                                <div>
                                    <div class="fw-semibold">Q3 Budget Review — Minutes</div>
                                    <div class="small text-secondary">Generated in about 40 seconds</div>
                                </div>
                                <span class="badge bg-green-lt ms-auto">9 / 9 sections</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="text-uppercase small fw-bold text-secondary mb-1">
                                    4. Decisions &amp; Resolutions
                                </div>
                                <div class="small">
                                    <strong>D1.</strong> Approved the revised Q4 marketing budget of
                                    R480 000, reduced from R520 000.
                                    <span class="text-secondary">
                                        Approved by N. Cruickshank. Rationale: shifting spend to
                                        Q1 to align with the product launch.
                                    </span>
                                </div>
                            </div>

                            <div>
                                <div class="text-uppercase small fw-bold text-secondary mb-1">
                                    5. Action Items
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0 small">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Action</th>
                                                <th>Owner</th>
                                                <th>Due</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>A1</td>
                                                <td>Circulate the revised budget spreadsheet</td>
                                                <td>T. Mahlangu</td>
                                                <td>12 Aug</td>
                                            </tr>
                                            <tr>
                                                <td>A2</td>
                                                <td>Confirm the launch date with the product team</td>
                                                <td>S. Naidoo</td>
                                                <td>15 Aug</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- The problem --}}
    <div class="container-xl py-6">
        <div class="row g-4 align-items-center">
            <div class="col-lg-5">
                <h2 class="h1 mb-3">Nobody wants to write up the meeting.</h2>
                <p class="fs-5 text-secondary">
                    So it gets done days later from half-remembered notes, or not at all. The
                    decision nobody wrote down gets re-litigated next month. The action item
                    with no owner never happens.
                </p>
                <p class="text-secondary">
                    Recording and transcribing a meeting is easy now. Turning that raw
                    transcript into a document a non-attendee can read is still the boring,
                    skilled, hour-long job. That is the part this does.
                </p>
            </div>

            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card card-sm h-100">
                            <div class="card-body">
                                <i class="ti ti-clock text-primary fs-2 mb-2 d-block"></i>
                                <div class="fw-semibold mb-1">Minutes in a minute</div>
                                <div class="text-secondary small">
                                    A 90-minute meeting transcript becomes a finished document in
                                    under a minute, not the following afternoon.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card card-sm h-100">
                            <div class="card-body">
                                <i class="ti ti-layout-list text-primary fs-2 mb-2 d-block"></i>
                                <div class="fw-semibold mb-1">The same structure every time</div>
                                <div class="text-secondary small">
                                    Nine defined sections, stored as structured data rather than
                                    free text. Every set of minutes is comparable with every other.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card card-sm h-100">
                            <div class="card-body">
                                <i class="ti ti-list-check text-primary fs-2 mb-2 d-block"></i>
                                <div class="fw-semibold mb-1">Nothing quietly dropped</div>
                                <div class="text-secondary small">
                                    Decisions numbered D1, D2. Actions numbered A1, A2, each with a
                                    single owner. Points buried mid-discussion get captured too.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card card-sm h-100">
                            <div class="card-body">
                                <i class="ti ti-eye-off text-primary fs-2 mb-2 d-block"></i>
                                <div class="fw-semibold mb-1">No invented facts</div>
                                <div class="text-secondary small">
                                    Anything the transcript does not support is marked
                                    <code>[Not specified]</code> rather than guessed at. A gap you
                                    can see beats a plausible fabrication.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- The nine sections --}}
    <div class="border-top border-bottom bg-surface-secondary">
        <div class="container-xl py-6">
            <div class="text-center mb-5">
                <h2 class="h1 mb-2">What comes back</h2>
                <p class="fs-5 text-secondary mb-0">
                    Every generation fills all nine sections. Anything the transcript did not
                    cover is marked as unspecified, not invented.
                </p>
            </div>

            @php
                /*
                 * The nine canonical sections from docs/PRODUCT_SPEC.md — the received
                 * third-party requirements document, which is the source of truth for
                 * what the generator produces. If that list changes, this changes.
                 */
                $sections = [
                    ['Meeting Information', 'Title, date and time, duration, location, meeting type, objective, chair.'],
                    ['Attendance', 'Present with titles and organisations, apologies with reasons, external guests.'],
                    ['Discussion Summary', 'Per agenda item: narrative, key points attributed to people, differing views, questions and answers, data presented.'],
                    ['Decisions & Resolutions', 'Numbered D1, D2… with who approved, the rationale, conditions and expected impact.'],
                    ['Action Items', 'Numbered A1, A2… with a single owner, due date, success criteria, dependencies and priority.'],
                    ['Parking Lot & Deferred', 'Tabled topics with the reason, ideas needing research, off-topic items captured rather than lost.'],
                    ['Supporting Materials', 'Documents, reports and presentations referenced, key figures, links, anything distributed.'],
                    ['General Discussion', 'On-purpose topics that matter for the record but do not warrant their own section.'],
                    ['Next Steps', 'Next meeting, interim checkpoints, communication plan, items to monitor.'],
                ];
            @endphp

            <div class="row g-3">
                @foreach ($sections as $index => [$heading, $detail])
                    <div class="col-md-6 col-lg-4">
                        <div class="card card-sm h-100">
                            <div class="card-body d-flex gap-3">
                                <span class="mn-section-chip">{{ $index + 1 }}</span>
                                <div>
                                    <div class="fw-semibold mb-1">{{ $heading }}</div>
                                    <div class="text-secondary small">{{ $detail }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- How --}}
    <div class="container-xl py-6">
        <div class="text-center mb-5">
            <h2 class="h1 mb-2">Four steps</h2>
        </div>

        <div class="row g-4">
            @php
                $steps = [
                    ['upload', 'Paste or upload', 'Drop in a transcript, meeting notes, or a .txt/.docx/.pdf file. Whatever your recorder or note-taker produced.'],
                    ['wand', 'Generate', 'The model fills a defined structure — not free-form prose. Long transcripts are processed in chunks and merged.'],
                    ['edit', 'Edit and regenerate', 'Correct any section by hand, or ask for just that section again and accept the change side by side with the original.'],
                    ['download', 'Export and circulate', 'Word for editing, PDF for distributing, Markdown for your wiki. Or print straight from the page.'],
                ];
            @endphp

            @foreach ($steps as $i => [$icon, $heading, $detail])
                <div class="col-md-6 col-lg-3">
                    <div class="text-center">
                        <span class="avatar avatar-lg bg-primary-lt mb-3">
                            <i class="ti ti-{{ $icon }} fs-2"></i>
                        </span>
                        <div class="fw-semibold mb-1">{{ $i + 1 }}. {{ $heading }}</div>
                        <div class="text-secondary small">{{ $detail }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Pricing --}}
    <div class="border-top bg-surface-secondary">
        <div class="container-xl py-6">
            <div class="text-center mb-5">
                <h2 class="h1 mb-2">Simple pricing</h2>
                <p class="fs-5 text-secondary mb-0">
                    Start free. Upgrade when you are minuting more than a few meetings a month.
                </p>
            </div>

            <div class="row row-deck g-3">
                @foreach ($plans as $plan)
                    <div class="col-md-6 col-lg-3">
                        {{-- Shared with the in-app plan picker so public and in-app
                             descriptions of a tier can never drift apart. --}}
                        @include('billing::partials.plan-card', [
                            'plan' => $plan,
                            'actionUrl' => auth()->check()
                                ? route('billing.plans')
                                : route('auth.register'),
                            'actionMethod' => 'get',
                            'actionLabel' => $plan->isFree() ? 'Start free' : 'Choose ' . $plan->name,
                        ])
                    </div>
                @endforeach
            </div>

            <div class="text-center mt-4">
                <a href="{{ route('site.pricing') }}" class="btn btn-link">
                    Full pricing detail<i class="ti ti-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- CTA --}}
    <div class="container-xl py-6">
        <div class="card">
            <div class="card-body text-center py-5">
                <h2 class="h1 mb-2">Try it on your last meeting</h2>
                <p class="fs-5 text-secondary mb-4">
                    Paste the transcript you already have. If the minutes are not worth
                    circulating, you have lost two minutes and nothing else.
                </p>
                @auth
                    <a href="{{ route('core.dashboard') }}" class="btn btn-primary btn-lg">
                        Open the app<i class="ti ti-arrow-right ms-1"></i>
                    </a>
                @else
                    <a href="{{ route('auth.register') }}" class="btn btn-primary btn-lg">
                        Create a free account<i class="ti ti-arrow-right ms-1"></i>
                    </a>
                    <div class="text-secondary small mt-3">No card. Three sets of minutes a month, free.</div>
                @endauth
            </div>
        </div>
    </div>
@endsection
