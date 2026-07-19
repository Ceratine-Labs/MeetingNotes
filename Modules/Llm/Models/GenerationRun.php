<?php

namespace Modules\Llm\Models;

use Modules\Core\Models\BaseModel;

class GenerationRun extends BaseModel
{
    protected $table = 'generation_runs';

    protected $fillable = [
        'meeting_id',
        'prompt_template_id',
        'task_type',
        'provider',
        'model',
        'tokens_in',
        'tokens_out',
        'cost_estimate',
        'latency_ms',
        'status',
        'error',
    ];

    protected $casts = [
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_estimate' => 'float',
        'latency_ms' => 'integer',
    ];
}
