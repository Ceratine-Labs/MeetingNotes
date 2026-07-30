<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completion tracking for action items, powering the cross-meeting register.
 *
 * These columns are human workflow state, NOT generator output: the model
 * never sets them. Because the action_items rows are a projection rebuilt on
 * every (re)generation, MinutesGenerator::persist() carries these values
 * across the rebuild by matching refs — see the comment there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_items', function (Blueprint $table) {
            $table->string('status')->default('open')->index();
            $table->timestamp('completed_at')->nullable();
            // Who ticked it off. No DB-level FK (house hard rule #1).
            $table->uuid('completed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('action_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'completed_at', 'completed_by']);
        });
    }
};
