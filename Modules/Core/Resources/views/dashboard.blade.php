@extends('core::layouts.app')

@section('title', 'Dashboard — ' . config('app.name'))

@section('content')
    <h1 class="h3 mb-4">Dashboard</h1>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <div class="text-secondary small">Minutes library</div>
                    <div class="fs-4">Coming in Phase 2</div>
                    <div class="small text-secondary">Paste or upload a transcript, get structured minutes.</div>
                </div>
            </div>
        </div>
    </div>
@endsection
