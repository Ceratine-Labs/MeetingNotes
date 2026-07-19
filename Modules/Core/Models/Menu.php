<?php

namespace Modules\Core\Models;

/**
 * One sidebar entry. The sidebar is 100% database-driven (house hard
 * rule #6) — modules seed their entries through MenuService::seed()
 * from a MenuSeeder, never by editing blade files.
 */
class Menu extends BaseModel
{
    protected $table = 'menus';

    protected $fillable = [
        'section',
        'label',
        'route_name',
        'icon',
        'required_role',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];
}
