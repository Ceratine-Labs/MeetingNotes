<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auth v1.2 — drop the legacy `users.role` column.
 *
 * `role` was the single-tenant admin flag ('admin' | 'user'). Both of the things it
 * used to decide now live elsewhere:
 *
 *   - back-office access is the `admin` guard against the separate `admins` table
 *     (Modules/Admin);
 *   - per-workspace permissions are `organisation_user.role`
 *     (Membership::ROLE_OWNER|ADMIN|MEMBER).
 *
 * Nothing reads the column any more: the EnsureAdmin middleware and its `admin` alias
 * are gone, the Llm and Backup screens moved to the back office, and MenuService now
 * interprets `required_role` as a workspace role.
 *
 * Dropped rather than left in place because a dormant privilege column is a standing
 * hazard: any future mass-assignment mistake that set it would be granting a
 * privilege nothing checks today but something might tomorrow. The safest state for
 * an unused authorisation flag is not to exist.
 *
 * down() restores the column with its original default so a rollback is clean. The
 * per-row values are not recoverable, which is acceptable — the column had no
 * remaining meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so the migration is safe on a database where it has already been
        // applied by hand, or where the column never existed.
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');
        });
    }
};
