<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\LegacyJournalImportAuditReader;
use Tests\TestCase;

class LegacyJournalImportAuditReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id');
            $table->string('source_type');
            $table->string('source_number')->nullable();
            $table->string('source_key')->nullable();
            $table->string('source_action')->default('review');
            $table->longText('entry_data');
            $table->string('processing_status');
            $table->text('processing_message')->nullable();
            $table->text('processing_error')->nullable();
            $table->unsignedInteger('journal_mapping_id')->nullable();
            $table->string('reconciliation_status')->nullable();
            $table->decimal('journal_total', 22, 4)->nullable();
            $table->decimal('business_total', 22, 4)->nullable();
            $table->decimal('difference_amount', 22, 4)->nullable();
        });
    }

    public function test_reads_batch_status_and_mapping_diagnostics_only_for_requested_business(): void
    {
        $entry = [
            'source_type' => 'Expense',
            'source_number' => 'EXP-100',
            'source_key' => 'Expense|EXP-100|2023-05-03',
            'date' => '2023-05-03',
        ];
        DB::table('accounting_journal_import_rows')->insert([
            [
                'business_id' => 987654,
                'journal_import_id' => 41,
                'source_type' => 'Expense',
                'source_number' => 'EXP-100',
                'source_key' => $entry['source_key'],
                'source_action' => 'create',
                'entry_data' => json_encode($entry),
                'processing_status' => 'failed',
                'processing_error' => 'Akun GL belum dipetakan.',
                'journal_total' => 250,
            ],
            [
                'business_id' => 987655,
                'journal_import_id' => 41,
                'source_type' => 'Expense',
                'source_number' => 'EXP-100',
                'source_key' => $entry['source_key'],
                'source_action' => 'create',
                'entry_data' => json_encode($entry),
                'processing_status' => 'completed',
                'processing_error' => null,
                'journal_total' => null,
            ],
            [
                'business_id' => 987654,
                'journal_import_id' => 42,
                'source_type' => 'Expense',
                'source_number' => 'EXP-OTHER-BATCH',
                'source_key' => 'Expense|EXP-OTHER-BATCH|2023-05-03',
                'source_action' => 'create',
                'entry_data' => json_encode($entry),
                'processing_status' => 'completed',
                'processing_error' => null,
                'journal_total' => null,
            ],
        ]);

        $missingAccountLines = [[
            'account_no' => '5-80001',
            'account_name' => 'Office Expense',
            'description' => 'Biaya kantor',
        ]];
        $rows = app(LegacyJournalImportAuditReader::class)->read(987654, 41, [
            $entry['source_key'] => $missingAccountLines,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(2023, $rows[0]['year']);
        $this->assertSame('failed', $rows[0]['processing_status']);
        $this->assertSame('Akun GL belum dipetakan.', $rows[0]['processing_error']);
        $this->assertSame($missingAccountLines, $rows[0]['missing_account_lines']);
        $this->assertSame([], app(LegacyJournalImportAuditReader::class)->read(987654, null));
    }
}
