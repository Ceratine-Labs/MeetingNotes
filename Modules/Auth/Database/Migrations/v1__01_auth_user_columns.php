<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auth v1 — columns the SaaS conversion adds to `users`.
 *
 * The `users` table itself is created by Laravel's own
 * 0001_01_01_000000_create_users_table migration (already run in every
 * environment), so this is an ALTER rather than a CREATE. It lives in the Auth
 * module because Auth owns the User model, and the columns are added in one
 * migration so a fresh install and an existing database converge on the same
 * schema in a single step.
 *
 * Note `role` is deliberately left alone here. It is the legacy
 * single-tenant admin flag; the SaaS back office uses a separate `admins` table
 * and guard (Admin module), and per-workspace permissions live on
 * organisation_user.role. Dropping the column is a follow-up once nothing reads
 * it — a v2 migration, not a silent change inside this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // UI preference, resolved server-side on every render so the first
            // paint is already correct. Nullable = "never chosen", which falls
            // through to the cookie and then to light. See ThemeService.
            $table->string('theme', 10)->nullable()->after('password');

            // Which workspace this user is currently acting in. A *hint* only —
            // membership is always re-verified before it is honoured, because a
            // user removed from a workspace still has its id here until
            // something clears it. See OrganisationResolver.
            $table->uuid('current_organisation_id')->nullable()->after('theme');

            // Support and abuse questions ("when did they last get in?") without
            // trawling session rows, which are pruned.
            $table->timestamp('last_login_at')->nullable()->after('current_organisation_id');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'theme',
                'current_organisation_id',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
