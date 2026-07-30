<?php

/*
|--------------------------------------------------------------------------
| Site (public marketing) configuration
|--------------------------------------------------------------------------
|
| Merged under the `site` key by SiteServiceProvider. Anything a non-developer
| might reasonably need to change about the public pages lives here rather than
| being buried in a Blade template.
|
*/

return [

    /*
    | Who to contact. Rendered on the legal pages, which are worthless without a
    | real address — POPIA requires a reachable information officer, and a
    | placeholder there is a compliance gap, not a cosmetic one.
    */
    'contact_email' => env('SITE_CONTACT_EMAIL', 'hello@ceratine-labs.co.za'),
    'support_email' => env('SITE_SUPPORT_EMAIL', 'support@ceratine-labs.co.za'),

    /*
    | The legal entity behind the service, named on the terms and privacy pages.
    */
    'company_name' => env('SITE_COMPANY_NAME', 'Ceratine Labs'),
    'company_country' => env('SITE_COMPANY_COUNTRY', 'South Africa'),

    /*
    | Date the current terms and privacy policy took effect. Shown on both pages —
    | a policy with no effective date cannot be relied on by either side.
    |
    | Update this whenever the wording of those pages changes materially.
    */
    'legal_effective_date' => env('SITE_LEGAL_EFFECTIVE_DATE', '2026-07-30'),

    /*
    | How long a deleted account's data is retained before permanent erasure.
    | Stated in the privacy policy, so changing it here changes a published
    | commitment — check the copy still matches.
    */
    'data_retention_days' => (int) env('SITE_DATA_RETENTION_DAYS', 30),

];
