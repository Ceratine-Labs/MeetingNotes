<?php

namespace Modules\Minutes\Models;

use Modules\Core\Models\BaseModel;

/**
 * Typed projection of sections.decisions — queryable/exportable rows,
 * rebuilt from the JSON on every (re)generation.
 */
class Decision extends BaseModel
{
    protected $table = 'decisions';

    protected $fillable = [
        'meeting_id', 'ref', 'decision', 'made_by', 'rationale', 'conditions', 'impact', 'sort',
    ];

    protected $casts = ['sort' => 'integer'];
}
