<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Controllers\JournalEntryController;

class AnalyzeJournalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 0;

    public $tries = 1;

    protected $importId;

    public function __construct($importId)
    {
        $this->importId = $importId;
        $this->onQueue('imports');
    }

    public function handle(JournalEntryController $controller)
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();

        if (empty($import)) {
            return;
        }

        try {
            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'analyzing',
                'last_message' => 'Analyzing uploaded file...',
                'updated_at' => now(),
            ]);

            $analysis = $controller->analyzeJournalWorkbook($import->file_path, $import->business_id);
            $analysisPath = $controller->writeTemporaryJournalAnalysis($analysis);

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'analysis_path' => $analysisPath,
                'source_date_range' => $analysis['source_date_range'],
                'total_entries' => $analysis['total_entries'],
                'processed_entries' => $analysis['total_entries'],
                'unbalanced_entries' => $analysis['unbalanced_count'],
                'duplicate_entries' => $analysis['duplicate_count'],
                'missing_accounts' => count($analysis['missing_accounts']),
                'unsupported_entries' => $analysis['non_journal_count'],
                'summary' => json_encode($controller->compactJournalAnalysisSummary($analysis)),
                'status' => 'analyzed',
                'last_message' => 'Analysis completed.',
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }
}
