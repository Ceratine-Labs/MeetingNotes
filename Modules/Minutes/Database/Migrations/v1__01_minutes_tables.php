<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('title')->nullable();
            $table->date('meeting_date')->nullable();
            $table->string('source_type');            // paste | file
            $table->string('status')->default('draft');
            $table->string('progress_stage')->nullable();
            $table->text('error')->nullable();
            $table->json('sections')->nullable();
            $table->longText('rendered_html')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
        });

        Schema::create('transcripts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id')->index();
            $table->longText('raw_text');
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('token_estimate')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id')->index();
            $table->string('ref', 10);
            $table->text('decision');
            $table->string('made_by')->nullable();
            $table->text('rationale')->nullable();
            $table->text('conditions')->nullable();
            $table->text('impact')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('action_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id')->index();
            $table->string('ref', 10);
            $table->text('description');
            $table->string('owner');
            $table->string('due_date')->nullable();
            $table->text('success_criteria')->nullable();
            $table->text('dependencies')->nullable();
            $table->string('priority')->default('medium');
            $table->json('collaborators')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_items');
        Schema::dropIfExists('decisions');
        Schema::dropIfExists('transcripts');
        Schema::dropIfExists('meetings');
    }
};
