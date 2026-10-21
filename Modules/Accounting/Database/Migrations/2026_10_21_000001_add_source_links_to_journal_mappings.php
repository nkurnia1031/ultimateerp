<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addSourceRecordType = ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_type');
        $addSourceRecordId = ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_id');
        $addSourceLinkStatus = ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_link_status');
        $addSourceLinkNote = ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_link_note');

        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) use ($addSourceRecordType, $addSourceRecordId, $addSourceLinkStatus, $addSourceLinkNote) {
            if ($addSourceRecordType) {
                $table->string('source_record_type', 64)->nullable()->after('import_source_key');
            }
            if ($addSourceRecordId) {
                $table->unsignedBigInteger('source_record_id')->nullable()->after('source_record_type');
            }
            if ($addSourceLinkStatus) {
                $table->string('source_link_status', 20)->nullable()->after('source_record_id');
            }
            if ($addSourceLinkNote) {
                $table->text('source_link_note')->nullable()->after('source_link_status');
            }

            if ($addSourceRecordId) {
                $table->index(['business_id', 'source_record_type', 'source_record_id'], 'aatm_source_record_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_id')) {
            return;
        }

        Schema::table('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->dropIndex('aatm_source_record_index');
            $table->dropColumn(['source_record_type', 'source_record_id', 'source_link_status', 'source_link_note']);
        });
    }
};
