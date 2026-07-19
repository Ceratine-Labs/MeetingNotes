@extends('core::layouts.app')

@section('title', 'Generation Log — MeetingNotes')

@section('content')
    <h1 class="h3 mb-4">Generation Log</h1>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-secondary small">Tokens in</div>
                <div class="fs-4">{{ number_format($totals['tokens_in']) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-secondary small">Tokens out</div>
                <div class="fs-4">{{ number_format($totals['tokens_out']) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-secondary small">Est. cost (USD)</div>
                <div class="fs-4">${{ number_format($totals['cost'], 4) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-secondary small">Errors</div>
                <div class="fs-4 {{ $totals['errors'] ? 'text-danger' : '' }}">{{ $totals['errors'] }}</div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>When</th><th>Task</th><th>Provider / model</th>
                    <th class="text-end">In</th><th class="text-end">Out</th>
                    <th class="text-end">Cost</th><th class="text-end">Latency</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($runs as $run)
                    <tr>
                        <td class="text-secondary small">{{ $run->created_at->format('Y-m-d H:i:s') }}</td>
                        <td><code class="small">{{ $run->task_type }}</code></td>
                        <td class="small">{{ $run->provider }} / {{ $run->model }}</td>
                        <td class="text-end small">{{ $run->tokens_in !== null ? number_format($run->tokens_in) : '—' }}</td>
                        <td class="text-end small">{{ $run->tokens_out !== null ? number_format($run->tokens_out) : '—' }}</td>
                        <td class="text-end small">{{ $run->cost_estimate !== null ? '$' . number_format($run->cost_estimate, 4) : '—' }}</td>
                        <td class="text-end small">{{ $run->latency_ms !== null ? $run->latency_ms . 'ms' : '—' }}</td>
                        <td>
                            @if ($run->status === 'ok')
                                <span class="badge text-bg-success">ok</span>
                            @else
                                <span class="badge text-bg-danger" title="{{ $run->error }}">error</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-secondary py-4">No generation runs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $runs->links() }}</div>
@endsection
