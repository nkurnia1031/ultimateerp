<?php

namespace Modules\Accounting\Services;

use App\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneralLedgerReportService
{
    private const DEFAULT_PAGE_SIZE = 100;

    private $presenter;

    private $accountComponentService;

    private $cashBankMappingService;

    public function __construct(
        GeneralLedgerReportPresenter $presenter,
        JournalImportAccountComponentService $accountComponentService,
        GeneralLedgerCashBankMappingService $cashBankMappingService
    ) {
        $this->presenter = $presenter;
        $this->accountComponentService = $accountComponentService;
        $this->cashBankMappingService = $cashBankMappingService;
    }

    public function paginate(int $businessId, string $startDate, string $endDate, ?int $accountId, int $page = 1): LengthAwarePaginator
    {
        $accountIds = $this->cashBankMappingService->accountIds($businessId, $accountId);
        $query = $this->entryQuery($businessId, $startDate, $endDate, $accountIds);
        $total = (clone $query)->count('aat.id');
        $page = max(1, $page);
        $offset = ($page - 1) * self::DEFAULT_PAGE_SIZE;
        $entries = (clone $query)
            ->orderBy('aat.operation_date')
            ->orderBy('aat.id')
            ->offset($offset)
            ->limit(self::DEFAULT_PAGE_SIZE)
            ->get();

        $rows = $this->presentPage($businessId, $entries, $startDate, $accountIds, $page);

        return new LengthAwarePaginator(
            $rows,
            $total,
            self::DEFAULT_PAGE_SIZE,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function all(int $businessId, string $startDate, string $endDate, ?int $accountId = null): array
    {
        $accountIds = $this->cashBankMappingService->accountIds($businessId, $accountId);
        $entries = $this->entryQuery($businessId, $startDate, $endDate, $accountIds)
            ->orderBy('aat.operation_date')
            ->orderBy('aat.id')
            ->get();

        return $this->presentAll($businessId, $entries, $startDate, $accountIds);
    }

    public function mappedCashBankAccounts(int $businessId)
    {
        return $this->cashBankMappingService->mappedAccounts($businessId);
    }

    public function accountMappingReview(int $businessId, string $startDate, string $endDate)
    {
        return $this->cashBankMappingService->reviewAccounts($businessId, $startDate, $endDate);
    }

    public function saveCashBankAccountMappings(int $businessId, int $userId, array $mappings): int
    {
        return $this->cashBankMappingService->save($businessId, $userId, $mappings);
    }

    private function entryQuery(int $businessId, string $startDate, string $endDate, array $accountIds)
    {
        $query = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->leftJoin('accounting_acc_trans_mappings as atm', function ($join) {
                $join->on('atm.id', '=', 'aat.acc_trans_mapping_id')
                    ->on('atm.business_id', '=', 'aa.business_id');
            })
            ->where('aa.business_id', $businessId)
            ->where('aat.operation_date', '>=', $startDate.' 00:00:00')
            ->where('aat.operation_date', '<=', \Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s'))
            ->select([
                'aat.id',
                'aat.accounting_account_id',
                'aat.operation_date',
                'aat.type',
                'aat.amount',
                'aat.sub_type',
                'aat.note as journal_note',
                'aat.transaction_id',
                'aat.transaction_payment_id',
                'aa.name as account_name',
                'aa.gl_code',
                'aa.account_primary_type',
                'atm.type as mapping_type',
                'atm.ref_no as mapping_ref',
                'atm.note as mapping_note',
                'atm.source_record_type',
                'atm.source_record_id',
            ]);
        $this->applyActivePostingScope($query);

        if ($accountIds === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->whereIn('aa.id', $accountIds);
        $neutralTransferIds = $this->internalTransferMappingIds($businessId, $startDate, $endDate, $accountIds);
        if ($neutralTransferIds !== []) {
            $query->where(function ($query) use ($neutralTransferIds) {
                $query->whereNull('aat.acc_trans_mapping_id')
                    ->orWhereNotIn('aat.acc_trans_mapping_id', $neutralTransferIds);
            });
        }

        return $query;
    }

    private function internalTransferMappingIds(int $businessId, string $startDate, string $endDate, array $accountIds): array
    {
        if ($accountIds === [] || ! Schema::hasTable('accounting_acc_trans_mappings')) {
            return [];
        }

        $start = $startDate.' 00:00:00';
        $end = \Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s');
        $candidateQuery = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->join('accounting_acc_trans_mappings as atm', function ($join) {
                $join->on('atm.id', '=', 'aat.acc_trans_mapping_id')
                    ->on('atm.business_id', '=', 'aa.business_id');
            })
            ->where('aa.business_id', $businessId)
            ->whereIn('aa.id', $accountIds)
            ->whereBetween('aat.operation_date', [$start, $end])
            ->where(function ($query) {
                $query->where('atm.type', 'transfer')
                    ->orWhere('aat.sub_type', 'transfer');
            })
            ->whereNotNull('aat.acc_trans_mapping_id')
            ->distinct();
        $this->applyActivePostingScope($candidateQuery);
        $candidateIds = $candidateQuery->pluck('aat.acc_trans_mapping_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($candidateIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($accountIds), '?'));
        $outsideScope = "SUM(CASE WHEN aat.accounting_account_id NOT IN ({$placeholders}) THEN 1 ELSE 0 END)";

        $transferGroupQuery = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->where('aa.business_id', $businessId)
            ->whereIn('aat.acc_trans_mapping_id', $candidateIds)
            ->select('aat.acc_trans_mapping_id')
            ->selectRaw('COUNT(DISTINCT aat.accounting_account_id) as account_count')
            ->selectRaw($outsideScope.' as outside_scope_count', $accountIds)
            ->selectRaw("SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE 0 END) as debit_total")
            ->selectRaw("SUM(CASE WHEN aat.type = 'credit' THEN aat.amount ELSE 0 END) as credit_total")
            ->groupBy('aat.acc_trans_mapping_id')
            ->havingRaw('COUNT(DISTINCT aat.accounting_account_id) > 1')
            ->havingRaw($outsideScope.' = 0', $accountIds)
            ->havingRaw('MIN(aat.operation_date) >= ?', [$start])
            ->havingRaw('MAX(aat.operation_date) <= ?', [$end])
            ->havingRaw("SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE 0 END) = SUM(CASE WHEN aat.type = 'credit' THEN aat.amount ELSE 0 END)");
        $this->applyActivePostingScope($transferGroupQuery);

        return $transferGroupQuery->pluck('aat.acc_trans_mapping_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function presentPage(int $businessId, $entries, string $startDate, array $accountIds, int $page): array
    {
        if ($accountIds === []) {
            return [];
        }

        $openingBalance = $this->balanceBefore($businessId, $accountIds, $startDate);
        if ($entries->isEmpty()) {
            return $page === 1 ? [$this->combinedOpeningBalanceRow($startDate, $openingBalance)] : [];
        }

        $first = $entries->first();
        $priorBalance = $page > 1
            ? $this->balanceBeforePosting($businessId, $accountIds, $startDate, $first->operation_date, (int) $first->id)
            : 0.0;
        $sourceContext = $this->loadTransactions($businessId, $entries);
        $transactions = $sourceContext['transactions'];
        $plates = $this->loadPlates($businessId, $transactions->keys()->map(fn ($id) => (int) $id)->all());
        $presented = $this->presenter->presentAccountEntries(
            'asset',
            $this->presenterEntries($businessId, $entries, $transactions, $plates, $sourceContext['payment_transaction_ids']),
            $openingBalance + $priorBalance,
            $this->currencyPrecision()
        );

        if ($page === 1) {
            array_unshift($presented, $this->combinedOpeningBalanceRow($startDate, $openingBalance));
        }

        return $presented;
    }

    private function presentAll(int $businessId, $entries, string $startDate, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $openingBalance = $this->balanceBefore($businessId, $accountIds, $startDate);
        $rows = [$this->combinedOpeningBalanceRow($startDate, $openingBalance)];
        if ($entries->isEmpty()) {
            return $rows;
        }

        $sourceContext = $this->loadTransactions($businessId, $entries);
        $transactions = $sourceContext['transactions'];
        $plates = $this->loadPlates($businessId, $transactions->keys()->map(fn ($id) => (int) $id)->all());
        $rows = array_merge($rows, $this->presenter->presentAccountEntries(
            'asset',
            $this->presenterEntries($businessId, $entries, $transactions, $plates, $sourceContext['payment_transaction_ids']),
            $openingBalance,
            $this->currencyPrecision()
        ));

        return $rows;
    }

    private function balanceBefore(int $businessId, array $accountIds, string $startDate): float
    {
        if ($accountIds === []) {
            return 0.0;
        }

        $query = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->where('aa.business_id', $businessId)
            ->whereIn('aa.id', $accountIds)
            ->where('aat.operation_date', '<', $startDate.' 00:00:00')
            ->selectRaw("COALESCE(SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE -1 * aat.amount END), 0) as balance");
        $this->applyActivePostingScope($query);
        $balance = $query->value('balance');

        return (float) $balance;
    }

    private function balanceBeforePosting(int $businessId, array $accountIds, string $startDate, string $operationDate, int $entryId): float
    {
        $query = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->where('aa.business_id', $businessId)
            ->whereIn('aa.id', $accountIds)
            ->where('aat.operation_date', '>=', $startDate.' 00:00:00')
            ->where(function ($query) use ($operationDate, $entryId) {
                $query->where('aat.operation_date', '<', $operationDate)
                    ->orWhere(function ($query) use ($operationDate, $entryId) {
                        $query->where('aat.operation_date', '=', $operationDate)
                            ->where('aat.id', '<', $entryId);
                    });
            })
            ->selectRaw("COALESCE(SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE -1 * aat.amount END), 0) as balance");
        $this->applyActivePostingScope($query);
        $balance = $query->value('balance');

        return (float) $balance;
    }

    private function applyActivePostingScope($query): void
    {
        if (! Schema::hasTable('accounting_journal_imports')
            || ! Schema::hasColumn('accounting_accounts_transactions', 'journal_import_id')) {
            return;
        }

        $query->whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('accounting_journal_imports as reverted_import')
                ->whereColumn('reverted_import.id', 'aat.journal_import_id')
                ->where('reverted_import.status', 'reverted');
        });
    }

    private function presenterEntries(int $businessId, $accountEntries, $transactions, array $plates, array $paymentTransactionIds): array
    {
        $result = [];

        foreach ($accountEntries as $entry) {
            $transactionId = $this->resolveTransactionId($entry, $paymentTransactionIds);
            $transaction = $transactionId ? $transactions->get($transactionId) : null;
            $productLines = $this->productLines($transaction);
            $label = $this->transactionLabel($entry, $transaction);
            foreach ($productLines as &$productLine) {
                $lineLabel = $this->transactionLabel($entry, $transaction, $productLine);
                $productLine['description'] = $lineLabel['label'];
                $productLine['detail_label'] = $lineLabel['detail'];
                $productLine['needs_classification_review'] = $lineLabel['needs_classification_review'];
            }
            unset($productLine);
            $classification = $this->accountComponentService->groupJournalLines([
                'lines' => [[
                    'account_no' => (string) ($entry->gl_code ?? ''),
                    'account_name' => (string) ($entry->account_name ?? ''),
                    'type' => (string) ($entry->type ?? ''),
                    'amount' => (float) ($entry->amount ?? 0),
                ]],
            ], $businessId);
            $contact = $transaction && $transaction->contact
                ? (trim((string) $transaction->contact->supplier_business_name) ?: trim((string) $transaction->contact->name))
                : '';

            $result[] = [
                'id' => (int) $entry->id,
                'accounting_account_id' => (int) $entry->accounting_account_id,
                'account_id' => (int) $entry->accounting_account_id,
                'account_name' => (string) ($entry->account_name ?? ''),
                'gl_code' => (string) ($entry->gl_code ?? ''),
                'operation_date' => $entry->operation_date,
                'type' => (string) $entry->type,
                'amount' => (float) $entry->amount,
                'is_tax_account' => ! empty($classification[0]['is_tax']),
                'products' => $productLines,
                'description' => $label['label'],
                'detail_label' => $label['detail'],
                'needs_classification_review' => $label['needs_classification_review'],
                'reference' => $this->reference($entry, $transaction),
                'note' => $this->note($entry, $transaction),
                'contact' => $contact,
                'plate' => $transactionId ? ($plates[$transactionId] ?? '') : '',
            ];
        }

        return $result;
    }

    private function loadTransactions(int $businessId, $entries): array
    {
        $transactionIds = [];
        $paymentIds = [];

        foreach ($entries as $entry) {
            if (! empty($entry->transaction_id)) {
                $transactionIds[] = (int) $entry->transaction_id;
            } elseif (($entry->source_record_type ?? null) === Transaction::class && ! empty($entry->source_record_id)) {
                $transactionIds[] = (int) $entry->source_record_id;
            }

            if (! empty($entry->transaction_payment_id)) {
                $paymentIds[] = (int) $entry->transaction_payment_id;
            } elseif (($entry->source_record_type ?? null) === \App\TransactionPayment::class && ! empty($entry->source_record_id)) {
                $paymentIds[] = (int) $entry->source_record_id;
            }
        }

        $paymentIds = array_values(array_unique($paymentIds));
        $paymentTransactionIds = [];
        if ($paymentIds !== [] && Schema::hasTable('transaction_payments')) {
            $paymentTransactionIds = DB::table('transaction_payments')
                ->whereIn('id', $paymentIds)
                ->pluck('transaction_id', 'id')
                ->mapWithKeys(fn ($transactionId, $paymentId) => [(int) $paymentId => (int) $transactionId])
                ->all();
            $transactionIds = array_merge($transactionIds, array_values($paymentTransactionIds));
        }

        $transactionIds = array_values(array_unique(array_filter($transactionIds)));
        if ($transactionIds === []) {
            return ['transactions' => collect(), 'payment_transaction_ids' => $paymentTransactionIds];
        }

        $transactions = Transaction::where('business_id', $businessId)
            ->whereIn('id', $transactionIds)
            ->with([
                'contact',
                'purchase_lines.product.unit',
                'purchase_lines.product.category',
                'purchase_lines.product.sub_category',
                'purchase_lines.variations.product_variation',
                'purchase_lines.sub_unit',
                'sell_lines.product.unit',
                'sell_lines.product.category',
                'sell_lines.product.sub_category',
                'sell_lines.variations.product_variation',
                'sell_lines.sub_unit',
                'expense_lines.category',
                'expense_lines.subCategory',
                'expense_category',
                'expense_sub_category',
            ])
            ->get()
            ->keyBy('id');

        $paymentTransactionIds = array_filter($paymentTransactionIds, fn ($transactionId) => $transactions->has($transactionId));

        return ['transactions' => $transactions, 'payment_transaction_ids' => $paymentTransactionIds];
    }

    private function resolveTransactionId($entry, array $paymentTransactionIds): ?int
    {
        if (! empty($entry->transaction_id)) {
            return (int) $entry->transaction_id;
        }

        if (($entry->source_record_type ?? null) === Transaction::class && ! empty($entry->source_record_id)) {
            return (int) $entry->source_record_id;
        }

        $paymentId = ! empty($entry->transaction_payment_id) ? (int) $entry->transaction_payment_id : null;
        if ($paymentId === null && ($entry->source_record_type ?? null) === \App\TransactionPayment::class) {
            $paymentId = ! empty($entry->source_record_id) ? (int) $entry->source_record_id : null;
        }
        return $paymentId === null || empty($paymentTransactionIds[$paymentId])
            ? null
            : (int) $paymentTransactionIds[$paymentId];
    }

    private function productLines($transaction): array
    {
        if (! $transaction) {
            return [];
        }

        $lines = [];
        foreach ($transaction->purchase_lines ?? [] as $line) {
            $lines[] = $this->catalogLine($line, 'purchase_price');
        }
        foreach ($transaction->sell_lines ?? [] as $line) {
            $lines[] = $this->catalogLine($line, 'unit_price');
        }

        if ($lines !== []) {
            return $lines;
        }

        $expenseLines = $transaction->expense_lines ?? collect();
        $expenseTotal = (float) $expenseLines->sum('amount');
        $taxTotal = (float) ($transaction->tax_amount ?? 0);
        foreach ($expenseLines as $line) {
            $name = trim((string) $line->description);
            if ($name === '') {
                $name = trim((string) ($line->subCategory->name ?? $line->category->name ?? ''));
            }
            $netAmount = (float) $line->amount;
            $lines[] = [
                'name' => $name,
                'quantity' => null,
                'unit' => '',
                'net_amount' => $netAmount,
                'tax_amount' => $expenseTotal > 0 ? $taxTotal * $netAmount / $expenseTotal : 0,
                'category_name' => trim((string) ($line->category->name ?? '')),
                'sub_category_name' => trim((string) ($line->subCategory->name ?? '')),
                'source_description' => trim((string) $line->description),
            ];
        }

        return $lines;
    }

    private function catalogLine($line, string $priceField): array
    {
        $product = $line->product;
        $name = trim((string) ($product->name ?? ''));
        $variation = $line->variations ?? $line->variation ?? null;
        if (($product->type ?? null) === 'variable') {
            foreach ([data_get($variation, 'product_variation.name'), data_get($variation, 'name')] as $variationName) {
                $variationName = trim((string) $variationName);
                if ($variationName !== '' && $variationName !== 'DUMMY') {
                    $name .= ($name !== '' ? ' - ' : '').$variationName;
                }
            }
        }

        $quantity = (float) ($line->quantity ?? 0);
        $netAmount = (float) ($line->{$priceField} ?? 0) * $quantity;

        return [
            'name' => $name,
            'quantity' => $quantity,
            'unit' => trim((string) (data_get($line, 'sub_unit.short_name') ?: data_get($product, 'unit.short_name', ''))),
            'net_amount' => $netAmount,
            'tax_amount' => (float) ($line->item_tax ?? 0) * $quantity,
            'category_name' => trim((string) data_get($product, 'category.name', '')),
            'sub_category_name' => trim((string) data_get($product, 'sub_category.name', '')),
        ];
    }

    private function loadPlates(int $businessId, array $transactionIds): array
    {
        $transactionIds = array_values(array_unique(array_filter($transactionIds)));
        if ($transactionIds === []) {
            return [];
        }

        $assetUnits = [];
        $tagNames = [];
        if (Schema::hasTable('heavy_equipment_trip_billings')
            && Schema::hasTable('heavy_equipment_trips')
            && Schema::hasTable('heavy_equipment_rate_units')
            && Schema::hasTable('assets')) {
            $tripRows = DB::table('heavy_equipment_trip_billings as billing')
                ->join('heavy_equipment_trips as trip', 'trip.id', '=', 'billing.trip_id')
                ->join('assets as asset', function ($join) use ($businessId) {
                    $join->on('asset.id', '=', 'trip.asset_id')
                        ->where('asset.business_id', '=', $businessId);
                })
                ->leftJoin('heavy_equipment_rate_units as unit', function ($join) {
                    $join->on('unit.route_rate_id', '=', 'trip.route_rate_id')
                        ->on('unit.asset_id', '=', 'trip.asset_id');
                })
                ->where('billing.business_id', $businessId)
                ->whereIn('billing.transaction_id', $transactionIds)
                ->whereNotNull('unit.unit_label')
                ->select('billing.transaction_id', 'unit.unit_label')
                ->get();

            foreach ($tripRows as $row) {
                $unitLabel = trim((string) $row->unit_label);
                if ($unitLabel !== '') {
                    $assetUnits[(int) $row->transaction_id][] = $unitLabel;
                }
            }
        }

        if (Schema::hasTable('transaction_tags') && Schema::hasTable('tags')) {
            $transactionTagRows = DB::table('transaction_tags as transaction_tag')
                ->join('tags', 'tags.id', '=', 'transaction_tag.tag_id')
                ->where('tags.business_id', $businessId)
                ->whereIn('transaction_tag.transaction_id', $transactionIds)
                ->select('transaction_tag.transaction_id', 'tags.name')
                ->get();

            foreach ($transactionTagRows as $row) {
                $tagName = trim((string) $row->name);
                if ($tagName !== '') {
                    $tagNames[(int) $row->transaction_id][] = $tagName;
                }
            }
        }

        if (Schema::hasTable('transaction_sell_lines')
            && Schema::hasTable('transaction_sell_line_tags')
            && Schema::hasTable('tags')) {
            $sellLineTagRows = DB::table('transaction_sell_lines as sell_line')
                ->join('transaction_sell_line_tags as sell_line_tag', 'sell_line_tag.transaction_sell_line_id', '=', 'sell_line.id')
                ->join('tags', 'tags.id', '=', 'sell_line_tag.tag_id')
                ->where('tags.business_id', $businessId)
                ->whereIn('sell_line.transaction_id', $transactionIds)
                ->select('sell_line.transaction_id', 'tags.name')
                ->get();

            foreach ($sellLineTagRows as $row) {
                $tagName = trim((string) $row->name);
                if ($tagName !== '') {
                    $tagNames[(int) $row->transaction_id][] = $tagName;
                }
            }
        }

        $plates = [];
        foreach ($transactionIds as $transactionId) {
            $values = $assetUnits[$transactionId] ?? $tagNames[$transactionId] ?? [];
            $values = array_values(array_unique(array_filter(array_map('trim', $values))));
            if ($values !== []) {
                $plates[$transactionId] = implode(', ', $values);
            }
        }

        return $plates;
    }

    private function transactionLabel($entry, $transaction, ?array $sourceLine = null): array
    {
        $type = strtolower((string) ($entry->sub_type ?? $entry->mapping_type ?? ''));
        if ($transaction && ! empty($transaction->type)) {
            $type = strtolower((string) $transaction->type);
        }

        if ($type === 'opening_balance') {
            return ['label' => 'Saldo Awal', 'detail' => '', 'needs_classification_review' => false];
        }
        if ($type === 'transfer') {
            return ['label' => 'Transfer', 'detail' => '', 'needs_classification_review' => false];
        }

        $category = trim((string) ($sourceLine['category_name'] ?? ''));
        $subCategory = trim((string) ($sourceLine['sub_category_name'] ?? ''));
        $sourceDescription = trim((string) ($sourceLine['source_description'] ?? ''));
        $journalDescription = trim((string) ($entry->mapping_note ?: $entry->journal_note));
        if ($category === '' && $transaction && in_array($type, ['expense', 'expense_refund'], true)) {
            $category = trim((string) ($transaction->expense_category->name ?? ''));
            $subCategory = $subCategory ?: trim((string) ($transaction->expense_sub_category->name ?? ''));
        }
        if ($category === '' && $subCategory !== '') {
            $category = $subCategory;
            $subCategory = '';
        }

        $taxonomies = ['HPP', 'Operasional', 'Asset Lancar', 'Pendapatan Usaha'];
        if ($transaction && in_array($type, ['purchase', 'purchase_return'], true) && $category !== '') {
            $prefix = $type === 'purchase_return' ? 'Retur Pembelian' : 'Pembelian';
            $label = in_array($category, $taxonomies, true) || stripos($category, $prefix.' ') === 0
                ? $category
                : $prefix.' '.$category;

            return [
                'label' => $label,
                'detail' => $subCategory ?: $sourceDescription,
                'needs_classification_review' => false,
            ];
        }

        if ($transaction && in_array($type, ['purchase', 'purchase_return'], true) && $category === '') {
            return [
                'label' => $journalDescription ?: ($type === 'purchase_return' ? 'Retur Pembelian' : 'Pembelian'),
                'detail' => $sourceDescription,
                'needs_classification_review' => true,
            ];
        }

        if ($transaction && in_array($type, ['expense', 'expense_refund'], true) && $category !== '') {
            $label = in_array($category, $taxonomies, true)
                ? $category
                : ($type === 'expense_refund' ? 'Pengembalian Biaya' : 'Biaya Operasional');

            return [
                'label' => $label,
                'detail' => $subCategory ?: ($sourceDescription ?: $category),
                'needs_classification_review' => false,
            ];
        }

        if ($transaction && in_array($type, ['expense', 'expense_refund'], true) && $category === '') {
            return [
                'label' => $journalDescription ?: ($type === 'expense_refund' ? 'Pengembalian Biaya' : 'Biaya Operasional'),
                'detail' => $sourceDescription,
                'needs_classification_review' => true,
            ];
        }

        if ($transaction && in_array($type, ['sell', 'sell_return'], true)) {
            $label = $category !== '' && $category === 'Pendapatan Usaha'
                ? $category
                : ($type === 'sell_return' ? 'Retur Penjualan' : 'Pendapatan Usaha');

            return [
                'label' => $label,
                'detail' => $subCategory ?: $category,
                'needs_classification_review' => false,
            ];
        }

        if ($transaction && $type !== '' && $type !== 'journal_entry') {
            $labels = [
                'sell' => 'Pendapatan Usaha',
                'sell_return' => 'Retur Penjualan',
                'purchase' => 'Pembelian',
                'purchase_return' => 'Retur Pembelian',
                'expense' => 'Biaya Operasional',
                'expense_refund' => 'Pengembalian Biaya',
                'payroll' => 'Payroll',
            ];
            $label = $labels[$type] ?? ucwords(str_replace('_', ' ', $type));

            return [
                'label' => $label,
                'detail' => $subCategory ?: ($category ?: $sourceDescription),
                'needs_classification_review' => in_array($type, ['purchase', 'expense'], true),
            ];
        }

        if ($journalDescription !== '') {
            return ['label' => $journalDescription, 'detail' => '', 'needs_classification_review' => true];
        }

        $labels = [
            'journal_entry' => 'Jurnal',
            'payroll' => 'Payroll',
        ];

        return [
            'label' => $labels[$type] ?? ($type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Jurnal'),
            'detail' => '',
            'needs_classification_review' => true,
        ];
    }

    private function reference($entry, $transaction): string
    {
        return trim((string) ($entry->mapping_ref
            ?: data_get($transaction, 'invoice_no')
            ?: data_get($transaction, 'ref_no', '')));
    }

    private function note($entry, $transaction): string
    {
        return trim((string) ($entry->mapping_note
            ?: $entry->journal_note
            ?: ($transaction->additional_notes ?? '')));
    }

    private function openingBalanceRow($account, string $startDate, float $openingBalance): array
    {
        $debitNormal = in_array(strtolower((string) ($account->account_primary_type ?? '')), ['asset', 'expense', 'expenses'], true);
        $isDebit = ($openingBalance >= 0) === $debitNormal;
        $amount = abs($openingBalance);

        return [
            'id' => null,
            'accounting_account_id' => (int) $account->accounting_account_id,
            'account_id' => (int) $account->accounting_account_id,
            'account_name' => $account->account_name,
            'gl_code' => $account->gl_code,
            'operation_date' => $startDate,
            'description' => 'Saldo Awal',
            'reference' => '',
            'note' => '',
            'contact' => '',
            'plate' => '',
            'product' => '',
            'quantity' => null,
            'amount' => $amount,
            'debit' => $isDebit ? $amount : 0.0,
            'credit' => $isDebit ? 0.0 : $amount,
            'balance' => $openingBalance,
            'is_opening_balance' => true,
        ];
    }

    private function combinedOpeningBalanceRow(string $startDate, float $openingBalance): array
    {
        $amount = abs($openingBalance);
        $isDebit = $openingBalance >= 0;

        return [
            'id' => null,
            'accounting_account_id' => null,
            'account_id' => null,
            'account_name' => 'Kas/Bank Gabungan',
            'gl_code' => '',
            'operation_date' => $startDate,
            'description' => 'Saldo Awal',
            'reference' => '',
            'note' => '',
            'contact' => '',
            'plate' => '',
            'product' => '',
            'quantity' => null,
            'amount' => $amount,
            'debit' => $isDebit ? $amount : 0.0,
            'credit' => $isDebit ? 0.0 : $amount,
            'balance' => $openingBalance,
            'is_opening_balance' => true,
        ];
    }

    private function currencyPrecision(): int
    {
        return max(0, min(4, (int) session('business.currency_precision', 2)));
    }
}
