<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\InvitationController;
use Modules\Tenancy\Http\Controllers\MemberController;
use Modules\Tenancy\Http\Controllers\OrganisationController;

/*
|--------------------------------------------------------------------------
| Tenancy web routes
|--------------------------------------------------------------------------
|
| Registered by TenancyServiceProvider under the 'web' middleware. Note the
| three distinct protection levels below — the grouping is the authorisation
| model, so moving a route between groups changes who can reach it.
|
*/

/*
| 1. Public — the invite link from an email.
|
| No auth and no organisation middleware: the recipient may have no account
| and certainly has no membership yet. The token in the URL is the credential,
| and it is verified by hash lookup in InvitationService.
*/
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->name('tenancy.invitations.show');

Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('tenancy.invitations.accept');

/*
| 2. Signed in, but NOT inside a workspace.
|
| Creating a workspace and switching workspace cannot require the
| `organisation` middleware: create is where EnsureOrganisation sends a user
| who has no workspace (requiring one would be a redirect loop), and switch is
| the act of changing which one is bound.
*/
Route::middleware('auth')->prefix('app')->group(function () {
    Route::get('/workspaces/create', [OrganisationController::class, 'create'])
        ->name('tenancy.organisations.create');

    Route::post('/workspaces', [OrganisationController::class, 'store'])
        ->name('tenancy.organisations.store');

    Route::post('/workspaces/{organisation}/switch', [OrganisationController::class, 'switch'])
        ->name('tenancy.organisations.switch');
});

/*
| 3. Inside a workspace, admin or owner only.
|
| `organisation` binds the workspace; `organisation.role:admin` requires at
| least the admin role in it (owner passes too — roles are a hierarchy).
*/
Route::middleware(['auth', 'organisation', 'organisation.role:admin'])
    ->prefix('app/workspace')
    ->group(function () {
        Route::get('/settings', [OrganisationController::class, 'edit'])
            ->name('tenancy.organisations.edit');

        Route::put('/settings', [OrganisationController::class, 'update'])
            ->name('tenancy.organisations.update');

        Route::get('/members', [MemberController::class, 'index'])
            ->name('tenancy.members.index');

        Route::put('/members/{membership}/role', [MemberController::class, 'updateRole'])
            ->name('tenancy.members.role');

        Route::delete('/members/{membership}', [MemberController::class, 'destroy'])
            ->name('tenancy.members.destroy');

        Route::post('/invitations', [InvitationController::class, 'store'])
            ->name('tenancy.invitations.store');

        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->name('tenancy.invitations.destroy');
    });
