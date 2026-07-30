<?php

return [
    'name' => 'Core',

    // App-level identity used by layouts and exports.
    // Falls back to the product name; kept as its own key so a deployment can show
    // something different in the UI to the APP_NAME used for mail and queues.
    'app_label' => env('APP_LABEL', env('APP_NAME', 'MeetingNotes')),

    // Initial admin password for AdminUserSeeder. env() must only be
    // read inside config files (config caching breaks it elsewhere).
    'admin_seed_password' => env('ADMIN_SEED_PASSWORD'),
];
