<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy v1 — organisation workspaces.
 *
 * An organisation is the unit that owns data and holds a subscription. A user
 * belongs to one or more organisations through `organisation_user` and always
 * acts within exactly one at a time (their "current" organisation).
 *
 * Deliberately NOT here:
 *   - Plan / subscription / quota columns. Billing owns those in its own
 *     tables keyed by organisation_id. Caching a plan code on the
 *     organisation row would create a second source of truth that drifts.
 *   - Foreign keys. House hard rule #1 — integrity is enforced in the
 *     service layer and Eloquent relationships, never by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');

            // Human-friendly identifier. Unique so it can safely appear in
            // URLs and invite emails later without a lookup collision.
            $table->string('slug')->unique();

            // The member who created it. Kept denormalised (rather than read
            // from organisation_user where role = owner) so the row is never
            // ownerless mid-transaction during a member shuffle.
            $table->uuid('owner_user_id')->index();

            // Minutes carry meeting dates and times; rendering them in the
            // wrong zone is a correctness bug, not a cosmetic one.
            $table->string('timezone')->default('Africa/Johannesburg');

            // Free-form per-org preferences (default export format, house
            // style overrides). JSON because it is read whole and never
            // queried by key.
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organisation_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->uuid('user_id')->index();

            // owner | admin | member — see Membership::ROLE_* constants.
            // owner:  billing + delete the org. Exactly one per org.
            // admin:  manage members and org settings, no billing.
            // member: create and edit minutes only.
            $table->string('role')->default('member');

            $table->uuid('invited_by_user_id')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One membership row per user per org. Not unique-with-deleted:
            // a removed member who is re-invited gets a fresh row, keeping
            // the soft-deleted original as an audit record.
            $table->index(['organisation_id', 'user_id']);
        });

        Schema::create('organisation_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->string('email')->index();
            $table->string('role')->default('member');

            // SHA-256 of the token that went out in the email — never the
            // token itself. A leaked database must not yield working invite
            // links, same reasoning as password hashing.
            $table->string('token_hash', 64)->unique();

            $table->uuid('invited_by_user_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->uuid('accepted_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_invitations');
        Schema::dropIfExists('organisation_user');
        Schema::dropIfExists('organisations');
    }
};
