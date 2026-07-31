<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Modules\Admin\Http\Middleware\AuthenticateAdmin;
use Modules\Core\Services\ThemeService;
use Modules\Tenancy\Http\Middleware\EnsureOrganisation;
use Modules\Tenancy\Http\Middleware\EnsureOrganisationRole;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Guests hitting an auth-only route land on the customer login page.
        // The admin back office overrides this for its own routes — see
        // Modules/Admin/Http/Middleware/AuthenticateAdmin.
        $middleware->redirectGuestsTo(fn () => route('auth.login'));

        /*
         * Session-integrity guard for the whole web group.
         *
         * This is what makes "sign out my other devices" actually work: it
         * stamps the password hash into the session and re-checks it on every
         * request, so rotating the password (ProfileController::updatePassword,
         * or a password reset) invalidates every OTHER session immediately.
         *
         * Without it, `logoutOtherDevices()` silently does nothing useful and a
         * user who changes their password because they suspect someone has it
         * would not actually evict them.
         */
        /*
         * The theme cookie is written and read by JavaScript as well as by PHP, so it
         * must NOT be encrypted.
         *
         * Laravel encrypts every cookie by default and silently DISCARDS any it cannot
         * decrypt on the way in. That meant a plaintext `mn_theme` set by the browser
         * never reached PHP at all, so a guest's theme choice was dropped on the next
         * request and the site snapped back to the default.
         */
        $middleware->encryptCookies(except: [
            ThemeService::COOKIE,
        ]);

        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        /*
         * Middleware ORDER, not just membership.
         *
         * Laravel's priority list forces `SubstituteBindings` (route-model binding) to
         * run after `auth`. Middleware that is NOT in that list keeps its declared
         * position — which put our `organisation` middleware AFTER binding.
         *
         * That is a real bug, not a nicety. Route-model binding resolves tenant-owned
         * models (`/app/minutes/{meeting}`), and it was doing so before any
         * organisation was bound. The OrganisationScope then saw an unbound context
         * and, in a browser request, would throw MissingOrganisationContextException —
         * a 500 on every single show/edit/export route.
         *
         * Inserting these before SubstituteBindings guarantees the tenant is bound
         * before anything looks a tenant-owned model up. AuthenticateAdmin is included
         * for a related reason: an unauthenticated request should be rejected before it
         * starts loading records out of the database.
         */
        foreach ([EnsureOrganisation::class, EnsureOrganisationRole::class, AuthenticateAdmin::class] as $middlewareClass) {
            // One call each — prependToPriorityList() takes a single class, not a list.
            $middleware->prependToPriorityList(
                before: SubstituteBindings::class,
                prepend: $middlewareClass,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Render errors as JSON when the CLIENT asked for JSON — not only under api/*.
         *
         * The previous rule was `$request->is('api/*')` alone, which meant every
         * fetch()-driven endpoint outside that prefix (the theme toggle, workspace
         * search, the generation status poll, section edits) received a 302 to an HTML
         * page on a validation failure instead of a 422 with the errors. The browser
         * would then try to parse HTML as JSON and the real problem stayed invisible.
         *
         * expectsJson() covers Accept: application/json and XHR requests, which is
         * exactly the set of callers that cannot do anything useful with HTML.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
