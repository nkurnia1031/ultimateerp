<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class JournalImportDraftService
{
    private const SOURCE_TYPES = [
        'Journal Entry',
        'Sales Invoice',
        'Purchase Invoice',
        'Expense',
        'Receive Payment',
        'Purchase Payment',
        'Bank Deposit',
        'Bank Transfer',
        'Bank Withdrawal',
        'Stock Adjustment',
        'Stock In/Out',
        'Opening Balance',
        'Reversal',
    ];

    public function sourceTypes(): array
    {
        return self::SOURCE_TYPES;
    }

    public function defaultActionFor(array $entry): string
    {
        if (($entry['source_type'] ?? null) === 'Journal Entry') {
            return 'journal';
        }

        switch ($entry['source_link_status'] ?? null) {
            case 'matched':
                return 'link';
            case 'unmatched':
                return 'create';
            default:
                return 'review';
        }
    }

    public function isValidSourceDecision(array $entry, string $action, ?string $recordType, ?int $recordId): bool
    {
        $sourceType = $entry['source_type'] ?? null;
        $sourceStatus = $entry['source_link_status'] ?? null;

        if ($sourceType === 'Journal Entry') {
            return $action === 'journal' && $recordType === null && $recordId === null;
        }

        if ($action === 'review') {
            return $recordType === null && $recordId === null;
        }

        if ($action === 'create') {
            return in_array($sourceStatus, ['unmatched', 'ambiguous'], true)
                && $recordType === null
                && $recordId === null;
        }

        if (! in_array($action, ['update', 'link'], true) || $recordType === null || $recordId === null) {
            return false;
        }

        $candidates = $entry['source_link_candidate_details'] ?? [];
        if ($sourceStatus === 'matched' && ! empty($entry['source_record_type']) && ! empty($entry['source_record_id'])) {
            $candidates[] = [
                'record_type' => $entry['source_record_type'],
                'id' => $entry['source_record_id'],
            ];
        }

        foreach ($candidates as $candidate) {
            if (($candidate['record_type'] ?? null) === $recordType
                && (int) ($candidate['id'] ?? 0) === $recordId) {
                return true;
            }
        }

        return false;
    }

    public function isCurrentSourceRecordAllowed(string $sourceType, string $recordType, int $recordId, int $businessId): bool
    {
        $transactionTypes = [
            'Sales Invoice' => ['sell'],
            'Purchase Invoice' => ['purchase'],
            'Expense' => ['expense'],
            'Stock Adjustment' => ['stock_adjustment'],
            'Stock In/Out' => ['opening_stock', 'sell_transfer', 'purchase_transfer', 'stock_adjustment'],
            'Opening Balance' => ['opening_balance', 'opening_stock'],
        ];

        if ($recordType === \App\Transaction::class && isset($transactionTypes[$sourceType])) {
            return DB::table('transactions')
                ->where('id', $recordId)
                ->where('business_id', $businessId)
                ->whereIn('type', $transactionTypes[$sourceType])
                ->exists();
        }

        if ($recordType === \App\TransactionPayment::class && in_array($sourceType, ['Receive Payment', 'Purchase Payment'], true)) {
            $transactionType = $sourceType === 'Receive Payment' ? 'sell' : 'purchase';

            return DB::table('transaction_payments')
                ->join('transactions', 'transactions.id', '=', 'transaction_payments.transaction_id')
                ->where('transaction_payments.id', $recordId)
                ->where('transactions.business_id', $businessId)
                ->where('transactions.type', $transactionType)
                ->exists();
        }

        if ($recordType === \App\AccountTransaction::class && in_array($sourceType, ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'], true)) {
            $subType = [
                'Bank Deposit' => 'deposit',
                'Bank Transfer' => 'fund_transfer',
                'Bank Withdrawal' => 'withdrawal',
                'Reversal' => 'reversal',
            ][$sourceType];
            $query = DB::table('account_transactions')
                ->join('accounts', 'accounts.id', '=', 'account_transactions.account_id')
                ->where('account_transactions.id', $recordId)
                ->where('accounts.business_id', $businessId)
                ->where('account_transactions.sub_type', $subType)
                ->whereNull('account_transactions.deleted_at')
                ->whereNull('accounts.deleted_at');
            if (in_array($sourceType, ['Bank Transfer', 'Bank Withdrawal'], true)) {
                $query->where('account_transactions.type', 'debit');
            } elseif ($sourceType === 'Bank Deposit') {
                $query->where('account_transactions.type', 'credit');
            }

            return $query->exists();
        }

        return false;
    }

    public function rowResponse($row): array
    {
        $entry = json_decode((string) $row->entry_data, true) ?: [];

        return [
            'id' => (int) $row->id,
            'entry_index' => (int) $row->entry_index,
            'source_type' => $row->source_type,
            'source_number' => $row->source_number,
            'date' => $entry['date'] ?? null,
            'description' => $entry['description'] ?? '',
            'debit_total' => (float) ($entry['debit_total'] ?? 0),
            'credit_total' => (float) ($entry['credit_total'] ?? 0),
            'line_count' => (int) ($entry['line_count'] ?? count($entry['lines'] ?? [])),
            'source_action' => $row->source_action,
            'source_record_type' => $row->source_record_type,
            'source_record_id' => $row->source_record_id === null ? null : (int) $row->source_record_id,
            'source_link_status' => $entry['source_link_status'] ?? 'unmatched',
            'source_link_note' => $entry['source_link_note'] ?? '',
            'source_link_label' => $entry['source_link_label'] ?? null,
            'source_link_candidates' => $entry['source_link_candidates'] ?? [],
            'source_link_candidate_details' => $entry['source_link_candidate_details'] ?? [],
            'is_reviewed' => (bool) $row->is_reviewed,
            'draft_data' => json_decode((string) $row->draft_data, true) ?: [],
            'processing_status' => $row->processing_status ?? 'pending',
            'processing_attempts' => (int) ($row->processing_attempts ?? 0),
            'processing_message' => $row->processing_message ?? null,
            'processing_error' => $row->processing_error ?? null,
            'processing_started_at' => $row->processing_started_at ?? null,
            'processed_at' => $row->processed_at ?? null,
            'journal_mapping_id' => isset($row->journal_mapping_id) ? (int) $row->journal_mapping_id : null,
            'reconciliation_status' => $row->reconciliation_status ?? 'not_checked',
            'journal_total' => ($row->journal_total ?? null) === null ? null : (float) $row->journal_total,
            'business_total' => ($row->business_total ?? null) === null ? null : (float) $row->business_total,
            'difference_amount' => ($row->difference_amount ?? null) === null ? null : (float) $row->difference_amount,
            'difference_approved_by' => isset($row->difference_approved_by) ? (int) $row->difference_approved_by : null,
            'difference_approved_at' => $row->difference_approved_at ?? null,
            'difference_approval_reason' => $row->difference_approval_reason ?? null,
        ];
    }

    public function validateDraftData(array $draftData, int $businessId, string $sourceType, ?int $importId = null): array
    {
        $allowedFields = ['contact_id', 'location_id', 'payment_account_id', 'transfer_account_id', 'reversal_target_id', 'movement_direction', 'payment_method', 'invoice_reference', 'expense_category_id', 'details'];
        if (array_diff(array_keys($draftData), $allowedFields) !== []) {
            throw ValidationException::withMessages([
                'draft_data' => 'Draft berisi field yang tidak didukung.',
            ]);
        }

        foreach (($draftData['details'] ?? []) as $index => $detail) {
            if (! is_array($detail) || array_diff(array_keys($detail), ['variation_id', 'unit_id', 'quantity', 'unit_price', 'account_id', 'description']) !== []) {
                throw ValidationException::withMessages([
                    'draft_data.details.'.$index => 'Detail harus berisi field yang didukung.',
                ]);
            }
        }

        $validated = Validator::make($draftData, [
            'contact_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'payment_account_id' => 'nullable|integer|min:1',
            'transfer_account_id' => 'nullable|integer|min:1',
            'reversal_target_id' => 'nullable|integer|min:1',
            'movement_direction' => 'nullable|string|in:in,out',
            'payment_method' => 'nullable|string|in:cash,card,cheque,bank_transfer,other,custom_pay_1,custom_pay_2,custom_pay_3,custom_pay_4,custom_pay_5,custom_pay_6,custom_pay_7',
            'invoice_reference' => ['nullable', 'string', 'max:64', 'regex:/^(transaction|draft):[1-9][0-9]*$/'],
            'expense_category_id' => 'nullable|integer|min:1',
            'details' => 'sometimes|array|max:100',
            'details.*.variation_id' => 'nullable|integer|min:1',
            'details.*.unit_id' => 'nullable|integer|min:1',
            'details.*.quantity' => 'nullable|numeric|min:0.0001',
            'details.*.unit_price' => 'nullable|numeric|min:0',
            'details.*.account_id' => 'nullable|integer|min:1',
            'details.*.description' => 'nullable|string|max:255',
        ])->validate();

        $paymentTypes = ['Receive Payment', 'Purchase Payment'];
        if (! in_array($sourceType, $paymentTypes, true)
            && (! empty($validated['payment_method']) || ! empty($validated['invoice_reference']))) {
            throw ValidationException::withMessages([
                'draft_data' => 'Data pembayaran hanya didukung untuk Receive Payment dan Purchase Payment.',
            ]);
        }

        $bankSourceTypes = ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'];
        if (! in_array($sourceType, $bankSourceTypes, true)
            && (! empty($validated['transfer_account_id']) || ! empty($validated['reversal_target_id']))) {
            throw ValidationException::withMessages([
                'draft_data' => 'Detail akun transfer dan target reversal hanya didukung untuk transaksi bank.',
            ]);
        }
        if (! in_array($sourceType, ['Bank Deposit', 'Bank Transfer'], true) && ! empty($validated['transfer_account_id'])) {
            throw ValidationException::withMessages([
                'draft_data.transfer_account_id' => 'Akun pasangan hanya didukung untuk deposit dan transfer bank.',
            ]);
        }
        if ($sourceType !== 'Reversal' && ! empty($validated['reversal_target_id'])) {
            throw ValidationException::withMessages([
                'draft_data.reversal_target_id' => 'Target transaksi hanya didukung untuk Reversal.',
            ]);
        }

        if ($sourceType !== 'Stock In/Out' && ! empty($validated['movement_direction'])) {
            throw ValidationException::withMessages([
                'draft_data.movement_direction' => 'Arah stok hanya didukung untuk Stock In/Out.',
            ]);
        }

        if (in_array($sourceType, $paymentTypes, true) && ! empty($validated['invoice_reference'])) {
            [$referenceType, $referenceId] = explode(':', $validated['invoice_reference'], 2);
            $invoiceType = $sourceType === 'Receive Payment' ? 'sell' : 'purchase';
            if ($referenceType === 'transaction') {
                $validInvoice = DB::table('transactions')
                    ->where('id', (int) $referenceId)
                    ->where('business_id', $businessId)
                    ->where('type', $invoiceType)
                    ->exists();
            } elseif ($referenceType === 'draft' && $importId !== null) {
                $draftSourceType = $sourceType === 'Receive Payment' ? 'Sales Invoice' : 'Purchase Invoice';
                $validInvoice = DB::table('accounting_journal_import_rows')
                    ->where('id', (int) $referenceId)
                    ->where('journal_import_id', $importId)
                    ->where('business_id', $businessId)
                    ->where('source_type', $draftSourceType)
                    ->whereIn('source_action', ['create', 'update', 'link'])
                    ->exists();
            } else {
                $validInvoice = false;
            }

            if (! $validInvoice) {
                throw ValidationException::withMessages([
                    'draft_data.invoice_reference' => 'Invoice harus berasal dari business aktif atau baris invoice pada batch yang sama.',
                ]);
            }
        }

        if (! empty($validated['contact_id'])) {
            $contactQuery = DB::table('contacts')
                ->where('id', $validated['contact_id'])
                ->where('business_id', $businessId)
                ->whereNull('deleted_at');
            if (in_array($sourceType, ['Sales Invoice', 'Receive Payment'], true)) {
                $contactQuery->whereIn('type', ['customer', 'both']);
            } elseif (in_array($sourceType, ['Purchase Invoice', 'Purchase Payment'], true)) {
                $contactQuery->whereIn('type', ['supplier', 'both']);
            }
            if (! $contactQuery->exists()) {
                throw ValidationException::withMessages(['draft_data.contact_id' => 'Kontak tidak ditemukan pada business aktif atau tipenya tidak sesuai.']);
            }
        }

        if (! empty($validated['location_id']) && ! DB::table('business_locations')
            ->where('id', $validated['location_id'])
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->exists()) {
            throw ValidationException::withMessages(['draft_data.location_id' => 'Lokasi tidak ditemukan pada business aktif.']);
        }

        foreach (['payment_account_id', 'transfer_account_id', 'expense_category_id'] as $field) {
            if (empty($validated[$field])) {
                continue;
            }
            $query = in_array($field, ['payment_account_id', 'transfer_account_id'], true)
                ? DB::table('accounts')->where('is_closed', 0)->whereNull('deleted_at')
                : DB::table('expense_categories')->whereNull('deleted_at');
            if ($field === 'expense_category_id' && $sourceType === 'Expense') {
                $query->whereNull('parent_id');
            }
            if (! $query->where('id', $validated[$field])->where('business_id', $businessId)->exists()) {
                throw ValidationException::withMessages(['draft_data.'.$field => 'Pilihan tidak ditemukan pada business aktif.']);
            }
        }

        if (! empty($validated['reversal_target_id'])) {
            $targetExists = DB::table('account_transactions as target')
                ->join('accounts as target_account', 'target_account.id', '=', 'target.account_id')
                ->where('target.id', $validated['reversal_target_id'])
                ->where('target_account.business_id', $businessId)
                ->whereNull('target_account.deleted_at')
                ->whereNull('target.deleted_at')
                ->whereIn('target.sub_type', ['deposit', 'fund_transfer', 'withdrawal'])
                ->exists();
            if (! $targetExists) {
                throw ValidationException::withMessages(['draft_data.reversal_target_id' => 'Target reversal tidak ditemukan pada business aktif.']);
            }
        }

        foreach (($validated['details'] ?? []) as $index => $detail) {
            if (! empty($detail['variation_id']) && ! DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('v.id', $detail['variation_id'])
                ->where('p.business_id', $businessId)
                ->where('p.is_inactive', 0)
                ->whereNull('v.deleted_at')
                ->exists()) {
                throw ValidationException::withMessages(['draft_data.details.'.$index.'.variation_id' => 'Produk atau variasi tidak ditemukan pada business aktif.']);
            }
            if (! empty($detail['account_id']) && ! DB::table('accounts')
                ->where('id', $detail['account_id'])
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->exists()) {
                throw ValidationException::withMessages(['draft_data.details.'.$index.'.account_id' => 'Akun tidak ditemukan pada business aktif.']);
            }
            if (! empty($detail['variation_id']) && ! empty($detail['unit_id'])) {
                $variation = DB::table('variations as v')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->where('v.id', $detail['variation_id'])
                    ->where('p.business_id', $businessId)
                    ->where('p.is_inactive', 0)
                    ->whereNull('v.deleted_at')
                    ->first(['p.unit_id', 'p.sub_unit_ids']);
                $unit = DB::table('units')
                    ->where('id', $detail['unit_id'])
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')
                    ->first(['id', 'base_unit_id', 'base_unit_multiplier']);
                $subUnitIds = $variation ? (json_decode((string) $variation->sub_unit_ids, true) ?: []) : [];
                $isBaseUnit = $variation && (int) $variation->unit_id === (int) ($unit->id ?? 0);
                $isAllowedSubUnit = $variation && $unit
                    && in_array((int) $unit->id, array_map('intval', $subUnitIds), true)
                    && (int) $unit->base_unit_id === (int) $variation->unit_id
                    && is_numeric($unit->base_unit_multiplier)
                    && (float) $unit->base_unit_multiplier > 0;
                if (! $unit || (! $isBaseUnit && ! $isAllowedSubUnit)) {
                    throw ValidationException::withMessages(['draft_data.details.'.$index.'.unit_id' => 'Satuan harus satuan dasar produk atau sub-satuan yang terhubung.']);
                }
            }
        }

        return $validated;
    }

    public function persistEntries(int $importId, int $businessId, array $entries): void
    {
        $now = now();

        foreach (array_chunk(array_values($entries), 200, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $entryIndex => $entry) {
                $rows[] = [
                    'journal_import_id' => $importId,
                    'business_id' => $businessId,
                    'entry_index' => $entryIndex,
                    'source_type' => (string) ($entry['source_type'] ?? 'Unknown'),
                    'source_number' => $entry['source_number'] ?? null,
                    'source_key' => $entry['source_key'] ?? null,
                    'source_action' => $this->defaultActionFor($entry),
                    'source_record_type' => $entry['source_record_type'] ?? null,
                    'source_record_id' => $entry['source_record_id'] ?? null,
                    'draft_data' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_reviewed' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('accounting_journal_import_rows')->insertOrIgnore($rows);
            }
        }
    }
}
