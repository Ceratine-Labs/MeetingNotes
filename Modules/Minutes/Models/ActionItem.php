<?php

namespace Modules\Minutes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Typed projection of sections.action_items — rebuilt from the JSON on
 * every (re)generation. due_date stays a string: the LLM reports what
 * the meeting said ("end of Q3", "[Not specified]"), not a parsed date.
 *
 * status / completed_at / completed_by are the exception to "projection":
 * they are human workflow state the generator never touches, and
 * MinutesGenerator::persist() carries them across rebuilds by ref.
 *
 * NOT organisation-scoped (see BelongsToOrganisation's docblock): the meeting
 * is the tenant boundary for this object graph. Any query that does not go
 * through a Meeting must constrain via the meeting relation — whereHas() picks
 * up Meeting's organisation scope — and route-bound instances must be
 * ownership-checked before use, as ActionItemController does.
 */
class ActionItem extends BaseModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    protected $table = 'action_items';

    protected $fillable = [
        'meeting_id', 'ref', 'description', 'owner', 'due_date',
        'success_criteria', 'dependencies', 'priority', 'collaborators', 'sort',
        'status', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'collaborators' => 'array',
        'sort' => 'integer',
        'completed_at' => 'datetime',
    ];

    /**
     * The minutes this item came out of. Organisation-scoped via Meeting,
     * which is what makes `$item->meeting()->exists()` an ownership test.
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    /**
     * Who marked it done, for the register's audit trail.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'completed_by');
    }

    /*
     * Both scopes qualify the column: the register's query joins meetings for
     * ordering, and meetings has a status column of its own.
     */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_OPEN);
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_DONE);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * Tick the item off / reopen it. Kept on the model so the two fields that
     * must move together (status + audit stamp) cannot drift apart at a call
     * site.
     */
    public function markDone(string $userId): void
    {
        $this->update([
            'status' => self::STATUS_DONE,
            'completed_at' => now(),
            'completed_by' => $userId,
        ]);
    }

    public function markOpen(): void
    {
        $this->update([
            'status' => self::STATUS_OPEN,
            'completed_at' => null,
            'completed_by' => null,
        ]);
    }
}
