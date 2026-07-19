<?php

namespace Modules\Core\Models;

/**
 * Ledger of executed seeders — the seed master's memory. A class name
 * present here never runs again (except via an explicit
 * `seed:master --force=FQCN`, which appends a new batch row).
 */
class SeedRegistry extends BaseModel
{
    protected $table = 'seed_registry';

    protected $fillable = ['seeder_class', 'batch', 'checksum', 'executed_at'];

    protected $casts = [
        'batch' => 'integer',
        'executed_at' => 'datetime',
    ];
}
