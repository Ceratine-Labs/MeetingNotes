<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\HasUuid;

/**
 * Foundation Eloquent class for all domain models. MeetingNotes is
 * single-tenant, so unlike the Ceratine original there is no company
 * scope — this is the only base model.
 *
 * Referential integrity is application-layer only (house hard rule #1):
 * no DB-level foreign keys anywhere; relationships + FormRequests +
 * services enforce validity.
 */
abstract class BaseModel extends Model
{
    use HasUuid;
    use SoftDeletes;

    /** @var bool UUID primary keys — never auto-increment. */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Best-effort human label for dropdowns and logs. Prefers `name`,
     * then `title`, falls back to the UUID so the UI always renders.
     */
    public function getDisplayName(): string
    {
        return $this->name ?? $this->title ?? $this->getKey();
    }

    /**
     * Case-insensitive free-text search (PostgreSQL ILIKE, house
     * convention). Caller passes columns that exist on the model.
     */
    public function scopeSearch($query, string $search, array $fields = ['name'])
    {
        return $query->where(function ($q) use ($search, $fields) {
            foreach ($fields as $field) {
                $q->orWhere($field, 'ILIKE', "%{$search}%");
            }
        });
    }
}
