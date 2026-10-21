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

    // Match the queue worker timeout and stay below database retry_after.
    public $timeout = 1800;

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
            $options = is_array($options) ? $options : [];
            $total = count($analysis['entries']);
            $chunkSize = 50;
            $offset = 0;
            $importedCount = 0;
            $skippedCount = 0;

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'importing',
                'processed_entries' => 0,
                'imported_entries' => 0,
                'skipped_entries' => 0,
                'last_message' => 'Worker mulai menyimpan '.$total.' entry.',
                'updated_at' => now(),
            ]);

            while ($offset < $total) {
                $entries = array_slice($analysis['entries'], $offset, $chunkSize);

                DB::beginTransaction();
                $result = $controller->processJournalImportEntriesForBatch($import, $entries, $options);
                $importedCount += (int) $result['imported'];
                $skippedCount += (int) $result['skipped'];
                $processedEntries = min($offset + count($entries), $total);
                DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                    'imported_entries' => DB::raw('imported_entries + '.$result['imported']),
                    'skipped_entries' => DB::raw('skipped_entries + '.$result['skipped']),
                    'processed_entries' => $processedEntries,
                    'last_message' => 'Memproses entry '.$processedEntries.' / '.$total.' ('.$importedCount.' dibuat, '.$skippedCount.' dilewati).',
                    'updated_at' => now(),
                ]);
                DB::commit();

                $offset += count($entries);
            }

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'reconciling',
                'last_message' => 'Entry selesai disimpan; sedang merekonsiliasi pajak dan relasi sumber.',
                'updated_at' => now(),
            ]);

            DB::beginTransaction();
            $controller->persistJournalTaxAccountMappings(
                $analysis,
                $import->business_id,
                $import->created_by,
                $options
            );
            $taxReconciliation = $controller->reconcileJournalImportTaxes(
                (int) $import->id,
                (int) $import->business_id
            );
            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'imported',
                'last_message' => 'Import selesai: '.$importedCount.' entry dibuat, '.$skippedCount.' dilewati; '.($taxReconciliation['review'] ?? 0).' rekonsiliasi pajak perlu ditinjau.',
                'updated_at' => now(),
            ]);
            DB::commit();

            $controller->deleteTemporaryJournalFile($import->file_path);
            $controller->deleteTemporaryJournalFile($import->analysis_path);
        } catch (\Throwable $e) {
            DB::rollBack();

            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'status' => 'failed',
                'last_message' => 'Import gagal. Buka detail batch untuk melihat kesalahan.',
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
            ->whereIn('status', ['queued_import', 'importing', 'reconciling', 'processing'])
            ->update([
                'status' => 'failed',
                'last_message' => 'Worker menghentikan import sebelum selesai.',
                'error_message' => substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
    }
}
