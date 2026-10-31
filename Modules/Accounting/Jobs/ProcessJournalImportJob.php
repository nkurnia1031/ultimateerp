<?php

namespace Modules\Accounting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportExpenseService;
use Modules\Accounting\Services\JournalImportIdentityService;
use Modules\Accounting\Services\JournalImportInvoiceService;
use Modules\Accounting\Services\JournalImportPaymentService;
use Modules\Accounting\Services\JournalImportBankTransactionService;
use Modules\Accounting\Services\JournalImportCancellationService;
use Modules\Accounting\Services\JournalImportQueueService;
use Modules\Accounting\Services\JournalImportStockService;
use Modules\Accounting\Services\JournalTaxReconciliationService;

class ProcessJournalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 25;

    private const CHUNK_DELAY_SECONDS = 2;

    // Match the queue worker timeout and stay below database retry_after.
    public $timeout = 300;

    public $tries = 1;

    protected $importId;

    protected $phase;

    protected $afterMappingId;

    protected $afterRowId;

    protected $dispatchContinuations;

    protected $runner;

    public function __construct(
        $importId,
        string $phase = 'entries',
        int $afterMappingId = 0,
        int $afterRowId = 0,
        bool $dispatchContinuations = true,
        string $runner = 'worker'
    )
    {
        $this->importId = $importId;
        $this->phase = $phase;
        $this->afterMappingId = $afterMappingId;
        $this->afterRowId = $afterRowId;
        $this->dispatchContinuations = $dispatchContinuations;
        $this->runner = $runner;
        $this->onQueue('imports');
    }

    public function middleware(): array
    {
        // Avoid overlapping deliveries that share this batch's persisted cursors.
        return [
            (new WithoutOverlapping('journal-import-'.$this->importId))
                ->dontRelease()
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(
        JournalEntryController $controller,
        JournalImportQueueService $queueService,
        JournalImportInvoiceService $invoiceService,
        JournalImportExpenseService $expenseService,
        JournalImportPaymentService $paymentService,
        JournalImportBankTransactionService $bankTransactionService,
        ?JournalImportStockService $stockService = null,
        ?JournalImportCancellationService $cancellationService = null
    ): void
    {
        $cancellationService = $cancellationService ?: app(JournalImportCancellationService::class);
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (empty($import)) {
            return;
        }

        if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        if ($this->phase === 'prepare') {
            if ($import->status !== 'preparing_import') {
                return;
            }
            if ($this->afterRowId < (int) $import->prepare_cursor) {
                return;
            }
        } elseif ($this->phase === 'entries') {
            if ($import->status === 'queued_import') {
                // Only the first delivery claims a new batch. Continuations run
                // while the batch remains in importing state.
                $claimedBatch = DB::table('accounting_journal_imports')
                    ->where('id', $this->importId)
                    ->where('status', 'queued_import')
                    ->update([
                        'status' => 'importing',
                        'last_message' => ucfirst($this->runner).' mengambil alih batch Journal Import.',
                        'updated_at' => now(),
                    ]);

                if (! $claimedBatch) {
                    return;
                }
            } elseif ($import->status !== 'importing') {
                return;
            }
        } elseif ($this->phase === 'tax') {
            if ($import->status !== 'reconciling'
                || $this->afterMappingId < (int) $import->tax_reconciliation_cursor) {
                return;
            }
        } else {
            return;
        }

        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (empty($import)) {
            return;
        }

        $options = ! empty($import->import_options) ? json_decode($import->import_options, true) : [];
        $options = is_array($options) ? $options : [];

        if ($this->phase === 'prepare') {
            $this->prepareImportChunk($import, $options, $queueService, $cancellationService);

            return;
        }

        $rows = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $this->importId)
            ->where('business_id', $import->business_id)
            ->where('processing_status', 'queued')
            ->orderByRaw("CASE source_type WHEN 'Sales Invoice' THEN 0 WHEN 'Purchase Invoice' THEN 1 WHEN 'Expense' THEN 2 WHEN 'Receive Payment' THEN 3 WHEN 'Purchase Payment' THEN 4 WHEN 'Bank Deposit' THEN 5 WHEN 'Bank Transfer' THEN 6 WHEN 'Bank Withdrawal' THEN 7 WHEN 'Reversal' THEN 8 WHEN 'Stock Adjustment' THEN 9 WHEN 'Stock In/Out' THEN 10 WHEN 'Opening Balance' THEN 11 ELSE 12 END")
            ->orderBy('entry_index')
            ->limit(self::CHUNK_SIZE)
            ->get();

        if ($this->phase === 'entries') {
            DB::table('accounting_journal_imports')->where('id', $this->importId)->update([
                'last_message' => ucfirst($this->runner).' memproses maksimal '.self::CHUNK_SIZE.' baris dari draft tersimpan.',
                'updated_at' => now(),
            ]);
        }

        foreach ($rows as $queuedRow) {
            $claimed = DB::transaction(function () use ($queuedRow, $import) {
                $lockedBatch = DB::table('accounting_journal_imports')
                    ->where('id', $import->id)
                    ->where('business_id', $import->business_id)
                    ->lockForUpdate()
                    ->first();

                if (empty($lockedBatch)
                    || $lockedBatch->status !== 'importing'
                    || ! empty($lockedBatch->cancel_requested_at)) {
                    return false;
                }

                return DB::table('accounting_journal_import_rows')
                    ->where('id', $queuedRow->id)
                    ->where('business_id', $import->business_id)
                    ->where('processing_status', 'queued')
                    ->update([
                        'processing_status' => 'running',
                        'processing_attempts' => DB::raw('processing_attempts + 1'),
                        'processing_message' => ucfirst($this->runner).' sedang memproses baris Journal Import.',
                        'processing_error' => null,
                        'processing_started_at' => now(),
                        'updated_at' => now(),
                    ]);
            });

            if (! $claimed) {
                if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                    return;
                }
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
                        $identityMatch = $row->source_type === 'Journal Entry'
                            ? app(JournalImportIdentityService::class)->compareExistingMapping(
                                $existingMapping,
                                $entry,
                                (int) $import->business_id
                            )
                            : 'identical';

                        if ($identityMatch === 'conflict') {
                            DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                                'processing_status' => 'pending',
                                'processing_message' => 'Nomor dan tanggal Journal Entry sudah memiliki posting dengan isi berbeda; tinjau konflik sebelum memproses.',
                                'processing_error' => null,
                                'processing_started_at' => null,
                                'updated_at' => now(),
                            ]);

                            return;
                        }

                        DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                            'processing_status' => 'completed',
                            'processing_message' => $identityMatch === 'identical'
                                ? 'Posting Journal dengan isi yang sama sudah tersedia; tidak dibuat ulang.'
                                : 'Posting Journal dengan kunci ini sudah tersedia; tidak dibuat ulang.',
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

                    app(\Modules\Accounting\Services\JournalImportSourceLinkService::class)->sync(
                        (int) $row->id,
                        (int) $import->id,
                        (int) $import->business_id,
                        (string) $row->source_type,
                        $entry,
                        $entry['source_record_type'] ?? $row->source_record_type,
                        isset($entry['source_record_id'])
                            ? (int) $entry['source_record_id']
                            : ($row->source_record_id === null ? null : (int) $row->source_record_id)
                    );

                    if ($row->source_type === 'Journal Entry') {
                        app(JournalImportIdentityService::class)->completeIdenticalPendingRows(
                            (int) $import->id,
                            (int) $import->business_id,
                            $sourceKey,
                            $entry,
                            (int) $mapping->id
                        );
                    }
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

            if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                return;
            }

        }

        if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        if ($this->phase === 'entries') {
            $counts = $this->processingCounts((int) $import->id, (int) $import->business_id);
            $queueProcessed = $counts['completed'] + $counts['failed'];
            $queueTotal = $counts['completed'] + $counts['failed'] + $counts['queued'] + $counts['running'];
            app(\Modules\Accounting\Services\JournalImportProgressService::class)->record(
                (int) $import->id,
                (int) $import->business_id,
                'importing',
                $queueProcessed,
                $queueTotal,
                'baris import',
                $counts,
                'Progres import: '.$queueProcessed.' / '.$queueTotal.' baris; '
                    .$counts['completed'].' selesai, '.$counts['failed'].' gagal, '
                    .$counts['queued'].' menunggu, '.$counts['pending'].' perlu ditinjau.',
                [
                    'processed_entries' => $queueProcessed,
                    'imported_entries' => $counts['completed'],
                    'skipped_entries' => $counts['failed'],
                ]
            );

            $hasQueuedRows = DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $import->id)
                ->where('business_id', $import->business_id)
                ->where('processing_status', 'queued')
                ->exists();

            if ($hasQueuedRows && $this->dispatchContinuations) {
                self::dispatch($this->importId, 'entries')->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));
                return;
            }

            if ($hasQueuedRows) {
                return;
            }

            $reconciliationTotal = $this->taxReconciliationTotal((int) $import->id, (int) $import->business_id);
            $transitioned = app(\Modules\Accounting\Services\JournalImportProgressService::class)->record(
                (int) $import->id,
                (int) $import->business_id,
                'tax_reconciliation',
                0,
                $reconciliationTotal,
                'mapping pajak',
                [],
                'Pemrosesan Journal Import selesai; rekonsiliasi pajak 0 / '.$reconciliationTotal.' mapping.',
                ['status' => 'reconciling']
            );
            if ($transitioned === []) {
                $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
                return;
            }
            if ($this->dispatchContinuations) {
                self::dispatch($this->importId, 'tax')->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));
            }

            return;
        }

        $this->processTaxReconciliationChunk(
            $import,
            $controller,
            app(JournalTaxReconciliationService::class),
            $cancellationService
        );
    }

    public function failed(\Throwable $exception): void
    {
        $import = DB::table('accounting_journal_imports')->where('id', $this->importId)->first();
        if (! empty($import)
            && app(JournalImportCancellationService::class)->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        $updated = DB::table('accounting_journal_imports')
            ->where('id', $this->importId)
            ->whereNull('cancel_requested_at')
            ->whereIn('status', ['preparing_import', 'queued_import', 'importing', 'reconciling', 'processing'])
            ->update([
                'status' => 'failed',
                'last_message' => ucfirst($this->runner).' menghentikan import sebelum selesai.',
                'error_message' => substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

        if (! $updated) {
            if (! empty($import)) {
                app(JournalImportCancellationService::class)->stopIfRequested((int) $import->id, (int) $import->business_id);
            }

            return;
        }

        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $this->importId)
            ->whereIn('processing_status', ['queued', 'running'])
            ->update([
                'processing_status' => 'failed',
                'processing_message' => ucfirst($this->runner).' menghentikan proses sebelum baris selesai.',
                'processing_error' => mb_substr($exception->getMessage(), 0, 2000),
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

    private function taxReconciliationTotal(int $importId, int $businessId): int
    {
        return (int) DB::table('accounting_acc_trans_mappings')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->where('source_link_status', 'matched')
            ->whereNotNull('source_record_id')
            ->count();
    }

    private function processTaxReconciliationChunk(
        object $import,
        JournalEntryController $controller,
        JournalTaxReconciliationService $taxService,
        JournalImportCancellationService $cancellationService
    ): void {
        try {
            $chunk = $taxService->reconcileImportChunk(
                (int) $import->id,
                (int) $import->business_id,
                $this->afterMappingId,
                self::CHUNK_SIZE
            );
        } catch (\Throwable $exception) {
            if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
                return;
            }

            DB::table('accounting_journal_imports')
                ->where('id', $import->id)
                ->whereNull('cancel_requested_at')
                ->update([
                'last_message' => 'Journal Entry sudah diproses; rekonsiliasi pajak belum selesai.',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);

            $this->finishImport($import, $controller, $cancellationService);

            return;
        }

        if ($cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        if ($chunk['has_more']) {
            $this->recordTaxProgress($import, $chunk, (int) $chunk['last_id']);
            if ($this->dispatchContinuations) {
                self::dispatch($this->importId, 'tax', (int) $chunk['last_id'])
                    ->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));
            }

            return;
        }

        $this->recordTaxProgress($import, $chunk);

        $this->finishImport($import, $controller, $cancellationService);
    }

    private function recordTaxProgress(object $import, array $chunk, ?int $cursor = null): void
    {
        $progressService = app(\Modules\Accounting\Services\JournalImportProgressService::class);
        $progress = $progressService->decode($import->progress_data ?? null);
        $taxProgress = $progress['phases']['tax_reconciliation'] ?? [];
        $processed = (int) ($taxProgress['processed'] ?? 0) + (int) ($chunk['processed_rows'] ?? 0);
        $total = max(0, (int) ($taxProgress['total'] ?? 0));
        $priorCounts = is_array($taxProgress['counts'] ?? null) ? $taxProgress['counts'] : [];
        $chunkCounts = is_array($chunk['result'] ?? null) ? $chunk['result'] : [];
        $cumulativeCounts = [];
        foreach (['reconciled', 'review', 'not_applicable'] as $key) {
            $cumulativeCounts[$key] = (int) ($priorCounts[$key] ?? 0) + (int) ($chunkCounts[$key] ?? 0);
        }
        $message = 'Rekonsiliasi pajak: '.$processed.' / '.$total.' mapping; '
            .$cumulativeCounts['reconciled'].' sesuai, '
            .$cumulativeCounts['review'].' perlu ditinjau, '
            .$cumulativeCounts['not_applicable'].' tidak memerlukan pajak.';
        $extra = $cursor === null ? [] : ['tax_reconciliation_cursor' => $cursor];

        $progressService->record(
            (int) $import->id,
            (int) $import->business_id,
            'tax_reconciliation',
            $processed,
            $total,
            'mapping pajak',
            $cumulativeCounts,
            $message,
            $extra
        );
    }

    private function prepareImportChunk(
        object $import,
        array $options,
        JournalImportQueueService $queueService,
        JournalImportCancellationService $cancellationService
    ): void {
        $chunk = DB::transaction(function () use ($queueService, $import, $options) {
            $lockedBatch = DB::table('accounting_journal_imports')
                ->where('id', $import->id)
                ->where('business_id', $import->business_id)
                ->lockForUpdate()
                ->first();

            if (empty($lockedBatch)
                || $lockedBatch->status !== 'preparing_import'
                || ! empty($lockedBatch->cancel_requested_at)) {
                return null;
            }

            return $queueService->queueRowsForBatch(
                (int) $import->id,
                (int) $import->business_id,
                $options,
                $this->afterRowId,
                self::CHUNK_SIZE
            );
        });

        if ($chunk === null
            || $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id)) {
            return;
        }

        $progressService = app(\Modules\Accounting\Services\JournalImportProgressService::class);
        $progressData = $progressService->decode($import->progress_data ?? null);
        $prepareProgress = $progressData['phases']['preparing_import'] ?? [];
        $prepareTotal = max(0, (int) ($prepareProgress['total'] ?? $import->total_entries ?? 0));
        $prepareProcessed = (int) ($prepareProgress['processed'] ?? 0) + (int) ($chunk['processed_rows'] ?? 0);
        $prepareProcessed = min($prepareTotal, $prepareProcessed);
        $counts = $this->processingCounts((int) $import->id, (int) $import->business_id);
        $prepareMessage = 'Persiapan antrean: '.$prepareProcessed.' / '.$prepareTotal.' draft baris; '
            .$counts['queued'].' siap, '.$counts['pending'].' perlu ditinjau, '
            .$counts['completed'].' sudah selesai, '.$counts['failed'].' gagal.';

        if ($chunk['has_more']) {
            $progressService->record(
                (int) $import->id,
                (int) $import->business_id,
                'preparing_import',
                $prepareProcessed,
                $prepareTotal,
                'draft baris',
                $counts,
                $prepareMessage,
                ['prepare_cursor' => (int) $chunk['last_id']]
            );
            if ($this->dispatchContinuations) {
                self::dispatch($this->importId, 'prepare', 0, (int) $chunk['last_id'])
                    ->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));
            }

            return;
        }

        $shouldReconcile = $counts['queued'] === 0 && $counts['running'] === 0;
        $status = $counts['queued'] > 0 ? 'queued_import' : ($shouldReconcile ? 'reconciling' : 'partially_imported');
        $queueTotal = $counts['completed'] + $counts['failed'] + $counts['queued'] + $counts['running'];
        $queueProcessed = $counts['completed'] + $counts['failed'];
        $message = $counts['queued'] > 0
            ? ($this->runner === 'cli'
                ? $prepareMessage.' CLI melanjutkan '.$counts['queued'].' baris valid.'
                : $prepareMessage.' Menunggu worker untuk memproses '.$counts['queued'].' baris valid.')
            : $prepareMessage.' Baris yang belum siap tetap tersimpan untuk ditinjau.';

        $progressPhase = $counts['queued'] > 0 || ! $shouldReconcile ? 'importing' : 'tax_reconciliation';
        $progressProcessed = $progressPhase === 'importing' ? $queueProcessed : 0;
        $progressTotal = $progressPhase === 'importing' ? $queueTotal : $this->taxReconciliationTotal((int) $import->id, (int) $import->business_id);
        $progressService->record(
            (int) $import->id,
            (int) $import->business_id,
            'preparing_import',
            $prepareProcessed,
            $prepareTotal,
            'draft baris',
            $counts,
            $prepareMessage
        );
        if ($progressPhase === 'tax_reconciliation') {
            $message .= ' Rekonsiliasi pajak: 0 / '.$progressTotal.' mapping.';
        }

        $updated = $progressService->record(
            (int) $import->id,
            (int) $import->business_id,
            $progressPhase,
            $progressProcessed,
            $progressTotal,
            $progressPhase === 'importing' ? 'baris import' : 'mapping pajak',
            $counts,
            $message,
            [
                'status' => $status,
                'processed_entries' => $queueProcessed,
                'imported_entries' => $counts['completed'],
                'skipped_entries' => $counts['failed'],
                'error_message' => null,
            ]
        );

        if ($updated === []) {
            $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);
            return;
        }

        if ($counts['queued'] > 0 && $this->dispatchContinuations) {
            self::dispatch($this->importId, 'entries')->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));
        } elseif ($shouldReconcile && $this->dispatchContinuations) {
            self::dispatch($this->importId, 'tax')->delay(now()->addSeconds(self::CHUNK_DELAY_SECONDS));
        }
    }

    private function finishImport(
        object $import,
        JournalEntryController $controller,
        JournalImportCancellationService $cancellationService
    ): void
    {
        $counts = $this->processingCounts((int) $import->id, (int) $import->business_id);
        $queueTotal = $counts['completed'] + $counts['failed'] + $counts['queued'] + $counts['running'];
        $allComplete = $counts['pending'] === 0 && $queueTotal === $counts['completed'];
        $status = $allComplete ? 'imported' : ($counts['completed'] === 0 && $counts['failed'] > 0 && $counts['pending'] === 0 ? 'failed' : 'partially_imported');
        $lastMessage = 'Queue selesai: '.$counts['completed'].' baris berhasil, '.$counts['failed'].' gagal, '
            .$counts['pending'].' menunggu kelengkapan atau handler transaksi.';

        $updated = DB::table('accounting_journal_imports')
            ->where('id', $import->id)
            ->whereNull('cancel_requested_at')
            ->update([
            'status' => $status,
            'processed_entries' => $counts['completed'] + $counts['failed'],
            'imported_entries' => $counts['completed'],
            'skipped_entries' => $counts['failed'],
            'last_message' => $lastMessage,
            'updated_at' => now(),
        ]);

        if (! $updated) {
            $cancellationService->stopIfRequested((int) $import->id, (int) $import->business_id);

            return;
        }

        if ($allComplete) {
            $controller->deleteTemporaryJournalFile($import->file_path);
            $controller->deleteTemporaryJournalFile($import->analysis_path);
        }
    }
}
