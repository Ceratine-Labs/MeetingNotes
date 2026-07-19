<?php

namespace Modules\Core\Traits;

use Illuminate\Support\Str;

/**
 * UUID primary keys for every model in this codebase — never
 * auto-increment (house hard rule #2).
 */
trait HasUuid
{
    public function initializeHasUuid(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid7();
            }
        });
    }
}
