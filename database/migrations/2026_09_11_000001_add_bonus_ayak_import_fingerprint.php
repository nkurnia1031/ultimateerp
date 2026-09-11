<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bonus_ayak')) {
            return;
        }

        Schema::table('bonus_ayak', function (Blueprint $table) {
            if (! Schema::hasColumn('bonus_ayak', 'source_fingerprint')) {
                $table->string('source_fingerprint', 64)->nullable()->after('created_by');
                $table->unique(['business_id', 'source_fingerprint'], 'bonus_ayak_source_fingerprint_idx');
            }
        });

        // Existing manual/legacy rows are intentionally left nullable. Only
        // rows imported through the preview flow receive a deterministic key.
        $this->backfillUniqueFingerprints();
    }

    public function down(): void
    {
        if (! Schema::hasTable('bonus_ayak') || ! Schema::hasColumn('bonus_ayak', 'source_fingerprint')) {
            return;
        }

        Schema::table('bonus_ayak', function (Blueprint $table) {
            $table->dropIndex('bonus_ayak_source_fingerprint_idx');
            $table->dropColumn('source_fingerprint');
        });
    }

    private function backfillUniqueFingerprints(): void
    {
        DB::table('bonus_ayak')->whereNull('source_fingerprint')->orderBy('id')->chunkById(250, function ($rows) {
            foreach ($rows as $row) {
                $base = implode('|', [$row->business_id ?: 0, $row->tanggal, $row->driver_id ?: 0, $row->asset_id ?: 0, $row->trip]);
                $fingerprint = hash('sha256', $base);
                $fingerprint = DB::table('bonus_ayak')->where('source_fingerprint', $fingerprint)->exists()
                    ? hash('sha256', $base.'|legacy|'.$row->id)
                    : $fingerprint;
                DB::table('bonus_ayak')->where('id', $row->id)->update(['source_fingerprint' => $fingerprint]);
            }
        });
    }
};
