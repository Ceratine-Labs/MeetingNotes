<?php

namespace Modules\Minutes\Models;

use Modules\Core\Models\BaseModel;

/**
 * Typed projection of sections.action_items — rebuilt from the JSON on
 * every (re)generation. due_date stays a string: the LLM reports what
 * the meeting said ("end of Q3", "[Not specified]"), not a parsed date.
 */
class ActionItem extends BaseModel
{
    protected $table = 'action_items';

    protected $fillable = [
        'meeting_id', 'ref', 'description', 'owner', 'due_date',
        'success_criteria', 'dependencies', 'priority', 'collaborators', 'sort',
    ];

    protected $casts = [
        'collaborators' => 'array',
        'sort' => 'integer',
    ];
}
