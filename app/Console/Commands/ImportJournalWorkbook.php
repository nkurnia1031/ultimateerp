<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Modules\Accounting\Services\JournalImportCancellationService;

class ImportJournalWorkbook extends Command
{
    protected $signature = 'accounting:import-journal
        {workbook : Path to an XLSX, XLS, or CSV journal workbook}
        {--business-id= : Business ID that owns the import}
        {--user-id= : Existing user ID recorded as the import author}';

    protected $description = 'Import a journal workbook directly from CLI into a persistent, web-reviewable batch.';

    public function handle(): int
    {
        $businessId = $this->positiveIntegerOption('business-id');
        $userId = $this->positiveIntegerOption('user-id');
        if ($businessId === null || $userId === null) {
            $this->error('Opsi --business-id dan --user-id wajib berupa bilangan bulat positif.');

            return self::FAILURE;
        }

        $business = DB::table('business')->where('id', $businessId)->first();
        if (empty($business)) {
            $this->error('Business #'.$businessId.' tidak ditemukan.');

            return self::FAILURE;
        }

        if (! DB::table('users')->where('id', $userId)->exists()) {
            $this->error('User #'.$userId.' tidak ditemukan; gunakan user yang tercatat sebagai pelaksana import.');

            return self::FAILURE;
        }

        $sourcePath = realpath((string) $this->argument('workbook'));
        if (empty($sourcePath) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            $this->error('File workbook tidak ditemukan atau tidak dapat dibaca.');

            return self::FAILURE;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $this->error('Format workbook harus XLSX, XLS, atau CSV.');

            return self::FAILURE;
        }

        $batchId = null;
        $relativePath = null;

        try {
            $uploadDirectory = public_path('uploads/temp/journal_imports');
            if (! is_dir($uploadDirectory) && ! mkdir($uploadDirectory, 0755, true) && ! is_dir($uploadDirectory)) {
                throw new \RuntimeException('Folder sementara Journal Import tidak dapat dibuat.');
            }

            $storedName = Str::uuid().'.'.$extension;
            $relativePath = 'uploads/temp/journal_imports/'.$storedName;
            if (! copy($sourcePath, public_path($relativePath))) {
                throw new \RuntimeException('Workbook tidak dapat disalin ke penyimpanan batch Journal Import.');
            }

            $batchId = DB::table('accounting_journal_imports')->insertGetId([
                'business_id' => $businessId,
                'file_name' => basename($sourcePath),
                'file_path' => $relativePath,
                'status' => 'queued',
                'last_message' => 'CLI menerima workbook; analisis dimulai langsung.',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info('Business: '.($business->name ?? 'Business').' (#'.$businessId.')');
            $this->line('Batch ID: '.$batchId);
            $this->line('Runner: CLI langsung; tidak menunggu worker queue imports.');
            $this->line('Analisis workbook dimulai.');

            $lastAnalysisRow = 0;
            $analysisJob = (new AnalyzeJournalImportJob($batchId))->onProgress(function (array $progress) use (&$lastAnalysisRow) {
                if ($this->getOutput()->isQuiet()) {
                    return;
                }

                $processedRows = (int) ($progress['processed_rows'] ?? 0);
                $message = (string) ($progress['message'] ?? 'Analisis workbook berjalan.');
                if (($lastAnalysisRow === 0 && $processedRows === 0)
                    || $processedRows >= $lastAnalysisRow + 5000) {
                    $this->line($message);
                    $lastAnalysisRow = $processedRows;
                }
            });
            app()->call([$analysisJob, 'handle']);

            $batch = $this->findBatch($batchId, $businessId);
            if ($batch->status === 'analyzed') {
                $this->startPersistentProcessing($batchId, $businessId);
                $batch = $this->runPersistentBatch($batchId, $businessId);
            }

            $this->printSummary($batchId, $businessId, $batch);

            return $batch->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            if ($batchId !== null) {
                $this->markInterruptedBatchRecoverable($batchId, $businessId, $exception);
                $this->line('Batch ID: '.$batchId);
            } elseif ($relativePath !== null) {
                @unlink(public_path($relativePath));
            }

            $this->error($exception->getMessage());
            if ($batchId !== null) {
                $this->line('Batch dan baris yang sudah tersimpan tetap dapat ditinjau melalui Journal Import di web.');
            }

            return self::FAILURE;
        }
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = (string) $this->option($name);
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function startPersistentProcessing(int $batchId, int $businessId): void
    {
        $started = DB::transaction(function () use ($batchId, $businessId) {
            $batch = DB::table('accounting_journal_imports')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($batch) || $batch->status !== 'analyzed' || ! empty($batch->cancel_requested_at)) {
                return false;
            }

            DB::table('accounting_journal_imports')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->update([
                    'status' => 'preparing_import',
                    'cancel_requested_at' => null,
                    'cancel_requested_by' => null,
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'prepare_cursor' => 0,
                    'tax_reconciliation_cursor' => 0,
                    'import_options' => json_encode(['execution_mode' => 'cli']),
                    'last_message' => 'CLI menyiapkan antrean dari draft tersimpan dalam beberapa bagian.',
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            return true;
        });

        if (! $started) {
            throw new \RuntimeException('Batch tidak lagi siap diproses atau sudah menerima permintaan terminate.');
        }
    }

    private function runPersistentBatch(int $batchId, int $businessId): object
    {
        $iterations = 0;
        $lastReportedStatus = null;
        $nextReportedRows = 500;

        while (++$iterations <= 100000) {
            $batch = $this->findBatch($batchId, $businessId);
            if (! empty($batch->cancel_requested_at)) {
                app(JournalImportCancellationService::class)->stopIfRequested($batchId, $businessId);
                $batch = $this->findBatch($batchId, $businessId);
            }

            if (! in_array($batch->status, ['preparing_import', 'queued_import', 'importing', 'reconciling'], true)) {
                return $batch;
            }

            $before = [
                'status' => $batch->status,
                'prepare_cursor' => (int) ($batch->prepare_cursor ?? 0),
                'tax_cursor' => (int) ($batch->tax_reconciliation_cursor ?? 0),
                'processed_entries' => (int) ($batch->processed_entries ?? 0),
            ];

            if ($batch->status === 'preparing_import') {
                $this->callProcessorChunk($batchId, 'prepare', 0, (int) $batch->prepare_cursor);
            } elseif (in_array($batch->status, ['queued_import', 'importing'], true)) {
                $this->callProcessorChunk($batchId, 'entries');
            } else {
                $this->callProcessorChunk($batchId, 'tax', (int) $batch->tax_reconciliation_cursor);
            }

            $batch = $this->findBatch($batchId, $businessId);
            if ($batch->status !== $lastReportedStatus) {
                $this->line('Status: '.$batch->status.' — '.($batch->last_message ?: 'proses berlanjut.'));
                $lastReportedStatus = $batch->status;
            }

            $processed = (int) ($batch->processed_entries ?? 0);
            if ($processed >= $nextReportedRows) {
                $this->line('Progres: '.$processed.' / '.(int) $batch->total_entries.' baris ditangani.');
                $nextReportedRows = (int) (floor($processed / 500) + 1) * 500;
            }

            $after = [
                'status' => $batch->status,
                'prepare_cursor' => (int) ($batch->prepare_cursor ?? 0),
                'tax_cursor' => (int) ($batch->tax_reconciliation_cursor ?? 0),
                'processed_entries' => $processed,
            ];
            if ($before === $after && in_array($batch->status, ['preparing_import', 'queued_import', 'importing', 'reconciling'], true)) {
                throw new \RuntimeException('Proses tidak memajukan cursor atau status batch; batch disimpan untuk ditinjau.');
            }
        }

        throw new \RuntimeException('Batas iterasi CLI tercapai; batch disimpan untuk dilanjutkan melalui web.');
    }

    private function callProcessorChunk(int $batchId, string $phase, int $afterMappingId = 0, int $afterRowId = 0): void
    {
        $job = new ProcessJournalImportJob($batchId, $phase, $afterMappingId, $afterRowId, false, 'cli');
        app()->call([$job, 'handle']);
    }

    private function findBatch(int $batchId, int $businessId): object
    {
        $batch = DB::table('accounting_journal_imports')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();

        if (empty($batch)) {
            throw new \RuntimeException('Batch Journal Import tidak ditemukan pada business yang diminta.');
        }

        return $batch;
    }

    private function markInterruptedBatchRecoverable(int $batchId, int $businessId, \Throwable $exception): void
    {
        DB::table('accounting_journal_imports')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->whereNull('cancel_requested_at')
            ->whereNotIn('status', ['imported', 'cancelled'])
            ->update([
                'status' => 'failed',
                'last_message' => 'CLI berhenti karena kesalahan; draft tersimpan untuk ditinjau atau dicoba kembali dari web.',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batchId)
            ->where('business_id', $businessId)
            ->whereIn('processing_status', ['queued', 'running'])
            ->update([
                'processing_status' => 'failed',
                'processing_message' => 'CLI berhenti sebelum baris selesai; periksa kesalahan lalu coba kembali dari web.',
                'processing_error' => mb_substr($exception->getMessage(), 0, 2000),
                'processing_started_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function printSummary(int $batchId, int $businessId, object $batch): void
    {
        $counts = ['pending' => 0, 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batchId)
            ->where('business_id', $businessId)
            ->select('processing_status', DB::raw('COUNT(*) as row_count'))
            ->groupBy('processing_status')
            ->get()
            ->each(function ($row) use (&$counts) {
                if (array_key_exists($row->processing_status, $counts)) {
                    $counts[$row->processing_status] = (int) $row->row_count;
                }
            });

        $this->line('Batch ID: '.$batchId);
        $this->line('Status akhir: '.$batch->status);
        $this->line('Total baris: '.(int) $batch->total_entries);
        $this->line('Berhasil: '.$counts['completed']);
        $this->line('Perlu ditinjau: '.$counts['pending']);
        $this->line('Gagal: '.$counts['failed']);
        $this->line('Masih dalam antrean: '.($counts['queued'] + $counts['running']));
        if (! empty($batch->last_message)) {
            $this->line('Pesan: '.$batch->last_message);
        }
        if (! empty($batch->error_message)) {
            $this->warn('Kesalahan: '.$batch->error_message);
        }
        if (in_array($batch->status, ['partially_imported', 'cancelled', 'failed'], true)) {
            $this->comment('Sisa batch tersedia pada halaman Journal Import untuk ditinjau atau dilanjutkan.');
        }
    }
}
