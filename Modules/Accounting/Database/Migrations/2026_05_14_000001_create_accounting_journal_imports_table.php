<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('accounting_journal_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id');
            $table->string('file_name')->nullable();
            $table->string('source_date_range')->nullable();
            $table->integer('total_entries')->default(0);
            $table->integer('imported_entries')->default(0);
            $table->integer('skipped_entries')->default(0);
            $table->integer('unbalanced_entries')->default(0);
            $table->integer('duplicate_entries')->default(0);
            $table->integer('missing_accounts')->default(0);
            $table->integer('unsupported_entries')->default(0);
            $table->integer('processed_entries')->default(0);
            $table->text('last_message')->nullable();
            $table->text('error_message')->nullable();
            $table->string('file_path')->nullable();
            $table->string('analysis_path')->nullable();
            $table->longText('import_options')->nullable();
            $table->string('status')->default('imported');
            $table->longText('summary')->nullable();
            $table->integer('created_by');
            $table->integer('reverted_by')->nullable();
            $table->dateTime('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_import_id')->nullable()->after('id');
            $table->string('import_source_key')->nullable()->after('journal_import_id');
            $table->index(['business_id', 'import_source_key'], 'aatm_business_import_source_key_index');
            $table->index('journal_import_id');
        });

        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_import_id')->nullable()->after('id');
            $table->index('journal_import_id');
        });
    }

    public function down()
    {
        Schema::table('accounting_accounts_transactions', function (Blueprint $table) {
            $table->dropIndex(['journal_import_id']);
            $table->dropColumn('journal_import_id');
        });

        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->dropIndex('aatm_business_import_source_key_index');
            $table->dropIndex(['journal_import_id']);
            $table->dropColumn(['journal_import_id', 'import_source_key']);
        });

        Schema::dropIfExists('accounting_journal_imports');
    }
};
