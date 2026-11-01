<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Services\LegacyImportRunService;
use Tests\TestCase;

class LegacyImportRunServiceTest extends TestCase
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

        $this->createSchema();
    }

    public function test_run_lookup_is_business_and_year_scoped_and_journal_crosswalk_sync_is_chunked_and_idempotent(): void
    {
        $manifest = hash('sha256', 'synthetic legacy manifest');
        $runService = new LegacyImportRunService();
        $runId = $runService->createRun(2, 5, 2022, sys_get_temp_dir(), $manifest, [[
            'path' => 'journal.xlsx',
            'sha256' => hash('sha256', 'journal bytes'),
            'classification' => 'journal_posting_source',
            'period_start' => '2022-01-01',
            'period_end' => '2022-12-31',
            'size_bytes' => 12,
        ]]);

        $this->assertSame($runId, (int) $runService->findByManifest(2, 2022, $manifest)->id);
        $this->assertNull($runService->findByManifest(3, 2022, $manifest));
        $this->assertNull($runService->findByManifest(2, 2023, $manifest));
        $this->assertNull($runService->findRun($runId, 3));

        $entries = [];
        for ($index = 1; $index <= 251; $index++) {
            $identity = hash('sha256', 'journal-group-'.$index);
            $entries[] = [
                'journal_import_id' => 77,
                'business_id' => 2,
                'source_type' => 'Journal Entry',
                'source_key' => 'JE-'.$index,
                'entry_data' => json_encode([
                    'source_type' => 'Journal Entry',
                    'source_key' => 'JE-'.$index,
                    'source_number' => 'JE-'.$index,
                    'date' => '2022-06-15',
                    'legacy_sources' => [[
                        'path' => 'journal.xlsx',
                        'fingerprint' => 'fingerprint-'.$index,
                        'source_identity' => $identity,
                    ]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'processing_status' => 'completed',
                'journal_mapping_id' => $index,
                'source_record_type' => null,
                'source_record_id' => null,
                'processing_error' => null,
                'processing_message' => null,
            ];
        }
        foreach (array_chunk($entries, 100) as $chunk) {
            DB::table('accounting_journal_import_rows')->insert($chunk);
        }

        $firstSyncCount = $runService->syncJournalCrosswalks($runId, 2, 77);
        $firstIds = DB::table('accounting_legacy_import_crosswalks')
            ->where('legacy_import_run_id', $runId)
            ->orderBy('source_identity')
            ->pluck('id')
            ->all();

        $this->assertSame(251, $firstSyncCount);
        $this->assertCount(251, $firstIds);
        $this->assertSame('linked', DB::table('accounting_legacy_import_crosswalks')->value('status'));
        $this->assertSame(AccountingAccTransMapping::class, DB::table('accounting_legacy_import_crosswalks')->value('target_record_type'));

        $secondSyncCount = $runService->syncJournalCrosswalks($runId, 2, 77);
        $secondIds = DB::table('accounting_legacy_import_crosswalks')
            ->where('legacy_import_run_id', $runId)
            ->orderBy('source_identity')
            ->pluck('id')
            ->all();

        $this->assertSame(251, $secondSyncCount);
        $this->assertSame($firstIds, $secondIds);
        $this->assertSame(251, DB::table('accounting_legacy_import_crosswalks')->count());
        $this->assertSame('linked', DB::table('accounting_legacy_import_sources')->value('status'));
        $this->assertSame(251, (int) DB::table('accounting_legacy_import_sources')->value('records_linked'));
        $this->assertSame(0, (int) DB::table('accounting_legacy_import_sources')->value('records_for_review'));
    }

    private function createSchema(): void
    {
        Schema::create('accounting_legacy_import_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->unsignedSmallInteger('year_filter')->nullable();
            $table->string('source_directory', 500);
            $table->char('manifest_sha256', 64);
            $table->string('status', 32)->default('preparing');
            $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->longText('summary')->nullable();
            $table->text('last_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'manifest_sha256']);
        });

        Schema::create('accounting_legacy_import_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedInteger('business_id');
            $table->string('source_path', 500);
            $table->char('sha256', 64);
            $table->string('classification', 64);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 32)->default('inventoried');
            $table->unsignedBigInteger('journal_import_id')->nullable();
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_linked')->default(0);
            $table->unsignedInteger('records_for_review')->default(0);
            $table->longText('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['legacy_import_run_id', 'source_path']);
        });

        Schema::create('accounting_legacy_import_crosswalks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedBigInteger('legacy_import_source_id');
            $table->unsignedInteger('business_id');
            $table->char('source_identity', 64);
            $table->string('legacy_record_id', 100)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->text('source_number')->nullable();
            $table->date('source_date')->nullable();
            $table->unsignedBigInteger('journal_import_row_id')->nullable();
            $table->string('target_record_type', 64)->nullable();
            $table->unsignedBigInteger('target_record_id')->nullable();
            $table->string('status', 32)->default('review');
            $table->string('match_method', 32)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['legacy_import_run_id', 'legacy_import_source_id', 'source_identity']);
        });

        Schema::create('accounting_journal_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->string('source_type', 100)->nullable();
            $table->string('source_key', 255)->nullable();
            $table->longText('entry_data');
            $table->string('processing_status', 32)->nullable();
            $table->unsignedBigInteger('journal_mapping_id')->nullable();
            $table->string('source_record_type', 100)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->text('processing_error')->nullable();
            $table->text('processing_message')->nullable();
        });
    }
}
