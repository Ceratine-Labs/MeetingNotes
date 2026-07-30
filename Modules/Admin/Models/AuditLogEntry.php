<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\HasUuid;

/**
 * One recorded back-office action.
 *
 * Append-only. Extends Model directly rather than BaseModel because BaseModel
 * brings SoftDeletes, and a deletable audit log defeats the purpose. There is no
 * `updated_at` either — a row here is a fact about a moment, and a timestamp
 * implying it could be edited would be misleading.
 *
 * The actor's email is stored on the row alongside the id, which duplicates data on
 * purpose: the log has to stay readable after an account is deactivated, renamed or
 * removed. This is a snapshot of who acted, not a pointer that can change under the
 * record — one of the few places where copying beats referencing.
 *
 * @property string $id
 * @property string|null $admin_id
 * @property string|null $admin_email
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon $created_at
 */
class AuditLogEntry extends Model
{
    use HasUuid;

    /**
     * Only created_at is maintained; there is no updated_at column.
     */
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'admin_audit_log';

    protected $fillable = [
        'admin_id',
        'admin_email',
        'action',
        'target_type',
        'target_id',
        'context',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    /**
     * Human sentence for the log table, e.g. "Organisation plan changed".
     *
     * Derived from the dotted action verb rather than stored, so adding a new action
     * needs no lookup table — the cost is that action names must stay
     * self-describing.
     */
    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $this->action));
    }
}
