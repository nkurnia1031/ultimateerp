<?php

namespace Tests\Feature;

use App\Media;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\LegacyAttachmentImporter;
use Modules\Accounting\Entities\Media as AccountingMedia;
use Tests\TestCase;

class LegacyAttachmentImporterTest extends TestCase
{
    private string $sourceDirectory;

    private string $documentsDirectory;

    private string $reportDirectory;

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
            'legacy_import.attachments.allowed_hosts' => ['jurnal-quickbook.jurnal.id'],
            'legacy_import.attachments.max_bytes' => 100000,
            'legacy_import.attachments.timeout_seconds' => 5,
            'legacy_import.attachments.connect_timeout_seconds' => 2,
            'legacy_import.attachments.allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'zip', 'xlsx'],
            'legacy_import.attachments.mapping_report_directory' => sys_get_temp_dir(),
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->createSchema();

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-attachments-'.bin2hex(random_bytes(8));
        $this->sourceDirectory = $base.DIRECTORY_SEPARATOR.'source';
        $this->documentsDirectory = $base.DIRECTORY_SEPARATOR.'documents';
        $this->reportDirectory = $base.DIRECTORY_SEPARATOR.'reports';
        mkdir($this->sourceDirectory.DIRECTORY_SEPARATOR.'json', 0777, true);
        mkdir($this->documentsDirectory, 0777, true);
        mkdir($this->reportDirectory, 0777, true);

