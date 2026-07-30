<?php

namespace Modules\Minutes\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\BaseModel;
use Modules\Tenancy\Concerns\BelongsToOrganisation;

/**
 * One minutes record. `sections` (validated canonical JSON) is the
 * source of truth; `rendered_html` and the decisions/action_items
 * child rows are derived artifacts rebuilt on every (re)generation —
 * the defined storage point that keeps all minutes structurally
 * identical.
 *
 * This is the tenant boundary for the whole minutes object graph. Transcripts,
 * decisions and action items are reached only through a meeting, so scoping here
 * is what isolates them — they carry no organisation_id of their own, precisely so
 * they cannot end up disagreeing with their parent.
 *
 * A meeting with a NULL organisation_id is invisible to every workspace rather
 * than visible to all of them (the scope matches an exact id). That is how
 * pre-SaaS rows stay safe until `php artisan saas:backfill` assigns them an
 * owner.
 */
class Meeting extends BaseModel
{
    use BelongsToOrganisation;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'meetings';

    protected $fillable = [
        // Stamped automatically from the bound organisation on create (see
        // BelongsToOrganisation). Fillable so the backfill command and the admin
        // back office can assign it explicitly.
        'organisation_id',
        'user_id',
        'title',
        'meeting_date',
        'source_type',
        'status',
        'progress_stage',
        'error',
        'sections',
        'rendered_html',
        'model_used',
        'prompt_version',
        'regen_section',
        'section_proposal',
    ];

    protected $casts = [
        'meeting_date' => 'date',
        'sections' => 'array',
        'section_proposal' => 'array',
    ];

    /**
     * The source material this record was generated from.
     */
    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class, 'meeting_id');
    }

    /**
     * Decision rows (D1, D2…), in the order they arose.
     *
     * Derived from the canonical `sections` JSON and rebuilt on every
     * regeneration — never edited directly.
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'meeting_id')->orderBy('sort');
    }

    /**
     * Action item rows (A1, A2…), in the order they arose.
     */
    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'meeting_id')->orderBy('sort');
    }

    /**
     * Who created the record.
     *
     * Distinct from the owning organisation: the workspace owns the data, this is
     * just attribution for the UI.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'user_id');
    }

    /**
     * Is generation currently running?
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Are the minutes generated and safe to read, edit and export?
     */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
