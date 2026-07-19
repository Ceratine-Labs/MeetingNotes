@extends('core::layouts.app')

@section('title', 'New Minutes — MeetingNotes')

@section('content')
    <h1 class="h3 mb-4">New minutes</h1>

    <form method="POST" action="{{ route('minutes.store') }}" enctype="multipart/form-data" style="max-width: 860px;">
        @csrf
        <input type="hidden" name="mode" id="mode" value="{{ old('mode', 'paste') }}">

        <div class="card mb-3">
            <div class="card-header p-0">
                <ul class="nav nav-tabs card-header-tabs m-2" role="tablist">
                    <li class="nav-item">
                        <button type="button" class="nav-link" id="tab-paste" data-mode="paste">
                            <i class="bi bi-clipboard me-1"></i> Paste
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" id="tab-upload" data-mode="upload">
                            <i class="bi bi-upload me-1"></i> Upload
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div id="pane-paste">
                    <label class="form-label" for="pasted_text">Transcript, notes or recording text</label>
                    <textarea name="pasted_text" id="pasted_text" rows="14"
                              class="form-control font-monospace @error('pasted_text') is-invalid @enderror"
                              placeholder="Paste the raw meeting content here…">{{ old('pasted_text') }}</textarea>
                    @error('pasted_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div id="pane-upload">
                    <label class="form-label" for="file">File ({{ implode(', ', array_map(fn ($e) => '.' . $e, $supported)) }} — max 20&nbsp;MB)</label>
                    <div id="dropzone" class="border border-2 border-secondary-subtle rounded p-5 text-center" style="border-style: dashed !important; cursor: pointer;">
                        <i class="bi bi-file-earmark-arrow-up fs-1 d-block mb-2 text-secondary"></i>
                        <span id="dropzone-label" class="text-secondary">Drag a file here or click to browse</span>
                        <input type="file" name="file" id="file" class="d-none"
                               accept="{{ implode(',', array_map(fn ($e) => '.' . $e, $supported)) }}">
                    </div>
                    @error('file')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    <div class="form-text">Scanned PDFs aren't supported yet — paste the text instead.</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Optional metadata <span class="text-secondary small">— used when the transcript doesn't say</span></div>
            <div class="card-body row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="title">Meeting title</label>
                    <input type="text" name="title" id="title" class="form-control" value="{{ old('title') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="meeting_date">Meeting date</label>
                    <input type="date" name="meeting_date" id="meeting_date" class="form-control" value="{{ old('meeting_date') }}">
                </div>
            </div>
        </div>

        <button class="btn btn-primary"><i class="bi bi-magic me-1"></i> Generate minutes</button>
        <a href="{{ route('minutes.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
@endsection

@push('scripts')
<script>
    const modeInput = document.getElementById('mode');

    function setMode(mode) {
        modeInput.value = mode;
        document.getElementById('tab-paste').classList.toggle('active', mode === 'paste');
        document.getElementById('tab-upload').classList.toggle('active', mode === 'upload');
        document.getElementById('pane-paste').style.display = mode === 'paste' ? '' : 'none';
        document.getElementById('pane-upload').style.display = mode === 'upload' ? '' : 'none';
    }
    document.querySelectorAll('[data-mode]').forEach(btn =>
        btn.addEventListener('click', () => setMode(btn.dataset.mode)));
    setMode(modeInput.value);

    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file');
    dropzone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            document.getElementById('dropzone-label').textContent = fileInput.files[0].name;
        }
    });
    ;['dragover', 'dragenter'].forEach(ev => dropzone.addEventListener(ev, e => {
        e.preventDefault();
        dropzone.classList.add('border-primary');
    }));
    ;['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, e => {
        e.preventDefault();
        dropzone.classList.remove('border-primary');
    }));
    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
            setMode('upload');
        }
    });
</script>
@endpush
