<?php

namespace Modules\Minutes\Models;

use Modules\Core\Models\BaseModel;

class Transcript extends BaseModel
{
    protected $table = 'transcripts';

    protected $fillable = [
        'meeting_id',
        'raw_text',
        'original_filename',
        'file_path',
        'mime',
        'word_count',
        'token_estimate',
    ];

    protected $casts = [
        'word_count' => 'integer',
        'token_estimate' => 'integer',
    ];
}
