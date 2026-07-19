<?php

namespace Modules\Llm\Models;

use Modules\Core\Models\BaseModel;

/**
 * Versioned prompt bodies. Editing never mutates a version — it
 * creates version+1 and moves the is_active flag, and every
 * generation run records which version it used. The third-party spec
 * that seeds v1 WILL change; this is the audit trail for output drift.
 */
class PromptTemplate extends BaseModel
{
    protected $table = 'prompt_templates';

    protected $fillable = ['name', 'version', 'body', 'is_active'];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function active(string $name): ?self
    {
        return static::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Create the next version of this template and make it active.
     */
    public function publishNewVersion(string $body): self
    {
        $next = static::query()->where('name', $this->name)->max('version') + 1;

        static::query()->where('name', $this->name)->update(['is_active' => false]);

        return static::query()->create([
            'name' => $this->name,
            'version' => $next,
            'body' => $body,
            'is_active' => true,
        ]);
    }
}
