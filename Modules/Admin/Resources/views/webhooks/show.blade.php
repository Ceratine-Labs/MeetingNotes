@extends('admin::layouts.app')

@section('title', 'Webhook ' . $event->event_type . ' — back office')
@section('page_pretitle', 'Payment webhook')
@section('page_title', $event->event_type)

@section('page_actions')
    <a href="{{ route('admin.webhooks.index') }}" class="btn">
        <i class="ti ti-arrow-left me-1"></i>All webhooks
    </a>

    {{-- Safe to press repeatedly: every handler checks current state before changing
         anything, which it has to anyway because Paystack re-delivers. --}}
    <form method="POST" action="{{ route('admin.webhooks.replay', $event) }}"
          data-confirm="The handler will run again. Handlers are idempotent, so this is safe even if it already applied."
          data-confirm-title="Replay this event?"
          data-confirm-button="Yes, replay">
        @csrf
        <button type="submit" class="btn btn-primary">
            <i class="ti ti-refresh me-1"></i>Replay
        </button>
    </form>
@endsection

@section('content')
    <div class="row row-cards">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Event</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5 text-secondary">Type</dt>
                        <dd class="col-7"><code class="small">{{ $event->event_type }}</code></dd>

                        <dt class="col-5 text-secondary">Idempotency key</dt>
                        <dd class="col-7"><code class="small">{{ $event->event_id }}</code></dd>

                        <dt class="col-5 text-secondary">Received</dt>
                        <dd class="col-7">{{ $event->created_at->toDayDateTimeString() }}</dd>

                        <dt class="col-5 text-secondary">Handled</dt>
                        <dd class="col-7">
                            {{ $event->processed_at?->toDayDateTimeString() ?? 'Not yet' }}
                        </dd>

                        <dt class="col-5 text-secondary">Attempts</dt>
                        <dd class="col-7 mb-0">{{ $event->attempts }}</dd>
                    </dl>

                    @if ($event->error)
                        <div class="alert alert-danger mt-3 mb-0" role="alert">
                            <div class="small">{{ $event->error }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Payload</h3></div>
                <div class="card-body">
                    <pre class="mn-transcript mb-0">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        </div>
    </div>
@endsection
