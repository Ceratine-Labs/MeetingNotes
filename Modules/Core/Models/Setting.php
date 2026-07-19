<?php

namespace Modules\Core\Models;

/**
 * Key-value application settings. Values flagged `is_encrypted` are
 * stored Crypt-encrypted at rest (LLM API keys, SMTP credentials, …)
 * and only ever decrypted server-side — never rendered back to the
 * browser once saved.
 */
class Setting extends BaseModel
{
    protected $table = 'settings';

    protected $fillable = ['key', 'value', 'group', 'is_encrypted'];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];
}
