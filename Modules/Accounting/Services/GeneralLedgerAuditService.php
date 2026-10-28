<?php

namespace Modules\Accounting\Services;

use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only comparison of operational cash movements with explicitly mapped GL accounts.
 */
class GeneralLedgerAuditService
{
    private const PAGE_SIZE = 100;
    private const AMOUNT_TOLERANCE = 0.01;

    private $cashBankMappingService;

    private $accountComponentService;

    public function __construct(
        GeneralLedgerCashBankMappingService $cashBankMappingService,
        JournalImportAccountComponentService $accountComponentService
    ) {
        $this->cashBankMappingService = $cashBankMappingService;
        $this->accountComponentService = $accountComponentService;
    }

    public function paymentAccounts(int $businessId)
    {
        if (! Schema::hasTable('accounts')) {
            return collect();
        }

        return DB::table('accounts')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function report(int $businessId, string $startDate, string $endDate, array $filters = [], int $page = 1): array
    {
        $data = $this->buildAudit($businessId, $startDate, $endDate, $filters);
        $rows = $this->filterRows($data['rows'], $filters);
        $page = max(1, $page);
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            count($rows),
            self::PAGE_SIZE,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'summary' => $data['summary'],
            'rows' => $paginator,
            'accounts' => $this->paymentAccounts($businessId),
            'source_types' => $data['source_types'],
            'statuses' => $data['statuses'],
        ];
    }

    public function exportRows(int $businessId, string $startDate, string $endDate, array $filters = []): array
    {
        $data = $this->buildAudit($businessId, $startDate, $endDate, $filters);

        return $this->filterRows($data['rows'], $filters);
    }

    /** Pure comparison seam used by the audit and its focused feature tests. */
    public function compareMovement(float $sourceAmount, float $postingAmount): array
    {
        $difference = round($sourceAmount - $postingAmount, 4);

        return [
            'difference' => $difference,
            'matches' => abs($difference) <= self::AMOUNT_TOLERANCE,
        ];
    }

    private function buildAudit(int $businessId, string $startDate, string $endDate, array $filters): array
    {
        $mappedAccounts = $this->cashBankMappingService->mappedAccounts($businessId)->keyBy('id');
        $accountCrosswalk = $this->accountCrosswalk($businessId, $mappedAccounts);
        $selectedMainAccountId = empty($filters['account_id']) ? null : (int) $filters['account_id'];
        if ($selectedMainAccountId !== null) {
            $selectedGlIds = array_column($accountCrosswalk[$selectedMainAccountId] ?? [], 'id');
            $mappedAccounts = $mappedAccounts->only($selectedGlIds);
        }
        $postingGroups = $this->loadPostingGroups($businessId, $startDate, $endDate, $mappedAccounts);
        $mappingIds = array_values(array_filter(array_unique(array_column($postingGroups, 'mapping_id'))));
        $this->addJournalDetails($businessId, $postingGroups, $mappingIds);
        $this->addTaxComponents($businessId, $postingGroups, $mappingIds);

        $sourceRows = $this->loadOperationalMovements($businessId, $startDate, $endDate, $selectedMainAccountId);
        $transactionSourceCounts = [];
        foreach ($sourceRows as $source) {
            if (! empty($source['transaction_id'])) {
                $transactionSourceCounts[(int) $source['transaction_id']] = ($transactionSourceCounts[(int) $source['transaction_id']] ?? 0) + 1;
            }
        }

        $postingIndexes = $this->postingIndexes($postingGroups);
        $preparedRows = [];
        $groupSourceCounts = [];
        $sourceTotals = $this->emptyTotals();
        $statusCounts = [];

        foreach ($sourceRows as $source) {
            $row = $this->presentSource($source, $accountCrosswalk, $postingIndexes, $postingGroups, $transactionSourceCounts);
            foreach ($row['_posting_group_keys'] as $groupKey) {
                $groupSourceCounts[$groupKey] = ($groupSourceCounts[$groupKey] ?? 0) + 1;
            }
            $preparedRows[] = $row;
        }

        foreach ($preparedRows as &$row) {
            if ($row['status'] === 'matched' && count($row['_posting_group_keys']) === 1) {
                $groupKey = $row['_posting_group_keys'][0];
                if (($groupSourceCounts[$groupKey] ?? 0) > 1 && empty($postingGroups[$groupKey]['internal_transfer'])) {
                    $row['status'] = 'ambiguous_match';
                    $row['reason'] = 'Satu grup posting terkait ke beberapa movement sumber.';
                    $row['in_cashflow'] = false;
                }
            }
            $this->addSourceTotals($sourceTotals, $row);
            $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
            unset($row['_posting_group_keys'], $row['_tax_direction']);
        }
        unset($row);

        $matchedGroupKeys = [];
        foreach ($preparedRows as $row) {
            foreach ($row['_matched_group_keys'] ?? [] as $groupKey) {
                $matchedGroupKeys[$groupKey] = true;
            }
        }
        foreach ($postingGroups as $groupKey => $group) {
            if (isset($matchedGroupKeys[$groupKey])) {
                continue;
            }
            if (! empty($group['internal_transfer'])) {
                $preparedRows[] = $this->presentInternalTransfer($group);
                $statusCounts['internal_transfer'] = ($statusCounts['internal_transfer'] ?? 0) + 1;
                continue;
            }
            $postingRow = $this->presentUnmatchedPosting($group);
            $preparedRows[] = $postingRow;
            $statusCounts[$postingRow['status']] = ($statusCounts[$postingRow['status']] ?? 0) + 1;
        }

        foreach ($preparedRows as &$row) {
            unset($row['_matched_group_keys']);
        }
        unset($row);

        usort($preparedRows, function ($left, $right) {
            $dateOrder = strcmp((string) $left['date'], (string) $right['date']);

            return $dateOrder !== 0 ? $dateOrder : strcmp((string) $left['sort_key'], (string) $right['sort_key']);
        });

        foreach ($postingGroups as $group) {
            if (! empty($group['internal_transfer']) || ! empty($group['non_asset_account'])) {
                continue;
            }
            $sourceTotals['debit_total'] += (float) $group['debit'];
            $sourceTotals['credit_total'] += (float) $group['credit'];
            $sourceTotals['gl_inflow'] += (float) $group['debit'];
            $sourceTotals['gl_outflow'] += (float) $group['credit'];
            $sourceTotals['journal_vat_in'] += (float) $group['vat_in'];
            $sourceTotals['journal_vat_out'] += (float) $group['vat_out'];
        }

        $opening = $this->openingBalances($businessId, $startDate, $mappedAccounts, $accountCrosswalk, $selectedMainAccountId);
        $sourceTotals['source_count'] = count($sourceRows);
        $sourceTotals['posting_group_count'] = count($postingGroups);
        $sourceTotals['status_counts'] = $statusCounts;
        $sourceTotals['opening_balance'] = $opening['gl'];
        $sourceTotals['source_opening_balance'] = $opening['source'];
        $sourceTotals['opening_balance_difference'] = round($opening['gl'] - $opening['source'], 4);
        $sourceTotals['closing_balance'] = round($opening['gl'] + $sourceTotals['gl_inflow'] - $sourceTotals['gl_outflow'], 4);
        $sourceTotals['source_closing_balance'] = round($opening['source'] + $sourceTotals['source_inflow'] - $sourceTotals['source_outflow'], 4);
        $sourceTotals['closing_balance_difference'] = round($sourceTotals['closing_balance'] - $sourceTotals['source_closing_balance'], 4);
        $sourceTotals['missing_posting_count'] = $statusCounts['missing_posting'] ?? 0;
        $sourceTotals['missing_source_count'] = $statusCounts['missing_source'] ?? 0;
        $sourceTotals['duplicate_count'] = $statusCounts['duplicate_posting'] ?? 0;
        $sourceTotals['amount_difference_count'] = $statusCounts['amount_difference'] ?? 0;
        $sourceTotals['vat_difference_count'] = $statusCounts['vat_difference'] ?? 0;
        $sourceTotals['journal_import_duplicate_count'] = $statusCounts['duplicate_journal_import'] ?? 0;
        $sourceTotals['journal_import_conflict_count'] = $statusCounts['conflicting_journal_import'] ?? 0;
        $sourceTotals['exception_count'] = array_sum(array_filter($statusCounts, function ($count, $status) {
            return $status !== 'matched' && $status !== 'internal_transfer' && strpos($status, 'excluded_') !== 0;
        }, ARRAY_FILTER_USE_BOTH));

        $sourceTypes = [];
        $statuses = [];
        foreach ($preparedRows as $row) {
            if ($row['source_type'] !== '') {
                $sourceTypes[$row['source_type']] = $row['source_type'];
            }
            $statuses[$row['status']] = $row['status'];
        }

        return [
            'rows' => $preparedRows,
            'summary' => $sourceTotals,
            'source_types' => array_values($sourceTypes),
            'statuses' => array_values($statuses),
        ];
    }

