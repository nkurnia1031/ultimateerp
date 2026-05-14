<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Controllers\JournalEntryController;

class ProcessJournalImportJob implements ShouldQueue
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

        if (empty($import) || empty($import->analysis_path)) {
            return;
        }

        try {
            $analysis = $controller->readTemporaryJournalAnalysis($import->analysis_path);
            $options = ! empty($import->import_options) ? json_decode($import->import_options, true) : [];
            $total = count($analysis['entries']);
            $chunkSize = 50;
            $offset = 0;

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'importing',
                'processed_entries' => 0,
                'imported_entries' => 0,
                'skipped_entries' => 0,
                'last_message' => 'Starting import...',
                'updated_at' => now(),
            ]);

            while ($offset < $total) {
                $entries = array_slice($analysis['entries'], $offset, $chunkSize);

                DB::beginTransaction();
                $result = $controller->processJournalImportEntriesForBatch($import, $entries, $options);
                DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                    'imported_entries' => DB::raw('imported_entries + '.$result['imported']),
                    'skipped_entries' => DB::raw('skipped_entries + '.$result['skipped']),
                    'processed_entries' => min($offset + count($entries), $total),
                    'last_message' => 'Imported '.min($offset + count($entries), $total).' / '.$total,
                    'updated_at' => now(),
                ]);
                DB::commit();

                $offset += count($entries);
            }

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'imported',
                'last_message' => 'Import completed.',
                'updated_at' => now(),
            ]);

            $controller->deleteTemporaryJournalFile($import->file_path);
            $controller->deleteTemporaryJournalFile($import->analysis_path);
        } catch (\Throwable $e) {
            DB::rollBack();

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }
}
