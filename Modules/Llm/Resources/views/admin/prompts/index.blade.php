@extends('core::layouts.app')

@section('title', 'Prompt Templates — MeetingNotes')

@section('content')
    <h1 class="h3 mb-1">Prompt Templates</h1>
    <p class="text-secondary mb-4">
        Editing a template publishes a new version and activates it — versions are never
        rewritten, and every generation run records the version it used.
    </p>

    @foreach ($templates as $name => $versions)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <code>{{ $name }}</code>
                <span class="text-secondary small">{{ $versions->count() }} version(s)</span>
            </div>
            <table class="table table-sm mb-0 align-middle">
                <tbody>
                    @foreach ($versions as $template)
                        <tr>
                            <td style="width: 90px;">v{{ $template->version }}</td>
                            <td>
                                @if ($template->is_active)
                                    <span class="badge text-bg-success">active</span>
                                @else
                                    <span class="badge text-bg-secondary">inactive</span>
                                @endif
                            </td>
                            <td class="text-secondary small">{{ $template->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('llm.admin.prompts.edit', $template) }}" class="btn btn-sm btn-outline-primary">
                                    View / edit
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@endsection
