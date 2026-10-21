<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_import_batches')
            || Schema::hasColumn('expense_import_batches', 'row_overrides')) {
            return;
        }

        Schema::table('expense_import_batches', function (Blueprint $table) {
            $table->json('row_overrides')->nullable()->after('date_overrides');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_import_batches')
            && Schema::hasColumn('expense_import_batches', 'row_overrides')) {
            Schema::table('expense_import_batches', function (Blueprint $table) {
                $table->dropColumn('row_overrides');
            });
        }
    }
};
