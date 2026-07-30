@extends('core::layouts.marketing')

@section('title', config('app.name') . ': professional meeting minutes from your transcript')
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
                        <i class="ti ti-sparkles me-1"></i>Free to try, no card
                    </div>

                    <h1 class="display-6 fw-bold mb-3">
                        Turn a meeting transcript into minutes somebody can actually use.
                    </h1>

                    <p class="fs-4 text-secondary mb-4">
                        Paste your transcript. Get back complete, professional minutes: who
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
                        A looping, three-phase mock of the product doing its job:
                        transcript in, generation ticking through the nine
                        sections, finished minutes out. app.js cycles data-phase;
                        the CSS in theme.css does all the acting.

                        Ships with data-phase="3" so a no-JS or reduced-motion
                        visitor sees the finished minutes, which is also why the
                        content is static markup: it must not depend on a real
                        record existing.
                    --}}
                    <div class="card shadow-sm mn-demo" data-mn-demo data-phase="3">
                        <div class="card-header">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap">
                                <i class="ti ti-file-text text-primary"></i>
                                <div class="fw-semibold">Q3 Budget Review</div>
                                <div class="ms-auto d-flex gap-1">
                                    <span class="mn-demo-step" data-step="1">1 Transcript</span>
                                    <span class="mn-demo-step" data-step="2">2 Generate</span>
                                    <span class="mn-demo-step" data-step="3">3 Minutes</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body mn-demo-body">
                            {{-- Phase 1: the raw transcript, as a recorder produces it. --}}
                            <div class="mn-demo-pane" data-pane="1">
                                <div class="text-uppercase small fw-bold text-secondary mb-2">
                                    Pasted transcript
                                </div>
                                <div class="font-monospace small text-secondary lh-lg">
                                    <span class="mn-demo-line" style="--i: 0;">[00:02] Nadia: Right, budgets. The Q4 marketing number is sitting at 520.</span>
                                    <span class="mn-demo-line" style="--i: 1;">[00:41] Thabo: That was before the launch moved. Half of that spend lands too early now.</span>
                                    <span class="mn-demo-line" style="--i: 2;">[01:15] Nadia: Agreed. Take it down to 480 and shift the rest into Q1.</span>
                                    <span class="mn-demo-line" style="--i: 3;">[01:38] Sarah: I still think the launch date itself is soft. Product hasn't confirmed.</span>
                                    <span class="mn-demo-line" style="--i: 4;">[02:04] Nadia: Fair. Sarah, chase that this week. Thabo, recirculate the sheet.</span>
                                    <span class="mn-demo-line" style="--i: 5;">[02:19] Thabo: Will do, by Tuesday.</span>
                                </div>
                            </div>

                            {{-- Phase 2: the nine sections ticking off. --}}
                            <div class="mn-demo-pane" data-pane="2">
                                <div class="text-uppercase small fw-bold text-secondary mb-2">
                                    Generating minutes
                                </div>
                                <div class="progress progress-sm mb-3">
                                    <div class="progress-bar mn-demo-progress" role="progressbar"></div>
                                </div>
                                <div class="row g-0">
                                    @php
                                        // Display order mirrors the canonical nine sections below.
                                        $demoSections = [
                                            'Meeting Information', 'Attendance', 'Discussion Summary',
                                            'Decisions & Resolutions', 'Action Items', 'Parking Lot',
                                            'Supporting Materials', 'General Discussion', 'Next Steps',
                                        ];
                                    @endphp
                                    @foreach ($demoSections as $i => $name)
                                        <div class="col-6">
                                            <div class="mn-demo-check" style="--i: {{ $i }};">
                                                <i class="ti ti-circle-check text-green"></i>
                                                <span>{{ $name }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Phase 3: the finished document. --}}
                            <div class="mn-demo-pane" data-pane="3">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="text-uppercase small fw-bold text-secondary">
                                        4. Decisions &amp; Resolutions
                                    </div>
                                    <span class="badge bg-green-lt ms-auto">9 / 9 sections</span>
                                </div>

                                <div class="mn-demo-result mb-3" style="--i: 0;">
                                    <div class="small">
                                        <strong>D1.</strong> Approved the revised Q4 marketing budget of
                                        R480 000, reduced from R520 000.
                                        <span class="text-secondary">
                                            Approved by N. Cruickshank. Rationale: shifting spend to
                                            Q1 to align with the product launch.
                                        </span>
                                    </div>
                                </div>

                                <div class="mn-demo-result" style="--i: 1;">
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
                        <div class="card-footer text-secondary small">
                            <i class="ti ti-clock me-1"></i>From pasted transcript to circulated minutes in about a minute.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Stat strip. Values are duplicated in data-mn-count so the
                 count-up animation knows its target while the server-rendered
                 number stays correct without JS. --}}
            <div class="row g-3 text-center mt-4">
                @php
                    $stats = [
                        ['9', 'sections filled in, every time'],
                        ['60', 'seconds, give or take, per generation'],
                        ['3', 'free sets of minutes each month'],
                        ['1', 'owner on every action item'],
                    ];
                @endphp
                @foreach ($stats as $i => [$value, $label])
                    <div class="col-6 col-lg-3 mn-reveal" style="--mn-reveal-delay: {{ $i * 0.08 }}s;">
                        <div class="mn-stat-value" data-mn-count="{{ $value }}">{{ $value }}</div>
                        <div class="text-secondary small">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- The problem --}}
    <div class="container-xl py-6">
        <div class="row g-4 align-items-center">
            <div class="col-lg-5 mn-reveal">
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
                    @php
                        $problems = [
                            ['clock', 'Minutes in a minute', 'A 90-minute meeting transcript becomes a finished document in under a minute, not the following afternoon.'],
                            ['layout-list', 'The same structure every time', 'Nine defined sections, stored as structured data rather than free text. Every set of minutes is comparable with every other.'],
                            ['list-check', 'Nothing quietly dropped', 'Decisions numbered D1, D2. Actions numbered A1, A2, each with a single owner. Points buried mid-discussion get captured too.'],
                            ['eye-off', 'No invented facts', null],
                        ];
                    @endphp

                    @foreach ($problems as $i => [$icon, $heading, $detail])
                        <div class="col-md-6 mn-reveal" style="--mn-reveal-delay: {{ $i * 0.08 }}s;">
                            <div class="card card-sm h-100 mn-lift">
                                <div class="card-body">
                                    <i class="ti ti-{{ $icon }} text-primary fs-2 mb-2 d-block"></i>
                                    <div class="fw-semibold mb-1">{{ $heading }}</div>
                                    <div class="text-secondary small">
                                        @if ($detail)
                                            {{ $detail }}
                                        @else
                                            Anything the transcript does not support is marked
                                            <code>[Not specified]</code> rather than guessed at. A gap
                                            you can see beats a plausible fabrication.
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- The nine sections --}}
    <div class="border-top border-bottom bg-surface-secondary">
        <div class="container-xl py-6">
            <div class="text-center mb-5 mn-reveal">
                <h2 class="h1 mb-2">What comes back</h2>
                <p class="fs-5 text-secondary mb-0">
                    Every generation fills all nine sections. Anything the transcript did not
                    cover is marked as unspecified, not invented.
                </p>
            </div>

            @php
                /*
                 * The nine canonical sections from docs/PRODUCT_SPEC.md, the received
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
                    <div class="col-md-6 col-lg-4 mn-reveal" style="--mn-reveal-delay: {{ ($index % 3) * 0.08 }}s;">
                        <div class="card card-sm h-100 mn-lift">
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
        <div class="text-center mb-5 mn-reveal">
            <h2 class="h1 mb-2">Four steps</h2>
        </div>

        <div class="row g-4 mn-steps">
            @php
                $steps = [
                    ['upload', 'Paste or upload', 'Drop in a transcript, meeting notes, or a .txt/.docx/.pdf file. Whatever your recorder or note-taker produced.'],
                    ['wand', 'Generate', 'The model fills a defined structure, not free-form prose. Long transcripts are processed in chunks and merged.'],
                    ['edit', 'Edit and regenerate', 'Correct any section by hand, or ask for just that section again and accept the change side by side with the original.'],
                    ['download', 'Export and circulate', 'Word for editing, PDF for distributing, Markdown for your wiki. Or print straight from the page.'],
                ];
            @endphp

            @foreach ($steps as $i => [$icon, $heading, $detail])
                <div class="col-md-6 col-lg-3 mn-reveal" style="--mn-reveal-delay: {{ $i * 0.12 }}s;">
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
            <div class="text-center mb-5 mn-reveal">
                <h2 class="h1 mb-2">Simple pricing</h2>
                <p class="fs-5 text-secondary mb-0">
                    Start free. Upgrade when you are minuting more than a few meetings a month.
                </p>
            </div>

            <div class="row row-deck g-3">
                @foreach ($plans as $i => $plan)
                    <div class="col-md-6 col-lg-3 mn-reveal" style="--mn-reveal-delay: {{ $i * 0.08 }}s;">
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
        <div class="card mn-reveal">
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
