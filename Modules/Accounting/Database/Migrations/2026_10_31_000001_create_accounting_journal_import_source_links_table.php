<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_journal_import_source_links')) {
            Schema::create('accounting_journal_import_source_links', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('business_id');
                $table->unsignedBigInteger('journal_import_id');
                $table->unsignedBigInteger('journal_import_row_id');
                $table->string('journal_source_type', 100);
                $table->string('source_record_type', 64);
                $table->unsignedBigInteger('source_record_id');
                $table->text('source_reference')->nullable();
                $table->date('source_date')->nullable();
                $table->decimal('base_amount', 22, 4)->nullable();
                $table->decimal('tax_amount', 22, 4)->nullable();
                $table->decimal('total_amount', 22, 4)->nullable();
                $table->string('link_method', 32)->default('single');
                $table->timestamps();

                $table->unique(
                    ['journal_import_row_id', 'source_record_type', 'source_record_id'],
                    'ajisl_row_source_unique'
                );
                $table->index(['business_id', 'journal_import_id', 'journal_source_type'], 'ajisl_business_import_type_index');
                $table->index(['business_id', 'source_record_type', 'source_record_id'], 'ajisl_business_source_index');
            });
        }

        if (! Schema::hasTable('accounting_journal_import_rows')
            || ! Schema::hasColumn('accounting_journal_import_rows', 'source_record_type')
            || ! Schema::hasColumn('accounting_journal_import_rows', 'source_record_id')) {
            return;
        }

        DB::table('accounting_journal_import_rows')
            ->whereNotNull('source_record_type')
            ->whereNotNull('source_record_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $now = now();
                $links = [];
                foreach ($rows as $row) {
                    $entry = json_decode((string) ($row->entry_data ?? ''), true) ?: [];
                    $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
                    $sourceDate = null;
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dateParts) === 1
                        && checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
                        $sourceDate = $date;
                    }
                    $links[] = [
                        'business_id' => (int) $row->business_id,
                        'journal_import_id' => (int) $row->journal_import_id,
                        'journal_import_row_id' => (int) $row->id,
                        'journal_source_type' => (string) $row->source_type,
                        'source_record_type' => (string) $row->source_record_type,
                        'source_record_id' => (int) $row->source_record_id,
                        'source_reference' => $row->source_number ?? null,
                        'source_date' => $sourceDate,
                        'base_amount' => null,
                        'tax_amount' => null,
                        'total_amount' => null,
                        'link_method' => 'legacy',
                        'created_at' => $row->created_at ?? $now,
                        'updated_at' => $row->updated_at ?? $now,
                    ];
                }

                if ($links !== []) {
                    DB::table('accounting_journal_import_source_links')->insertOrIgnore($links);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_import_source_links');
    }
};
