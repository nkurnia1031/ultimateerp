<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Controllers\JournalEntryController;
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

    public function handle(JournalEntryController $controller, JournalImportDraftService $draftService)
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();

        if (empty($import)) {
            return;
        }

        try {
            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'analyzing',
                'last_message' => 'Worker mulai membuka workbook.',
                'updated_at' => now(),
            ]);

            $analysis = $controller->analyzeJournalWorkbook(
                $import->file_path,
                $import->business_id,
                function (array $progress) {
                    DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                        'last_message' => $progress['message'] ?? 'Analisis workbook berjalan.',
                        'updated_at' => now(),
                    ]);
                }
            );
            $analysisPath = $controller->writeTemporaryJournalAnalysis($analysis);
            $draftService->persistEntries((int) $import->id, (int) $import->business_id, $analysis['entries']);

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
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
        } catch (\Throwable $e) {
            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'failed',
                'last_message' => 'Analisis gagal. Buka detail batch untuk melihat kesalahan.',
                'error_message' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        DB::table('accounting_journal_imports')
            ->where('id', $this->importId)
            ->whereIn('status', ['queued', 'analyzing'])
            ->update([
                'status' => 'failed',
                'last_message' => 'Worker menghentikan analisis sebelum selesai.',
                'error_message' => substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
    }
}