    private function loadOperationalMovements(int $businessId, string $startDate, string $endDate, ?int $selectedMainAccountId): array
    {
        if (! Schema::hasTable('account_transactions') || ! Schema::hasTable('accounts')) {
            return [];
        }

        $hasTransaction = Schema::hasTable('transactions');
        $query = DB::table('account_transactions as at')
            ->join('accounts as account', function ($join) use ($businessId) {
                $join->on('account.id', '=', 'at.account_id')
                    ->where('account.business_id', '=', $businessId);
            })
            ->whereBetween('at.operation_date', [$startDate.' 00:00:00', Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s')])
            ->select([
                'at.id as movement_id', 'at.account_id', 'account.name as payment_account', 'account.deleted_at as account_deleted_at',
                'at.type as movement_type', 'at.sub_type', 'at.amount', 'at.operation_date as movement_date', 'at.reff_no as reference',
                'at.note', 'at.transaction_id', 'at.transaction_payment_id', 'at.transfer_transaction_id', 'at.deleted_at',
            ]);
        if ($selectedMainAccountId !== null) {
            $query->where('at.account_id', $selectedMainAccountId);
        }

        if ($hasTransaction) {
            $query->leftJoin('transactions as tr', function ($join) use ($businessId) {
                $join->on('tr.id', '=', 'at.transaction_id')->where('tr.business_id', '=', $businessId);
            });
            $query->addSelect([
                'tr.type as transaction_type', 'tr.status as transaction_status', 'tr.final_total', 'tr.tax_amount',
                'tr.ref_no as transaction_reference', 'tr.invoice_no',
            ]);
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $query->addSelect('tr.deleted_at as transaction_deleted_at');
            }
            if (Schema::hasColumn('transactions', 'is_cancelled')) {
                $query->addSelect('tr.is_cancelled');
            }
            if (Schema::hasColumn('transactions', 'expense_category_id') && Schema::hasTable('expense_categories')) {
                $query->leftJoin('expense_categories as ec', 'ec.id', '=', 'tr.expense_category_id')->addSelect('ec.name as expense_category');
            }
            if (Schema::hasColumn('transactions', 'contact_id') && Schema::hasTable('contacts')) {
                $query->leftJoin('contacts as contact', function ($join) use ($businessId) {
                    $join->on('contact.id', '=', 'tr.contact_id')->where('contact.business_id', '=', $businessId);
                })->addSelect('contact.name as contact_name');
            }
        }
        if (Schema::hasColumn('account_transactions', 'journal_import_row_id')
            && Schema::hasTable('accounting_journal_import_rows')) {
            $query->leftJoin('accounting_journal_import_rows as jr', function ($join) use ($businessId) {
                $join->on('jr.id', '=', 'at.journal_import_row_id')->where('jr.business_id', '=', $businessId);
            })->addSelect(['jr.id as journal_row_id', 'jr.source_type as journal_source_type', 'jr.source_number as journal_source_number', 'jr.entry_data as journal_entry_data']);
        }

        $rows = [];
        foreach ($query->orderBy('at.operation_date')->orderBy('at.id')->cursor() as $row) {
            $rows[] = $this->sourceArray($row, 'account_transaction');
        }

        if (Schema::hasTable('transaction_payments')
            && Schema::hasColumn('transaction_payments', 'account_id')
            && Schema::hasColumn('transaction_payments', 'paid_on')) {
            $paymentQuery = DB::table('transaction_payments as tp')
                ->leftJoin('accounts as account', function ($join) use ($businessId) {
                    $join->on('account.id', '=', 'tp.account_id')->where('account.business_id', '=', $businessId);
                })
                ->whereBetween('tp.paid_on', [$startDate.' 00:00:00', Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s')])
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))->from('account_transactions as existing_at')
                        ->whereColumn('existing_at.transaction_payment_id', 'tp.id');
                })
                ->select([
                    'tp.id as payment_id', 'tp.account_id', 'account.name as payment_account', 'account.deleted_at as account_deleted_at',
                    'tp.amount', 'tp.paid_on as movement_date', 'tp.payment_ref_no as reference',
                    DB::raw('NULL as note'), 'tp.transaction_id', DB::raw('tp.id as transaction_payment_id'),
                    DB::raw('NULL as movement_type'), DB::raw('NULL as sub_type'), DB::raw('NULL as transfer_transaction_id'),
                    DB::raw('NULL as deleted_at'),
                ]);
            if ($selectedMainAccountId !== null) {
                $paymentQuery->where('tp.account_id', $selectedMainAccountId);
            }
            if (Schema::hasColumn('transaction_payments', 'business_id')) {
                $paymentQuery->where('tp.business_id', $businessId);
            } elseif (Schema::hasTable('transactions')) {
                $paymentQuery->join('transactions as payment_transaction', function ($join) use ($businessId) {
                    $join->on('payment_transaction.id', '=', 'tp.transaction_id')->where('payment_transaction.business_id', '=', $businessId);
                });
            }
            if (Schema::hasTable('transactions')) {
                if (Schema::hasColumn('transaction_payments', 'business_id')) {
                    $paymentQuery->leftJoin('transactions as tr', function ($join) use ($businessId) {
                        $join->on('tr.id', '=', 'tp.transaction_id')->where('tr.business_id', '=', $businessId);
                    });
                } else {
                    $paymentQuery->leftJoin('transactions as tr', 'tr.id', '=', 'tp.transaction_id');
                }
                $paymentQuery->addSelect(['tr.type as transaction_type', 'tr.status as transaction_status', 'tr.final_total', 'tr.tax_amount', 'tr.ref_no as transaction_reference', 'tr.invoice_no']);
                if (Schema::hasColumn('transactions', 'deleted_at')) {
                    $paymentQuery->addSelect('tr.deleted_at as transaction_deleted_at');
                }
                if (Schema::hasColumn('transactions', 'is_cancelled')) {
                    $paymentQuery->addSelect('tr.is_cancelled');
                }
                if (Schema::hasColumn('transactions', 'expense_category_id') && Schema::hasTable('expense_categories')) {
                    $paymentQuery->leftJoin('expense_categories as ec', 'ec.id', '=', 'tr.expense_category_id')->addSelect('ec.name as expense_category');
                }
                if (Schema::hasColumn('transactions', 'contact_id') && Schema::hasTable('contacts')) {
                    $paymentQuery->leftJoin('contacts as contact', function ($join) use ($businessId) {
                        $join->on('contact.id', '=', 'tr.contact_id')->where('contact.business_id', '=', $businessId);
                    })->addSelect('contact.name as contact_name');
                }
            }
            foreach (['payment_type', 'is_return', 'method'] as $column) {
                if (Schema::hasColumn('transaction_payments', $column)) {
                    $paymentQuery->addSelect('tp.'.$column);
                }
            }
            foreach ($paymentQuery->orderBy('tp.paid_on')->orderBy('tp.id')->cursor() as $row) {
                $rows[] = $this->sourceArray($row, 'payment_without_movement');
            }
        }

