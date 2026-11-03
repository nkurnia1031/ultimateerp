<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class JournalImportQueueService
{
    public function queueRowsForBatch(
        int $importId,
        int $businessId,
        ?array $optionsOverride = null,
        ?int $afterRowId = null,
        ?int $limit = null
    )
    {
        $import = DB::table('accounting_journal_imports')
            ->where('id', $importId)
            ->where('business_id', $businessId)
            ->first();
        $options = $optionsOverride ?? (! empty($import->import_options) ? json_decode($import->import_options, true) : []);
        $options = is_array($options) ? $options : [];
        $legacyAuthoritative = ! empty($options['legacy_authoritative']);
        $rowsQuery = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->whereIn('processing_status', ['pending', 'failed']);
        if ($afterRowId !== null) {
            $rowsQuery->where('id', '>', $afterRowId)->orderBy('id');
        } else {
            $rowsQuery->orderByRaw("CASE source_type WHEN 'Sales Invoice' THEN 0 WHEN 'Purchase Invoice' THEN 1 WHEN 'Expense' THEN 2 WHEN 'Receive Payment' THEN 3 WHEN 'Purchase Payment' THEN 4 WHEN 'Bank Deposit' THEN 5 WHEN 'Bank Transfer' THEN 6 WHEN 'Bank Withdrawal' THEN 7 WHEN 'Reversal' THEN 8 WHEN 'Stock Adjustment' THEN 9 WHEN 'Stock In/Out' THEN 10 WHEN 'Opening Balance' THEN 11 ELSE 12 END")
                ->orderBy('entry_index');
        }
        if ($limit !== null) {
            $rowsQuery->limit($limit + 1);
        }
        $rows = $rowsQuery->lockForUpdate()->get();
        $hasMore = $limit !== null && $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->take($limit);
        }
        $lastRowId = (int) ($rows->last()->id ?? $afterRowId ?? 0);

        $queued = 0;
        foreach ($rows as $row) {
            $entry = json_decode((string) $row->entry_data, true) ?: [];
            $sourceKey = (string) ($entry['source_key'] ?? $row->source_key ?? '');
            $sourceType = (string) ($entry['source_type'] ?? $row->source_type ?? '');
            $amountMismatch = ($entry['legacy_evidence_status'] ?? null) === 'amount_mismatch'
                || ! empty($entry['legacy_amount_mismatch']);
            if ($legacyAuthoritative && $amountMismatch && ! empty($entry['source_identity_conflict'])) {
                unset($entry['source_identity_conflict'], $entry['source_identity_conflict_message']);
                DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                    'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
                $row->entry_data = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($sourceKey !== '') {
                $existingMapping = DB::table('accounting_acc_trans_mappings')
                    ->where('business_id', $businessId)
                    ->where('type', 'journal_entry')
                    ->where('import_source_key', $sourceKey)
                    ->first();
                if (! empty($existingMapping)) {
                    $identityMatch = app(JournalImportIdentityService::class)->compareExistingMapping(
                        $existingMapping,
                        $entry,
                        $businessId
                    );

                    if ($identityMatch === 'conflict') {
                        DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                            'processing_status' => 'pending',
                            'processing_message' => 'Nomor dan tanggal Journal Entry sudah memiliki posting dengan isi berbeda; tinjau konflik sebelum memproses.',
                            'processing_error' => null,
                            'updated_at' => now(),
                        ]);
                        continue;
                    }

                    $sourceAttached = $legacyAuthoritative
                        && $this->attachMatchedSourceToExistingMapping($row, $entry, $existingMapping, $businessId);

                    DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                        'processing_status' => 'completed',
                        'processing_message' => $identityMatch === 'identical'
                            ? 'Posting Journal dengan isi yang sama sudah tersedia; tidak dibuat ulang.'
                            : 'Posting Journal dengan kunci ini sudah tersedia; tidak dibuat ulang.',
                        'processing_error' => null,
                        'journal_mapping_id' => $existingMapping->id,
                        'source_action' => $sourceAttached ? 'link' : $row->source_action,
                        'source_record_type' => $legacyAuthoritative
                            ? ($sourceAttached ? ($existingMapping->source_record_type ?: $entry['source_record_type']) : null)
                            : ($row->source_record_type ?? null),
                        'source_record_id' => $legacyAuthoritative
                            ? ($sourceAttached ? ($existingMapping->source_record_id ?: $entry['source_record_id']) : null)
                            : ($row->source_record_id ?? null),
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    continue;
                }
            }

            if ($legacyAuthoritative) {
                $row = $this->prepareLegacyAuthoritativeRow($row, $entry, $sourceType, $businessId);
                if ($row === null) {
                    continue;
                }
                $entry = json_decode((string) $row->entry_data, true) ?: $entry;
            } elseif ($sourceType === 'Journal Entry' && ! empty($entry['source_identity_conflict'])) {
                DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                    'processing_status' => 'pending',
                    'processing_message' => (string) ($entry['source_identity_conflict_message']
                        ?? 'Kunci sumber berulang dengan isi berbeda; tinjau konflik sebelum memproses.'),
                    'processing_error' => null,
                    'updated_at' => now(),
                ]);
                continue;
            }

            if (in_array((string) $row->source_type, ['Sales Invoice', 'Purchase Invoice', 'Expense', 'Receive Payment', 'Purchase Payment', 'Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal', 'Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)) {
                $hasDraftData = trim((string) ($row->draft_data ?? '')) !== '' && trim((string) $row->draft_data) !== '{}';
                $serviceClass = $row->source_type === 'Expense'
                    ? JournalImportExpenseService::class
                    : (in_array($row->source_type, ['Receive Payment', 'Purchase Payment'], true)
                        ? JournalImportPaymentService::class
                        : (in_array($row->source_type, ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'], true)
                            ? JournalImportBankTransactionService::class
                            : (in_array($row->source_type, ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true)
                                ? JournalImportStockService::class
                                : JournalImportInvoiceService::class)));
                $shouldCalculateTotal = $row->source_action !== 'journal'
                    && (in_array($row->source_action, ['link', 'update'], true) || $hasDraftData);
                $businessTotal = $shouldCalculateTotal
                    ? app($serviceClass)->draftTotal($row, $entry, $import, $options)
                    : null;
                $journalTotal = $this->journalTotal($entry);
                if ($businessTotal !== null && $journalTotal !== null) {
                    $this->recordReconciliation((int) $row->id, $businessId, $businessTotal, $journalTotal);
                } else {
                    $this->clearReconciliation((int) $row->id, $businessId);
                }
                $row = DB::table('accounting_journal_import_rows')->where('id', $row->id)->first();
            }

            $decision = $this->queueDecision(
                $entry,
                (string) $row->source_action,
                (string) ($row->processing_status ?? 'pending'),
                (string) ($row->reconciliation_status ?? 'not_checked'),
                $row,
                $options,
                $businessId
            );

            if (! $decision['eligible']) {
                DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                    'processing_message' => $decision['message'],
                    'updated_at' => now(),
                ]);
                continue;
            }

            DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                'processing_status' => 'queued',
                'processing_message' => 'Menunggu worker queue imports.',
                'processing_error' => null,
                'processing_started_at' => null,
                'processed_at' => null,
                'updated_at' => now(),
            ]);
            $queued++;
        }

        if ($limit !== null) {
            return [
                'queued' => $queued,
                'processed_rows' => $rows->count(),
                'last_id' => $lastRowId,
                'has_more' => $hasMore,
            ];
        }

        return $queued;
    }

    public function queueDecision(
        array $entry,
        string $action,
        string $processingStatus,
        string $reconciliationStatus = 'not_checked',
        ?object $row = null,
        array $options = [],
        ?int $businessId = null
    ): array
    {
        if (! in_array($processingStatus, ['pending', 'failed'], true)) {
            return ['eligible' => false, 'message' => 'Baris ini sudah masuk antrean atau sudah selesai diproses.'];
        }

        $message = $this->entryIssue($entry, $action, $reconciliationStatus, $row, $options, $businessId);

        return [
            'eligible' => $message === null,
            'message' => $message ?? 'Siap diproses.',
        ];
    }

    public function entryIssue(
        array $entry,
        string $action,
        string $reconciliationStatus = 'not_checked',
        ?object $row = null,
        array $options = [],
        ?int $businessId = null
    ): ?string
    {
        $legacyJournalOnly = ! empty($options['legacy_authoritative']) && $action === 'journal';
        if (! empty($entry['source_identity_conflict']) && ! $legacyJournalOnly) {
            return (string) ($entry['source_identity_conflict_message']
                ?? 'Kunci sumber berulang dengan isi berbeda; tinjau konflik sebelum memproses.');
        }

        $sourceType = (string) ($entry['source_type'] ?? '');
        $isInvoice = in_array($sourceType, ['Sales Invoice', 'Purchase Invoice'], true);
        $isExpense = $sourceType === 'Expense';
        $isPayment = in_array($sourceType, ['Receive Payment', 'Purchase Payment'], true);
        $isBankTransaction = in_array($sourceType, ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'], true);
        $isStockTransaction = in_array($sourceType, ['Stock Adjustment', 'Stock In/Out', 'Opening Balance'], true);
        $isSourceTransaction = $isInvoice || $isExpense || $isPayment || $isBankTransaction || $isStockTransaction;
        if ($sourceType !== 'Journal Entry' && ! $isSourceTransaction && ! $legacyJournalOnly) {
            return 'Jenis transaksi ini menunggu handler transaksi terkait.';
        }

        if ($sourceType === 'Journal Entry' && $action !== 'journal') {
            return 'Baris Journal Entry harus diproses sebagai posting GL.';
        }
        if ($isSourceTransaction && ! $legacyJournalOnly && ! in_array($action, ['create', 'update', 'link'], true)) {
            return $isPayment
                ? 'Pilih Buat baru, Lengkapi sumber, atau Tautkan pembayaran untuk memproses transaksi.'
                : 'Pilih Buat baru, Lengkapi sumber, atau Tautkan sumber untuk memproses transaksi.';
        }

        if ($isSourceTransaction && ! $legacyJournalOnly && $row !== null && $businessId !== null) {
            $draftService = app(JournalImportDraftService::class);
            $recordType = $row->source_record_type ?? null;
            $recordId = empty($row->source_record_id) ? null : (int) $row->source_record_id;
            if (! $draftService->isValidSourceDecision($entry, $action, $recordType, $recordId)) {
                return 'Pilihan sumber tidak valid; pilih kandidat yang tersedia atau Buat baru secara eksplisit.';
            }
            if ($recordType !== null && $recordId !== null
                && ! $draftService->isCurrentSourceRecordAllowed($sourceType, $recordType, $recordId, $businessId)) {
                return 'Sumber pilihan tidak lagi tersedia pada business aktif.';
            }
            $serviceClass = $isExpense
                ? JournalImportExpenseService::class
                : ($isPayment ? JournalImportPaymentService::class : JournalImportInvoiceService::class);
            if ($isBankTransaction) {
                $serviceClass = JournalImportBankTransactionService::class;
            }
            if ($isStockTransaction) {
                $serviceClass = JournalImportStockService::class;
            }
            $readinessIssue = app($serviceClass)->readinessIssue($row, $entry, $businessId, $options);
            if ($readinessIssue !== null) {
                $prefix = $isExpense ? 'Draft Expense belum siap: '
                    : ($isPayment ? 'Draft pembayaran belum siap: '
                    : ($isBankTransaction ? 'Draft transaksi bank belum siap: '
                        : ($isStockTransaction ? 'Draft stok belum siap: ' : 'Draft invoice belum siap: ')));

                return $prefix.$readinessIssue;
            }
        }

        if (! empty($entry['is_unbalanced'])) {
            return 'Debit dan kredit belum seimbang.';
        }

        if (! empty($entry['is_duplicate']) && ! ($legacyJournalOnly && ! empty($entry['source_key']))) {
            return 'Baris ditandai duplikat dalam workbook.';
        }

        if (! empty($entry['exists_in_system'])) {
            return 'Posting dengan kunci ini sudah ada di sistem.';
        }

        if (empty($entry['source_key']) || empty($entry['lines']) || ! is_array($entry['lines'])) {
            return 'Kunci atau baris akun GL belum lengkap.';
        }

        if ($isInvoice && $row !== null && empty($row->draft_data) && $action !== 'link') {
            return 'Lengkapi customer/supplier, lokasi, dan item invoice sebelum diproses.';
        }

        return null;
    }

    private function prepareLegacyAuthoritativeRow(object $row, array $entry, string $sourceType, int $businessId): ?object
    {
        $sourceKey = (string) ($entry['source_key'] ?? $row->source_key ?? '');
        $debit = (float) ($entry['debit_total'] ?? 0);
        $credit = (float) ($entry['credit_total'] ?? 0);
        $lines = $entry['lines'] ?? [];

        if ((! is_array($lines) || $lines === [])
            && abs($debit) <= 0.0001
            && abs($credit) <= 0.0001
            && $sourceKey !== '') {
            DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
                'source_action' => 'journal',
                'processing_status' => 'completed',
                'processing_message' => 'Grup Journal kosong dengan debit dan kredit nol; diarsipkan tanpa membuat posting GL.',
                'processing_error' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return null;
        }

        $action = (string) ($row->source_action ?? 'review');
        $sourceStatus = (string) ($entry['source_link_status'] ?? 'unmatched');
        $sourceRecordType = $row->source_record_type ?? ($entry['source_record_type'] ?? null);
        $sourceRecordId = empty($row->source_record_id)
            ? (empty($entry['source_record_id']) ? null : (int) $entry['source_record_id'])
            : (int) $row->source_record_id;
        $isAmountMismatch = ($entry['legacy_evidence_status'] ?? null) === 'amount_mismatch'
            || ! empty($entry['legacy_amount_mismatch']);
        if ($sourceKey !== '' && ! empty($entry['is_duplicate'])) {
            // The worker checks the persisted source key again before posting each row.
            // This lets the first valid copy post and archives subsequent copies idempotently.
            $entry['is_duplicate'] = false;
        }
        if ($isAmountMismatch) {
            unset($entry['source_identity_conflict'], $entry['source_identity_conflict_message']);
        }

        if ($sourceType === 'Journal Entry') {
            $action = 'journal';
        } elseif ($action === 'review') {
            $action = $sourceStatus === 'matched' && $sourceRecordType !== null && $sourceRecordId !== null
                ? 'link'
                : 'journal';
        }

        $fallbackReason = null;
        if ($action !== 'journal') {
            $candidateRow = clone $row;
            $candidateRow->source_record_type = $sourceRecordType;
            $candidateRow->source_record_id = $sourceRecordId;
            $issue = $this->entryIssue(
                $entry,
                $action,
                (string) ($row->reconciliation_status ?? 'not_checked'),
                $candidateRow,
                ['legacy_authoritative' => true],
                $businessId
            );
            if ($issue !== null) {
                $fallbackReason = $issue;
                $action = 'journal';
            }
        } elseif ($sourceType !== 'Journal Entry') {
            $fallbackReason = ! empty($entry['source_identity_conflict_message'])
                ? (string) $entry['source_identity_conflict_message']
                : (string) ($entry['legacy_evidence_reason'] ?? 'Detail transaksi operasional tidak cukup untuk dibuat atau ditautkan dengan aman.');
        }

        if ($action === 'journal' && ($sourceType !== 'Journal Entry' || ! empty($entry['source_identity_conflict']))) {
            unset($entry['source_record_type'], $entry['source_record_id'], $entry['source_link_group']);
            unset($entry['source_identity_conflict'], $entry['source_identity_conflict_message']);
            $entry['source_link_status'] = 'unmatched';
            $entry['source_link_note'] = $fallbackReason
                ? 'Posting GL mengikuti Journal legacy; sumber operasional tidak ditautkan: '.$fallbackReason
                : 'Posting GL mengikuti Journal legacy; detail operasional belum cukup untuk ditautkan.';
            $entry['legacy_operational_fallback_reason'] = $fallbackReason;
        }

        $entry['source_action'] = $action;
        DB::table('accounting_journal_import_rows')->where('id', $row->id)->update([
            'source_action' => $action,
            'source_record_type' => $action === 'journal' ? null : $sourceRecordType,
            'source_record_id' => $action === 'journal' ? null : $sourceRecordId,
            'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processing_message' => $action === 'journal' && $sourceType !== 'Journal Entry'
                ? 'Journal GL diprioritaskan; detail operasional akan dicatat pada audit legacy.'
                : ($row->processing_message ?? null),
            'updated_at' => now(),
        ]);

        return DB::table('accounting_journal_import_rows')->where('id', $row->id)->first();
    }

    private function attachMatchedSourceToExistingMapping(object $row, array $entry, object $mapping, int $businessId): bool
    {
        $amountMismatch = ($entry['legacy_evidence_status'] ?? null) === 'amount_mismatch'
            || ! empty($entry['legacy_amount_mismatch']);
        if (! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_type')
            || ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_link_status')) {
            return false;
        }

        $draftService = app(JournalImportDraftService::class);
        if (! empty($mapping->source_record_type) && ! empty($mapping->source_record_id)) {
            $isAllowed = $draftService->isCurrentSourceRecordAllowed(
                (string) $row->source_type,
                (string) $mapping->source_record_type,
                (int) $mapping->source_record_id,
                $businessId
            );
            if ($isAllowed && Schema::hasTable('accounting_journal_import_source_links')) {
                $existingEntry = $entry;
                unset($existingEntry['source_link_group']);
                app(JournalImportSourceLinkService::class)->sync(
                    (int) $row->id,
                    (int) $row->journal_import_id,
                    $businessId,
                    (string) $row->source_type,
                    $existingEntry,
                    (string) $mapping->source_record_type,
                    (int) $mapping->source_record_id
                );
            }

            return $isAllowed;
        }

        if (($entry['source_link_status'] ?? null) !== 'matched'
            || empty($entry['source_record_type'])
            || empty($entry['source_record_id'])
            || (! empty($entry['source_identity_conflict']) && ! $amountMismatch)) {
            return false;
        }

        $recordType = (string) $entry['source_record_type'];
        $recordId = (int) $entry['source_record_id'];
        if (! $draftService->isCurrentSourceRecordAllowed((string) $row->source_type, $recordType, $recordId, $businessId)) {
            return false;
        }

        $hasExistingSource = ! empty($mapping->source_record_type) || ! empty($mapping->source_record_id);
        if ($hasExistingSource
            && ((string) ($mapping->source_record_type ?? '') !== $recordType
                || (int) ($mapping->source_record_id ?? 0) !== $recordId)) {
            return false;
        }
        if (! $hasExistingSource) {
            DB::table('accounting_acc_trans_mappings')
                ->where('id', $mapping->id)
                ->where('business_id', $businessId)
                ->whereNull('source_record_id')
                ->update([
                    'source_record_type' => $recordType,
                    'source_record_id' => $recordId,
                    'source_link_status' => 'matched',
                    'source_link_note' => 'Relasi operasional unik dipulihkan dari sumber legacy saat deduplikasi Journal.',
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('accounting_journal_import_source_links')) {
            $linkedType = ! empty($mapping->source_record_type) ? (string) $mapping->source_record_type : $recordType;
            $linkedId = ! empty($mapping->source_record_id) ? (int) $mapping->source_record_id : $recordId;
            app(JournalImportSourceLinkService::class)->sync(
                (int) $row->id,
                (int) $row->journal_import_id,
                $businessId,
                (string) $row->source_type,
                $entry,
                $linkedType,
                $linkedId
            );
        }

        return true;
    }

    public function recordReconciliation(int $rowId, int $businessId, float $businessTotal, float $journalTotal, float $tolerance = 0.01): object
    {
        if (! is_finite($businessTotal) || ! is_finite($journalTotal) || ! is_finite($tolerance) || $tolerance < 0) {
            throw ValidationException::withMessages([
                'reconciliation' => 'Nilai rekonsiliasi tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($rowId, $businessId, $businessTotal, $journalTotal, $tolerance) {
            $row = DB::table('accounting_journal_import_rows')
                ->where('id', $rowId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($row)) {
                throw ValidationException::withMessages([
                    'row' => 'Baris draft tidak ditemukan pada business aktif.',
                ]);
            }

            $difference = round($businessTotal - $journalTotal, 4);
            $isMismatch = abs($difference) > $tolerance;
            $sameReconciliation = round((float) ($row->business_total ?? 0), 4) === round($businessTotal, 4)
                && round((float) ($row->journal_total ?? 0), 4) === round($journalTotal, 4);
            $preserveManualApproval = $isMismatch
                && $sameReconciliation
                && $row->reconciliation_status === 'approved'
                && ! empty($row->difference_approved_by)
                && ! empty($row->difference_approved_at)
                && trim((string) ($row->difference_approval_reason ?? '')) !== '';
            $approvalReason = $preserveManualApproval ? $row->difference_approval_reason : null;

            DB::table('accounting_journal_import_rows')
                ->where('id', $rowId)
                ->where('business_id', $businessId)
                ->update([
                    'journal_total' => $journalTotal,
                    'business_total' => $businessTotal,
                    'difference_amount' => $difference,
                    'reconciliation_status' => $isMismatch
                        ? ($preserveManualApproval ? 'approved' : 'auto_approved')
                        : 'matched',
                    'difference_approved_by' => $preserveManualApproval ? $row->difference_approved_by : null,
                    'difference_approved_at' => $preserveManualApproval ? $row->difference_approved_at : null,
                    'difference_approval_reason' => $approvalReason,
                    'updated_at' => now(),
                ]);

            return DB::table('accounting_journal_import_rows')
                ->where('id', $rowId)
                ->where('business_id', $businessId)
                ->first();
        });
    }

    public function approveDiscrepancy(int $rowId, int $businessId, int $userId, string $reason): object
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan persetujuan wajib diisi dan maksimal 1000 karakter.',
            ]);
        }

        return DB::transaction(function () use ($rowId, $businessId, $userId, $reason) {
            $row = DB::table('accounting_journal_import_rows')
                ->where('id', $rowId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($row)) {
                throw ValidationException::withMessages([
                    'row' => 'Baris draft tidak ditemukan pada business aktif.',
                ]);
            }

            if ($row->reconciliation_status !== 'mismatch') {
                throw ValidationException::withMessages([
                    'row' => 'Hanya selisih total di luar toleransi pembulatan yang dapat disetujui.',
                ]);
            }

            if (! in_array($row->processing_status, ['pending', 'failed'], true)) {
                throw ValidationException::withMessages([
                    'row' => 'Selisih hanya dapat disetujui sebelum baris masuk antrean atau setelah percobaan gagal.',
                ]);
            }

            DB::table('accounting_journal_import_rows')
                ->where('id', $rowId)
                ->where('business_id', $businessId)
                ->update([
                    'reconciliation_status' => 'approved',
                    'difference_approved_by' => $userId,
                    'difference_approved_at' => now(),
                    'difference_approval_reason' => $reason,
                    'updated_at' => now(),
                ]);

            return DB::table('accounting_journal_import_rows')
                ->where('id', $rowId)
                ->where('business_id', $businessId)
                ->first();
        });
    }

    public function approveDiscrepancies(int $importId, int $businessId, int $userId, array $rowIds, string $reason): array
    {
        $rowIds = array_values(array_unique(array_filter(array_map('intval', $rowIds))));
        $reason = trim($reason);
        if ($rowIds === [] || count($rowIds) > 100) {
            throw ValidationException::withMessages([
                'row_ids' => 'Pilih 1 sampai 100 baris selisih untuk persetujuan massal.',
            ]);
        }
        if ($reason === '' || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan persetujuan wajib diisi 5 sampai 1000 karakter.',
            ]);
        }

        return DB::transaction(function () use ($importId, $businessId, $userId, $rowIds, $reason) {
            $rows = DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $importId)
                ->where('business_id', $businessId)
                ->whereIn('id', $rowIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->count() !== count($rowIds)) {
                throw ValidationException::withMessages([
                    'row_ids' => 'Sebagian baris tidak ditemukan pada batch dan business aktif.',
                ]);
            }

            foreach ($rows as $row) {
                if (! in_array($row->reconciliation_status, ['mismatch', 'auto_approved', 'approved'], true)) {
                    throw ValidationException::withMessages([
                        'row_ids' => 'Semua baris terpilih harus memiliki selisih yang masih tercatat.',
                    ]);
                }
                if (! in_array($row->processing_status, ['pending', 'failed'], true)) {
                    throw ValidationException::withMessages([
                        'row_ids' => 'Baris yang sudah masuk antrean atau selesai tidak dapat disetujui ulang.',
                    ]);
                }
            }

            $reviewedAt = now();
            DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $importId)
                ->where('business_id', $businessId)
                ->whereIn('id', $rowIds)
                ->update([
                    'reconciliation_status' => 'approved',
                    'difference_approved_by' => $userId,
                    'difference_approved_at' => $reviewedAt,
                    'difference_approval_reason' => $reason,
                    'is_reviewed' => 1,
                    'reviewed_by' => $userId,
                    'reviewed_at' => $reviewedAt,
                    'updated_at' => $reviewedAt,
                ]);

            return DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $importId)
                ->where('business_id', $businessId)
                ->whereIn('id', $rowIds)
                ->orderBy('entry_index')
                ->get()
                ->all();
        });
    }

    public function reviewDiscrepancies(int $importId, int $businessId, int $userId, array $rowIds, ?string $reason): array
    {
        $rowIds = array_values(array_unique(array_filter(array_map('intval', $rowIds))));
        $reason = trim((string) $reason);
        if ($rowIds === [] || count($rowIds) > 100) {
            throw ValidationException::withMessages([
                'row_ids' => 'Pilih 1 sampai 100 selisih pada halaman aktif.',
            ]);
        }

        return DB::transaction(function () use ($importId, $businessId, $userId, $rowIds, $reason) {
            $rows = DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $importId)
                ->where('business_id', $businessId)
                ->whereIn('id', $rowIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->count() !== count($rowIds)) {
                throw ValidationException::withMessages([
                    'row_ids' => 'Sebagian baris tidak ditemukan pada batch dan business aktif.',
                ]);
            }

            $unexplainedCount = 0;
            foreach ($rows as $row) {
                if (! in_array($row->reconciliation_status, ['mismatch', 'auto_approved'], true)) {
                    throw ValidationException::withMessages([
                        'row_ids' => 'Semua baris terpilih harus memiliki selisih yang masih perlu ditinjau.',
                    ]);
                }
                if (! in_array($row->processing_status, ['pending', 'failed'], true)) {
                    throw ValidationException::withMessages([
                        'row_ids' => 'Baris yang sudah masuk antrean atau selesai tidak dapat ditinjau ulang.',
                    ]);
                }
                if ($row->reconciliation_status === 'auto_approved' && (bool) $row->is_reviewed) {
                    throw ValidationException::withMessages([
                        'row_ids' => 'Selisih yang sudah ditinjau tidak dapat dipilih kembali.',
                    ]);
                }
                if ($row->reconciliation_status === 'mismatch') {
                    $unexplainedCount++;
                }
            }

            if ($unexplainedCount > 0 && (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000)) {
                throw ValidationException::withMessages([
                    'reason' => 'Alasan wajib diisi 5 sampai 1000 karakter untuk selisih yang belum terjelaskan.',
                ]);
            }

            $reviewedAt = now();
            foreach ($rows as $row) {
                $changes = [
                    'is_reviewed' => 1,
                    'reviewed_by' => $userId,
                    'reviewed_at' => $reviewedAt,
                    'updated_at' => $reviewedAt,
                ];
                if ($row->reconciliation_status === 'mismatch') {
                    $changes += [
                        'reconciliation_status' => 'approved',
                        'difference_approved_by' => $userId,
                        'difference_approved_at' => $reviewedAt,
                        'difference_approval_reason' => $reason,
                    ];
                }
                DB::table('accounting_journal_import_rows')
                    ->where('id', $row->id)
                    ->where('journal_import_id', $importId)
                    ->where('business_id', $businessId)
                    ->update($changes);
            }

            $updatedRows = DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $importId)
                ->where('business_id', $businessId)
                ->whereIn('id', $rowIds)
                ->orderBy('entry_index')
                ->get()
                ->all();

            return [
                'rows' => $updatedRows,
                'reviewed_rows' => count($updatedRows),
                'approved_rows' => $unexplainedCount,
            ];
        });
    }

    public function clearReconciliation(int $rowId, int $businessId): void
    {
        DB::table('accounting_journal_import_rows')
            ->where('id', $rowId)
            ->where('business_id', $businessId)
            ->update([
                'journal_total' => null,
                'business_total' => null,
                'difference_amount' => null,
                'reconciliation_status' => 'not_checked',
                'difference_approved_by' => null,
                'difference_approved_at' => null,
                'difference_approval_reason' => null,
                'updated_at' => now(),
            ]);
    }

    private function journalTotal(array $entry): ?float
    {
        if (isset($entry['debit_total']) && is_numeric($entry['debit_total'])) {
            return (float) $entry['debit_total'];
        }

        if (empty($entry['lines']) || ! is_array($entry['lines'])) {
            return null;
        }

        $total = 0.0;
        foreach ($entry['lines'] as $line) {
            if (strtolower((string) ($line['type'] ?? '')) === 'debit') {
                $total += (float) ($line['debit'] ?? $line['amount'] ?? 0);
            }
        }

        return is_finite($total) ? $total : null;
    }
}
