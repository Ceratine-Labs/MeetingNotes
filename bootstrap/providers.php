<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // The only module registered here — Core discovers and registers
    // every other module from its module.json (house pattern).
    Modules\Core\Providers\CoreServiceProvider::class,
];
