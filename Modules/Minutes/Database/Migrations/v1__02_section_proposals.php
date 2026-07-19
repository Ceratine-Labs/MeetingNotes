<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.1 — per-section regeneration proposals. `regen_section` marks an
 * in-flight regen; `section_proposal` holds {section, value} awaiting
 * accept/discard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('regen_section')->nullable();
            $table->json('section_proposal')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['regen_section', 'section_proposal']);
        });
    }
};
