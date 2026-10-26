<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_journal_import_rows')
            && ! Schema::hasColumn('accounting_journal_import_rows', 'source_mutation_snapshot')) {
            Schema::table('accounting_journal_import_rows', function (Blueprint $table) {
                $table->longText('source_mutation_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_journal_import_rows')
            && Schema::hasColumn('accounting_journal_import_rows', 'source_mutation_snapshot')) {
            Schema::table('accounting_journal_import_rows', function (Blueprint $table) {
                $table->dropColumn('source_mutation_snapshot');
            });
        }
    }
};
