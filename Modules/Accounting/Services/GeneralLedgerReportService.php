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

    public function __construct(
        GeneralLedgerReportPresenter $presenter,
        JournalImportAccountComponentService $accountComponentService
    ) {
        $this->presenter = $presenter;
        $this->accountComponentService = $accountComponentService;
    }

    public function paginate(int $businessId, string $startDate, string $endDate, ?int $accountId, int $page = 1): LengthAwarePaginator
    {
        $query = $this->entryQuery($businessId, $startDate, $endDate, $accountId);
        $total = (clone $query)->count('aat.id');
        $page = max(1, $page);
        $offset = ($page - 1) * self::DEFAULT_PAGE_SIZE;
        $periodAccountIds = (clone $query)
            ->distinct()
            ->pluck('aat.accounting_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $entries = (clone $query)
            ->orderBy('aa.id')
            ->orderBy('aat.operation_date')
            ->orderBy('aat.id')
            ->offset($offset)
            ->limit(self::DEFAULT_PAGE_SIZE)
            ->get();

        $rows = $this->presentPage($businessId, $entries, $startDate, $endDate, $accountId, $periodAccountIds, $page);

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
        $entries = $this->entryQuery($businessId, $startDate, $endDate, $accountId)
            ->orderBy('aa.id')
            ->orderBy('aat.operation_date')
            ->orderBy('aat.id')
            ->get();

        return $this->presentAll($businessId, $entries, $startDate, $endDate, $accountId);
    }

    private function entryQuery(int $businessId, string $startDate, string $endDate, ?int $accountId)
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

        if ($accountId !== null) {
            $query->where('aa.id', $accountId);
        }

        return $query;
    }

    private function presentPage(int $businessId, $entries, string $startDate, string $endDate, ?int $accountId, array $periodAccountIds, int $page): array
    {
        if ($entries->isEmpty()) {
            return $page === 1
                ? $this->openingOnlyRows($businessId, $startDate, $accountId, $periodAccountIds)
                : [];
        }

        $accountIds = $entries->pluck('accounting_account_id')->unique()->map(fn ($id) => (int) $id)->all();
        $openingBalances = $this->balancesBefore($businessId, $accountIds, $startDate);
        $cutoffs = [];
        foreach ($entries->groupBy('accounting_account_id') as $accountId => $accountEntries) {
            $first = $accountEntries->first();
            $cutoffs[(int) $accountId] = [
                'date' => $first->operation_date,
                'id' => (int) $first->id,
            ];
        }
        $priorBalances = $this->balancesBeforePageRows($businessId, $accountIds, $cutoffs, $startDate, $endDate);
        $sourceContext = $this->loadTransactions($businessId, $entries);
        $transactions = $sourceContext['transactions'];
        $plates = $this->loadPlates($businessId, $transactions->keys()->map(fn ($id) => (int) $id)->all());
        $pageRows = [];

        foreach ($entries->groupBy('accounting_account_id') as $groupAccountId => $accountEntries) {
            $account = $accountEntries->first();
            $openingBalance = (float) ($openingBalances[(int) $groupAccountId] ?? 0);
            $prior = $priorBalances[(int) $groupAccountId] ?? ['balance' => 0, 'count' => 0];
            $startingBalance = $openingBalance + (float) $prior['balance'];

            if ((int) $prior['count'] === 0 && abs($openingBalance) > 0.00001) {
                $pageRows[] = $this->openingBalanceRow($account, $startDate, $openingBalance);
            }

            $accountEntriesForPresenter = $this->presenterEntries($businessId, $accountEntries, $transactions, $plates, $sourceContext['payment_transaction_ids']);
            $presented = $this->presenter->presentAccountEntries(
                (string) ($account->account_primary_type ?? ''),
                $accountEntriesForPresenter,
                $startingBalance,
                $this->currencyPrecision()
            );

            foreach ($presented as $row) {
                $row['account_id'] = (int) $groupAccountId;
                $row['account_name'] = $account->account_name;
                $row['gl_code'] = $account->gl_code;
                $pageRows[] = $row;
            }
        }

        if ($page === 1) {
            $pageRows = array_merge($pageRows, $this->openingOnlyRows($businessId, $startDate, $accountId, $periodAccountIds));
        }

        usort($pageRows, function ($left, $right) {
            $accountOrder = (int) $left['account_id'] <=> (int) $right['account_id'];
            if ($accountOrder !== 0) {
                return $accountOrder;
            }
            $dateOrder = strcmp((string) $left['operation_date'], (string) $right['operation_date']);

            return $dateOrder !== 0 ? $dateOrder : ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
        });

        return $pageRows;
    }

    private function presentAll(int $businessId, $entries, string $startDate, string $endDate, ?int $accountId): array
    {
        $periodAccountIds = $entries->pluck('accounting_account_id')->unique()->map(fn ($id) => (int) $id)->all();
        $openingOnlyRows = $this->openingOnlyRows($businessId, $startDate, $accountId, $periodAccountIds);
        if ($entries->isEmpty()) {
            return $openingOnlyRows;
        }

        $accountIds = $entries->pluck('accounting_account_id')->unique()->map(fn ($id) => (int) $id)->all();
        $openingBalances = $this->balancesBefore($businessId, $accountIds, $startDate);
        $sourceContext = $this->loadTransactions($businessId, $entries);
        $transactions = $sourceContext['transactions'];
        $plates = $this->loadPlates($businessId, $transactions->keys()->map(fn ($id) => (int) $id)->all());
        $rows = [];

        foreach ($entries->groupBy('accounting_account_id') as $accountId => $accountEntries) {
            $account = $accountEntries->first();
            $openingBalance = (float) ($openingBalances[(int) $accountId] ?? 0);
            if (abs($openingBalance) > 0.00001) {
                $rows[] = $this->openingBalanceRow($account, $startDate, $openingBalance);
            }

            $presented = $this->presenter->presentAccountEntries(
                (string) ($account->account_primary_type ?? ''),
                $this->presenterEntries($businessId, $accountEntries, $transactions, $plates, $sourceContext['payment_transaction_ids']),
                $openingBalance,
                $this->currencyPrecision()
            );

            foreach ($presented as $row) {
                $row['account_id'] = (int) $accountId;
                $row['account_name'] = $account->account_name;
                $row['gl_code'] = $account->gl_code;
                $rows[] = $row;
            }
        }

        $rows = array_merge($rows, $openingOnlyRows);
        usort($rows, function ($left, $right) {
            $accountOrder = (int) $left['account_id'] <=> (int) $right['account_id'];
            if ($accountOrder !== 0) {
                return $accountOrder;
            }
            $dateOrder = strcmp((string) $left['operation_date'], (string) $right['operation_date']);

            return $dateOrder !== 0 ? $dateOrder : ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
        });

        return $rows;
    }

    private function openingOnlyRows(int $businessId, string $startDate, ?int $accountId, array $periodAccountIds): array
    {
        $accountsQuery = DB::table('accounting_accounts as aa')->where('aa.business_id', $businessId);
        if ($accountId !== null) {
            $accountsQuery->where('aa.id', $accountId);
        }
        if ($periodAccountIds !== []) {
            $accountsQuery->whereNotIn('aa.id', $periodAccountIds);
        }

        $accounts = $accountsQuery->orderBy('aa.id')->get([
            'aa.id as accounting_account_id',
            'aa.name as account_name',
            'aa.gl_code',
            'aa.account_primary_type',
        ]);
        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = $this->balancesBefore(
            $businessId,
            $accounts->pluck('accounting_account_id')->map(fn ($id) => (int) $id)->all(),
            $startDate
        );
        $rows = [];
        foreach ($accounts as $account) {
            $balance = (float) ($balances[(int) $account->accounting_account_id] ?? 0);
            if (abs($balance) > 0.00001) {
                $rows[] = $this->openingBalanceRow($account, $startDate, $balance);
            }
        }

        return $rows;
    }

    private function balancesBefore(int $businessId, array $accountIds, string $startDate): array
    {
        if ($accountIds === []) {
            return [];
        }

        return DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->where('aa.business_id', $businessId)
            ->whereIn('aa.id', $accountIds)
            ->where('aat.operation_date', '<', $startDate.' 00:00:00')
            ->select('aat.accounting_account_id')
            ->selectRaw($this->balanceExpression().' as balance')
            ->groupBy('aat.accounting_account_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->accounting_account_id => (float) $row->balance])
            ->all();
    }

    private function balancesBeforePageRows(int $businessId, array $accountIds, array $cutoffs, string $startDate, string $endDate): array
    {
        if ($cutoffs === []) {
            return [];
        }

        $query = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->where('aa.business_id', $businessId)
            ->whereIn('aa.id', $accountIds)
            ->where('aat.operation_date', '>=', $startDate.' 00:00:00')
            ->where('aat.operation_date', '<=', \Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s'))
            ->where(function ($query) use ($cutoffs) {
                foreach ($cutoffs as $accountId => $cutoff) {
                    $query->orWhere(function ($query) use ($accountId, $cutoff) {
                        $query->where('aat.accounting_account_id', $accountId)
                            ->where(function ($query) use ($cutoff) {
                                $query->where('aat.operation_date', '<', $cutoff['date'])
                                    ->orWhere(function ($query) use ($cutoff) {
                                        $query->where('aat.operation_date', '=', $cutoff['date'])
                                            ->where('aat.id', '<', $cutoff['id']);
                                    });
                            });
                    });
                }
            });

        return $query->select('aat.accounting_account_id')
            ->selectRaw($this->balanceExpression().' as balance')
            ->selectRaw('COUNT(aat.id) as entry_count')
            ->groupBy('aat.accounting_account_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->accounting_account_id => [
                'balance' => (float) $row->balance,
                'count' => (int) $row->entry_count,
            ]])
            ->all();
    }

    private function balanceExpression(): string
    {
        return "SUM(CASE
            WHEN ((aa.account_primary_type IN ('asset', 'expense', 'expenses') AND aat.type = 'debit')
                OR (aa.account_primary_type IN ('income', 'equity', 'liability') AND aat.type = 'credit'))
            THEN aat.amount ELSE -1 * aat.amount END)";
    }

    private function presenterEntries(int $businessId, $accountEntries, $transactions, array $plates, array $paymentTransactionIds): array
    {
        $result = [];

        foreach ($accountEntries as $entry) {
            $transactionId = $this->resolveTransactionId($entry, $paymentTransactionIds);
            $transaction = $transactionId ? $transactions->get($transactionId) : null;
            $productLines = $this->productLines($transaction);
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
            $label = $this->transactionLabel($entry, $transaction);

            $result[] = [
                'id' => (int) $entry->id,
                'accounting_account_id' => (int) $entry->accounting_account_id,
                'operation_date' => $entry->operation_date,
                'type' => (string) $entry->type,
                'amount' => (float) $entry->amount,
                'is_tax_account' => ! empty($classification[0]['is_tax']),
                'products' => $productLines,
                'description' => $label,
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
                'purchase_lines.variations.product_variation',
                'purchase_lines.sub_unit',
                'sell_lines.product.unit',
                'sell_lines.variations.product_variation',
                'sell_lines.sub_unit',
                'expense_lines.category',
                'expense_lines.subCategory',
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

    private function transactionLabel($entry, $transaction): string
    {
        $type = strtolower((string) ($entry->sub_type ?? $entry->mapping_type ?? ''));
        if ($transaction && ! empty($transaction->type)) {
            $type = strtolower((string) $transaction->type);
        }
        $labels = [
            'sell' => 'Penjualan',
            'sell_return' => 'Retur Penjualan',
            'purchase' => 'Pembelian',
            'purchase_return' => 'Retur Pembelian',
            'expense' => 'Biaya Operasional',
            'expense_refund' => 'Pengembalian Biaya',
            'journal_entry' => 'Journal Entry',
            'opening_balance' => 'Saldo Awal',
            'payroll' => 'Payroll',
            'transfer' => 'Transfer',
        ];

        return $labels[$type] ?? ($type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Jurnal');
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

    private function currencyPrecision(): int
    {
        return max(0, min(4, (int) session('business.currency_precision', 2)));
    }
}
