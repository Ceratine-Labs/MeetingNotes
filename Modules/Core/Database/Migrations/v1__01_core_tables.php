<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core v1 tables: seed ledger, settings, DB-driven sidebar.
 * UUID PKs, no DB-level foreign keys (house hard rules #1/#2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('seeder_class');
            $table->unsignedInteger('batch');
            $table->string('checksum', 64);
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->softDeletes();
            $table->index('seeder_class');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('section')->nullable();
            $table->string('label');
            $table->string('route_name')->unique();
            $table->string('icon')->nullable();
            $table->string('required_role')->nullable();
            $table->integer('sort')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('seed_registry');
    }
};
