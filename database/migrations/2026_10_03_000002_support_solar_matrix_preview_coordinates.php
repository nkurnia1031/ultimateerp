<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'solar_preview_batch_ledger_row_unique';
    private const NEW_INDEX = 'solar_preview_batch_sheet_cell_unique';

    public function up(): void
    {
        if (! Schema::hasTable('solar_import_preview_rows')) {
            return;
        }

        if (! Schema::hasColumn('solar_import_preview_rows', 'source_column')) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->string('source_column', 10)->default('')->after('source_row');
            });
        }

        if ($this->hasIndex(self::OLD_INDEX)) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->dropUnique(self::OLD_INDEX);
            });
        }

        if (! $this->hasIndex(self::NEW_INDEX)) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->unique(
                    ['batch_id', 'ledger', 'source_sheet', 'source_row', 'source_column'],
                    self::NEW_INDEX
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('solar_import_preview_rows')
            || ! Schema::hasColumn('solar_import_preview_rows', 'source_column')) {
            return;
        }

        if ($this->hasIndex(self::NEW_INDEX)) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->dropUnique(self::NEW_INDEX);
            });
        }

        $hasRepeatedSourceRows = DB::table('solar_import_preview_rows')
            ->select('batch_id', 'ledger', 'source_row')
            ->whereNotNull('source_row')
            ->groupBy('batch_id', 'ledger', 'source_row')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRepeatedSourceRows) {
            throw new RuntimeException(
                'Cannot restore the previous Solar preview row index while matrix preview cells share source rows.'
            );
        }

        if (! $this->hasIndex(self::OLD_INDEX)) {
            Schema::table('solar_import_preview_rows', function (Blueprint $table) {
                $table->unique(['batch_id', 'ledger', 'source_row'], self::OLD_INDEX);
            });
        }

        Schema::table('solar_import_preview_rows', function (Blueprint $table) {
            $table->dropColumn('source_column');
        });
    }

    private function hasIndex(string $name): bool
    {
        return collect(DB::select('SHOW INDEX FROM `solar_import_preview_rows`'))
            ->contains(fn ($index) => ($index->Key_name ?? null) === $name);
    }
};
