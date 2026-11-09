<?php

namespace Modules\Accounting\Services;

use App\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyOperationalOnlyProcessor
{
    public function process(
        int $runId,
        int $businessId,
        int $userId,
        array $evidence,
        array $journalEvidenceIdentities,
        array $standaloneDrafts,
        LegacyImportRunService $runService,
        JournalImportInvoiceService $invoiceService,
        JournalImportExpenseService $expenseService,
        ?LegacySourceReconciliationFindingService $sourceFindings = null,
        ?callable $progressCallback = null
    ): array {
        $journalEvidenceIdentities = array_fill_keys($journalEvidenceIdentities, true);
        $counts = ['created' => 0, 'linked_existing' => 0, 'review' => 0, 'with_journal' => 0];

        $documents = (array) ($evidence['documents'] ?? []);
        $total = count($documents);
        $processed = 0;
        foreach ($documents as $identity => $document) {
            if (isset($journalEvidenceIdentities[$identity])) {
                $counts['with_journal']++;
                $processed++;
                if ($progressCallback && ($processed % 100 === 0 || $processed === $total)) {
                    $progressCallback($processed, $total, $counts);
                }
                continue;
            }
            if (! is_array($document)) {
                $processed++;
                continue;
            }

            $result = DB::transaction(function () use (
                $runId,
                $businessId,
                $userId,
                $identity,
                $document,
                $standaloneDrafts,
                $runService,
                $invoiceService,
                $expenseService,
                $sourceFindings
            ) {
                DB::table('accounting_legacy_import_runs')
                    ->where('id', $runId)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->first();

                $sourceIdentities = array_values(array_unique(array_filter(array_map(
                    fn (array $source) => $source['source_identity'] ?? null,
                    (array) ($document['provenance'] ?? [])
                ))));
                $existingCrosswalk = DB::table('accounting_legacy_import_crosswalks')
                    ->where('legacy_import_run_id', $runId)
                    ->where('business_id', $businessId)
                    ->whereIn('source_identity', $sourceIdentities)
                    ->where('target_record_type', Transaction::class)
                    ->whereNotNull('target_record_id')
                    ->first();
                if ($existingCrosswalk) {
                    $transaction = Transaction::where('business_id', $businessId)->find((int) $existingCrosswalk->target_record_id);
                    if ($transaction) {
                        if (in_array((string) $existingCrosswalk->status, ['review', 'conflict', 'failed'], true)) {
                            $this->recordFinding(
                                $sourceFindings,
                                $runId,
                                $businessId,
                                $document,
                                in_array((string) $existingCrosswalk->match_method, ['existing_operational', 'created_without_journal'], true)
                                    ? 'journal_source_missing'
                                    : (string) ($existingCrosswalk->match_method ?: 'legacy_crosswalk_review'),
                                (string) ($existingCrosswalk->reason ?: 'Sumber legacy masih berstatus review.'),
                                (int) $transaction->id
                            );
                        } else {
                            $this->resolveFinding($sourceFindings, $runId, $businessId, $document, (int) $transaction->id);
                        }

                        return 'linked_existing';
                    }
                }

                $existing = $this->findExistingTransaction($businessId, $document);
                if ($existing['count'] > 1) {
                    $reason = 'Lebih dari satu transaksi operasional memiliki nomor dan tanggal sumber yang sama.';
                    $runService->linkOperationalRecord($runId, $businessId, $document, null, null, 'review', 'ambiguous_existing', $reason);
                    $this->recordFinding($sourceFindings, $runId, $businessId, $document, 'ambiguous_existing', $reason);

                    return 'review';
                }
                if ($existing['count'] === 1) {
                    $transaction = $existing['transaction'];
                    if (! $this->amountsMatch($transaction, $document)) {
                        $reason = 'Transaksi dengan nomor dan tanggal yang sama sudah ada, tetapi nominalnya berbeda.';
                        $runService->linkOperationalRecord($runId, $businessId, $document, null, null, 'review', 'amount_conflict', $reason);
                        $this->recordFinding($sourceFindings, $runId, $businessId, $document, 'amount_conflict', $reason);

                        return 'review';
                    }
                    $runService->linkOperationalRecord(
                        $runId,
                        $businessId,
                        $document,
                        Transaction::class,
                        (int) $transaction->id,
                        'review',
                        'existing_operational',
                        'Transaksi operasional yang cocok sudah ada; sumber Journal tidak ditemukan sehingga GL belum diposting.'
                    );
                    $this->recordFinding(
                        $sourceFindings,
                        $runId,
                        $businessId,
                        $document,
                        'journal_source_missing',
                        'Transaksi operasional yang cocok sudah ada; sumber Journal tidak ditemukan sehingga GL belum diposting.',
                        (int) $transaction->id
                    );

                    return 'linked_existing';
                }

                $draft = $standaloneDrafts[$identity] ?? ['ready' => false, 'reason' => 'Sumber operasional belum dipetakan.'];
                if (empty($draft['ready'])) {
                    $reviewStatus = ($document['status'] ?? null) === 'conflict' ? 'conflict' : 'review';
                    $runService->linkOperationalRecord(
                        $runId,
                        $businessId,
                        $document,
                        null,
                        null,
                        $reviewStatus,
                        'operational_only',
                        (string) ($draft['reason'] ?? 'Detail sumber belum cukup untuk membuat transaksi operasional.')
                    );
                    $this->recordFinding(
                        $sourceFindings,
                        $runId,
                        $businessId,
                        $document,
                        (string) (($document['status'] ?? null) === 'conflict' ? 'source_evidence_conflict' : 'operational_source_incomplete'),
                        (string) ($draft['reason'] ?? 'Detail sumber belum cukup untuk membuat transaksi operasional.')
                    );

                    return 'review';
                }

                $sourceType = (string) ($document['source_type'] ?? '');
                $transaction = in_array($sourceType, ['Sales Invoice', 'Purchase Invoice'], true)
                    ? $invoiceService->createLegacyOperational($document, (array) $draft['draft_data'], $businessId, $userId)
                    : $expenseService->createLegacyOperational($document, (array) $draft['draft_data'], (array) $draft['expense_lines'], $businessId, $userId);
                $runService->linkOperationalRecord(
                    $runId,
                    $businessId,
                    $document,
                    Transaction::class,
                    (int) $transaction->id,
                    'review',
                    'created_without_journal',
                    'Transaksi operasional dibuat dari sumber lengkap. Sumber Journal tidak ditemukan, jadi GL belum diposting dan perlu ditinjau.'
                );
                $this->recordFinding(
                    $sourceFindings,
                    $runId,
                    $businessId,
                    $document,
                    'journal_source_missing',
                    'Transaksi operasional dibuat dari sumber lengkap. Sumber Journal tidak ditemukan, jadi GL belum diposting dan perlu ditinjau.',
                    (int) $transaction->id
                );

                return 'created';
            });

            $counts[$result]++;
            $processed++;
            if ($progressCallback && ($processed % 100 === 0 || $processed === $total)) {
                $progressCallback($processed, $total, $counts);
            }
        }

        $runService->refreshSourceCounts($runId, $businessId);

        return $counts;
    }

    private function recordFinding(
        ?LegacySourceReconciliationFindingService $service,
        int $runId,
        int $businessId,
        array $document,
        string $reasonCode,
        string $reason,
        ?int $targetId = null
    ): void {
        if ($service !== null) {
            $service->recordReview($runId, $businessId, $document, $reasonCode, $reason, $targetId);
        }
    }

    private function resolveFinding(?LegacySourceReconciliationFindingService $service, int $runId, int $businessId, array $document, ?int $targetId): void
    {
        if ($service !== null) {
            $service->resolveFromRecheck($runId, $businessId, $document, $targetId);
        }
    }

    private function findExistingTransaction(int $businessId, array $document): array
    {
        $sourceType = (string) ($document['source_type'] ?? '');
        $transactionType = [
            'Sales Invoice' => 'sell',
            'Purchase Invoice' => 'purchase',
            'Expense' => 'expense',
        ][$sourceType] ?? null;
        $number = trim((string) ($document['source_number'] ?? ''));
        $date = substr(trim((string) ($document['date'] ?? '')), 0, 10);
        if ($transactionType === null || $number === '' || $date === '') {
            return ['count' => 0, 'transaction' => null];
        }

        $query = Transaction::where('business_id', $businessId)
            ->where('type', $transactionType)
            ->whereDate('transaction_date', $date)
            ->when(Schema::hasColumn('transactions', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->where(function ($query) use ($number) {
                $query->where('ref_no', $number);
                if ($number !== '') {
                    $query->orWhere('invoice_no', $number);
                }
            });
        $matches = $query->limit(2)->get();

        return ['count' => $matches->count(), 'transaction' => $matches->count() === 1 ? $matches[0] : null];
    }

    private function amountsMatch(Transaction $transaction, array $document): bool
    {
        if (is_numeric($document['total'] ?? null)
            && abs((float) $transaction->final_total - (float) $document['total']) > 0.01) {
            return false;
        }
        if (is_numeric($document['base'] ?? null)
            && $transaction->total_before_tax !== null
            && abs((float) $transaction->total_before_tax - (float) $document['base']) > 0.01) {
            return false;
        }
        if (is_numeric($document['tax'] ?? null)
            && $transaction->tax_amount !== null
            && abs((float) $transaction->tax_amount - (float) $document['tax']) > 0.01) {
            return false;
        }

        return true;
    }
}
