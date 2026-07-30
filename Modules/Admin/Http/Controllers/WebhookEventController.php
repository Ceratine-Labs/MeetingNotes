<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Admin\Services\AuditLogger;
use Modules\Billing\Models\WebhookEvent;
use Modules\Billing\Services\WebhookProcessor;

/**
 * Inspecting and replaying payment webhooks.
 *
 * This screen exists because of one specific failure mode: a webhook that arrives,
 * passes signature verification, and then fails to apply — because of a bug, or a
 * plan it could not match. Paystack retries for a while and then stops, and the
 * customer is left on the wrong plan with nothing visibly broken. These rows are the
 * only trace, so they need somewhere to be seen and a way to be re-run after the
 * cause is fixed.
 */
class WebhookEventController extends Controller
{
    public function __construct(
        private readonly WebhookProcessor $processor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Received events, unprocessed first.
     *
     * Ordering by `processed_at IS NULL DESC` puts everything still outstanding at
     * the top regardless of age — a stuck event from last week matters more than a
     * successful one from this morning.
     */
    public function index(Request $request): View
    {
        $filter = $request->query('filter', 'all');

        $events = WebhookEvent::query()
            ->when($filter === 'unprocessed', fn ($query) => $query->whereNull('processed_at'))
            ->when($filter === 'failed', fn ($query) => $query
                ->whereNull('processed_at')
                ->where('attempts', '>', 0))
            ->orderByRaw('processed_at IS NULL DESC')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin::webhooks.index', [
            'events' => $events,
            'filter' => $filter,
            'handledTypes' => WebhookProcessor::HANDLED,
        ]);
    }

    /**
     * One event, with its full payload.
     */
    public function show(string $event): View
    {
        return view('admin::webhooks.show', [
            'event' => WebhookEvent::query()->findOrFail($event),
        ]);
    }

    /**
     * Re-run an event's handler.
     *
     * Safe to press more than once: every handler is written to be idempotent (it
     * checks current state before changing anything), which is a requirement anyway
     * because Paystack itself re-delivers.
     *
     * Audited — a replay can change a customer's entitlement, so it belongs in the
     * log next to the manual plan changes.
     */
    public function replay(string $event): RedirectResponse
    {
        $target = WebhookEvent::query()->findOrFail($event);

        $this->processor->process($target);

        // Re-read: process() writes processed_at or the error, and the in-memory
        // instance is stale by the time the message below is composed.
        $target->refresh();

        $this->audit->record(AuditLogger::WEBHOOK_REPLAYED, $target, [
            'event_type' => $target->event_type,
            'event_id' => $target->event_id,
            'succeeded' => $target->isProcessed(),
        ]);

        return $target->isProcessed()
            ? back()->with('status', 'Event replayed and applied.')
            : back()->with('error', 'Replay failed again: '.($target->error ?? 'unknown error'));
    }
}
