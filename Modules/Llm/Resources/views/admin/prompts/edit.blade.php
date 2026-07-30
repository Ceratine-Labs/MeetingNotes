@extends('admin::layouts.app')

@section('title', 'Edit Prompt — MeetingNotes')

@section('content')
    <h1 class="h3 mb-1"><code>{{ $template->name }}</code> — v{{ $template->version }}</h1>
    <p class="text-secondary mb-4">
        Saving publishes a new version (v{{ \Modules\Llm\Models\PromptTemplate::query()->where('name', $template->name)->max('version') + 1 }})
        and makes it active. v{{ $template->version }} stays in history untouched.
    </p>

    <form method="POST" action="{{ route('llm.admin.prompts.version', $template) }}" style="max-width: 900px;">
        @csrf
        <div class="mb-3">
            <textarea name="body" rows="24" class="form-control font-monospace @error('body') is-invalid @enderror">{{ old('body', $template->body) }}</textarea>
            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-primary">Publish as new active version</button>
        <a href="{{ route('llm.admin.prompts') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
@endsection
