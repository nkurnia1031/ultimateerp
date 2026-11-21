<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\UserImportBatchService;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportCancellationRequestedException;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportHistoryService;
use Modules\Accounting\Services\JournalImportProgressService;

class AnalyzeJournalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Match the queue worker timeout and stay below database retry_after.
    public $timeout = 1800;

    public $tries = 1;

    protected $importId;

    protected $progressListener;

    public function __construct($importId)
    {
        $this->importId = $importId;
        $this->onQueue('imports');
    }

    public function onProgress(callable $listener): self
    {
        $this->progressListener = $listener;

        return $this;
    }

    public function handle(
        JournalEntryController $controller,
        JournalImportDraftService $draftService,
        JournalImportCancellationService $cancellationService,
        JournalImportProgressService $progressService,
        ?JournalImportHistoryService $historyService = null,
        ?UserImportBatchService $batchService = null
    )
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();

        if (empty($import)) {
            return;
        }

        $isWebImport = ($import->import_origin ?? 'web') === 'web';

        if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        try {
            if ($isWebImport) {
                $historyService = $historyService ?: app(JournalImportHistoryService::class);
                $batchService = $batchService ?: app(UserImportBatchService::class);
            }

            $claimed = DB::table('accounting_journal_imports')
                ->where('id', $this->importId)
                ->where('status', 'queued')
                ->whereNull('cancel_requested_at')
                ->update([
                'status' => 'analyzing',
                'last_message' => 'Proses mulai membuka workbook.',
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
                return;
            }

            $analysisSourcePath = $import->file_path;
            if ($isWebImport) {
                $sharedBatch = $historyService->linkBatch((int) $import->id, (int) $import->business_id);
                $resolvedSourcePath = ! empty($analysisSourcePath)
                    ? $controller->resolveJournalImportFilePath($analysisSourcePath)
                    : null;

                if (! empty($resolvedSourcePath) && is_file($resolvedSourcePath)) {
                    $sharedBatch = $historyService->captureSourceSnapshot(
                        (int) $import->id,
                        (int) $import->business_id,
                        $resolvedSourcePath
                    );
                    $sourcePathToClean = $analysisSourcePath;
                } elseif ($sharedBatch && $sharedBatch->hasSourceSnapshot()) {
                    $restoredTempPath = $batchService->restoredWorkbookPath($sharedBatch);
                    $restoredName = (string) Str::uuid().'.xlsx';
                    $restoredContents = file_get_contents($restoredTempPath);
                    if ($restoredContents === false || ! Storage::disk('journal_imports')->put($restoredName, $restoredContents)) {
                        throw new \RuntimeException('Workbook dari snapshot Journal tidak dapat dipulihkan ke staging privat.');
                    }
                    $analysisSourcePath = 'journal_imports/'.$restoredName;
                    $sourcePathToClean = $analysisSourcePath;
                } else {
                    throw new \RuntimeException('Workbook sumber tidak tersedia dan snapshot Journal belum tersimpan.');
                }
            }

            $progressService->record(
                (int) $import->id,
                (int) $import->business_id,
                'workbook_analysis',
                0,
                0,
                'baris workbook',
                [],
                'Membuka workbook dan menyiapkan analisis.'
            );

            $analysis = $controller->analyzeJournalWorkbook(
                $analysisSourcePath,
                $import->business_id,
                function (array $progress) use ($cancellationService, $progressService, $import) {
                    if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                        throw new JournalImportCancellationRequestedException('Analisis dihentikan atas permintaan pengguna.');
                    }

                    $phase = (string) ($progress['phase'] ?? 'workbook_analysis');
                    $progressService->record(
                        (int) $import->id,
                        (int) $import->business_id,
                        $phase,
                        (int) ($progress['processed'] ?? $progress['processed_rows'] ?? 0),
                        (int) ($progress['total'] ?? $progress['total_rows'] ?? 0),
                        (string) ($progress['unit'] ?? ($phase === 'workbook_analysis' ? 'baris workbook' : 'entry')),
                        [],
                        (string) ($progress['message'] ?? 'Analisis workbook berjalan.')
                    );

                    if ($this->progressListener !== null) {
                        call_user_func($this->progressListener, $progress);
                    }
                }
            );

            if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                return;
            }

            $analysisPath = $controller->writeTemporaryJournalAnalysis($analysis);
            $draftService->persistEntries((int) $import->id, (int) $import->business_id, $analysis['entries']);

            if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                return;
            }

            $updated = DB::table('accounting_journal_imports')
                ->where('id', $this->importId)
                ->whereNull('cancel_requested_at')
                ->update([
                'analysis_path' => $analysisPath,
                'source_date_range' => $analysis['source_date_range'],
                'total_entries' => $analysis['total_entries'],
                'processed_entries' => 0,
                'unbalanced_entries' => $analysis['unbalanced_count'],
                'duplicate_entries' => $analysis['duplicate_count'],
                'missing_accounts' => count($analysis['missing_accounts']),
                'unsupported_entries' => 0,
                'summary' => json_encode($controller->compactJournalAnalysisSummary($analysis)),
                'status' => 'analyzed',
                'last_message' => 'Analisis selesai; draft baris tersimpan dan preview siap ditinjau.',
                'updated_at' => now(),
            ]);
            if (! $updated) {
                $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
            } elseif ($isWebImport) {
                $historyService->linkBatch((int) $import->id, (int) $import->business_id);
            }
        } catch (JournalImportCancellationRequestedException $e) {
            $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
        } catch (\Throwable $e) {
            if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                return;
            }

            $failed = DB::table('accounting_journal_imports')
                ->where('id', $this->importId)
                ->whereNull('cancel_requested_at')
                ->whereIn('status', ['queued', 'analyzing'])
                ->update([
                    'status' => 'failed',
                    'last_message' => 'Analisis gagal. Buka detail batch untuk melihat kesalahan.',
                    'error_message' => $e->getMessage(),
                    'updated_at' => now(),
                ]);
            if (! $failed) {
                $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
            } elseif ($isWebImport) {
                ($historyService ?: app(JournalImportHistoryService::class))->linkBatch((int) $import->id, (int) $import->business_id);
            }

            throw $e;
        } finally {
            if (isset($sourcePathToClean) && $sourcePathToClean !== null
                && $isWebImport) {
                $this->cleanupSourceStaging($import, $sourcePathToClean, $controller);
            }

            if (isset($restoredTempPath) && is_string($restoredTempPath) && is_file($restoredTempPath)) {
                @unlink($restoredTempPath);
                @rmdir(dirname($restoredTempPath));
            }
        }
    }

    public function failed(\Throwable $exception)
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (! empty($import)
            && app(JournalImportCancellationService::class)->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            $this->cleanupSourceStaging($import);
            return;
        }

        $updated = DB::table('accounting_journal_imports')
            ->where('id', $this->importId)
            ->whereNull('cancel_requested_at')
            ->whereIn('status', ['queued', 'analyzing'])
            ->update([
                'status' => 'failed',
                'last_message' => 'Worker menghentikan analisis sebelum selesai.',
                'error_message' => substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        if (! empty($import) && ($import->import_origin ?? 'web') === 'web') {
            $this->cleanupSourceStaging($import);
            app(JournalImportHistoryService::class)->linkBatch((int) $import->id, (int) $import->business_id);
        }
        if (! $updated && ! empty($import)) {
            app(JournalImportCancellationService::class)->stopIfRequested((int) $import->id, (int) $import->business_id);
        }
    }

    private function cleanupSourceStaging(object $import, ?string $path = null, ?JournalEntryController $controller = null): void
    {
        if (($import->import_origin ?? 'web') !== 'web') {
            return;
        }

        $historyService = app(JournalImportHistoryService::class);
        $sharedBatch = $historyService->linkBatch((int) $import->id, (int) $import->business_id);
        if (! $sharedBatch || ! $sharedBatch->hasSourceSnapshot()) {
            return;
        }

        $pathToClean = $path ?: ($import->file_path ?? null);
        if (filled($pathToClean)) {
            ($controller ?: app(JournalEntryController::class))->deleteTemporaryJournalFile($pathToClean);
        }

        DB::table('accounting_journal_imports')
            ->where('id', $import->id)
            ->where('business_id', $import->business_id)
            ->update(['file_path' => null]);
        $sharedBatch->staging_path = null;
        $sharedBatch->save();
    }
}
