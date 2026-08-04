<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('solar_import_batches')) {
            return;
        }

        Schema::table('solar_import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('solar_import_batches', 'operation')) {
                $table->string('operation', 20)->default('preview')->after('status')->index();
            }
            if (! Schema::hasColumn('solar_import_batches', 'process_state')) {
                $table->string('process_state', 20)->default('completed')->after('operation')->index();
            }
            if (! Schema::hasColumn('solar_import_batches', 'stage')) {
                $table->string('stage', 30)->nullable()->after('process_state');
            }
            if (! Schema::hasColumn('solar_import_batches', 'progress_current')) {
                $table->unsignedInteger('progress_current')->default(0)->after('stage');
            }
            if (! Schema::hasColumn('solar_import_batches', 'progress_total')) {
                $table->unsignedInteger('progress_total')->default(0)->after('progress_current');
            }
            if (! Schema::hasColumn('solar_import_batches', 'progress_percent')) {
                $table->unsignedTinyInteger('progress_percent')->default(0)->after('progress_total');
            }
            if (! Schema::hasColumn('solar_import_batches', 'progress_message')) {
                $table->string('progress_message', 255)->nullable()->after('progress_percent');
            }
            if (! Schema::hasColumn('solar_import_batches', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->after('progress_message')->index();
            }
            if (! Schema::hasColumn('solar_import_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('heartbeat_at');
            }
            if (! Schema::hasColumn('solar_import_batches', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('solar_import_batches', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('solar_import_batches', 'error_message')) {
                $table->text('error_message')->nullable()->after('failed_at');
            }
            if (! Schema::hasColumn('solar_import_batches', 'cancel_requested_at')) {
                $table->timestamp('cancel_requested_at')->nullable()->after('error_message');
            }
        });

        // Existing preview batches were already processed synchronously.
        Schema::getConnection()->table('solar_import_batches')
            ->whereNull('stage')
            ->update([
                'operation' => 'preview',
                'process_state' => 'completed',
                'stage' => 'finished',
                'progress_percent' => 100,
                'progress_message' => 'Preview selesai.',
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('solar_import_batches')) {
            return;
        }

        $columns = [
            'operation', 'process_state', 'stage', 'progress_current',
            'progress_total', 'progress_percent', 'progress_message',
            'heartbeat_at', 'started_at', 'completed_at', 'failed_at',
            'error_message', 'cancel_requested_at',
        ];
        $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('solar_import_batches', $column)));
        if ($columns !== []) {
            Schema::table('solar_import_batches', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
