<?php

return [
    'name' => 'Core',

    // App-level identity used by layouts and exports.
    'app_label' => 'MeetingNotes',

    // Initial admin password for AdminUserSeeder. env() must only be
    // read inside config files (config caching breaks it elsewhere).
    'admin_seed_password' => env('ADMIN_SEED_PASSWORD'),
];
