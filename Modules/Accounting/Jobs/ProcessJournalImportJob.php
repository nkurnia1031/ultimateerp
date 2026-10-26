<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportExpenseService;
use Modules\Accounting\Services\JournalImportInvoiceService;
use Modules\Accounting\Services\JournalImportPaymentService;
use Modules\Accounting\Services\JournalImportBankTransactionService;
use Modules\Accounting\Services\JournalImportQueueService;
use Modules\Accounting\Services\JournalImportStockService;

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

    public function handle(
        JournalEntryController $controller,
        JournalImportQueueService $queueService,
        JournalImportInvoiceService $invoiceService,
        JournalImportExpenseService $expenseService,
        JournalImportPaymentService $paymentService,
        JournalImportBankTransactionService $bankTransactionService,
        ?JournalImportStockService $stockService = null
    ): void
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (empty($import)) {
            return;
        }

        // Only one delivery may own a batch. A duplicate queue delivery should
        // not race the first worker or make the batch look resumable mid-run.
        $claimedBatch = DB::table('accounting_journal_imports')
            ->where('id', $this->importId)
            ->where('status', 'queued_import')
            ->update([
                'status' => 'importing',
                'last_message' => 'Worker mengambil alih batch Journal Import.',
                'updated_at' => now(),
            ]);

        if (! $claimedBatch) {
            return;
        }

        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (empty($import)) {
            return;
        }

        $options = ! empty($import->import_options) ? json_decode($import->import_options, true) : [];
        $options = is_array($options) ? $options : [];
        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $this->importId)
            ->where('business_id', $import->business_id)
            ->where('processing_status', 'queued')
            ->orderByRaw("CASE source_type WHEN 'Sales Invoice' THEN 0 WHEN 'Purchase Invoice' THEN 1 WHEN 'Expense' THEN 2 WHEN 'Receive Payment' THEN 3 WHEN 'Purchase Payment' THEN 4 WHEN 'Bank Deposit' THEN 5 WHEN 'Bank Transfer' THEN 6 WHEN 'Bank Withdrawal' THEN 7 WHEN 'Reversal' THEN 8 WHEN 'Stock Adjustment' THEN 9 WHEN 'Stock In/Out' THEN 10 WHEN 'Opening Balance' THEN 11 ELSE 12 END")
            ->orderBy('entry_index')
            ->get();

        DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
            'last_message' => 'Worker memulai '.$rows->count().' baris siap proses dari draft tersimpan.',
            'updated_at' => now(),
        ]);

        foreach ($rows as $queuedRow) {
            $claimed = DB::table('accounting_journal_import_rows')
                ->where('id', $queuedRow->id)
                ->where('business_id', $import->business_id)
                ->where('processing_status', 'queued')
                ->update([
                    'processing_status' => 'running',
                    'processing_attempts' => DB::raw('processing_attempts + 1'),
                    'processing_message' => 'Worker sedang memproses baris Journal Import.',
                    'processing_error' => null,
                    'processing_started_at' => now(),
                    'updated_at' => now(),
                ]);

            if (! $claimed) {
                continue;
            }

            try {
                DB::transaction(function () use ($queuedRow, $import, $options, $controller, $queueService, $invoiceService, $expenseService, $paymentService, $bankTransactionService, $stockService) {
                    $row = DB::table('accounting_journal_import_rows')
                        ->where('id', $queuedRow->id)
                        ->where('journal_import_id', $import->id)
                        ->where('business_id', $import->business_id)
                        ->lockForUpdate()
                        ->first();

                    if (empty($row) || $row->processing_status !== 'running') {
                        return;
                    }

                    $entry = json_decode((string) $row->entry_data, true) ?: [];
                    $sourceKey = (string) ($entry['source_key'] ?? $row->source_key ?? '');

                    if (in_array($row->source_type, ['Receive Payment', 'Purchase Payment'], true)) {
                        $dependencyIssue = $paymentService->dependencyIssue($row, $entry, (int) $import->business_id);
                        if ($dependencyIssue !== null) {
                            DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                                'processing_status' => 'pending',
                                'processing_message' => $dependencyIssue,
                                'processing_error' => null,
                                'processing_started_at' => null,
                                'updated_at' => now(),
                            ]);

                            return;
                        }
                    }

                    $existingMapping = DB::table('accounting_acc_trans_mappings')
                        ->where('business_id', $import->business_id)
                        ->where('type', 'journal_entry')
                        ->where('import_source_key', $sourceKey)
                        ->first();

                    if (! empty($existingMapping)) {
                        DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                            'processing_status' => 'completed',
                            'processing_message' => 'Posting Journal dengan kunci ini sudah tersedia; tidak dibuat ulang.',
                            'processing_error' => null,
                            'journal_mapping_id' => $existingMapping->id,
                            'processed_at' => now(),
                            'updated_at' => now(),
                        ]);

                        return;
                    }

                    $issue = $queueService->entryIssue(
                        $entry,
                        (string) $row->source_action,
                        (string) ($row->reconciliation_status ?? 'not_checked'),
                        $row,
                        $options,
                        (int) $import->business_id
                    );
                    if ($issue !== null) {
                        throw new \RuntimeException($issue);
                    }

                    if (in_array($row->source_type, ['Sales Invoice', 'Purchase Invoice'], true)) {
                        $sourceTransaction = $invoiceService->process($row, $entry, $import, $options);
                        $entry['source_record_type'] = \App\Transaction::class;
                        $entry['source_record_id'] = (int) $sourceTransaction->id;
                        $entry['source_link_status'] = 'matched';
                        $entry['source_link_note'] = $row->source_action === 'create'
                            ? 'Invoice dibuat dari Journal Import.'
                            : 'Invoice dipilih secara eksplisit dari draft Journal Import.';
                    } elseif ($row->source_type === 'Expense') {
                        $sourceTransaction = $expenseService->process($row, $entry, $import, $options);
                        $entry['source_record_type'] = \App\Transaction::class;
                        $entry['source_record_id'] = (int) $sourceTransaction->id;
                        $entry['source_link_status'] = 'matched';
                        $entry['source_link_note'] = $row->source_action === 'create'
                            ? 'Expense dibuat dari Journal Import.'
                            : 'Expense dipilih secara eksplisit dari draft Journal Import.';
                    } elseif (in_array($row->source_type, ['Receive Payment', 'Purchase Payment'], true)) {
                        $sourcePayment = $paymentService->process($row, $entry, $import, $options);
                        $entry['source_record_type'] = \App\TransactionPayment::class;
                        $entry['source_record_id'] = (int) $sourcePayment->id;
                        $entry['source_link_status'] = 'matched';
                        $entry['source_link_note'] = $row->source_action === 'create'
                            ? 'Pembayaran dibuat dari Journal Import.'
                            : 'Pembayaran dipilih secara eksplisit dari draft Journal Import.';
                    } elseif (in_array($row->source_type, ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'], true)) {
                        $sourceTransaction = $bankTransactionService->process($row, $entry, $import, $options);
                        $entry['source_record_type'] = \App\AccountTransaction::class;
                        $entry['source_record_id'] = (int) $sourceTransaction->id;
                        $entry['source_link_status'] = 'matched';
                        $entry['source_link_note'] = $row->source_action === 'create'
                            ? 'Transaksi bank dibuat dari Journal Import.'
                            : 'Transaksi bank dipilih secara eksplisit dari draft Journal Import.';
                    } elseif (in_array($row->source_type, ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)) {
                        $sourceTransaction = ($stockService ?: app(JournalImportStockService::class))->process($row, $entry, $import, $options);
                        $entry['source_record_type'] = \App\Transaction::class;
                        $entry['source_record_id'] = (int) $sourceTransaction->id;
                        $entry['source_link_status'] = 'matched';
                        $entry['source_link_note'] = $row->source_action === 'create'
                            ? 'Record stok dibuat dari Journal Import.'
                            : 'Record stok dipilih secara eksplisit dari draft Journal Import.';
                    }

                    $result = $controller->processJournalImportEntriesForBatch($import, [$entry], $options);
                    if ((int) ($result['imported'] ?? 0) !== 1) {
                        throw new \RuntimeException('Baris dilewati. Periksa keseimbangan dan mapping akun GL.');
                    }

                    $mapping = DB::table('accounting_acc_trans_mappings')
                        ->where('business_id', $import->business_id)
                        ->where('journal_import_id', $import->id)
                        ->where('import_source_key', $sourceKey)
                        ->first();
                    if (empty($mapping)) {
                        throw new \RuntimeException('Posting Journal berhasil tetapi relasi sumber tidak ditemukan.');
                    }

                    DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                        'processing_status' => 'completed',
                        'processing_message' => 'Baris Journal Import berhasil diproses dan dikaitkan ke mapping GL.',
                        'processing_error' => null,
                        'journal_mapping_id' => $mapping->id,
                        'source_record_type' => $entry['source_record_type'] ?? $row->source_record_type,
                        'source_record_id' => $entry['source_record_id'] ?? $row->source_record_id,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (\Throwable $exception) {
                DB::table('accounting_journal_import_rows')
                    ->where('id', $queuedRow->id)
                    ->where('business_id', $import->business_id)
                    ->whereIn('processing_status', ['running', 'queued'])
                    ->update([
                        'processing_status' => 'failed',
                        'processing_message' => 'Baris gagal; periksa pesan kesalahan dan coba lagi setelah diperbaiki.',
                        'processing_error' => mb_substr($exception->getMessage(), 0, 2000),
                        'updated_at' => now(),
                    ]);
            }

            $counts = $this->processingCounts((int) $import->id, (int) $import->business_id);
            $queueProcessed = $counts['completed'] + $counts['failed'];
            $queueTotal = $counts['completed'] + $counts['failed'] + $counts['queued'] + $counts['running'];
            DB::table('accounting_journal_imports')->where('id', $import->id)->update([
                'processed_entries' => $queueProcessed,
                'imported_entries' => $counts['completed'],
                'skipped_entries' => $counts['failed'],
                'last_message' => 'Progres queue Journal Entry: '.$queueProcessed.' / '.$queueTotal
                    .' baris; '.$counts['completed'].' selesai, '.$counts['failed'].' gagal.',
                'updated_at' => now(),
            ]);
        }

        $entries = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $import->id)
            ->where('business_id', $import->business_id)
            ->orderBy('entry_index')
            ->get(['entry_data'])
            ->map(function ($row) {
                return json_decode((string) $row->entry_data, true) ?: [];
            })->all();

        DB::table('accounting_journal_imports')->where('id', $import->id)->update([
            'status' => 'reconciling',
            'last_message' => 'Queue Journal Import selesai; memeriksa pemetaan pajak dan sumber yang sudah tersedia.',
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($controller, $import, $options, $entries) {
                $analysis = ['entries' => $entries];
                $controller->persistJournalTaxAccountMappings(
                    $analysis,
                    $import->business_id,
                    $import->created_by,
                    $options
                );
                $controller->reconcileJournalImportTaxes((int) $import->id, (int) $import->business_id);
            });
        } catch (\Throwable $exception) {
            DB::table('accounting_journal_imports')->where('id', $import->id)->update([
                'last_message' => 'Journal Entry sudah diproses; rekonsiliasi pajak perlu dicoba kembali.',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        }

        $counts = $this->processingCounts((int) $import->id, (int) $import->business_id);
        $queueTotal = $counts['completed'] + $counts['failed'] + $counts['queued'] + $counts['running'];
        $allComplete = $counts['pending'] === 0 && $queueTotal === $counts['completed'];
        $status = $allComplete ? 'imported' : ($counts['completed'] === 0 && $counts['failed'] > 0 && $counts['pending'] === 0 ? 'failed' : 'partially_imported');
        $lastMessage = 'Queue selesai: '.$counts['completed'].' baris berhasil, '.$counts['failed'].' gagal, '
            .$counts['pending'].' menunggu kelengkapan atau handler transaksi.';

        DB::table('accounting_journal_imports')->where('id', $import->id)->update([
            'status' => $status,
            'processed_entries' => $counts['completed'] + $counts['failed'],
            'imported_entries' => $counts['completed'],
            'skipped_entries' => $counts['failed'],
            'last_message' => $lastMessage,
            'updated_at' => now(),
        ]);

        if ($allComplete) {
            $controller->deleteTemporaryJournalFile($import->file_path);
            $controller->deleteTemporaryJournalFile($import->analysis_path);
        }
    }

    public function failed(\Throwable $exception): void
    {
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $this->importId)
            ->whereIn('processing_status', ['queued', 'running'])
            ->update([
                'processing_status' => 'failed',
                'processing_message' => 'Worker menghentikan proses sebelum baris selesai.',
                'processing_error' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

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

    private function processingCounts(int $importId, int $businessId): array
    {
        $counts = array_fill_keys(['pending', 'queued', 'running', 'completed', 'failed'], 0);
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->select('processing_status', DB::raw('COUNT(*) as row_count'))
            ->groupBy('processing_status')
            ->get()
            ->each(function ($row) use (&$counts) {
                if (array_key_exists($row->processing_status, $counts)) {
                    $counts[$row->processing_status] = (int) $row->row_count;
                }
            });

        return $counts;
    }
}
