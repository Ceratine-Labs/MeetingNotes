<?php

/*
|--------------------------------------------------------------------------
| Tenancy configuration
|--------------------------------------------------------------------------
|
| Merged under the `tenancy` key by TenancyServiceProvider. Values that a
| deployment might reasonably want to change are env-backed; the rest are
| constants that only make sense to change in code review.
|
*/

return [

    /*
    | Timezone applied to a new organisation when the person registering does
    | not pick one. Minutes carry meeting dates and times, so an incorrect
    | default produces wrong documents, not just an odd-looking clock.
    */
    'default_timezone' => env('TENANCY_DEFAULT_TIMEZONE', 'Africa/Johannesburg'),

    /*
    | How long an emailed invitation stays acceptable. Long enough to survive a
    | holiday, short enough that a forwarded email from months ago cannot still
    | let someone into a workspace.
    */
    'invitation_expiry_days' => (int) env('TENANCY_INVITATION_EXPIRY_DAYS', 14),

    /*
    | Cap on how many workspaces one user may create themselves. Not a
    | commercial limit — an abuse limit, so a single account cannot spin up
    | thousands of free-tier organisations to farm free generations.
    */
    'max_organisations_per_user' => (int) env('TENANCY_MAX_ORGANISATIONS_PER_USER', 5),

];
