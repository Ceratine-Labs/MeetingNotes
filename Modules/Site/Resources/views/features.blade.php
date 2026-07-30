@extends('core::layouts.marketing')

@section('title', 'How it works: ' . config('app.name'))
@section('meta_description', 'How ' . config('app.name') . ' turns a raw transcript into professional minutes: a defined nine-section structure, chunked processing for long meetings, per-section regeneration, and exports to Word, PDF and Markdown.')
@section('robots', 'index, follow')

@section('content')
    <div class="mn-hero border-bottom">
        <div class="container-xl py-6">
            <div class="row justify-content-center text-center">
                <div class="col-lg-8">
                    <h1 class="display-6 fw-bold mb-3">How it works</h1>
                    <p class="fs-4 text-secondary mb-0">
                        A transcript goes in. A structured, professional document comes out, and
                        you stay in control of every section of it.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="container-xl py-6">
        <div class="row g-5">
            <div class="col-lg-7">
                <div class="mn-reveal">
                    <h2 class="h1 mb-4">The structure is the product</h2>

                    <p class="fs-5 text-secondary">
                        Most tools hand you a summary: a few paragraphs of prose that read well and
                        tell you nothing you can act on. This does something different. The model
                        fills a <strong>defined data structure</strong>, not a blank page.
                    </p>

                    <p class="text-secondary">
                        Decisions are rows in a decisions table. Action items are rows with an owner,
                        a due date and a priority. Attendance is a list of people with titles and
                        organisations. The document you read is rendered from that structure, which
                        means every set of minutes has the same shape, and the data behind them can be
                        searched, filtered and reported on later.
                    </p>

                    <p class="text-secondary">
                        It also means the model has much less room to wander. It is not asked to
                        "write minutes"; it is asked to fill named fields from the source material, and
                        to mark anything the source does not support as
                        <code>[Not specified]</code> rather than inventing it.
                    </p>
                </div>

                <div class="mn-reveal">
                    <h2 class="h1 mt-5 mb-4">Long meetings</h2>

                    <p class="text-secondary">
                        A three-hour transcript does not fit comfortably in one request, and stuffing
                        it in produces minutes that are detailed about the first half hour and vague
                        about everything after. So long transcripts are processed in overlapping
                        chunks. Each chunk is mined for facts on its own: attendees, decisions,
                        actions, figures quoted. A second pass then merges those extractions into the
                        final document, deduplicating the overlaps and flagging genuine contradictions
                        rather than silently picking one.
                    </p>

                    {{-- The chunking pipeline, drawn rather than described. Plain
                         divs styled by theme.css so it follows light and dark. --}}
                    <div class="card card-sm my-4">
                        <div class="card-body">
                            <div class="text-uppercase small fw-bold text-secondary mb-3">
                                Chunked processing, at a glance
                            </div>

                            <div class="mb-1 small text-secondary">Full transcript</div>
                            <div class="mn-chunk-bar mn-chunk-bar-full mb-3"></div>

                            <div class="mb-1 small text-secondary">Overlapping chunks, each mined for facts</div>
                            <div class="mn-chunk-bar mb-1" style="width: 44%;"></div>
                            <div class="mn-chunk-bar mb-1" style="width: 44%; margin-left: 28%;"></div>
                            <div class="mn-chunk-bar mb-3" style="width: 44%; margin-left: 56%;"></div>

                            <div class="text-center text-secondary my-2">
                                <i class="ti ti-arrow-down"></i>
                                <span class="small ms-1">merge, deduplicate, flag contradictions</span>
                            </div>

                            <div class="mn-chunk-doc p-3">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="ti ti-file-text text-primary"></i>
                                    <span class="fw-semibold small">One set of minutes, nine sections</span>
                                    <span class="badge bg-green-lt ms-auto">9 / 9</span>
                                </div>
                                <div class="row g-1">
                                    @foreach (range(1, 9) as $n)
                                        <div class="col-4">
                                            <div class="d-flex align-items-center gap-1 small text-secondary">
                                                <span class="mn-section-chip">{{ $n }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mn-reveal">
                    <h2 class="h1 mt-5 mb-4">You get the last word</h2>

                    <p class="text-secondary">
                        Every section is editable. Fix a misheard name and it is fixed. And if a whole
                        section came out weak, you can ask for just that section again. The model
                        re-reads the transcript for it, and you see the new version alongside the old
                        one and choose which to keep. Regenerating one section never disturbs the
                        eight you were happy with.
                    </p>
                </div>

                <div class="mn-reveal">
                    <h2 class="h1 mt-5 mb-4">Getting it out</h2>

                    <p class="text-secondary">
                        Minutes only matter once they are circulated. Export to <strong>Word</strong>
                        when somebody else needs to edit them, <strong>PDF</strong> when they need to be
                        distributed as a record, or <strong>Markdown</strong> for a wiki or a repository.
                        The page also prints cleanly: the app furniture drops away and you get just
                        the document.
                    </p>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card sticky-top" style="top: 5rem;">
                    <div class="card-header">
                        <h3 class="card-title">The nine sections</h3>
                    </div>
                    <div class="list-group list-group-flush">
                        @php
                            /*
                             * From docs/PRODUCT_SPEC.md, the received requirements
                             * document that defines the canonical output structure.
                             * Keep in step with the same list on the landing page.
                             */
                            $sections = [
                                'Meeting Information',
                                'Attendance',
                                'Discussion Summary',
                                'Decisions & Resolutions',
                                'Action Items',
                                'Parking Lot & Deferred Items',
                                'Supporting Materials',
                                'General Discussion',
                                'Next Steps',
                            ];
                        @endphp

                        @foreach ($sections as $index => $heading)
                            <div class="list-group-item d-flex align-items-center gap-3">
                                <span class="mn-section-chip">{{ $index + 1 }}</span>
                                <span>{{ $heading }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="card-footer text-secondary small">
                        Every generation fills all nine. Gaps are marked, not guessed.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="border-top bg-surface-secondary">
        <div class="container-xl py-6">
            <div class="text-center mb-5 mn-reveal">
                <h2 class="h1 mb-2">Details that matter in practice</h2>
            </div>

            <div class="row g-3">
                @php
                    $details = [
                        ['file-import', 'Paste or upload', 'Paste text straight in, or upload .txt, .docx or .pdf. Whatever your recorder, Teams, Zoom or note-taker produced.'],
                        ['users', 'Attribution', 'Key points and differing viewpoints are attributed to the people who made them, where the transcript supports it.'],
                        ['alert-triangle', 'Honest about gaps', 'A poor-quality or partial transcript is flagged as such in the record, rather than papered over with confident-sounding filler.'],
                        ['user-check', 'One owner per action', 'Every action item gets exactly one owner. Shared ownership is how action items die, so collaborators are listed separately.'],
                        ['building', 'Shared workspaces', 'Invite colleagues into a workspace. Members create and edit minutes; admins manage the workspace; the owner handles billing.'],
                        ['lock', 'Your data stays yours', 'Transcripts and minutes belong to your workspace and are never used to train anything. Markdown export is available on every plan, including free.'],
                    ];
                @endphp

                @foreach ($details as $i => [$icon, $heading, $detail])
                    <div class="col-md-6 col-lg-4 mn-reveal" style="--mn-reveal-delay: {{ ($i % 3) * 0.08 }}s;">
                        <div class="card card-sm h-100 mn-lift">
                            <div class="card-body">
                                <i class="ti ti-{{ $icon }} text-primary fs-2 mb-2 d-block"></i>
                                <div class="fw-semibold mb-1">{{ $heading }}</div>
                                <div class="text-secondary small">{{ $detail }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="text-center mt-5">
                @auth
                    <a href="{{ route('core.dashboard') }}" class="btn btn-primary btn-lg">
                        Open the app<i class="ti ti-arrow-right ms-1"></i>
                    </a>
                @else
                    <a href="{{ route('auth.register') }}" class="btn btn-primary btn-lg">
                        Try it free<i class="ti ti-arrow-right ms-1"></i>
                    </a>
                @endauth
            </div>
        </div>
    </div>
@endsection
