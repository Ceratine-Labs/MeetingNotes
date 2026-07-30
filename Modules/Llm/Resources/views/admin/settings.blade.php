@extends('admin::layouts.app')

@section('title', 'LLM Settings — ' . config('app.name'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">LLM Settings</h1>
        <button type="button" class="btn btn-outline-info" id="test-connection">
            <i class="bi bi-plug me-1"></i> Test connection
        </button>
    </div>

    <form method="POST" action="{{ route('llm.admin.settings.update') }}" style="max-width: 760px;">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-header">Provider</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="provider">Active provider</label>
                    <select name="provider" id="provider" class="form-select @error('provider') is-invalid @enderror">
                        @foreach ($providers as $key => $label)
                            <option value="{{ $key }}" @selected(old('provider', $current['provider']) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('provider')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3" data-provider-field="anthropic">
                    <label class="form-label" for="anthropic_api_key">Anthropic API key</label>
                    <input type="password" name="anthropic_api_key" id="anthropic_api_key" class="form-control"
                           autocomplete="off"
                           placeholder="{{ $keyHints['anthropic'] ? 'Configured — ends in ' . $keyHints['anthropic'] . ' (leave blank to keep)' : 'sk-ant-…' }}">
                </div>

                <div class="mb-3" data-provider-field="openai">
                    <label class="form-label" for="openai_api_key">OpenAI API key</label>
                    <input type="password" name="openai_api_key" id="openai_api_key" class="form-control"
                           autocomplete="off"
                           placeholder="{{ $keyHints['openai'] ? 'Configured — ends in ' . $keyHints['openai'] . ' (leave blank to keep)' : 'sk-…' }}">
                </div>

                <div data-provider-field="openai_compatible">
                    <div class="mb-3">
                        <label class="form-label" for="compatible_base_url">Base URL</label>
                        <input type="url" name="compatible_base_url" id="compatible_base_url"
                               class="form-control @error('compatible_base_url') is-invalid @enderror"
                               value="{{ old('compatible_base_url', $current['compatible_base_url']) }}"
                               placeholder="http://localhost:11434/v1">
                        @error('compatible_base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Ollama, LM Studio, vLLM, OpenRouter — any OpenAI-compatible endpoint.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="compatible_api_key">API key (if required)</label>
                        <input type="password" name="compatible_api_key" id="compatible_api_key" class="form-control"
                               autocomplete="off"
                               placeholder="{{ $keyHints['compatible'] ? 'Configured — ends in ' . $keyHints['compatible'] . ' (leave blank to keep)' : 'Optional' }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Model per task</div>
            <div class="card-body">
                @foreach ($taskTypes as $type => $label)
                    <div class="mb-3">
                        <label class="form-label" for="model_{{ $type }}">{{ $label }}</label>
                        <input type="text" name="models[{{ $type }}]" id="model_{{ $type }}" class="form-control"
                               value="{{ old("models.$type", $current['models'][$type]) }}"
                               data-model-input data-task="{{ $type }}"
                               placeholder="Provider default">
                        <div class="form-text" data-model-default data-task="{{ $type }}"></div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Generation parameters</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="temperature">Temperature</label>
                        <input type="number" step="0.1" min="0" max="2" name="temperature" id="temperature"
                               class="form-control @error('temperature') is-invalid @enderror"
                               value="{{ old('temperature', $current['temperature']) }}">
                        @error('temperature')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="max_tokens">Max output tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens"
                               class="form-control @error('max_tokens') is-invalid @enderror"
                               value="{{ old('max_tokens', $current['max_tokens']) }}">
                        @error('max_tokens')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="timeout">Request timeout (s)</label>
                        <input type="number" name="timeout" id="timeout"
                               class="form-control @error('timeout') is-invalid @enderror"
                               value="{{ old('timeout', $current['timeout']) }}">
                        @error('timeout')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <button class="btn btn-primary">Save settings</button>
    </form>
@endsection

@push('scripts')
<script>
    const defaults = @json($defaultModels);

    function syncProviderFields() {
        const provider = document.getElementById('provider').value;
        document.querySelectorAll('[data-provider-field]').forEach(el => {
            el.style.display = el.dataset.providerField === provider ? '' : 'none';
        });
        document.querySelectorAll('[data-model-default]').forEach(el => {
            const d = (defaults[provider] || {})[el.dataset.task];
            el.textContent = d ? `Default: ${d}` : 'No default — set explicitly.';
        });
    }
    document.getElementById('provider').addEventListener('change', syncProviderFields);
    syncProviderFields();

    document.getElementById('test-connection').addEventListener('click', async function () {
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing…';
        try {
            const res = await fetch('{{ route('llm.admin.settings.test') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            Swal.fire({
                icon: data.ok ? 'success' : 'error',
                title: data.ok ? 'Connected' : 'Connection failed',
                text: data.message,
                theme: 'dark',
            });
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Connection failed', text: String(e), theme: 'dark' });
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-plug me-1"></i> Test connection';
        }
    });
</script>
@endpush