        config(['legacy_import.attachments.mapping_report_directory' => $this->reportDirectory]);
        DB::table('accounting_legacy_import_runs')->insert([
            'id' => 1,
            'business_id' => 2,
            'created_by' => 5,
            'manifest_sha256' => hash('sha256', 'manifest'),
            'status' => 'prepared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('accounting_legacy_import_sources')->insert([
            'id' => 10,
            'legacy_import_run_id' => 1,
            'business_id' => 2,
            'source_path' => 'json/lampiran.json',
            'sha256' => hash('sha256', 'attachments'),
            'classification' => 'attachment_metadata',
            'status' => 'inventoried',
            'records_read' => 0,
            'records_linked' => 0,
            'records_for_review' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->sourceDirectory)) {
            $this->removeDirectory(dirname($this->sourceDirectory));
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_local_run_reuses_an_existing_file_and_retries_without_duplicate_media(): void
    {
        file_put_contents($this->documentsDirectory.DIRECTORY_SEPARATOR.'receipt.pdf', "%PDF-1.7\nexisting fixture");
        $this->writeAttachments([$this->attachment(700, 1200, 'Expense #19452', 'receipt.pdf')]);
        $transactionId = $this->transaction('expense');
        $this->crosswalk('Expense', '19452', '1200', $transactionId);
        Http::fake();

        $first = $this->import(false);
        $second = $this->import(false);
        $media = Media::where('business_id', 2)->where('legacy_source_attachment_id', '700')->first();

        $this->assertSame(1, $first['linked']);
        $this->assertSame(1, $second['linked']);
        $this->assertSame(1, Media::where('business_id', 2)->count());
        $this->assertSame('receipt.pdf', $media->original_name);
        $this->assertSame('documents', $media->storage_location);
        $this->assertSame($transactionId, (int) $media->model_id);
        $this->assertSame('App\\Transaction', $media->model_type);
        $this->assertStringContainsString('/uploads/documents/', $media->display_url);
        Http::assertNothingSent();
    }

    public function test_local_missing_file_stays_reviewable_without_a_broken_media_row_or_request(): void
    {
        $this->writeAttachments([$this->attachment(701, 1201, 'Expense #19453', 'missing.pdf')]);
        $transactionId = $this->transaction('expense');
        $this->crosswalk('Expense', '19453', '1201', $transactionId);
        Http::fake();

        $summary = $this->import(false);

        $this->assertSame(1, $summary['review']);
        $this->assertSame(0, Media::count());
        $this->assertSame('review', DB::table('accounting_legacy_import_attachments')->value('status'));
        Http::assertNothingSent();
    }

    public function test_multiple_attachments_share_one_legacy_transaction_crosswalk(): void
    {
        file_put_contents($this->documentsDirectory.DIRECTORY_SEPARATOR.'first.pdf', "%PDF-1.7\nfirst");
        file_put_contents($this->documentsDirectory.DIRECTORY_SEPARATOR.'second.pdf', "%PDF-1.7\nsecond");
        $this->writeAttachments([
            $this->attachment(710, 1210, 'Expense #19461', 'first.pdf'),
            $this->attachment(711, 1210, 'Expense #19461', 'second.pdf'),
        ]);
        $transactionId = $this->transaction('expense');
        $this->crosswalk('Expense', '19461', '1210', $transactionId);
        Http::fake();

        $summary = $this->import(false);

        $this->assertSame(2, $summary['linked']);
        $this->assertSame(2, Media::where('business_id', 2)->count());
        $this->assertSame(
            [$transactionId],
            Media::where('business_id', 2)->orderBy('id')->pluck('model_id')->unique()->map(fn ($id) => (int) $id)->values()->all()
        );
        Http::assertNothingSent();
    }

    public function test_production_download_is_mapped_through_the_crosswalk_and_stored_atomically(): void
    {
        $url = 'https://jurnal-quickbook.jurnal.id/uploads/transaction_attachment/file/702/invoice.pdf';
        $this->writeAttachments([$this->attachment(702, 1202, 'Expense #19454', 'invoice.pdf')]);
        $transactionId = $this->transaction('expense');
        $this->crosswalk('Expense', '19454', '1202', $transactionId);
        Http::fake([
            $url => Http::response("%PDF-1.7\nlegacy fixture", 200, ['Content-Type' => 'application/pdf']),
        ]);

        $summary = $this->import(true);
        $media = Media::where('legacy_source_attachment_id', '702')->first();

        $this->assertSame(1, $summary['linked']);
        $this->assertSame($transactionId, (int) $media->model_id);
        $this->assertSame("%PDF-1.7\nlegacy fixture", file_get_contents($this->documentsDirectory.DIRECTORY_SEPARATOR.$media->file_name));
        $this->assertSame('downloaded', DB::table('accounting_legacy_import_attachments')->value('file_action'));
        Http::assertSentCount(1);
    }

    public function test_unique_legacy_id_wins_and_duplicate_basename_gets_deterministic_names(): void
    {
        $this->writeAttachments([
            $this->attachment(703, 1203, 'Expense #19455', 'same.pdf'),
            $this->attachment(704, 1204, 'Expense #19456', 'same.pdf'),
        ]);
        $transactionOne = $this->transaction('expense');
        $transactionTwo = $this->transaction('expense');
        $this->crosswalk('Expense', '19455', '1203', $transactionOne);
        $this->crosswalk('Expense', '19456', '1204', $transactionTwo);
        Http::fake();

        $summary = $this->import(false);
        $rows = DB::table('accounting_legacy_import_attachments')->orderBy('legacy_source_attachment_id')->get();

        $this->assertSame(2, $summary['collision_attachments']);
        $this->assertSame('legacy-703__same.pdf', $rows[0]->destination_file_name);
        $this->assertSame('legacy-704__same.pdf', $rows[1]->destination_file_name);
        $this->assertSame($transactionOne, (int) $rows[0]->target_transaction_id);
        $this->assertSame($transactionTwo, (int) $rows[1]->target_transaction_id);
        $this->assertFileExists($summary['mapping_report_path']);
        $this->assertStringContainsString('legacy-703__same.pdf', file_get_contents($summary['mapping_report_path']));
        Http::assertNothingSent();
    }

    public function test_unambiguous_number_fallback_links_but_ambiguous_targets_stay_in_review(): void
    {
        file_put_contents($this->documentsDirectory.DIRECTORY_SEPARATOR.'fallback.pdf', "%PDF-1.7\nexisting fixture");
        $this->writeAttachments([
            $this->attachment(705, 1205, 'Expense #19457', 'fallback.pdf'),
            $this->attachment(706, 1206, 'Expense #19458', 'ambiguous.pdf'),
        ]);
        $fallbackTransaction = $this->transaction('expense');
        $this->crosswalk('Expense', '19457', null, $fallbackTransaction);
        $this->crosswalk('Expense', '19458', null, $this->transaction('expense'));
        $this->crosswalk('Expense', '19458', null, $this->transaction('expense'));
        Http::fake();

        $summary = $this->import(false);

        $this->assertSame(1, $summary['linked']);
        $this->assertSame(1, $summary['review']);
        $this->assertSame($fallbackTransaction, (int) Media::where('legacy_source_attachment_id', '705')->value('model_id'));
        $this->assertNull(Media::where('legacy_source_attachment_id', '706')->first());
        Http::assertNothingSent();
    }

    public function test_failed_download_can_be_retried_and_unsafe_host_is_rejected_without_request(): void
    {
        $url = 'https://jurnal-quickbook.jurnal.id/uploads/transaction_attachment/file/707/retry.pdf';
        $this->writeAttachments([$this->attachment(707, 1207, 'Expense #19459', 'retry.pdf')]);
        $transactionId = $this->transaction('expense');
        $this->crosswalk('Expense', '19459', '1207', $transactionId);
        Http::fake([
            $url => Http::sequence()
                ->push('temporarily unavailable', 503)
                ->push("%PDF-1.7\nretry fixture", 200, ['Content-Type' => 'application/pdf']),
        ]);

        $first = $this->import(true);
        $second = $this->import(true);

        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $second['linked']);
        $this->assertSame(1, Media::where('legacy_source_attachment_id', '707')->count());
        Http::assertSentCount(2);

        $this->writeAttachments([$this->attachment(708, 1208, 'Expense #19460', 'unsafe.pdf', 'https://evil.example/uploads/transaction_attachment/file/708/unsafe.pdf')]);
        $this->crosswalk('Expense', '19460', '1208', $this->transaction('expense'));
        Http::fake();
        $unsafe = $this->import(false);
        $this->assertSame(1, $unsafe['rejected']);
        Http::assertNothingSent();

        $this->writeAttachments([$this->attachment(709, 1209, 'Expense #19461', 'outside.pdf', 'https://jurnal-quickbook.jurnal.id/uploads/transaction_attachment/file/709/%2e%2e%2foutside.pdf')]);
        $this->crosswalk('Expense', '19461', '1209', $this->transaction('expense'));
        Http::fake();
        $traversal = $this->import(true);
        $this->assertSame(1, $traversal['rejected']);
        $this->assertFileDoesNotExist($this->documentsDirectory.DIRECTORY_SEPARATOR.'outside.pdf');
        Http::assertNothingSent();
    }

