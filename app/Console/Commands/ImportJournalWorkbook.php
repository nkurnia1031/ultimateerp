<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Jobs\AnalyzeJournalImportJob;
use Modules\Accounting\Jobs\ProcessJournalImportJob;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalImportProgressService;
use Symfony\Component\Console\Helper\ProgressBar;

class ImportJournalWorkbook extends Command
{
    protected $signature = 'accounting:import-journal
        {workbook : Path to an XLSX, XLS, or CSV journal workbook}
        {--business-id= : Business ID that owns the import}
        {--user-id= : Existing user ID recorded as the import author}
        {--no-create-missing-main-accounts : Keep missing operational accounts in review instead of creating them from exact workbook values}
        {--chunk-delay-ms=2000 : Delay between continuing database chunks (0-30000 ms)}';

    protected $description = 'Import a journal workbook directly from CLI into a persistent, web-reviewable batch.';

    public function handle(): int
    {
        $businessId = $this->positiveIntegerOption('business-id');
        $userId = $this->positiveIntegerOption('user-id');
        if ($businessId === null || $userId === null) {
            $this->error('Opsi --business-id dan --user-id wajib berupa bilangan bulat positif.');

            return self::FAILURE;
        }
        $chunkDelayMilliseconds = $this->nonNegativeIntegerOption('chunk-delay-ms', 0, 30000);
        if ($chunkDelayMilliseconds === null) {
            $this->error('Opsi --chunk-delay-ms harus berupa bilangan bulat antara 0 dan 30000.');

            return self::FAILURE;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
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
            $uploadDisk = Storage::disk('journal_imports');
            $uploadDirectory = $uploadDisk->path('');
            if (! is_dir($uploadDirectory) && ! mkdir($uploadDirectory, 0755, true) && ! is_dir($uploadDirectory)) {
                throw new \RuntimeException('Folder sementara Journal Import tidak dapat dibuat.');
            }

            $storedName = Str::uuid().'.'.$extension;
            $relativePath = 'journal_imports/'.$storedName;
            if (! copy($sourcePath, $uploadDisk->path($storedName))) {
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
            $this->line('Jeda antar-chunk database: '.$chunkDelayMilliseconds.' ms.');
            $createMissingMainAccounts = ! (bool) $this->option('no-create-missing-main-accounts');
            $this->line('Account Utama yang tidak ditemukan: '.($createMissingMainAccounts
                ? 'dibuat dari nomor dan nama akun workbook yang persis.'
                : 'dibiarkan untuk mapping dan review manual.'));
            $this->line('Analisis workbook dimulai.');

            $lastAnalysisRow = 0;
            $lastAnalysisPhase = null;
            $analysisJob = (new AnalyzeJournalImportJob($batchId))->onProgress(function (array $progress) use (&$lastAnalysisRow, &$lastAnalysisPhase) {
                if ($this->getOutput()->isQuiet()) {
                    return;
                }

                $message = (string) ($progress['message'] ?? 'Analisis workbook berjalan.');
                $phase = (string) ($progress['phase'] ?? 'workbook_analysis');
                $processedRows = (int) ($progress['processed'] ?? $progress['processed_rows'] ?? 0);
                $totalRows = (int) ($progress['total'] ?? $progress['total_rows'] ?? 0);
                if ($phase !== $lastAnalysisPhase
                    || $processedRows >= $lastAnalysisRow + 5000
                    || ($totalRows > 0 && $processedRows >= $totalRows)) {
                    $this->line($message);
                    $lastAnalysisPhase = $phase;
                    $lastAnalysisRow = $processedRows;
                }
            });
            app()->call([$analysisJob, 'handle']);

            $batch = $this->findBatch($batchId, $businessId);
            if ($batch->status === 'analyzed') {
                $this->startPersistentProcessing($batchId, $businessId, $createMissingMainAccounts);
                $batch = $this->runPersistentBatch($batchId, $businessId, $chunkDelayMilliseconds);
            }

            $this->printSummary($batchId, $businessId, $batch);

            return $batch->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            if ($batchId !== null) {
                $this->markInterruptedBatchRecoverable($batchId, $businessId, $exception);
                $this->line('Batch ID: '.$batchId);
            } elseif ($relativePath !== null) {
                $storedName = substr($relativePath, strlen('journal_imports/'));
                @unlink(Storage::disk('journal_imports')->path($storedName));
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

    private function nonNegativeIntegerOption(string $name, int $default, int $maximum): ?int
    {
        $value = (string) $this->option($name);
        if ($value === '') {
            return $default;
        }
        if (! ctype_digit($value) || (int) $value > $maximum) {
            return null;
        }

        return (int) $value;
    }

    private function startPersistentProcessing(int $batchId, int $businessId, bool $createMissingMainAccounts): void
    {
        $started = DB::transaction(function () use ($batchId, $businessId, $createMissingMainAccounts) {
            $batch = DB::table('accounting_journal_imports')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($batch) || $batch->status !== 'analyzed' || ! empty($batch->cancel_requested_at)) {
                return false;
            }

            $prepareTotal = (int) DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $batchId)
                ->where('business_id', $businessId)
                ->whereIn('processing_status', ['pending', 'failed'])
                ->count();
            $progress = app(JournalImportProgressService::class)->record(
                $batchId,
                $businessId,
                'preparing_import',
                0,
                $prepareTotal,
                'draft baris',
                ['unprocessed' => $prepareTotal],
                'Persiapan antrean: 0 / '.$prepareTotal.' draft baris diperiksa.',
                [
                    'status' => 'preparing_import',
                    'cancel_requested_at' => null,
                    'cancel_requested_by' => null,
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'prepare_cursor' => 0,
                    'tax_reconciliation_cursor' => 0,
                    'import_options' => json_encode([
                        'execution_mode' => 'cli',
                        'create_missing_main_accounts' => $createMissingMainAccounts,
                    ]),
                    'last_message' => 'Persiapan antrean: 0 / '.$prepareTotal.' draft baris diperiksa.',
                    'error_message' => null,
                ],
                true
            );

            return $progress !== [];
        });

        if (! $started) {
            throw new \RuntimeException('Batch tidak lagi siap diproses atau sudah menerima permintaan terminate.');
        }
    }

    private function runPersistentBatch(int $batchId, int $businessId, int $chunkDelayMilliseconds): object
    {
        $iterations = 0;
        $lastReportedStatus = null;
        $reportedProgress = [];
        $lastProgressOutputAt = [];
        $progressBar = null;
        $progressBarPhase = null;

        while (++$iterations <= 100000) {
            $batch = $this->findBatch($batchId, $businessId);
            if (! empty($batch->cancel_requested_at)) {
                app(JournalImportCancellationService::class)->stopIfRequested($batchId, $businessId);
                $batch = $this->findBatch($batchId, $businessId);
            }

            $this->reportProgress(
                $batch,
                $reportedProgress,
                $lastProgressOutputAt,
                $progressBar,
                $progressBarPhase
            );

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
            $this->reportProgress(
                $batch,
                $reportedProgress,
                $lastProgressOutputAt,
                $progressBar,
                $progressBarPhase
            );

            $after = [
                'status' => $batch->status,
                'prepare_cursor' => (int) ($batch->prepare_cursor ?? 0),
                'tax_cursor' => (int) ($batch->tax_reconciliation_cursor ?? 0),
                'processed_entries' => (int) ($batch->processed_entries ?? 0),
            ];
            if ($before === $after && in_array($batch->status, ['preparing_import', 'queued_import', 'importing', 'reconciling'], true)) {
                throw new \RuntimeException('Proses tidak memajukan cursor atau status batch; batch disimpan untuk ditinjau.');
            }
            if ($before['status'] === $after['status'] && $before !== $after && $chunkDelayMilliseconds > 0) {
                usleep($chunkDelayMilliseconds * 1000);
            }
        }

        throw new \RuntimeException('Batas iterasi CLI tercapai; batch disimpan untuk dilanjutkan melalui web.');
    }

    private function reportProgress(
        object $batch,
        array &$reportedProgress,
        array &$lastProgressOutputAt,
        ?ProgressBar &$progressBar,
        ?string &$progressBarPhase
    ): void {
        if ($this->getOutput()->isQuiet()) {
            return;
        }

        $progress = app(JournalImportProgressService::class)->decode($batch->progress_data ?? null);
        $phase = $progress['phase'] ?? null;
        $phases = $progress['phases'] ?? [];
        if (! is_string($phase) || ! isset($phases[$phase]) || ! is_array($phases[$phase])) {
            return;
        }

        $isInteractive = $this->getOutput()->isDecorated();
        if ($isInteractive) {
            if ($progressBar !== null && $progressBarPhase !== $phase) {
                $previous = $phases[$progressBarPhase] ?? null;
                if (is_array($previous) && (int) ($previous['total'] ?? 0) > 0) {
                    $previousTotal = (int) $previous['total'];
                    $previousProcessed = min((int) ($previous['processed'] ?? 0), $previousTotal);
                    $progressBar->setMaxSteps($previousTotal);
                    $progressBar->setProgress($previousProcessed);
                    $reportedProgress[$progressBarPhase] = $previousProcessed.'/'.$previousTotal;
                }
                $progressBar->finish();
                $progressBar = null;
                $progressBarPhase = null;
            }

            $current = $phases[$phase];
            $total = max(0, (int) ($current['total'] ?? 0));
            $processed = max(0, (int) ($current['processed'] ?? 0));
            $signature = $processed.'/'.$total;
            if ($total === 0) {
                if (($reportedProgress[$phase] ?? null) !== $signature) {
                    $this->line($this->progressLine($phase, $current));
                    $reportedProgress[$phase] = $signature;
                }

                return;
            }
            if ($progressBar === null && ($reportedProgress[$phase] ?? null) === $signature) {
                return;
            }

            if ($progressBar === null) {
                $progressBar = new ProgressBar($this->getOutput(), $total);
                $progressBar->setFormat('%message% %current%/%max%');
                $progressBar->start();
                $progressBarPhase = $phase;
            }

            $progressBar->setMessage(
                $this->phaseLabel($phase).' ('.($current['unit'] ?? 'baris').')'
                .$this->progressCountsSuffix(is_array($current['counts'] ?? null) ? $current['counts'] : [])
            );
            $progressBar->setMaxSteps($total);
            $progressBar->setProgress(min($processed, $total));
            if ($processed >= $total) {
                $progressBar->finish();
                $progressBar = null;
                $progressBarPhase = null;
                $reportedProgress[$phase] = $signature;
            }

            return;
        }

        $now = microtime(true);
        foreach ($phases as $stage => $stageProgress) {
            if (! is_array($stageProgress)) {
                continue;
            }
            $processed = max(0, (int) ($stageProgress['processed'] ?? 0));
            $total = max(0, (int) ($stageProgress['total'] ?? 0));
            $signature = $processed.'/'.$total;
            $isCurrent = $stage === $phase;
            $isComplete = $total === 0 || $processed >= $total;
            $isNew = ! array_key_exists($stage, $reportedProgress);
            $heartbeatDue = $isCurrent && ($now - (float) ($lastProgressOutputAt[$stage] ?? 0)) >= 5;
            if (($reportedProgress[$stage] ?? null) === $signature && ! $heartbeatDue) {
                continue;
            }
            if (! $isNew && ! $isComplete && ! $heartbeatDue) {
                continue;
            }

            $this->line($this->progressLine((string) $stage, $stageProgress));
            $reportedProgress[$stage] = $signature;
            $lastProgressOutputAt[$stage] = $now;
        }
    }

    private function progressLine(string $phase, array $progress): string
    {
        $processed = (int) ($progress['processed'] ?? 0);
        $total = (int) ($progress['total'] ?? 0);
        $unit = (string) ($progress['unit'] ?? 'baris');
        $line = $total > 0
            ? 'Progres '.$this->phaseLabel($phase).': '.$processed.' / '.$total.' '.$unit
            : 'Progres '.$this->phaseLabel($phase).': menyiapkan total '.$unit;

        return $line.$this->progressCountsSuffix(is_array($progress['counts'] ?? null) ? $progress['counts'] : []).'.';
    }

    private function progressCountsSuffix(array $counts): string
    {
        $parts = [];
        foreach ([
            'unprocessed' => 'belum diperiksa',
            'queued' => 'siap',
            'pending' => 'perlu ditinjau',
            'completed' => 'selesai',
            'failed' => 'gagal',
            'running' => 'sedang diproses',
            'reconciled' => 'sesuai',
            'review' => 'perlu ditinjau pajak',
            'not_applicable' => 'tidak berlaku',
        ] as $key => $label) {
            if (isset($counts[$key])) {
                $parts[] = (int) $counts[$key].' '.$label;
            }
        }
        if ($parts !== []) {
            return ' ('.implode(', ', $parts).')';
        }

        return '';
    }

    private function phaseLabel(string $phase): string
    {
        return [
            'workbook_analysis' => 'Analisis workbook',
            'source_matching' => 'Pencocokan transaksi sumber',
            'preparing_import' => 'Persiapan antrean',
            'importing' => 'Import',
            'tax_reconciliation' => 'Rekonsiliasi pajak',
        ][$phase] ?? $phase;
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
