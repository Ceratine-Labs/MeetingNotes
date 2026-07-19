<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['name', 'version']);
        });

        Schema::create('generation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id')->nullable()->index();
            $table->uuid('prompt_template_id')->nullable();
            $table->string('task_type');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status')->default('ok');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_runs');
        Schema::dropIfExists('prompt_templates');
    }
};
