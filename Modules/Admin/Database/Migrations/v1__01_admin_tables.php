<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin v1 — the back-office account table and its audit log.
 *
 * **Why a separate table instead of a flag on `users`.**
 *
 * This is the single most important structural decision in the module, so it is
 * worth stating plainly. Back-office accounts (Ryan and his partner) live in their
 * own table, authenticate through their own guard, and have their own password
 * reset flow. Consequences:
 *
 *   - No bug in the customer-facing auth path can produce a back-office session.
 *     A privilege-escalation flaw in registration, invitation acceptance or
 *     password reset gets an attacker a customer account, and stops there.
 *   - There is no `is_admin` column on `users` that a mass-assignment mistake can
 *     flip. The column does not exist, so it cannot be set.
 *   - Customer accounts and staff accounts have genuinely different lifecycles:
 *     customers self-register in their thousands, staff are provisioned
 *     deliberately and never self-register. One table would have to serve both
 *     patterns badly.
 *
 * The trade is that a staff member who also wants to use the product as a customer
 * needs two accounts. For a two-person company that is the correct trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // Revocation without deletion: an admin who leaves is deactivated, so
            // the audit log keeps a name to point at. AdminUser::canAuthenticate()
            // is what enforces it at login.
            $table->boolean('is_active')->default(true);

            // Back-office UI theme, same mechanism as users.theme.
            $table->string('theme', 10)->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        /*
         * Separate password-reset table from the customers' one.
         *
         * Laravel's broker keys reset tokens by email address in a single table. If
         * staff and customers shared it, a customer and an admin with the same
         * address would collide — and, worse, a reset token issued for one could be
         * presented to the other's broker. Separate tables make that impossible
         * rather than unlikely.
         */
        Schema::create('admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        /*
         * Back-office audit log.
         *
         * Staff can see and change other people's data, so every consequential
         * action they take is recorded. Append-only: no updated_at, no soft
         * deletes, nothing in the application ever edits a row here. An audit log
         * that can be quietly rewritten is not an audit log.
         */
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable so a failed login attempt against an unknown address can
            // still be recorded.
            $table->uuid('admin_id')->nullable()->index();

            // Denormalised deliberately: the log must stay readable after the
            // account is deactivated or renamed. This is a snapshot of who acted,
            // not a pointer that can change under the record.
            $table->string('admin_email')->nullable();

            // Dotted verb: 'admin.login', 'organisation.plan_changed',
            // 'user.impersonated', 'plan.updated'.
            $table->string('action')->index();

            // What was acted on — model class and key. Loose strings rather than a
            // polymorphic relation, because the target may be hard-deleted later
            // and the log entry must survive it.
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();

            // Before/after values, or any other detail worth keeping.
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // created_at only — a row here is a fact about a moment and is never
            // modified, so an updated_at column would be misleading.
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
        Schema::dropIfExists('admin_password_reset_tokens');
        Schema::dropIfExists('admins');
    }
};
