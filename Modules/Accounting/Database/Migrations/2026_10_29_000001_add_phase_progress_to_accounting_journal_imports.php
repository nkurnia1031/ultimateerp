<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_journal_imports')
            && ! Schema::hasColumn('accounting_journal_imports', 'progress_data')) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) {
                $table->longText('progress_data')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_journal_imports')
            && Schema::hasColumn('accounting_journal_imports', 'progress_data')) {
            Schema::table('accounting_journal_imports', function (Blueprint $table) {
                $table->dropColumn('progress_data');
            });
        }
    }
};
