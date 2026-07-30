<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Admin\Models\AuditLogEntry;
use Modules\Admin\Services\AuditLogger;

/**
 * The back-office audit log.
 *
 * Read-only — there is no delete, no edit, and no bulk action. An audit log that
 * staff can prune is not evidence of anything. Retention is a database concern, not
 * a UI feature.
 */
class AuditLogController extends Controller
{
    /**
     * Audit entries, filterable by action.
     */
    public function index(Request $request): View
    {
        $action = $request->query('action');

        $entries = AuditLogEntry::query()
            ->with('admin')
            ->when(is_string($action) && $action !== '', fn ($query) => $query->where('action', $action))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin::audit.index', [
            'entries' => $entries,
            'action' => $action,
            // The known action verbs, so the filter is a select rather than a
            // free-text box that only matches if you guess the exact string.
            'actions' => [
                AuditLogger::LOGIN => 'Admin sign-in',
                AuditLogger::LOGIN_FAILED => 'Failed sign-in',
                AuditLogger::LOGOUT => 'Admin sign-out',
                AuditLogger::PLAN_UPDATED => 'Plan updated',
                AuditLogger::PLAN_PUSHED_TO_GATEWAY => 'Plan pushed to Paystack',
                AuditLogger::ORGANISATION_PLAN_CHANGED => 'Workspace plan changed',
                AuditLogger::USER_IMPERSONATED => 'User impersonated',
                AuditLogger::WEBHOOK_REPLAYED => 'Webhook replayed',
            ],
        ]);
    }
}
