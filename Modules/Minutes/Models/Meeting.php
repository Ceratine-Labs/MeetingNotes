<?php

namespace Modules\Minutes\Models;

use Modules\Core\Models\BaseModel;

/**
 * One minutes record. `sections` (validated canonical JSON) is the
 * source of truth; `rendered_html` and the decisions/action_items
 * child rows are derived artifacts rebuilt on every (re)generation —
 * the defined storage point that keeps all minutes structurally
 * identical.
 */
class Meeting extends BaseModel
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'meetings';

    protected $fillable = [
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
    ];

    protected $casts = [
        'meeting_date' => 'date',
        'sections' => 'array',
    ];

    public function transcript()
    {
        return $this->hasOne(Transcript::class, 'meeting_id');
    }

    public function decisions()
    {
        return $this->hasMany(Decision::class, 'meeting_id')->orderBy('sort');
    }

    public function actionItems()
    {
        return $this->hasMany(ActionItem::class, 'meeting_id')->orderBy('sort');
    }

    public function user()
    {
        return $this->belongsTo(\Modules\Auth\Models\User::class, 'user_id');
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
