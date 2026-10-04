<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('solar_import_batches') && ! Schema::hasColumn('solar_import_batches', 'preview_warnings')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->json('preview_warnings')->nullable();
            });
        }

        if (! Schema::hasTable('solar_import_batch_events')) {
            Schema::create('solar_import_batch_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('batch_id');
                $table->unsignedBigInteger('business_id');
                $table->unsignedInteger('sequence');
                $table->string('stage', 40);
                $table->string('level', 16)->default('info');
                $table->text('message');
                $table->unsignedTinyInteger('progress_percent')->nullable();
                $table->unsignedInteger('progress_current')->nullable();
                $table->unsignedInteger('progress_total')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['batch_id', 'sequence'], 'solar_batch_event_sequence_unique');
                $table->index(['business_id', 'batch_id', 'sequence'], 'solar_batch_event_scope_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('solar_import_batch_events');

        if (Schema::hasTable('solar_import_batches') && Schema::hasColumn('solar_import_batches', 'preview_warnings')) {
            Schema::table('solar_import_batches', function (Blueprint $table) {
                $table->dropColumn('preview_warnings');
            });
        }
    }
};
