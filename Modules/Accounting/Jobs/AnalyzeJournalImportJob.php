<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportCancellationRequestedException;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalImportDraftService;

class AnalyzeJournalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Match the queue worker timeout and stay below database retry_after.
    public $timeout = 1800;

    public $tries = 1;

    protected $importId;

    public function __construct($importId)
    {
        $this->importId = $importId;
        $this->onQueue('imports');
    }

    public function handle(
        JournalEntryController $controller,
        JournalImportDraftService $draftService,
        JournalImportCancellationService $cancellationService
    )
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();

        if (empty($import)) {
            return;
        }

        if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        try {
            $claimed = DB::table('accounting_journal_imports')
                ->where('id', $this->importId)
                ->whereNull('cancel_requested_at')
                ->update([
                'status' => 'analyzing',
                'last_message' => 'Worker mulai membuka workbook.',
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
                return;
            }

            $analysis = $controller->analyzeJournalWorkbook(
                $import->file_path,
                $import->business_id,
                function (array $progress) use ($cancellationService, $import) {
                    if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                        throw new JournalImportCancellationRequestedException('Analisis dihentikan atas permintaan pengguna.');
                    }

                    DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                        'last_message' => $progress['message'] ?? 'Analisis workbook berjalan.',
                        'updated_at' => now(),
                    ]);
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
                'processed_entries' => $analysis['total_entries'],
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
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (! empty($import)
            && app(JournalImportCancellationService::class)->stopIfRequested((int) $import->id, (int) $import->business_id)) {
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
        if (! $updated && ! empty($import)) {
            app(JournalImportCancellationService::class)->stopIfRequested((int) $import->id, (int) $import->business_id);
        }
    }
}
