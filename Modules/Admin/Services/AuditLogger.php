<?php

namespace Modules\Admin\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\AuditLogEntry;

/**
 * Records what staff do in the back office.
 *
 * Staff can read and change data belonging to paying customers, so consequential
 * actions have to leave a trail — for accountability between Ryan and his partner,
 * and to be able to answer "who changed this customer's plan?" months later.
 *
 * What gets logged: sign-ins (including failures), anything that changes a
 * customer's entitlement or data, plan edits, and impersonation. What does not:
 * ordinary page views. Logging reads would bury the writes in noise, and it is the
 * writes that matter.
 */
class AuditLogger
{
    // Action verbs, kept as constants so a typo cannot silently create a second
    // action name that no report will ever group with the first.

    public const LOGIN = 'admin.login';

    public const LOGIN_FAILED = 'admin.login_failed';

    public const LOGOUT = 'admin.logout';

    public const PLAN_UPDATED = 'plan.updated';

    public const PLAN_PUSHED_TO_GATEWAY = 'plan.pushed_to_gateway';

    public const ORGANISATION_PLAN_CHANGED = 'organisation.plan_changed';

    public const USER_IMPERSONATED = 'user.impersonated';

    public const WEBHOOK_REPLAYED = 'webhook.replayed';

    /**
     * Write an audit entry.
     *
     * Failures here are swallowed after being reported. That is a considered
     * trade-off: an audit write that throws must not roll back or block the action
     * a staff member just took successfully. The alternative — losing a plan change
     * because the log insert failed — is worse, and report() still puts the gap in
     * front of a human.
     *
     * @param  string  $action  One of the self::* constants.
     * @param  Model|null  $target  What was acted on.
     * @param  array<string, mixed>  $context  Before/after values or other detail.
     *         Must never contain a password, token or card number.
     * @param  string|null  $actorEmail  Overrides the signed-in admin's address —
     *         used for failed logins, where there is no authenticated actor but the
     *         attempted address is the useful thing to record.
     */
    public function record(
        string $action,
        ?Model $target = null,
        array $context = [],
        ?string $actorEmail = null,
    ): void {
        try {
            $admin = Auth::guard('admin')->user();

            AuditLogEntry::query()->create([
                'admin_id' => $admin?->getKey(),
                'admin_email' => $actorEmail ?? $admin?->email,
                'action' => $action,
                'target_type' => $target !== null ? $target::class : null,
                'target_id' => $target?->getKey(),
                'context' => $context !== [] ? $context : null,
                'ip_address' => request()->ip(),
                // Truncated: some clients send very long strings, and the column is
                // for identifying a browser, not archiving it.
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write an admin audit log entry', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