    public function test_document_display_and_relation_deletion_preserve_document_binary_and_null_uploader_renders(): void
    {
        $relativeDirectory = 'uploads'.DIRECTORY_SEPARATOR.'documents';
        $documentsPath = public_path($relativeDirectory);
        $directoryExisted = is_dir($documentsPath);
        if (! $directoryExisted) {
            mkdir($documentsPath, 0777, true);
        }
        $fileName = 'legacy-media-test-'.bin2hex(random_bytes(6)).'.pdf';
        $filePath = $documentsPath.DIRECTORY_SEPARATOR.$fileName;
        $moduleFileName = 'legacy-module-media-test-'.bin2hex(random_bytes(6)).'.pdf';
        $moduleFilePath = $documentsPath.DIRECTORY_SEPARATOR.$moduleFileName;
        file_put_contents($filePath, "%PDF-1.7\nkeep binary");
        file_put_contents($moduleFilePath, "%PDF-1.7\nkeep module binary");

        try {
            $transactionId = $this->transaction('expense');
            $mediaId = DB::table('media')->insertGetId([
                'business_id' => 2,
                'file_name' => $fileName,
                'original_name' => 'Original legacy invoice.pdf',
                'storage_location' => 'documents',
                'legacy_source_attachment_id' => '709',
                'model_type' => 'App\\Transaction',
                'model_id' => $transactionId,
                'uploaded_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $media = Media::findOrFail($mediaId);
            $html = view('sell.partials.media_table', ['medias' => collect([$media])])->render();
            $spaceFile = new Media(['file_name' => 'legacy invoice.pdf', 'storage_location' => 'documents']);
            $accountingMedia = new AccountingMedia(['file_name' => 'legacy invoice.pdf', 'storage_location' => 'documents']);

            $this->assertSame('Original legacy invoice.pdf', $media->display_name);
            $this->assertStringContainsString('/uploads/documents/', $media->display_url);
            $this->assertStringContainsString('legacy%20invoice.pdf', $spaceFile->display_url);
            $this->assertStringEndsWith('legacy invoice.pdf', $spaceFile->display_path);
            $this->assertStringContainsString('/uploads/documents/', $accountingMedia->display_url);
            $this->assertStringContainsString('Original legacy invoice.pdf', $html);
            $this->assertStringContainsString('Import legacy', $html);

            Media::deleteMedia(2, $mediaId);

            $this->assertFileExists($filePath);
            $this->assertDatabaseMissing('media', ['id' => $mediaId]);

            $moduleMediaId = DB::table('media')->insertGetId([
                'business_id' => 2,
                'file_name' => $moduleFileName,
                'original_name' => 'Module original.pdf',
                'storage_location' => 'documents',
                'legacy_source_attachment_id' => '710',
                'model_type' => 'App\\Transaction',
                'model_id' => $transactionId,
                'uploaded_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AccountingMedia::deleteMedia(2, $moduleMediaId);
            $this->assertFileExists($moduleFilePath);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            if (file_exists($moduleFilePath)) {
                unlink($moduleFilePath);
            }
            if (! $directoryExisted && is_dir($documentsPath) && count(scandir($documentsPath) ?: []) === 2) {
                rmdir($documentsPath);
            }
        }
    }

    private function import(bool $allowDownloads): array
    {
        return app(LegacyAttachmentImporter::class)->import(
            1,
            2,
            $this->sourceDirectory,
            $this->documentsDirectory,
            $allowDownloads
        );
    }

    private function attachment(int $attachmentId, int $transactionId, string $number, string $fileName, ?string $url = null): array
    {
        return [
            'id' => $attachmentId,
            'transaction_id' => $transactionId,
            'entry' => [
                'id' => $transactionId,
                'number' => $number,
                'path' => '/expenses/'.$transactionId,
            ],
            'file' => [
                'url' => $url ?? 'https://jurnal-quickbook.jurnal.id/uploads/transaction_attachment/file/'.$attachmentId.'/'.$fileName,
            ],
        ];
    }

    private function writeAttachments(array $rows): void
    {
        file_put_contents(
            $this->sourceDirectory.DIRECTORY_SEPARATOR.'json'.DIRECTORY_SEPARATOR.'lampiran.json',
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function transaction(string $type): int
    {
        return (int) DB::table('transactions')->insertGetId([
            'business_id' => 2,
            'type' => $type,
            'deleted_at' => null,
        ]);
    }

    private function crosswalk(string $type, string $number, ?string $legacyId, int $transactionId): void
    {
        DB::table('accounting_legacy_import_crosswalks')->insert([
            'legacy_import_run_id' => 1,
            'legacy_import_source_id' => 10,
            'business_id' => 2,
            'source_identity' => hash('sha256', $type.'|'.$number.'|'.($legacyId ?? $transactionId)),
            'legacy_record_id' => $legacyId,
            'source_type' => $type,
            'source_number' => $number,
            'target_record_type' => 'App\\Transaction',
            'target_record_id' => $transactionId,
            'status' => 'linked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('accounting_legacy_import_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('created_by');
            $table->char('manifest_sha256', 64);
            $table->string('status', 32);
            $table->timestamps();
        });
        Schema::create('accounting_legacy_import_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedInteger('business_id');
            $table->string('source_path', 500);
            $table->char('sha256', 64);
            $table->string('classification', 64);
            $table->string('status', 32);
            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_linked')->default(0);
            $table->unsignedInteger('records_for_review')->default(0);
            $table->longText('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
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
        });
        Schema::create('accounting_legacy_import_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_import_run_id');
            $table->unsignedBigInteger('legacy_import_source_id');
            $table->unsignedInteger('business_id');
            $table->string('legacy_source_attachment_id', 100);
            $table->string('legacy_transaction_id', 100)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_number', 255)->nullable();
            $table->text('source_url')->nullable();
            $table->string('original_file_name', 255)->nullable();
            $table->string('destination_file_name', 255)->nullable();
            $table->unsignedBigInteger('target_transaction_id')->nullable();
            $table->unsignedInteger('media_id')->nullable();
            $table->string('match_method', 32)->nullable();
            $table->string('file_action', 24)->nullable();
            $table->string('status', 24)->default('review');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['legacy_import_run_id', 'legacy_source_attachment_id']);
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type', 32);
            $table->softDeletes();
        });
        Schema::create('media', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('file_name');
            $table->string('original_name')->nullable();
            $table->enum('storage_location', ['media', 'documents'])->default('media');
            $table->string('legacy_source_attachment_id', 100)->nullable();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->integer('uploaded_by')->nullable();
            $table->string('model_media_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'legacy_source_attachment_id']);
        });
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
