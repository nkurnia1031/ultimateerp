<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('accounting_journal_imports', function (Blueprint $table) {
            if (! Schema::hasColumn('accounting_journal_imports', 'processed_entries')) {
                $table->integer('processed_entries')->default(0)->after('unsupported_entries');
            }

            if (! Schema::hasColumn('accounting_journal_imports', 'last_message')) {
                $table->text('last_message')->nullable()->after('processed_entries');
            }

            if (! Schema::hasColumn('accounting_journal_imports', 'error_message')) {
                $table->text('error_message')->nullable()->after('last_message');
            }

            if (! Schema::hasColumn('accounting_journal_imports', 'file_path')) {
                $table->string('file_path')->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('accounting_journal_imports', 'analysis_path')) {
                $table->string('analysis_path')->nullable()->after('file_path');
            }

            if (! Schema::hasColumn('accounting_journal_imports', 'import_options')) {
                $table->longText('import_options')->nullable()->after('analysis_path');
            }
        });
    }

    public function down()
    {
        Schema::table('accounting_journal_imports', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_journal_imports', 'error_message')) {
                $table->dropColumn('error_message');
            }

            if (Schema::hasColumn('accounting_journal_imports', 'last_message')) {
                $table->dropColumn('last_message');
            }

            if (Schema::hasColumn('accounting_journal_imports', 'processed_entries')) {
                $table->dropColumn('processed_entries');
            }

            if (Schema::hasColumn('accounting_journal_imports', 'import_options')) {
                $table->dropColumn('import_options');
            }

            if (Schema::hasColumn('accounting_journal_imports', 'analysis_path')) {
                $table->dropColumn('analysis_path');
            }

            if (Schema::hasColumn('accounting_journal_imports', 'file_path')) {
                $table->dropColumn('file_path');
            }
        });
    }
};
