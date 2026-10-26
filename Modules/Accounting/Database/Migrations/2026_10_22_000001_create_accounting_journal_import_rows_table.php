<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_journal_import_rows')) {
            return;
        }

        Schema::create('accounting_journal_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type', 100);
            $table->text('source_number')->nullable();
            $table->text('source_key')->nullable();
            $table->string('source_action', 16)->default('review');
            $table->string('source_record_type', 64)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->longText('draft_data')->nullable();
            $table->longText('entry_data');
            $table->boolean('is_reviewed')->default(false);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['journal_import_id', 'entry_index'], 'ajir_import_entry_unique');
            $table->index(['business_id', 'source_type', 'is_reviewed'], 'ajir_business_type_reviewed_index');
            $table->index(['journal_import_id', 'source_type'], 'ajir_import_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_import_rows');
    }
};
