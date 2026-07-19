@extends('core::layouts.app')

@section('title', ($meeting->title ?? 'Minutes') . ' — MeetingNotes')

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
        <div class="row g-3">
            <div class="col d-none" id="source-pane">
                <div class="card h-100">
                    <div class="card-header">Source transcript</div>
                    <div class="card-body"><pre class="mb-0">{{ $meeting->transcript->raw_text }}</pre></div>
                </div>
            </div>
            <div class="col">
                <div class="card">
                    <div class="card-body" id="minutes-html">{!! $meeting->rendered_html !!}</div>
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
</script>
@endpush