        return $rows;
    }

    private function sourceArray(object $row, string $kind): array
    {
        $isPayment = $kind === 'payment_without_movement';
        $transactionType = (string) ($row->transaction_type ?? '');
        $sourceType = $this->sourceTypeLabel($row, $isPayment);
        $category = trim((string) ($row->expense_category ?? ''));
        $journalEntry = json_decode((string) ($row->journal_entry_data ?? ''), true);
        $journalLines = is_array($journalEntry) ? (array) ($journalEntry['lines'] ?? []) : [];
        $journalDescriptions = [];
        foreach ($journalLines as $line) {
            $description = trim((string) ($line['description'] ?? $line['category'] ?? ''));
            if ($description !== '') {
                $journalDescriptions[] = $description;
            }
        }
        $journalDescriptions = array_values(array_unique($journalDescriptions));
        $journalType = trim((string) ($row->journal_source_type ?? ($journalEntry['source_type'] ?? '')));
        if ($journalType !== '') {
            $sourceType = $journalType;
        }
        $journalSourceNumber = trim((string) ($row->journal_source_number ?? ($journalEntry['source_number'] ?? '')));
        $reference = trim((string) ($row->reference ?? ''));
        if ($reference === '') {
            $reference = trim((string) ($row->transaction_reference ?? $journalSourceNumber));
        }
        $movementId = $isPayment ? (int) ($row->payment_id ?? 0) : (int) ($row->movement_id ?? 0);

        return [
            'row_key' => ($isPayment ? 'payment:' : 'movement:').$movementId,
            'source_kind' => $kind,
            'source_type' => $sourceType,
            'source_record_type' => $isPayment ? TransactionPayment::class : AccountTransaction::class,
            'source_record_id' => $movementId,
            'transaction_payment_id' => empty($row->transaction_payment_id) ? null : (int) $row->transaction_payment_id,
            'transaction_id' => empty($row->transaction_id) ? null : (int) $row->transaction_id,
            'account_id' => empty($row->account_id) ? null : (int) $row->account_id,
            'payment_account' => (string) ($row->payment_account ?? ''),
            'account_deleted' => ! empty($row->account_deleted_at),
            'movement_type' => strtolower((string) ($row->movement_type ?? $row->payment_type ?? '')),
            'sub_type' => strtolower((string) ($row->sub_type ?? '')),
            'transfer_transaction_id' => empty($row->transfer_transaction_id) ? null : (int) $row->transfer_transaction_id,
            'amount' => is_numeric($row->amount ?? null) ? (float) $row->amount : NAN,
            'date' => substr((string) ($row->movement_date ?? ''), 0, 10),
            'reference' => $reference,
            'note' => trim((string) ($row->note ?? '')),
            'transaction_type' => $transactionType,
            'transaction_status' => strtolower((string) ($row->transaction_status ?? '')),
            'transaction_cancelled' => ! empty($row->is_cancelled),
            'transaction_deleted' => ! empty($row->transaction_deleted_at),
            'transaction_total' => is_numeric($row->final_total ?? null) ? (float) $row->final_total : null,
            'source_vat' => is_numeric($row->tax_amount ?? null) ? (float) $row->tax_amount : null,
            'contact' => trim((string) ($row->contact_name ?? '')),
            'category' => $category !== '' ? $category : $this->defaultCategory($transactionType, (string) ($row->sub_type ?? '')),
            'journal_category' => $journalDescriptions[0] ?? '',
            'journal_detail' => implode('; ', array_slice($journalDescriptions, 1)),
            'journal_source_number' => $journalSourceNumber,
            'is_return' => ! empty($row->is_return),
            'is_payment' => $isPayment,
            'deleted_at' => $row->deleted_at ?? null,
        ];
    }

    private function sourceTypeLabel(object $row, bool $isPayment): string
    {
        $transactionType = (string) ($row->transaction_type ?? '');
        if ($transactionType !== '') {
            return $transactionType;
        }

        return $isPayment ? 'Pembayaran' : (string) ($row->sub_type ?? 'Movement Rekening');
    }

    private function defaultCategory(string $transactionType, string $subType): string
    {
        $categories = [
            'purchase' => 'Pembelian', 'purchase_return' => 'Retur Pembelian',
            'sell' => 'Pendapatan Usaha', 'sell_return' => 'Retur Penjualan',
            'expense' => 'Operasional', 'expense_refund' => 'Pengembalian Biaya',
            'payroll' => 'Uang Jalan / Penggajian', 'opening_balance' => 'Saldo Awal',
            'stock_adjustment' => 'Asset Lancar / HPP', 'hms_booking' => 'Pendapatan Usaha',
        ];

        if ($subType === 'opening_balance') {
            return 'Saldo Awal';
        }
        if ($subType === 'fund_transfer') {
            return 'Transfer Antar Rekening';
        }

        return $categories[$transactionType] ?? ($transactionType !== '' ? ucfirst(str_replace('_', ' ', $transactionType)) : 'Movement Rekening');
    }

    private function accountCrosswalk(int $businessId, $mappedAccounts): array
    {
        if (! Schema::hasTable('accounting_general_ledger_cash_bank_mappings')
            || ! Schema::hasColumn('accounting_accounts', 'account_id')) {
            return [];
        }

        $rows = DB::table('accounting_accounts as aa')
            ->join('accounting_general_ledger_cash_bank_mappings as cbm', function ($join) {
                $join->on('cbm.accounting_account_id', '=', 'aa.id')->on('cbm.business_id', '=', 'aa.business_id');
            })
            ->where('aa.business_id', $businessId)
            ->whereIn('cbm.classification', ['cash', 'bank'])
            ->whereNotNull('aa.account_id')
            ->get(['aa.id', 'aa.account_id', 'aa.name', 'aa.gl_code', 'aa.account_primary_type', 'cbm.classification']);
        $byMain = [];
        foreach ($rows as $row) {
            $byMain[(int) $row->account_id][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'gl_code' => (string) ($row->gl_code ?? ''),
                'primary_type' => strtolower((string) ($row->account_primary_type ?? '')),
                'classification' => (string) $row->classification,
            ];
        }

        foreach ($byMain as $id => &$accounts) {
            $accounts = array_values(array_filter($accounts, function ($account) use ($mappedAccounts) {
                return $mappedAccounts->has($account['id']);
            }));
        }
        unset($accounts);

        return $byMain;
    }

    private function loadPostingGroups(int $businessId, string $startDate, string $endDate, $mappedAccounts): array
    {
        if ($mappedAccounts->isEmpty()) {
            return [];
        }

        $accountIds = $mappedAccounts->keys()->map(fn ($id) => (int) $id)->all();
        $query = DB::table('accounting_accounts_transactions as aat')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
            ->join('accounting_general_ledger_cash_bank_mappings as cbm', function ($join) {
                $join->on('cbm.accounting_account_id', '=', 'aa.id')->on('cbm.business_id', '=', 'aa.business_id');
            })
            ->leftJoin('accounting_acc_trans_mappings as atm', function ($join) {
                $join->on('atm.id', '=', 'aat.acc_trans_mapping_id')->on('atm.business_id', '=', 'aa.business_id');
            })
            ->where('aa.business_id', $businessId)
            ->whereIn('aa.id', $accountIds)
            ->whereBetween('aat.operation_date', [$startDate.' 00:00:00', Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s')])
            ->select([
                'aat.id', 'aat.acc_trans_mapping_id', 'aat.accounting_account_id', 'aat.operation_date', 'aat.type', 'aat.amount',
                'aat.sub_type', 'aat.transaction_id', 'aat.transaction_payment_id', 'aat.note', 'aa.name as account_name', 'aa.gl_code',
                'aa.account_primary_type', 'cbm.classification', 'atm.type as mapping_type', 'atm.ref_no as mapping_ref',
                'atm.note as mapping_note', 'atm.source_record_type', 'atm.source_record_id',
            ]);
        if (Schema::hasColumn('accounting_acc_trans_mappings', 'journal_import_id')) {
            $query->addSelect('atm.journal_import_id');
        }
        if (Schema::hasTable('accounting_journal_imports') && Schema::hasColumn('accounting_accounts_transactions', 'journal_import_id')) {
            $query->whereNotExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))->from('accounting_journal_imports as reverted_import')
                    ->whereColumn('reverted_import.id', 'aat.journal_import_id')->where('reverted_import.status', 'reverted');
            });
        }

        $groups = [];
        foreach ($query->orderBy('aat.operation_date')->orderBy('aat.id')->cursor() as $entry) {
            $mappingId = empty($entry->acc_trans_mapping_id) ? null : (int) $entry->acc_trans_mapping_id;
            $groupKey = $this->postingGroupKey($entry, $mappingId);
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey, 'mapping_id' => $mappingId, 'date' => substr((string) $entry->operation_date, 0, 10),
                    'source_record_type' => (string) ($entry->source_record_type ?? ''),
                    'source_record_id' => empty($entry->source_record_id) ? null : (int) $entry->source_record_id,
                    'transaction_payment_id' => empty($entry->transaction_payment_id) ? null : (int) $entry->transaction_payment_id,
                    'transaction_id' => empty($entry->transaction_id) ? null : (int) $entry->transaction_id,
                    'reference' => trim((string) ($entry->mapping_ref ?? '')),
                    'mapping_type' => strtolower((string) ($entry->mapping_type ?? '')),
                    'journal_import_id' => empty($entry->journal_import_id) ? null : (int) $entry->journal_import_id,
                    'note' => trim((string) ($entry->mapping_note ?? $entry->note ?? '')),
                    'accounts' => [], 'debit' => 0.0, 'credit' => 0.0, 'movement' => 0.0,
                    'entry_count' => 0, 'non_asset_account' => false, 'vat_in' => 0.0, 'vat_out' => 0.0,
                    'vat_ambiguous' => false, 'journal_category' => '', 'journal_detail' => '', 'source_type' => '',
                    'journal_source_key' => '', 'journal_identity_status' => null, 'journal_identity_fingerprint' => null,
                    'internal_transfer' => false, 'sort_key' => 'posting:'.$groupKey,
                ];
            }
            $amount = (float) $entry->amount;
            $type = strtolower((string) $entry->type);
            $groups[$groupKey]['debit'] += $type === 'debit' ? $amount : 0;
            $groups[$groupKey]['credit'] += $type === 'credit' ? $amount : 0;
            $groups[$groupKey]['movement'] += $type === 'debit' ? $amount : -$amount;
            $groups[$groupKey]['entry_count']++;
            $groups[$groupKey]['accounts'][(int) $entry->accounting_account_id] = [
                'id' => (int) $entry->accounting_account_id, 'name' => (string) $entry->account_name,
                'gl_code' => (string) ($entry->gl_code ?? ''), 'classification' => (string) $entry->classification,
            ];
            $groups[$groupKey]['non_asset_account'] = $groups[$groupKey]['non_asset_account']
                || strtolower((string) $entry->account_primary_type) !== 'asset';
        }

        foreach ($groups as &$group) {
            $group['debit'] = round($group['debit'], 4);
            $group['credit'] = round($group['credit'], 4);
            $group['movement'] = round($group['movement'], 4);
            $group['accounts'] = array_values($group['accounts']);
            $group['internal_transfer'] = in_array($group['mapping_type'], ['transfer', 'fund_transfer'], true)
                && abs($group['movement']) <= 0.0001
                && $group['entry_count'] > 1;
        }
        unset($group);

        $this->markInternalTransfers($businessId, $groups, $accountIds);

        return $groups;
    }

    private function markInternalTransfers(int $businessId, array &$groups, array $cashBankAccountIds): void
    {
        $mappingIds = array_values(array_unique(array_filter(array_map(function ($group) {
            return $group['mapping_id'] ?? null;
        }, $groups))));
        if ($mappingIds === []) {
            return;
        }

        foreach (array_chunk($mappingIds, 400) as $chunk) {
            $rows = DB::table('accounting_accounts_transactions as aat')
                ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
                ->where('aa.business_id', $businessId)
                ->whereIn('aat.acc_trans_mapping_id', $chunk)
                ->groupBy('aat.acc_trans_mapping_id')
                ->get([
                    'aat.acc_trans_mapping_id',
                    DB::raw('COUNT(DISTINCT aat.accounting_account_id) as account_count'),
                    DB::raw('SUM(CASE WHEN aat.accounting_account_id NOT IN ('.implode(',', array_map('intval', $cashBankAccountIds)).') THEN 1 ELSE 0 END) as outside_cash_bank_count'),
                    DB::raw("SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE 0 END) as debit_total"),
                    DB::raw("SUM(CASE WHEN aat.type = 'credit' THEN aat.amount ELSE 0 END) as credit_total"),
                ]);
            foreach ($rows as $row) {
                $mappingId = (int) $row->acc_trans_mapping_id;
                $key = 'mapping:'.$mappingId;
                if (! isset($groups[$key])) {
                    continue;
                }
                if ((int) $row->account_count > 1
                    && (int) $row->outside_cash_bank_count === 0
                    && abs((float) $row->debit_total - (float) $row->credit_total) <= 0.0001) {
                    $groups[$key]['internal_transfer'] = true;
                }
            }
        }
    }

    private function postingGroupKey(object $entry, ?int $mappingId): string
    {
        if ($mappingId !== null) {
            return 'mapping:'.$mappingId;
        }
        if (! empty($entry->source_record_type) && ! empty($entry->source_record_id)) {
            return 'source:'.strtolower((string) $entry->source_record_type).':'.(int) $entry->source_record_id;
        }
        if (! empty($entry->transaction_payment_id)) {
            return 'payment:'.(int) $entry->transaction_payment_id;
        }
        if (! empty($entry->transaction_id)) {
            return 'transaction:'.(int) $entry->transaction_id;
        }

        return 'posting:'.(int) $entry->id;
    }

    private function postingIndexes(array $groups): array
    {
        $indexes = ['identity' => [], 'payment' => [], 'transaction' => [], 'fallback' => []];
        foreach ($groups as $key => $group) {
            if (! empty($group['source_record_type']) && ! empty($group['source_record_id'])) {
                $this->pushIndex($indexes['identity'], $this->identityKey($group['source_record_type'], $group['source_record_id']), $key);
            }
            if (! empty($group['transaction_payment_id'])) {
                $this->pushIndex($indexes['payment'], (int) $group['transaction_payment_id'], $key);
                $this->pushIndex($indexes['identity'], $this->identityKey(TransactionPayment::class, $group['transaction_payment_id']), $key);
            }
            if (! empty($group['transaction_id'])) {
                $this->pushIndex($indexes['transaction'], (int) $group['transaction_id'], $key);
                $this->pushIndex($indexes['identity'], $this->identityKey(Transaction::class, $group['transaction_id']), $key);
            }
            $fallback = $this->fallbackKey($group['date'], $group['reference'], $group['movement']);
            if ($fallback !== null) {
                $this->pushIndex($indexes['fallback'], $fallback, $key);
            }
        }

        return $indexes;
    }

    private function presentSource(array $source, array $accountCrosswalk, array $indexes, array $groups, array $transactionSourceCounts): array
    {
        $accountMatches = $accountCrosswalk[(int) ($source['account_id'] ?? 0)] ?? [];
        $accountMappingAmbiguous = count($accountMatches) > 1;
        $glAccountIds = $accountMappingAmbiguous ? [] : array_column($accountMatches, 'id');
        $sourceSignedAmount = $this->sourceSignedAmount($source);
        $candidate = $this->findPostingCandidates($source, $indexes, $transactionSourceCounts, $glAccountIds, $groups);
        $candidateKeys = $candidate['keys'];
        $firstGroup = count($candidateKeys) === 1 ? ($groups[$candidateKeys[0]] ?? null) : null;
        $postingAmount = $firstGroup ? (float) $firstGroup['movement'] : null;
        $vatDirection = $this->taxDirection($source['transaction_type']);
        $sourceVat = $this->sourceHasFullTransactionPayment($source) ? $source['source_vat'] : null;
        $journalVat = $firstGroup && $vatDirection === 'input'
            ? (float) $firstGroup['vat_in']
            : ($firstGroup && $vatDirection === 'output' ? (float) $firstGroup['vat_out'] : null);
        $row = [
            'date' => $source['date'], 'source_type' => $source['source_type'] ?: ($source['transaction_type'] ?: $source['category']),
            'source_number' => $source['reference'], 'description' => $source['note'], 'category' => $source['category'],
            'detail_category' => $source['journal_category'], 'journal_detail' => $source['journal_detail'],
            'contact' => $source['contact'], 'payment_account' => $source['payment_account'],
            'account_id' => $source['account_id'], 'operational_amount' => $sourceSignedAmount,
            'accounting_amount' => $postingAmount, 'difference' => $postingAmount === null ? null : round($sourceSignedAmount - $postingAmount, 4),
            'source_vat' => $sourceVat, 'journal_vat' => $journalVat,
            'vat_difference' => $sourceVat === null || $journalVat === null ? null : round($sourceVat - $journalVat, 4),
            'status' => 'matched', 'reason' => '', 'source_record_type' => $source['source_record_type'],
            'source_record_id' => $source['source_record_id'], 'posting_count' => count($candidateKeys),
            'in_cashflow' => false, 'sort_key' => $source['row_key'], '_tax_direction' => $this->taxDirection($source['transaction_type']),
            '_posting_group_keys' => $candidateKeys,
            '_matched_group_keys' => $candidateKeys,
        ];

        if ($source['deleted_at'] !== null || $source['transaction_deleted']) {
            $row['status'] = 'excluded_soft_deleted';
            $row['reason'] = 'Movement operasional telah dihapus secara soft delete.';
        } elseif ($source['transaction_cancelled'] || in_array($source['transaction_status'], ['cancelled', 'canceled'], true)) {
            $row['status'] = 'excluded_cancelled';
            $row['reason'] = 'Transaksi operasional dibatalkan.';
        } elseif ($source['transaction_status'] === 'draft') {
            $row['status'] = 'excluded_draft';
            $row['reason'] = 'Transaksi masih berstatus draft.';
        } elseif ($source['is_payment'] && ($source['account_id'] === null || $source['payment_account'] === '')) {
            $row['status'] = 'excluded_non_cash';
            $row['reason'] = 'Pembayaran tidak memiliki akun Kas/Bank operasional.';
        } elseif ($source['account_deleted']) {
            $row['status'] = 'unmapped_account';
            $row['reason'] = 'Akun pembayaran sudah dihapus atau tidak tersedia.';
        } elseif ($accountMappingAmbiguous) {
            $row['status'] = 'ambiguous_account_mapping';
            $row['reason'] = 'Akun pembayaran mempunyai lebih dari satu mapping Kas/Bank Accounting.';
        } elseif ($glAccountIds === []) {
            $row['status'] = 'unmapped_account';
            $row['reason'] = 'Akun pembayaran belum memiliki mapping Kas/Bank Accounting yang eksplisit dan unik.';
        } elseif (strtolower((string) ($accountMatches[0]['primary_type'] ?? '')) !== 'asset') {
            $row['status'] = 'invalid_cash_bank_account';
            $row['reason'] = 'Akun pembayaran dipetakan ke CoA yang bukan bertipe Asset.';
        } elseif ($source['amount'] <= 0 || ! is_finite($source['amount'])) {
            $row['status'] = 'invalid_amount';
            $row['reason'] = 'Nominal movement tidak valid atau bernilai negatif.';
        } elseif ($source['sub_type'] === 'fund_transfer' || ($firstGroup && $firstGroup['internal_transfer'])) {
            $row['status'] = 'internal_transfer';
            $row['reason'] = 'Transfer antar akun Kas/Bank tidak mengubah saldo gabungan.';
        } elseif (count($candidateKeys) > 1) {
            $identityStatuses = array_map(function ($key) use ($groups) {
                return $groups[$key]['journal_identity_status'] ?? null;
            }, $candidateKeys);
            if (in_array('conflicting_journal_import', $identityStatuses, true)) {
                $row['status'] = 'conflicting_journal_import';
                $row['reason'] = 'Source key ditemukan pada Journal Import yang berbeda isinya: '.($firstGroup['journal_source_key'] ?? '');
            } elseif (in_array('duplicate_journal_import', $identityStatuses, true)) {
                $row['status'] = 'duplicate_journal_import';
                $row['reason'] = 'Grup Journal Import identik ditemukan lebih dari sekali untuk sumber yang sama.';
            } else {
                $row['status'] = $candidate['fallback'] ? 'ambiguous_match' : 'duplicate_posting';
                $row['reason'] = $candidate['fallback']
                    ? 'Lebih dari satu posting cocok dengan tanggal, referensi, dan nominal.'
                    : 'Sumber tertaut ke lebih dari satu grup posting Kas/Bank.';
            }
        } elseif ($candidateKeys === []) {
            $row['status'] = 'missing_posting';
            $row['reason'] = 'Movement operasional tidak memiliki posting pada akun Kas/Bank terpetakan.';
        } elseif ($firstGroup['non_asset_account']) {
            $row['status'] = 'invalid_cash_bank_account';
            $row['reason'] = 'Akun Kas/Bank yang dipetakan tidak bertipe Asset di CoA.';
        } elseif (! $this->compareMovement($sourceSignedAmount, $postingAmount)['matches']) {
            $row['status'] = 'amount_difference';
            $row['reason'] = 'Nominal movement berbeda dari posting Kas/Bank.';
        } elseif ($sourceVat !== null && $journalVat !== null && abs($row['vat_difference']) > self::AMOUNT_TOLERANCE) {
            $row['status'] = 'vat_difference';
            $row['reason'] = 'Nominal VAT sumber berbeda dari komponen VAT pada jurnal.';
        } elseif ($firstGroup && ($firstGroup['journal_identity_status'] ?? null) === 'conflicting_journal_import') {
            $row['status'] = 'conflicting_journal_import';
            $row['reason'] = 'Source key ditemukan pada Journal Import yang berbeda isinya: '.($firstGroup['journal_source_key'] ?? '');
        } elseif ($firstGroup && ($firstGroup['journal_identity_status'] ?? null) === 'duplicate_journal_import') {
            $row['status'] = 'duplicate_journal_import';
            $row['reason'] = 'Journal Import identik ditemukan lebih dari sekali untuk source key ini.';
        } elseif ($firstGroup && $firstGroup['vat_ambiguous']) {
            $row['status'] = 'vat_ambiguous';
            $row['reason'] = 'Arah akun VAT di jurnal tidak dapat ditentukan secara unik.';
        } elseif ($firstGroup && $firstGroup['journal_import_id'] !== null && $firstGroup['journal_category'] === '') {
            $row['status'] = 'classification_missing';
            $row['reason'] = 'Keterangan/detail pada Journal Import belum memiliki label sumber yang dapat dibaca.';
        }

        $row['in_cashflow'] = $glAccountIds !== []
            && strtolower((string) ($accountMatches[0]['primary_type'] ?? '')) === 'asset'
            && $source['amount'] > 0
            && is_finite($source['amount'])
            && ! in_array($row['status'], ['excluded_soft_deleted', 'excluded_cancelled', 'excluded_draft', 'excluded_non_cash', 'unmapped_account', 'ambiguous_account_mapping', 'invalid_amount', 'invalid_cash_bank_account', 'internal_transfer', 'duplicate_journal_import', 'conflicting_journal_import'], true)
            && $source['sub_type'] !== 'opening_balance';
        if ($firstGroup) {
            $row['detail_category'] = $row['detail_category'] !== '' ? $row['detail_category'] : $firstGroup['journal_category'];
            $row['journal_detail'] = $row['journal_detail'] !== '' ? $row['journal_detail'] : $firstGroup['journal_detail'];
            $row['source_type'] = $row['source_type'] !== '' ? $row['source_type'] : $firstGroup['source_type'];
            $row['posting_debit'] = $firstGroup['debit'];
            $row['posting_credit'] = $firstGroup['credit'];
        } else {
            $row['posting_debit'] = 0.0;
            $row['posting_credit'] = 0.0;
        }

        return $row;
    }

    private function findPostingCandidates(array $source, array $indexes, array $transactionSourceCounts, array $glAccountIds, array $groups): array
    {
        $candidateKeys = [];
        if ($source['source_kind'] === 'account_transaction') {
            $identity = $this->identityKey(AccountTransaction::class, $source['source_record_id']);
            $candidateKeys = $indexes['identity'][$identity] ?? [];
        }
        if ($candidateKeys === [] && ! empty($source['transaction_payment_id'])) {
            $candidateKeys = $indexes['payment'][(int) $source['transaction_payment_id']] ?? [];
        }
        if ($candidateKeys === [] && ! empty($source['transaction_id'])
            && ($transactionSourceCounts[(int) $source['transaction_id']] ?? 0) === 1) {
            $candidateKeys = $indexes['transaction'][(int) $source['transaction_id']] ?? [];
        }
        $fallback = false;
        if ($candidateKeys === []) {
            $fallbackKey = $this->fallbackKey($source['date'], $source['reference'], $this->sourceSignedAmount($source));
            if ($fallbackKey !== null && count($indexes['fallback'][$fallbackKey] ?? []) === 1) {
                $candidateKeys = $indexes['fallback'][$fallbackKey];
                $fallback = true;
            } elseif ($fallbackKey !== null && count($indexes['fallback'][$fallbackKey] ?? []) > 1) {
                $candidateKeys = $indexes['fallback'][$fallbackKey];
                $fallback = true;
            }
        }

        $candidateKeys = array_values(array_unique(array_filter($candidateKeys, function ($groupKey) use ($glAccountIds, $groups) {
            if ($glAccountIds === [] || empty($groups[$groupKey])) {
                return false;
            }
            $postedAccountIds = array_map('intval', array_column($groups[$groupKey]['accounts'], 'id'));

            return count(array_intersect($glAccountIds, $postedAccountIds)) > 0;
        })));

        return ['keys' => $candidateKeys, 'fallback' => $fallback];
    }

    private function identityKey(string $type, $id): string
    {
        return strtolower(ltrim($type, '\\')).':'.(int) $id;
    }

    private function pushIndex(array &$index, $key, string $groupKey): void
    {
        $index[$key][] = $groupKey;
    }

    private function fallbackKey(string $date, string $reference, float $signedAmount): ?string
    {
        $reference = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $reference)));
        if ($reference === '' || $date === '') {
            return null;
        }

        return $date.'|'.$reference.'|'.number_format($signedAmount, 4, '.', '');
    }

    private function sourceSignedAmount(array $source): float
    {
        if ($source['amount'] <= 0 || ! is_finite($source['amount'])) {
            return 0.0;
        }
        $type = strtolower((string) $source['movement_type']);
        if ($type === 'credit') {
            $sign = 1;
        } elseif ($type === 'debit') {
            $sign = -1;
        } else {
            $transactionType = strtolower((string) $source['transaction_type']);
            $sign = in_array($transactionType, ['sell', 'purchase_return', 'expense_refund', 'hms_booking', 'gym_subscription'], true) ? 1 : -1;
            if ($source['is_return']) {
                $sign *= -1;
            }
        }

        return round($source['amount'] * $sign, 4);
    }

    private function sourceHasFullTransactionPayment(array $source): bool
    {
        return $source['source_vat'] !== null && $source['transaction_total'] !== null
            && abs($source['amount'] - $source['transaction_total']) <= self::AMOUNT_TOLERANCE;
    }

    private function taxDirection(string $transactionType): ?string
    {
        if (in_array(strtolower($transactionType), ['purchase', 'purchase_return', 'expense', 'expense_refund'], true)) {
            return 'input';
        }
        if (in_array(strtolower($transactionType), ['sell', 'sell_return'], true)) {
            return 'output';
        }

        return null;
    }

    private function addJournalDetails(int $businessId, array &$groups, array $mappingIds): void
    {
        if ($mappingIds === [] || ! Schema::hasTable('accounting_journal_import_rows')
            || ! Schema::hasColumn('accounting_journal_import_rows', 'journal_mapping_id')) {
            return;
        }

        foreach (array_chunk($mappingIds, 400) as $chunk) {
            $select = ['journal_mapping_id', 'source_type', 'source_number', 'entry_data'];
            if (Schema::hasColumn('accounting_journal_import_rows', 'source_key')) {
                $select[] = 'source_key';
            }
            if (Schema::hasColumn('accounting_journal_import_rows', 'journal_import_id')) {
                $select[] = 'journal_import_id';
            }
            $rows = DB::table('accounting_journal_import_rows')
                ->where('business_id', $businessId)->whereIn('journal_mapping_id', $chunk)
                ->get($select);
            $identities = [];
            foreach ($rows as $row) {
                $key = 'mapping:'.(int) $row->journal_mapping_id;
                if (! isset($groups[$key])) {
                    continue;
                }
                $entry = json_decode((string) $row->entry_data, true);
                $lines = is_array($entry) ? (array) ($entry['lines'] ?? []) : [];
                $descriptions = [];
                foreach ($lines as $line) {
                    $label = trim((string) ($line['description'] ?? $line['category'] ?? ''));
                    if ($label !== '') {
                        $descriptions[] = $label;
                    }
                }
                $descriptions = array_values(array_unique($descriptions));
                $groups[$key]['source_type'] = trim((string) ($entry['source_type'] ?? $row->source_type));
                $groups[$key]['journal_category'] = $descriptions[0] ?? '';
                $groups[$key]['journal_detail'] = implode('; ', array_slice($descriptions, 1));
                $groups[$key]['journal_source_key'] = trim((string) ($row->source_key ?? ''));
                $groups[$key]['journal_import_id'] = empty($row->journal_import_id) ? null : (int) $row->journal_import_id;
                $groups[$key]['journal_identity_fingerprint'] = is_array($entry)
                    ? app(JournalImportIdentityService::class)->fingerprint($entry)
                    : null;
                if ($groups[$key]['reference'] === '') {
                    $groups[$key]['reference'] = trim((string) ($row->source_number ?? ''));
                }
                if ($groups[$key]['journal_source_key'] !== '') {
                    $identities[$groups[$key]['journal_source_key']][] = [
                        'group_key' => $key,
                        'import_id' => $groups[$key]['journal_import_id'],
                        'fingerprint' => $groups[$key]['journal_identity_fingerprint'],
                    ];
                }
            }
            foreach ($identities as $sourceKey => $matches) {
                $importIds = array_values(array_unique(array_filter(array_column($matches, 'import_id'))));
                if (count($importIds) < 2) {
                    continue;
                }
                $fingerprints = array_values(array_unique(array_filter(array_column($matches, 'fingerprint'))));
                $status = count($fingerprints) > 1 ? 'conflicting_journal_import' : 'duplicate_journal_import';
                foreach ($matches as $match) {
                    $groups[$match['group_key']]['journal_identity_status'] = $status;
                    $groups[$match['group_key']]['journal_source_key'] = $sourceKey;
                }
            }
        }
    }

    private function addTaxComponents(int $businessId, array &$groups, array $mappingIds): void
    {
        if ($mappingIds === []) {
            return;
        }

        foreach (array_chunk($mappingIds, 400) as $chunk) {
            $lineGroups = [];
            $lines = DB::table('accounting_accounts_transactions as aat')
                ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
                ->where('aa.business_id', $businessId)
                ->whereIn('aat.acc_trans_mapping_id', $chunk)
                ->groupBy('aat.acc_trans_mapping_id', 'aa.gl_code', 'aa.name')
                ->get([
                    'aat.acc_trans_mapping_id', 'aa.gl_code', 'aa.name',
                    DB::raw("SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE 0 END) as debit"),
                    DB::raw("SUM(CASE WHEN aat.type = 'credit' THEN aat.amount ELSE 0 END) as credit"),
                ]);
            foreach ($lines as $line) {
                $lineGroups[(int) $line->acc_trans_mapping_id][] = [
                    'account_no' => (string) ($line->gl_code ?? ''), 'account_name' => (string) $line->name,
                    'debit' => (float) $line->debit, 'credit' => (float) $line->credit,
                ];
            }
            foreach ($lineGroups as $mappingId => $accountLines) {
                $components = $this->accountComponentService->groupJournalLines(['lines' => $accountLines], $businessId);
                $key = 'mapping:'.$mappingId;
                foreach ($components as $component) {
                    if (empty($component['is_tax'])) {
                        continue;
                    }
                    if (! empty($component['tax_direction_conflict']) || empty($component['tax_direction'])) {
                        $groups[$key]['vat_ambiguous'] = true;
                    } elseif ($component['tax_direction'] === 'input') {
                        $groups[$key]['vat_in'] += (float) $component['debit'] + (float) $component['credit'];
                    } elseif ($component['tax_direction'] === 'output') {
                        $groups[$key]['vat_out'] += (float) $component['debit'] + (float) $component['credit'];
                    }
                }
            }
        }
    }

    private function presentUnmatchedPosting(array $group): array
    {
        $accounts = array_map(function ($account) {
            return trim(($account['gl_code'] !== '' ? $account['gl_code'].' - ' : '').$account['name']);
        }, $group['accounts']);

        return [
            'date' => $group['date'], 'source_type' => $group['source_type'] ?: $group['mapping_type'] ?: 'Posting Accounting',
            'source_number' => $group['reference'], 'description' => $group['note'],
            'category' => $group['journal_import_id'] !== null ? 'Journal Import' : 'Accounting',
            'detail_category' => $group['journal_category'], 'journal_detail' => $group['journal_detail'], 'contact' => '',
            'payment_account' => implode(', ', $accounts), 'account_id' => null, 'operational_amount' => null,
            'accounting_amount' => $group['movement'], 'difference' => null, 'source_vat' => null,
            'journal_vat' => $group['vat_in'] + $group['vat_out'], 'vat_difference' => null,
            'status' => $group['journal_identity_status']
                ?? ($group['non_asset_account'] ? 'invalid_cash_bank_account' : 'missing_source'),
            'reason' => ($group['journal_identity_status'] ?? null) === 'conflicting_journal_import'
                ? 'Source key ditemukan pada Journal Import yang berbeda isinya: '.($group['journal_source_key'] ?? '')
                : ((($group['journal_identity_status'] ?? null) === 'duplicate_journal_import')
                    ? 'Journal Import identik ditemukan lebih dari sekali untuk source key ini.'
                    : ($group['non_asset_account']
                        ? 'Akun Kas/Bank yang dipetakan tidak bertipe Asset di CoA.'
                        : 'Posting Kas/Bank tidak memiliki movement operasional yang cocok secara unik.')),
            'source_record_type' => $group['source_record_type'], 'source_record_id' => $group['source_record_id'],
            'posting_count' => $group['entry_count'], 'in_cashflow' => false,
            'posting_debit' => $group['debit'], 'posting_credit' => $group['credit'], 'sort_key' => $group['sort_key'],
        ];
    }

    private function presentInternalTransfer(array $group): array
    {
        $row = $this->presentUnmatchedPosting($group);
        $row['category'] = 'Transfer Antar Rekening';
        $row['status'] = 'internal_transfer';
        $row['reason'] = 'Debit dan kredit antar akun Kas/Bank seimbang; tidak mengubah saldo gabungan.';

        return $row;
    }

    private function emptyTotals(): array
    {
        return [
            'source_count' => 0, 'posting_group_count' => 0, 'source_inflow' => 0.0, 'source_outflow' => 0.0,
            'gl_inflow' => 0.0, 'gl_outflow' => 0.0, 'debit_total' => 0.0, 'credit_total' => 0.0,
            'source_vat_in' => 0.0, 'source_vat_out' => 0.0, 'journal_vat_in' => 0.0, 'journal_vat_out' => 0.0,
            'opening_balance' => 0.0, 'source_opening_balance' => 0.0, 'opening_balance_difference' => 0.0,
            'closing_balance' => 0.0, 'source_closing_balance' => 0.0, 'closing_balance_difference' => 0.0,
            'missing_posting_count' => 0, 'missing_source_count' => 0, 'duplicate_count' => 0,
            'amount_difference_count' => 0, 'vat_difference_count' => 0,
            'journal_import_duplicate_count' => 0, 'journal_import_conflict_count' => 0,
            'exception_count' => 0, 'status_counts' => [],
        ];
    }

    private function addSourceTotals(array &$totals, array $row): void
    {
        if (! $row['in_cashflow']) {
            return;
        }
        $amount = (float) $row['operational_amount'];
        if ($amount >= 0) {
            $totals['source_inflow'] += $amount;
        } else {
            $totals['source_outflow'] += abs($amount);
        }
        if ($row['source_vat'] !== null) {
            $direction = $row['_tax_direction'] ?? null;
            if ($direction === 'input') {
                $totals['source_vat_in'] += (float) $row['source_vat'];
            } elseif ($direction === 'output') {
                $totals['source_vat_out'] += (float) $row['source_vat'];
            }
        }
    }

    private function openingBalances(int $businessId, string $startDate, $mappedAccounts, array $accountCrosswalk, ?int $selectedMainAccountId): array
    {
        $glAccountIds = $mappedAccounts->keys()->map(fn ($id) => (int) $id)->all();
        if ($selectedMainAccountId !== null) {
            $crosswalk = $accountCrosswalk[$selectedMainAccountId] ?? [];
            $glAccountIds = count($crosswalk) === 1 ? array_column($crosswalk, 'id') : [];
        }
        $gl = 0.0;
        if ($glAccountIds !== []) {
            $query = DB::table('accounting_accounts_transactions as aat')
                ->join('accounting_accounts as aa', 'aa.id', '=', 'aat.accounting_account_id')
                ->where('aa.business_id', $businessId)->whereIn('aa.id', $glAccountIds)
                ->where('aat.operation_date', '<', $startDate.' 00:00:00');
            if (Schema::hasTable('accounting_journal_imports') && Schema::hasColumn('accounting_accounts_transactions', 'journal_import_id')) {
                $query->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))->from('accounting_journal_imports as reverted_import')
                        ->whereColumn('reverted_import.id', 'aat.journal_import_id')->where('reverted_import.status', 'reverted');
                });
            }
            $gl = (float) $query->selectRaw("COALESCE(SUM(CASE WHEN aat.type = 'debit' THEN aat.amount ELSE -aat.amount END), 0) as balance")->value('balance');
        }

        $mainAccountIds = [];
        foreach ($accountCrosswalk as $mainId => $crosswalk) {
            if (count($crosswalk) === 1) {
                $mainAccountIds[] = (int) $mainId;
            }
        }
        if ($selectedMainAccountId !== null) {
            $mainAccountIds = isset($accountCrosswalk[$selectedMainAccountId]) && count($accountCrosswalk[$selectedMainAccountId]) === 1
                ? [$selectedMainAccountId]
                : [];
        }
        $source = 0.0;
        if ($mainAccountIds !== [] && Schema::hasTable('account_transactions')) {
            $source = (float) DB::table('account_transactions as at')
                ->join('accounts as account', function ($join) use ($businessId) {
                    $join->on('account.id', '=', 'at.account_id')->where('account.business_id', '=', $businessId);
                })
                ->whereIn('at.account_id', $mainAccountIds)->whereNull('at.deleted_at')
                ->where('at.operation_date', '<', $startDate.' 00:00:00')
                ->selectRaw("COALESCE(SUM(CASE WHEN at.type = 'credit' THEN at.amount ELSE -at.amount END), 0) as balance")
                ->value('balance');
        }

        return ['gl' => round($gl, 4), 'source' => round($source, 4)];
    }

    private function filterRows(array $rows, array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        return array_values(array_filter($rows, function ($row) use ($filters, $search) {
            if (! empty($filters['account_id']) && (int) ($row['account_id'] ?? 0) !== (int) $filters['account_id']) {
                return false;
            }
            if (! empty($filters['source_type']) && $row['source_type'] !== $filters['source_type']) {
                return false;
            }
            if (! empty($filters['status'])) {
                $excluded = strpos($row['status'], 'excluded_') === 0;
                $exception = ! in_array($row['status'], ['matched', 'internal_transfer'], true) && ! $excluded;
                if (($filters['status'] === 'matched' && $row['status'] !== 'matched')
                    || ($filters['status'] === 'exception' && ! $exception)
                    || ($filters['status'] === 'excluded' && ! $excluded)
                    || ($filters['status'] === 'internal_transfer' && $row['status'] !== 'internal_transfer')) {
                    return false;
                }
            }
            if (! empty($filters['reason']) && $row['status'] !== $filters['reason']) {
                return false;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    $row['date'], $row['source_type'], $row['source_number'], $row['description'], $row['category'],
                    $row['detail_category'], $row['journal_detail'], $row['contact'], $row['payment_account'], $row['reason'],
                ]));
                if (mb_strpos($haystack, $search) === false) {
                    return false;
                }
            }

            return true;
        }));
    }
}
