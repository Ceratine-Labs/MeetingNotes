<?php

/*
|--------------------------------------------------------------------------
| Admin (back office) configuration
|--------------------------------------------------------------------------
|
| Merged under the `admin` key by AdminServiceProvider.
|
| The guard, user provider and password broker are NOT here — they are set in
| AdminServiceProvider::registerGuard(), so that deleting this module removes the
| back office cleanly without leaving config/auth.php pointing at classes that no
| longer exist.
|
*/

return [

    /*
    | Details for the first back-office account, created by AdminUserSeeder through
    | the seed master.
    |
    | The password is read from the environment ONLY. When it is absent the seeder
    | generates a random one and prints it to the console once — which is deliberate:
    | a hardcoded default admin password in a repository is how deployments get
    | compromised, so there is no fallback constant to forget to change.
    */
    'seed' => [
        'name' => env('ADMIN_SEED_NAME', 'Ryan Cruickshank'),
        'email' => env('ADMIN_SEED_EMAIL', 'ryan@ceratine-labs.co.za'),
        'password' => env('ADMIN_SEED_PASSWORD'),
    ],

    /*
    | Rows per page in the back-office listings. Higher than a customer-facing list
    | because these screens are used for scanning and reconciliation, where paging is
    | friction.
    */
    'per_page' => (int) env('ADMIN_PER_PAGE', 25),

];
