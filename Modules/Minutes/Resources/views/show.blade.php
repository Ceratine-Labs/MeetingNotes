@extends('core::layouts.app')

@section('title', ($meeting->title ?? 'Minutes') . ' — ' . config('app.name'))

@push('head')
<style>
    .mn-minutes h2 { font-size: 1.25rem; margin-top: 1.75rem; border-bottom: 1px solid var(--bs-border-color); padding-bottom: .35rem; }
    .mn-minutes h3 { font-size: 1.05rem; margin-top: 1rem; }
    .mn-minutes h4 { font-size: .9rem; margin-top: .75rem; text-transform: uppercase; letter-spacing: .04em; opacity: .75; }
    .mn-kv th { width: 180px; font-weight: 500; opacity: .7; text-align: left; vertical-align: top; padding: .25rem .75rem .25rem 0; }
    .mn-kv td { padding: .25rem 0; }
    .mn-actions { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .mn-actions th, .mn-actions td { border: 1px solid var(--bs-border-color); padding: .4rem .5rem; vertical-align: top; }
    .mn-na { opacity: .5; font-style: italic; }
    .mn-priority-high { color: var(--bs-danger); font-weight: 600; }
    .mn-priority-medium { color: var(--bs-warning); }
    .mn-priority-low { color: var(--bs-success); }
    .mn-quality { background: var(--bs-warning-bg-subtle); border-radius: .5rem; padding: .5rem 1rem; margin-top: 1.5rem; }
    #source-pane pre { white-space: pre-wrap; font-size: .8rem; max-height: 75vh; overflow-y: auto; }
</style>
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h3 mb-1">{{ $meeting->title ?? 'Untitled meeting' }}</h1>
            <div class="text-secondary small">
                {{ $meeting->meeting_date?->format('Y-m-d') ?? 'date unknown' }}
                · {{ $meeting->source_type === 'file' ? ($meeting->transcript->original_filename ?? 'file') : 'pasted text' }}
                @if ($meeting->model_used) · {{ $meeting->model_used }} (prompt v{{ $meeting->prompt_version }}) @endif
            </div>
        </div>
        <div class="d-flex gap-2">
            @if ($meeting->isReady())
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-download me-1"></i> Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('minutes.export', [$meeting, 'docx']) }}">
                            <i class="bi bi-file-earmark-word me-2"></i>Word (.docx)</a></li>
                        <li><a class="dropdown-item" href="{{ route('minutes.export', [$meeting, 'pdf']) }}">
                            <i class="bi bi-file-earmark-pdf me-2"></i>PDF</a></li>
                        <li><a class="dropdown-item" href="{{ route('minutes.export', [$meeting, 'md']) }}">
                            <i class="bi bi-filetype-md me-2"></i>Markdown</a></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-outline-secondary" id="toggle-source">
                    <i class="bi bi-layout-split me-1"></i> Source
                </button>
                <button type="button" class="btn btn-outline-secondary" id="copy-md" title="Copy as text">
                    <i class="bi bi-clipboard"></i>
                </button>
            @endif
            <a href="{{ route('minutes.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        </div>
    </div>

    @if ($meeting->isProcessing())
        <div class="card text-center py-5" id="processing-card"
             data-status-url="{{ route('minutes.status', $meeting) }}">
            <div class="card-body">
                <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
                <h2 class="h5">Generating minutes…</h2>
                <p class="text-secondary mb-0" id="progress-stage">{{ $meeting->progress_stage ?? 'queued' }}</p>
            </div>
        </div>
    @elseif ($meeting->status === 'failed')
        <div class="alert alert-danger">
            <h2 class="h6 mb-2"><i class="bi bi-exclamation-triangle me-1"></i> Generation failed</h2>
            <p class="mb-3">{{ $meeting->error }}</p>
            <form method="POST" action="{{ route('minutes.retry', $meeting) }}" class="d-inline">
                @csrf
                <button class="btn btn-sm btn-danger">Retry</button>
            </form>
        </div>
    @elseif ($meeting->isReady())
        <div id="proposal-panel" class="d-none">
            <div class="card border-info mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-arrow-repeat me-1"></i> Proposed regeneration — <code id="proposal-section"></code></span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success" id="proposal-accept">Accept</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="proposal-discard">Discard</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="text-secondary small mb-1">Current</div>
                            <div id="proposal-current" class="border rounded p-3"></div>
                        </div>
                        <div class="col-6">
                            <div class="text-secondary small mb-1">Proposed</div>
                            <div id="proposal-proposed" class="border border-info rounded p-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col d-none" id="source-pane">
                <div class="card h-100">
                    <div class="card-header">Source transcript</div>
                    <div class="card-body"><pre class="mb-0">{{ $meeting->transcript->raw_text }}</pre></div>
                </div>
            </div>
            <div class="col">
                <div class="card">
                    <div class="card-body" id="minutes-html"
                         data-regen-section="{{ $meeting->regen_section }}"
                         data-has-proposal="{{ $meeting->section_proposal ? '1' : '0' }}">{!! $meeting->rendered_html !!}</div>
                </div>
            </div>
        </div>

        {{-- Section JSON for the edit modal — struct only, never HTML. --}}
        <script type="application/json" id="sections-data">@json($meeting->sections)</script>

        <div class="modal fade" id="edit-modal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit section — <code id="edit-section-name"></code></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-secondary small">
                            Structured data for this section. Saving validates the shape, rebuilds the
                            decision/action rows and re-renders the minutes.
                        </p>
                        <textarea id="edit-json" rows="18" class="form-control font-monospace"></textarea>
                        <div id="edit-errors" class="text-danger small mt-2"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="edit-save">Save section</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    const processingCard = document.getElementById('processing-card');
    if (processingCard) {
        const poll = async () => {
            try {
                const res = await fetch(processingCard.dataset.statusUrl, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                if (data.status !== 'processing') { location.reload(); return; }
                document.getElementById('progress-stage').textContent = data.progress_stage || 'working…';
            } catch (e) { /* transient — keep polling */ }
            setTimeout(poll, 2500);
        };
        setTimeout(poll, 2500);
    }

    const toggleSource = document.getElementById('toggle-source');
    if (toggleSource) {
        toggleSource.addEventListener('click', () =>
            document.getElementById('source-pane').classList.toggle('d-none'));
    }

    const copyBtn = document.getElementById('copy-md');
    if (copyBtn) {
        copyBtn.addEventListener('click', async () => {
            await navigator.clipboard.writeText(document.getElementById('minutes-html').innerText);
            Swal.fire({ icon: 'success', title: 'Copied', timer: 1200, showConfirmButton: false, theme: 'dark' });
        });
    }

    // ---- Per-section edit / regenerate (ready state only) ----
    const minutesEl = document.getElementById('minutes-html');
    if (minutesEl) {
        const csrf = document.querySelector('meta[name=csrf-token]').content;
        const sections = JSON.parse(document.getElementById('sections-data').textContent);
        const base = '{{ url("/app/minutes/{$meeting->id}") }}';
        const post = (url, body) => fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
        });

        // Inject a small toolbar into each section heading.
        minutesEl.querySelectorAll('.mn-section[data-section]').forEach(sectionEl => {
            const key = sectionEl.dataset.section;
            if (key === 'quality_notes') return;
            const h2 = sectionEl.querySelector('h2');
            const tools = document.createElement('span');
            tools.className = 'float-end d-print-none';
            tools.innerHTML = `
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 me-1" data-edit="${key}" title="Edit section"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-regen="${key}" title="Regenerate section"><i class="bi bi-arrow-repeat"></i></button>`;
            h2.appendChild(tools);
        });

        // Edit modal.
        const editModalEl = document.getElementById('edit-modal');
        const editModal = new bootstrap.Modal(editModalEl);
        let editingSection = null;

        minutesEl.addEventListener('click', async (e) => {
            const editBtn = e.target.closest('[data-edit]');
            const regenBtn = e.target.closest('[data-regen]');

            if (editBtn) {
                editingSection = editBtn.dataset.edit;
                document.getElementById('edit-section-name').textContent = editingSection;
                document.getElementById('edit-json').value = JSON.stringify(sections[editingSection] ?? null, null, 2);
                document.getElementById('edit-errors').textContent = '';
                editModal.show();
            }

            if (regenBtn) {
                const key = regenBtn.dataset.regen;
                const confirmed = await Swal.fire({
                    text: `Regenerate "${key}" from the transcript? You'll see a diff before anything is applied.`,
                    icon: 'question', showCancelButton: true, confirmButtonText: 'Regenerate', theme: 'dark',
                });
                if (!confirmed.isConfirmed) return;
                const res = await post(`${base}/sections/${key}/regenerate`);
                if (!res.ok) {
                    const msg = (await res.json().catch(() => ({})))?.message || 'Could not start regeneration.';
                    Swal.fire({ icon: 'error', text: msg, theme: 'dark' });
                    return;
                }
                pollRegen();
                Swal.fire({ icon: 'info', text: 'Regenerating — the diff will appear at the top when ready.', timer: 2500, showConfirmButton: false, theme: 'dark' });
            }
        });

        document.getElementById('edit-save').addEventListener('click', async () => {
            let value;
            try {
                value = JSON.parse(document.getElementById('edit-json').value);
            } catch (e) {
                document.getElementById('edit-errors').textContent = 'Not valid JSON: ' + e.message;
                return;
            }
            const res = await fetch(`${base}/sections/${editingSection}`, {
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ value: JSON.stringify(value) }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) { location.reload(); return; }
            document.getElementById('edit-errors').textContent =
                (data.problems || [data.message || 'Save failed']).join('; ');
        });

        // Proposal diff panel.
        async function showProposal() {
            const res = await fetch(`${base}/proposal`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (data.error) {
                Swal.fire({ icon: 'error', title: 'Regeneration failed', text: data.error, theme: 'dark' });
                await post(`${base}/proposal/discard`);
                return;
            }
            const pick = (html) => {
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                return tmp.querySelector(`[data-section="${data.section}"]`)?.innerHTML || '';
            };
            document.getElementById('proposal-section').textContent = data.section;
            document.getElementById('proposal-current').innerHTML = pick(data.current_html);
            document.getElementById('proposal-proposed').innerHTML = pick(data.proposed_html);
            document.getElementById('proposal-panel').classList.remove('d-none');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        let regenTimer = null;
        function pollRegen() {
            if (regenTimer) return;
            regenTimer = setInterval(async () => {
                const res = await fetch('{{ route('minutes.status', $meeting) }}', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                if (!data.regen_section) {
                    clearInterval(regenTimer);
                    regenTimer = null;
                    if (data.has_proposal) showProposal();
                }
            }, 2500);
        }

        document.getElementById('proposal-accept').addEventListener('click', async () => {
            const res = await post(`${base}/proposal/accept`);
            if (res.ok) { location.reload(); return; }
            const data = await res.json().catch(() => ({}));
            Swal.fire({ icon: 'error', text: (data.problems || [data.message || 'Accept failed']).join('; '), theme: 'dark' });
        });
        document.getElementById('proposal-discard').addEventListener('click', async () => {
            await post(`${base}/proposal/discard`);
            document.getElementById('proposal-panel').classList.add('d-none');
        });

        if (minutesEl.dataset.hasProposal === '1') showProposal();
        if (minutesEl.dataset.regenSection) pollRegen();
    }
</script>
@endpush
