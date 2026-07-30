<?php

namespace Modules\Minutes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Minutes\Models\ActionItem;
use Modules\Minutes\Models\Meeting;

/**
 * The action items register: every action item across every meeting in the
 * workspace, in one list. This is the screen that turns a stack of individual
 * minutes into a living record of who owes what.
 *
 * ActionItem itself carries no organisation scope (the meeting is the tenant
 * boundary for the whole minutes graph), so every query here constrains
 * through the meeting relation — whereHas() applies Meeting's organisation
 * scope — and the route-bound item in update() is ownership-checked the same
 * way before it is touched.
 */
class ActionItemController extends Controller
{
    /**
     * The register. Defaults to open items, because "what is still owed" is
     * the question this screen exists to answer; done items are one filter
     * away for the audit trail.
     */
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();

        $items = ActionItem::query()
            ->whereHas('meeting', fn ($q) => $q->where('status', Meeting::STATUS_READY))
            ->with(['meeting:id,title,meeting_date', 'completedBy:id,name'])
            // '' (the default) and 'open' both mean open; 'all' applies neither.
            ->when(in_array($status, ['', 'open'], true), fn ($q) => $q->open())
            ->when($status === 'done', fn ($q) => $q->done())
            ->when($request->filled('owner'), fn ($q) => $q->where('owner', $request->string('owner')))
            ->when($request->filled('q'), fn ($q) => $q->search($request->string('q'), ['description', 'owner']))
            // Newest meetings first, then the items in the order they arose.
            // due_date cannot drive the sort: it is the LLM's transcription of
            // what the meeting said ("end of Q3"), not a parsed date.
            ->join('meetings', 'meetings.id', '=', 'action_items.meeting_id')
            ->orderByDesc('meetings.meeting_date')
            ->orderByDesc('meetings.created_at')
            ->orderBy('action_items.sort')
            ->select('action_items.*')
            ->paginate(50)
            ->withQueryString();

        /*
         * Owner filter options come from the workspace's own data — owners are
         * free-text names transcribed from meetings, so the list of real values
         * is the only sensible vocabulary. Meeting's organisation scope applies
         * inside whereHas, keeping the list tenant-clean.
         */
        $owners = ActionItem::query()
            ->whereHas('meeting')
            ->distinct()
            ->orderBy('owner')
            ->pluck('owner');

        $openCount = ActionItem::query()
            ->whereHas('meeting', fn ($q) => $q->where('status', Meeting::STATUS_READY))
            ->open()
            ->count();

        return view('minutes::actions.index', [
            'items' => $items,
            'owners' => $owners,
            'openCount' => $openCount,
            'status' => $status,
        ]);
    }

    /**
     * Tick an item off, or reopen it.
     */
    public function update(Request $request, ActionItem $actionItem): RedirectResponse
    {
        /*
         * Ownership check. Route binding resolved the item without any tenant
         * scope (ActionItem has none); meeting() DOES carry Meeting's
         * organisation scope, so this exists() is false for another
         * workspace's item and the request 404s rather than leaking or
         * mutating it.
         */
        abort_unless($actionItem->meeting()->exists(), 404);

        $validated = $request->validate([
            'status' => ['required', 'in:open,done'],
        ]);

        if ($validated['status'] === ActionItem::STATUS_DONE) {
            $actionItem->markDone($request->user()->id);
            $message = $actionItem->ref . ' marked done.';
        } else {
            $actionItem->markOpen();
            $message = $actionItem->ref . ' reopened.';
        }

        return back()->with('status', $message);
    }
}
