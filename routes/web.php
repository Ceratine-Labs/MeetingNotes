<?php

/*
|--------------------------------------------------------------------------
| Application web routes
|--------------------------------------------------------------------------
|
| Intentionally empty.
|
| Every route in this application belongs to a module and is registered by that
| module's service provider (house HMVC pattern). The public root "/" is owned by
| the Site module; the authenticated app lives under /app via Core, Tenancy,
| Billing and Minutes; the back office is under /admin via Admin.
|
| This file is still loaded by bootstrap/app.php because Laravel expects it, and
| it is the right home for a genuinely application-wide route belonging to no
| module. There is currently no such route — please keep it that way and add new
| routes to the owning module instead.
|
| It previously held `Route::redirect('/', '/app/dashboard')`, which had to go:
| "/" is now the public landing page.
|
*/
