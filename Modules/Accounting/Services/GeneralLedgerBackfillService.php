<?php

namespace Modules\Accounting\Services;

use App\AccountTransaction;
use App\Transaction;
use App\TransactionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneralLedgerBackfillService
{
    private $accountComponentService;

    private $mainAccountCache = [];

    private $accountingAccountsCache = [];

    private $locationMapsCache = [];

    private $expenseCategoriesCache = [];

    private $expensePaymentGroupsCache = [];

    private $reviewQueueBuffer = [];

    private const REVIEW_QUEUE_TABLE = 'accounting_general_ledger_backfill_reviews';

    public function __construct(JournalImportAccountComponentService $accountComponentService)
    {
        $this->accountComponentService = $accountComponentService;
    }

    public function run(int $businessId, bool $apply = false): array
    {
        $this->reviewQueueBuffer = [];

        $business = DB::table('business')->where('id', $businessId)->first(['id', 'name']);
        if (! $business) {
            return ['error' => 'Business ID tidak ditemukan.'];
        }

        if (! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_type')
            || ! Schema::hasColumn('accounting_acc_trans_mappings', 'source_record_id')) {
            return ['error' => 'Migrasi source link Journal belum tersedia. Jalankan migration aplikasi terlebih dahulu.'];
        }

        if ($apply && ! Schema::hasTable(self::REVIEW_QUEUE_TABLE)) {
            return ['error' => 'Migrasi antrean review backfill belum tersedia. Jalankan migration aplikasi terlebih dahulu.'];
        }

        $this->accountingAccountsCache[$businessId] = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->all();

        $this->locationMapsCache[$businessId] = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->get(['id', 'accounting_default_map'])
            ->mapWithKeys(function ($location) {
                $mapping = json_decode((string) $location->accounting_default_map, true);

                return [(int) $location->id => is_array($mapping) ? $mapping : []];
            })
            ->all();
        $this->expenseCategoriesCache[$businessId] = Schema::hasTable('expense_categories')
            ? DB::table('expense_categories')
                ->where('business_id', $businessId)
                ->when(Schema::hasColumn('expense_categories', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->get(['id', 'name'])
                ->keyBy('id')
                ->all()
            : [];
        $this->expensePaymentGroupsCache[$businessId] = $this->loadExpensePaymentGroups($businessId);

        $summary = [
            'business_name' => (string) $business->name,
            'candidate_groups' => 0,
            'planned_groups' => 0,
            'applied_groups' => 0,
            'already_linked' => 0,
            'planned_rows' => 0,
            'account_links' => [],
            'current_map_details' => [],
            'current_map_groups' => 0,
            'auto_map_details' => [],
            'auto_mapped_groups' => 0,
            'ambiguous_vat_accounts' => 0,
            'missing_vat_accounts' => 0,
            'skipped' => [],
            'review_source_keys' => [],
            'debit_total' => 0.0,
            'credit_total' => 0.0,
            'date_start' => null,
            'date_end' => null,
        ];
        $summary['account_mapping_audit'] = $this->auditMainAccountMappings($businessId);

        foreach ($this->transactionCandidates($businessId) as $candidate) {
            $this->processCandidate($candidate, $businessId, $apply, $summary);
        }

        foreach ($this->paymentCandidates($businessId) as $candidate) {
            $this->processCandidate($candidate, $businessId, $apply, $summary);
        }

        foreach ($this->bankTransferCandidates($businessId) as $candidate) {
            $this->processCandidate($candidate, $businessId, $apply, $summary);
        }

        foreach ($this->unpairedMainAccountMovements($businessId) as $reason => $count) {
            if ($count > 0) {
                $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + $count;
            }
        }

        $this->reviewUnpairedMainAccountMovements($businessId, $apply, $summary);
        $this->reviewInvalidTransfers($businessId, $apply, $summary);
        if ($apply) {
            $this->flushReviewQueue();
        }

        $summary['account_links'] = array_values($summary['account_links']);
        $summary['current_map_details'] = array_values($summary['current_map_details']);
        $summary['auto_map_details'] = array_values($summary['auto_map_details']);
        $summary['actionable_review_sources'] = count($summary['review_source_keys']);
        unset($summary['review_source_keys']);
        $summary['date_range'] = $summary['date_start'] === null
            ? '—'
            : $summary['date_start'].' — '.$summary['date_end'];
        unset($summary['date_start'], $summary['date_end']);

        return $summary;
    }

    private function auditMainAccountMappings(int $businessId): array
    {
        $query = DB::table('accounts')->where('business_id', $businessId)->orderBy('id');
        if (Schema::hasColumn('accounts', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $audit = [
            'account_id_matches' => 0,
            'exact_name_matches' => 0,
            'ambiguous_account_id' => 0,
            'ambiguous_exact_name' => 0,
            'conflicting_crosswalk' => 0,
            'nearest_matches' => 0,
            'unmatched' => 0,
            'matches' => [],
            'nearest_suggestions' => [],
        ];

        foreach ($query->get(['id', 'name']) as $mainAccount) {
            $accounts = $this->accountingAccountsCache[$businessId] ?? [];
            $direct = array_values(array_filter(
                $accounts,
                fn ($account) => (int) ($account->account_id ?? 0) === (int) $mainAccount->id
            ));

            if (count($direct) > 1) {
                $audit['ambiguous_account_id']++;
                continue;
            }
            if (count($direct) === 1) {
                $audit['account_id_matches']++;
                $audit['matches'][] = $this->accountMappingMatch($mainAccount, $direct[0], 'account_id');
                continue;
            }

            $nameMatches = array_values(array_filter(
                $accounts,
                fn ($account) => (string) $account->name === (string) $mainAccount->name
            ));
            if (count($nameMatches) > 1) {
                $audit['ambiguous_exact_name']++;
                continue;
            }
            if ($nameMatches === []) {
                $audit['unmatched']++;
                continue;
            }
            if (! empty($nameMatches[0]->account_id) && (int) $nameMatches[0]->account_id !== (int) $mainAccount->id) {
                $audit['conflicting_crosswalk']++;
                continue;
            }

            $audit['exact_name_matches']++;
            $audit['matches'][] = $this->accountMappingMatch($mainAccount, $nameMatches[0], 'exact_name');
        }

        foreach ($query->get(['id', 'name']) as $mainAccount) {
            $resolved = $this->mainAccountMapping($businessId, (int) $mainAccount->id);
            if (empty($resolved['automatic_nearest'])) {
                continue;
            }
            $audit['nearest_matches']++;
            $audit['nearest_suggestions'][] = $this->accountMappingMatch(
                $mainAccount,
                $resolved['account'],
                $resolved['method'] ?? 'nearest_name'
            ) + ['score' => $resolved['score']];
        }

        $audit['unmatched'] = max(0, $audit['unmatched'] - $audit['nearest_matches']);

        return $audit;
    }

    private function accountMappingMatch(object $mainAccount, object $accountingAccount, string $method): array
    {
        return [
            'account_id' => (int) $mainAccount->id,
            'account_name' => (string) $mainAccount->name,
            'accounting_account_id' => (int) $accountingAccount->id,
            'accounting_name' => (string) $accountingAccount->name,
            'method' => $method,
        ];
    }

    private function transactionCandidates(int $businessId): \Generator
    {
        $query = DB::table('transactions')
            ->where('business_id', $businessId)
            ->whereIn('type', ['sell', 'purchase', 'expense'])
            ->orderBy('id');

        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        foreach ($query->cursor() as $transaction) {
            if (! $this->isPostedTransaction($transaction)) {
                yield $this->transactionSkipCandidate($transaction, 'transaksi_bukan_posting', $businessId);
                continue;
            }

            $type = (string) $transaction->type;
            $locationMap = $this->locationMap($businessId, (int) ($transaction->location_id ?? 0));
            $mappingKey = $type === 'sell' ? 'sale' : ($type === 'purchase' ? 'purchases' : 'expense');
            if ($type === 'expense' && ! empty($transaction->expense_category_id)) {
                $mappingKey = 'expense_'.$transaction->expense_category_id;
            }
            $accountMap = $locationMap[$mappingKey] ?? [];
            $pair = $this->resolveAccountingPair($businessId, $accountMap);
            $autoMap = null;
            if (! empty($pair['reason']) && in_array($type, ['sell', 'purchase'], true)) {
                $autoMap = $this->inferTransactionPair($businessId, $type);
                if (empty($autoMap['reason'])) {
                    $pair = $autoMap['pair'];
                }
            } elseif (! empty($pair['reason']) && $type === 'expense') {
                $autoMap = $this->inferExpensePair($businessId, $transaction);
                if (empty($autoMap['reason'])) {
                    $pair = $autoMap['pair'];
                }
            }
            if (! empty($pair['reason'])) {
                yield $this->transactionSkipCandidate($transaction, (string) $pair['reason'], $businessId);
                continue;
            }

            $total = round((float) ($transaction->final_total ?? 0), 4);
            $tax = round((float) ($transaction->tax_amount ?? 0), 4);
            if (! is_finite($total) || ! is_finite($tax) || $total <= 0 || $tax < 0 || $tax > $total) {
                yield $this->transactionSkipCandidate($transaction, 'nominal_transaksi_tidak_valid', $businessId);
                continue;
            }

            $base = round($total - $tax, 4);
            $lines = [];
            if ($type === 'sell') {
                $lines[] = $this->line((int) $pair['deposit_to']->id, 'debit', $total);
                if ($base > 0) {
                    $lines[] = $this->line((int) $pair['payment_account']->id, 'credit', $base);
                }
                if ($tax > 0) {
                    $vat = $this->resolveVatAccount($businessId, 'output');
                    if (! empty($vat['reason'])) {
                        yield $this->transactionSkipCandidate($transaction, (string) $vat['reason'], $businessId);
                        continue;
                    }
                    $lines[] = $this->line((int) $vat['account']->id, 'credit', $tax);
                }
            } elseif ($type === 'purchase') {
                if ($base > 0) {
                    $lines[] = $this->line((int) $pair['deposit_to']->id, 'debit', $base);
                }
                if ($tax > 0) {
                    $vat = $this->resolveVatAccount($businessId, 'input');
                    if (! empty($vat['reason'])) {
                        yield $this->transactionSkipCandidate($transaction, (string) $vat['reason'], $businessId);
                        continue;
                    }
                    $lines[] = $this->line((int) $vat['account']->id, 'debit', $tax);
                }
                $lines[] = $this->line((int) $pair['payment_account']->id, 'credit', $total);
            } else {
                if ($base > 0) {
                    $lines[] = $this->line((int) $pair['deposit_to']->id, 'debit', $base);
                }
                if ($tax > 0) {
                    $vat = $this->resolveVatAccount($businessId, 'input');
                    if (! empty($vat['reason'])) {
                        yield $this->transactionSkipCandidate($transaction, (string) $vat['reason'], $businessId);
                        continue;
                    }
                    $lines[] = $this->line((int) $vat['account']->id, 'debit', $tax);
                }
                if ($autoMap !== null && ! empty($autoMap['payment_lines'])) {
                    foreach ($autoMap['payment_lines'] as $paymentLine) {
                        $lines[] = $paymentLine;
                    }
                } else {
                    $lines[] = $this->line((int) $pair['payment_account']->id, 'credit', $total);
                }
            }

            $date = (string) ($transaction->transaction_date ?? '');
            $candidate = $this->group(
                $businessId,
                Transaction::class,
                (int) $transaction->id,
                $type,
                $date,
                (int) ($transaction->created_by ?? 1),
                (string) ($transaction->invoice_no ?: $transaction->ref_no ?: strtoupper($type).'-'.$transaction->id),
                $autoMap === null
                    ? 'Backfill dari transaksi '.$type.' #'.$transaction->id.' dengan mapping lokasi saat ini.'
                    : 'Backfill dari transaksi '.$type.' #'.$transaction->id.' dengan pemetaan CoA terdekat otomatis.',
                $lines,
                $autoMap === null,
                (int) $transaction->id,
                null,
                $autoMap['account_links'] ?? [],
                $tax > 0,
                null,
                $this->mapInfo(
                    (int) ($transaction->location_id ?? 0),
                    $mappingKey,
                    $pair,
                    $autoMap === null ? 'location' : 'automatic_nearest',
                    $autoMap['payment_account_score'] ?? null,
                    $autoMap['deposit_to_score'] ?? null,
                    $autoMap['payment_account_method'] ?? null,
                    $autoMap['deposit_to_method'] ?? null
                )
            );
            $candidate['uses_auto_map'] = $autoMap !== null;
            yield $candidate;
        }
    }

    private function paymentCandidates(int $businessId): \Generator
    {
        $query = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->where('transaction.business_id', $businessId)
            ->where('payment.business_id', $businessId)
            ->whereIn('transaction.type', ['sell', 'purchase'])
            ->orderBy('payment.id')
            ->select(
                'payment.id as payment_id',
                'payment.transaction_id',
                'payment.account_id as payment_account_id',
                'payment.amount as payment_amount',
                'payment.paid_on',
                'payment.created_by as payment_created_by',
                'payment.is_return',
                'payment.is_advance',
                'transaction.business_id',
                'transaction.location_id',
                'transaction.type as transaction_type',
                'transaction.status as transaction_status',
                'transaction.invoice_no',
                'transaction.ref_no'
            );

        if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
            $query->whereNull('payment.deleted_at');
        }
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('transaction.deleted_at');
        }
        if (Schema::hasColumn('transaction_payments', 'is_advance')) {
            $query->where('payment.is_advance', 0);
        }
        if (Schema::hasColumn('transaction_payments', 'is_return')) {
            $query->where('payment.is_return', 0);
        }

        foreach ($query->cursor() as $payment) {
            $type = (string) $payment->transaction_type;
            if (! $this->isPostedTransaction((object) [
                'type' => $type,
                'status' => $payment->transaction_status,
            ])) {
                yield $this->paymentSkipCandidate($payment, 'transaksi_bukan_posting', $businessId);
                continue;
            }
            $mainAccountId = (int) ($payment->payment_account_id ?? 0);
            if ($mainAccountId < 1) {
                yield $this->paymentSkipCandidate($payment, 'akun_pembayaran_operasional_kosong', $businessId);
                continue;
            }

            $sourceAccount = $this->mainAccountMapping($businessId, $mainAccountId);
            if (! empty($sourceAccount['reason'])) {
                yield $this->paymentSkipCandidate($payment, (string) $sourceAccount['reason'], $businessId);
                continue;
            }

            $mapType = $type === 'sell' ? 'sell_payment' : 'purchase_payment';
            $locationMap = $this->locationMap($businessId, (int) ($payment->location_id ?? 0));
            $map = $locationMap[$mapType] ?? [];
            $counterpartId = (int) ($type === 'sell'
                ? ($map['payment_account'] ?? 0)
                : ($map['deposit_to'] ?? 0));
            $counterpart = $counterpartId > 0
                ? collect($this->accountingAccountsCache[$businessId] ?? [])->firstWhere('id', $counterpartId)
                : null;
            $autoMap = null;
            if (! $counterpart) {
                $autoMap = $this->inferOperationalRoleAccount($businessId, $type === 'sell' ? 'receivable' : 'payable');
                $counterpart = $autoMap['account'] ?? null;
            }
            if (! $counterpart) {
                yield $this->paymentSkipCandidate($payment, (string) ($autoMap['reason'] ?? ($counterpartId < 1
                    ? 'mapping_akun_lawan_tidak_lengkap'
                    : 'akun_lawan_tidak_ada_di_business')), $businessId);
                continue;
            }
            if ((int) $counterpart->id === (int) $sourceAccount['account']->id) {
                yield $this->paymentSkipCandidate($payment, 'akun_pembayaran_sama_dengan_akun_lawan', $businessId);
                continue;
            }

            $cashAccount = $sourceAccount['account'];
            if ($type === 'sell') {
                $pair = ['payment_account' => $counterpart, 'deposit_to' => $cashAccount];
                $lines = [
                    $this->line((int) $cashAccount->id, 'debit', (float) $payment->payment_amount),
                    $this->line((int) $counterpart->id, 'credit', (float) $payment->payment_amount),
                ];
            } else {
                $pair = ['payment_account' => $cashAccount, 'deposit_to' => $counterpart];
                $lines = [
                    $this->line((int) $counterpart->id, 'debit', (float) $payment->payment_amount),
                    $this->line((int) $cashAccount->id, 'credit', (float) $payment->payment_amount),
                ];
            }
            $links = $sourceAccount['crosswalk'] ? [$sourceAccount['crosswalk']] : [];
            $usesAutoMap = $autoMap !== null || ! empty($sourceAccount['automatic_nearest']);
            $paymentScore = $autoMap !== null && $type === 'sell'
                ? $autoMap['score']
                : ($type === 'purchase' && ! empty($sourceAccount['automatic_nearest']) ? $sourceAccount['score'] : null);
            $depositScore = $autoMap !== null && $type === 'purchase'
                ? $autoMap['score']
                : ($type === 'sell' && ! empty($sourceAccount['automatic_nearest']) ? $sourceAccount['score'] : null);
            $mappingNote = 'Akun kas/bank dari transaction_payments.account_id; akun lawan dari mapping lokasi saat ini.';
            if ($autoMap !== null) {
                $mappingNote = 'Akun lawan '.$autoMap['source_name'].' dipetakan otomatis ke CoA terdekat.';
            }
            if (! empty($sourceAccount['automatic_nearest'])) {
                $mappingNote .= ' Akun operasional '.$sourceAccount['source_name'].' dipetakan ke CoA terdekat dengan skor '.number_format((float) $sourceAccount['score'], 4, '.', '').'.';
            }
            $candidate = $this->group(
                $businessId,
                TransactionPayment::class,
                (int) $payment->payment_id,
                $mapType,
                (string) $payment->paid_on,
                (int) ($payment->payment_created_by ?: 1),
                (string) ($payment->ref_no ?: $payment->invoice_no ?: 'PAYMENT-'.$payment->payment_id),
                $mappingNote,
                $lines,
                ! $usesAutoMap,
                null,
                (int) $payment->payment_id,
                $links,
                false,
                null,
                $this->mapInfo(
                    (int) ($payment->location_id ?? 0),
                    $mapType,
                    $pair,
                    $usesAutoMap ? 'automatic_nearest' : 'location',
                    $paymentScore,
                    $depositScore,
                    $type === 'sell'
                        ? ($autoMap['method'] ?? null)
                        : ($sourceAccount['method'] ?? null),
                    $type === 'purchase'
                        ? ($autoMap['method'] ?? null)
                        : ($sourceAccount['method'] ?? null)
                )
            );
            $candidate['uses_auto_map'] = $usesAutoMap;
            yield $candidate;
        }
    }

    private function bankTransferCandidates(int $businessId): \Generator
    {
        $query = DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->join('account_transactions as counterpart', 'counterpart.id', '=', 'source.transfer_transaction_id')
            ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
            ->where('source_account.business_id', $businessId)
            ->where('counterpart_account.business_id', $businessId)
            ->whereNull('source_account.deleted_at')
            ->whereNull('counterpart_account.deleted_at')
            ->whereColumn('source.id', '<', 'counterpart.id')
            ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->whereColumn('source.sub_type', 'counterpart.sub_type')
            ->whereNull('source.deleted_at')
            ->whereNull('counterpart.deleted_at')
            ->orderBy('source.id')
            ->select(
                'source_account.business_id as business_id',
                'source.id as source_id',
                'source.account_id as source_account_id',
                'source.type as source_type',
                'source.sub_type as source_sub_type',
                'source.amount as source_amount',
                'source.operation_date as source_operation_date',
                'source.created_by as source_created_by',
                'source.note as source_note',
                'counterpart.id as counterpart_id',
                'counterpart.account_id as counterpart_account_id',
                'counterpart.type as counterpart_type',
                'counterpart.amount as counterpart_amount',
                'counterpart.operation_date as counterpart_operation_date'
            );

        foreach ($query->cursor() as $transfer) {
            if ((string) $transfer->source_type === (string) $transfer->counterpart_type
                || round((float) $transfer->source_amount, 4) !== round((float) $transfer->counterpart_amount, 4)
                || round((float) $transfer->source_amount, 4) <= 0) {
                yield $this->transferSkipCandidate($transfer, 'transfer_tidak_seimbang', $businessId);
                continue;
            }

            $sourceAccount = $this->mainAccountMapping($businessId, (int) $transfer->source_account_id);
            $counterpartAccount = $this->mainAccountMapping($businessId, (int) $transfer->counterpart_account_id);
            if (! empty($sourceAccount['reason']) || ! empty($counterpartAccount['reason'])) {
                yield $this->transferSkipCandidate(
                    $transfer,
                    (string) ($sourceAccount['reason'] ?? $counterpartAccount['reason']),
                    $businessId
                );
                continue;
            }

            $sourceType = $transfer->source_type === 'credit' ? 'debit' : 'credit';
            $counterpartType = $transfer->counterpart_type === 'credit' ? 'debit' : 'credit';
            $links = array_values(array_filter([
                $sourceAccount['crosswalk'],
                $counterpartAccount['crosswalk'],
            ]));
            yield $this->group(
                $businessId,
                AccountTransaction::class,
                (int) $transfer->source_id,
                (string) $transfer->source_sub_type,
                (string) $transfer->source_operation_date,
                (int) ($transfer->source_created_by ?: 1),
                'BANK-'.$transfer->source_id.'-'.$transfer->counterpart_id,
                'Backfill transfer rekening #'.$transfer->source_id.' dan #'.$transfer->counterpart_id.'.',
                [
                    $this->line((int) $sourceAccount['account']->id, $sourceType, (float) $transfer->source_amount),
                    $this->line((int) $counterpartAccount['account']->id, $counterpartType, (float) $transfer->counterpart_amount),
                ],
                false,
                null,
                null,
                $links,
                false,
                (int) $transfer->source_id
            );
        }
    }

    private function unpairedMainAccountMovements(int $businessId): array
    {
        $query = $this->unpairedMainAccountMovementQuery($businessId);

        $openingBalances = (clone $query)->where('source.sub_type', 'opening_balance')->count();
        $unpaired = (clone $query)->where(function ($query) {
            $query->whereNull('source.sub_type')->orWhereNotIn('source.sub_type', ['opening_balance']);
        })->count();

        return [
            'saldo_awal_rekening_tidak_disalin' => $openingBalances,
            'pergerakan_rekening_tanpa_akun_lawan' => $unpaired,
            'pasangan_transfer_tidak_valid' => $this->invalidTransferCount($businessId),
        ];
    }

    private function unpairedMainAccountMovementQuery(int $businessId)
    {
        $query = DB::table('account_transactions as source')
            ->join('accounts', 'accounts.id', '=', 'source.account_id')
            ->where('accounts.business_id', $businessId)
            ->whereNull('accounts.deleted_at')
            ->whereNull('source.deleted_at')
            ->whereNull('source.transaction_payment_id')
            ->whereNull('source.transfer_transaction_id');

        $representedTransactionIds = DB::table('transactions as represented')
            ->where('represented.business_id', $businessId)
            ->whereIn('represented.type', ['sell', 'purchase', 'expense']);
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $representedTransactionIds->whereNull('represented.deleted_at');
        }
        $query->where(function ($query) use ($representedTransactionIds) {
            $query->whereNull('source.transaction_id')
                ->orWhereNotIn('source.transaction_id', (clone $representedTransactionIds)->select('represented.id'));
        });

        return $query->select(
            'source.id as source_id',
            'source.account_id',
            'source.type as movement_type',
            'source.sub_type',
            'source.amount',
            'source.operation_date',
            'source.transaction_id',
            'source.note'
        );
    }

    private function reviewUnpairedMainAccountMovements(int $businessId, bool $apply, array &$summary): void
    {
        foreach ($this->unpairedMainAccountMovementQuery($businessId)->orderBy('source.id')->cursor() as $movement) {
            $reason = (string) $movement->sub_type === 'opening_balance'
                ? 'saldo_awal_rekening_tidak_disalin'
                : 'pergerakan_rekening_tanpa_akun_lawan';
            $label = $reason === 'saldo_awal_rekening_tidak_disalin' ? 'Saldo awal rekening' : 'Mutasi rekening tanpa akun lawan';
            $summaryText = trim((string) ($movement->note ?? ''));
            if ($summaryText === '') {
                $summaryText = $label.' #'.$movement->source_id;
            }

            $this->registerReviewSource([
                'business_id' => $businessId,
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => (int) $movement->source_id,
                'operation_date' => (string) $movement->operation_date,
                'source_reference' => 'ACCOUNT-TRANSACTION-'.$movement->source_id,
                'source_summary' => mb_substr($summaryText, 0, 1000),
                'source_context' => [
                    'account_id' => (int) $movement->account_id,
                    'movement_type' => (string) $movement->movement_type,
                    'sub_type' => $movement->sub_type,
                    'amount' => (float) $movement->amount,
                    'transaction_id' => $movement->transaction_id === null ? null : (int) $movement->transaction_id,
                ],
                'skip_reason' => $reason,
            ], $apply, $summary);
        }
    }

    private function reviewInvalidTransfers(int $businessId, bool $apply, array &$summary): void
    {
        $query = DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->where('source_account.business_id', $businessId)
            ->whereNull('source_account.deleted_at')
            ->whereNotNull('source.transfer_transaction_id')
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->whereNull('source.deleted_at')
            ->whereNotExists(function ($query) use ($businessId) {
                $query->select(DB::raw(1))
                    ->from('account_transactions as counterpart')
                    ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
                    ->where('counterpart_account.business_id', $businessId)
                    ->whereNull('counterpart_account.deleted_at')
                    ->whereColumn('counterpart.id', 'source.transfer_transaction_id')
                    ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
                    ->whereColumn('counterpart.sub_type', 'source.sub_type')
                    ->whereColumn('counterpart.amount', 'source.amount')
                    ->whereColumn('counterpart.type', '<>', 'source.type')
                    ->whereNull('counterpart.deleted_at');
            })
            ->orderBy('source.id')
            ->select(
                'source_account.business_id as business_id',
                'source.id as source_id',
                'source.account_id',
                'source.type as movement_type',
                'source.sub_type',
                'source.amount',
                'source.operation_date',
                'source.transfer_transaction_id',
                'source.note'
            );

        foreach ($query->cursor() as $transfer) {
            $summaryText = trim((string) ($transfer->note ?? ''));
            if ($summaryText === '') {
                $summaryText = 'Transfer rekening #'.$transfer->source_id.' dengan pasangan #'.$transfer->transfer_transaction_id;
            }

            $this->registerReviewSource([
                'business_id' => $businessId,
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => (int) $transfer->source_id,
                'operation_date' => (string) $transfer->operation_date,
                'source_reference' => 'ACCOUNT-TRANSFER-'.$transfer->source_id,
                'source_summary' => mb_substr($summaryText, 0, 1000),
                'source_context' => [
                    'account_id' => (int) $transfer->account_id,
                    'movement_type' => (string) $transfer->movement_type,
                    'sub_type' => (string) $transfer->sub_type,
                    'amount' => (float) $transfer->amount,
                    'counterpart_id' => (int) $transfer->transfer_transaction_id,
                ],
                'skip_reason' => 'pasangan_transfer_tidak_valid',
            ], $apply, $summary);
        }
    }

    private function invalidTransferCount(int $businessId): int
    {
        return DB::table('account_transactions as source')
            ->join('accounts as source_account', 'source_account.id', '=', 'source.account_id')
            ->whereNull('source_account.deleted_at')
            ->where('source_account.business_id', $businessId)
            ->whereIn('source.sub_type', ['fund_transfer', 'deposit'])
            ->whereNotNull('source.transfer_transaction_id')
            ->whereNull('source.deleted_at')
            ->whereNotExists(function ($query) use ($businessId) {
                $query->select(DB::raw(1))
                    ->from('account_transactions as counterpart')
                    ->join('accounts as counterpart_account', 'counterpart_account.id', '=', 'counterpart.account_id')
                    ->whereNull('counterpart_account.deleted_at')
                    ->where('counterpart_account.business_id', $businessId)
                    ->whereColumn('counterpart.id', 'source.transfer_transaction_id')
                    ->whereColumn('counterpart.transfer_transaction_id', 'source.id')
                    ->whereColumn('counterpart.sub_type', 'source.sub_type')
                    ->whereColumn('counterpart.amount', 'source.amount')
                    ->whereColumn('counterpart.type', '<>', 'source.type')
                    ->whereNull('counterpart.deleted_at');
            })
            ->count();
    }

    private function transactionSkipCandidate(object $transaction, string $reason, int $businessId): array
    {
        $type = (string) ($transaction->type ?? 'transaction');
        $reference = trim((string) ($transaction->invoice_no ?? '')) ?: trim((string) ($transaction->ref_no ?? ''));
        if ($reference === '') {
            $reference = strtoupper($type).'-'.(int) $transaction->id;
        }

        return [
            'skip_reason' => $reason,
            'review_source' => [
                'business_id' => $businessId,
                'source_record_type' => Transaction::class,
                'source_record_id' => (int) $transaction->id,
                'operation_date' => (string) ($transaction->transaction_date ?? ''),
                'source_reference' => $reference,
                'source_summary' => 'Transaksi '.$type.' #'.(int) $transaction->id.' ('.$reference.')',
                'source_context' => [
                    'transaction_type' => $type,
                    'location_id' => empty($transaction->location_id) ? null : (int) $transaction->location_id,
                    'expense_category_id' => empty($transaction->expense_category_id) ? null : (int) $transaction->expense_category_id,
                    'final_total' => isset($transaction->final_total) ? (float) $transaction->final_total : null,
                    'tax_amount' => isset($transaction->tax_amount) ? (float) $transaction->tax_amount : null,
                ],
            ],
        ];
    }

    private function paymentSkipCandidate(object $payment, string $reason, int $businessId): array
    {
        $reference = trim((string) ($payment->ref_no ?? '')) ?: trim((string) ($payment->invoice_no ?? ''));
        if ($reference === '') {
            $reference = 'PAYMENT-'.(int) $payment->payment_id;
        }

        return [
            'skip_reason' => $reason,
            'review_source' => [
                'business_id' => $businessId,
                'source_record_type' => TransactionPayment::class,
                'source_record_id' => (int) $payment->payment_id,
                'operation_date' => (string) ($payment->paid_on ?? ''),
                'source_reference' => $reference,
                'source_summary' => 'Pembayaran transaksi '.(string) $payment->transaction_type.' #'.(int) $payment->transaction_id.' ('.$reference.')',
                'source_context' => [
                    'transaction_id' => (int) $payment->transaction_id,
                    'transaction_type' => (string) $payment->transaction_type,
                    'location_id' => empty($payment->location_id) ? null : (int) $payment->location_id,
                    'payment_account_id' => empty($payment->payment_account_id) ? null : (int) $payment->payment_account_id,
                    'amount' => isset($payment->payment_amount) ? (float) $payment->payment_amount : null,
                ],
            ],
        ];
    }

    private function transferSkipCandidate(object $transfer, string $reason, int $businessId): array
    {
        return [
            'skip_reason' => $reason,
            'review_source' => [
                'business_id' => $businessId,
                'source_record_type' => AccountTransaction::class,
                'source_record_id' => (int) $transfer->source_id,
                'operation_date' => (string) ($transfer->source_operation_date ?? ''),
                'source_reference' => 'BANK-'.$transfer->source_id.'-'.$transfer->counterpart_id,
                'source_summary' => 'Transfer rekening #'.(int) $transfer->source_id.' dan #'.(int) $transfer->counterpart_id,
                'source_context' => [
                    'account_id' => (int) $transfer->source_account_id,
                    'counterpart_account_id' => (int) $transfer->counterpart_account_id,
                    'counterpart_id' => (int) $transfer->counterpart_id,
                    'movement_type' => (string) $transfer->source_sub_type,
                    'source_amount' => (float) $transfer->source_amount,
                    'counterpart_amount' => (float) $transfer->counterpart_amount,
                ],
            ],
        ];
    }

    private function reviewSourceFromGroup(array $candidate, string $reason): array
    {
        $sourceType = (string) ($candidate['source_record_type'] ?? '');
        $sourceId = (int) ($candidate['source_record_id'] ?? 0);
        $reference = trim((string) ($candidate['ref_no'] ?? ''));
        if ($reference === '') {
            $reference = strtoupper((string) ($candidate['sub_type'] ?? 'SOURCE')).'-'.$sourceId;
        }

        return [
            'business_id' => (int) ($candidate['business_id'] ?? 0),
            'source_record_type' => $sourceType,
            'source_record_id' => $sourceId,
            'operation_date' => (string) ($candidate['operation_date'] ?? ''),
            'source_reference' => $reference,
            'source_summary' => 'Sumber '.class_basename($sourceType).' #'.$sourceId.' ('.$reference.')',
            'source_context' => [
                'sub_type' => $candidate['sub_type'] ?? null,
                'transaction_id' => $candidate['transaction_id'] ?? null,
                'transaction_payment_id' => $candidate['transaction_payment_id'] ?? null,
                'source_account_transaction_id' => $candidate['source_account_transaction_id'] ?? null,
                'mapping_note' => $candidate['mapping_note'] ?? null,
            ],
            'skip_reason' => $reason,
        ];
    }

    private function registerReviewSource(?array $source, bool $apply, array &$summary): void
    {
        if ($source === null || ! $this->isActionableReviewReason((string) ($source['skip_reason'] ?? ''))) {
            return;
        }

        $businessId = (int) ($source['business_id'] ?? 0);
        $sourceType = (string) ($source['source_record_type'] ?? '');
        $sourceId = (int) ($source['source_record_id'] ?? 0);
        if ($businessId < 1 || $sourceType === '' || $sourceId < 1) {
            return;
        }

        if ($this->reviewSourceAlreadyLinked($source)) {
            if ($apply) {
                $this->resolveExistingReviewSource($source);
            }
            return;
        }

        $key = implode(':', [$businessId, $sourceType, $sourceId]);
        $summary['review_source_keys'][$key] = true;
        if (! $apply) {
            return;
        }

        $this->reviewQueueBuffer[$key] = $source;
        if (count($this->reviewQueueBuffer) >= 250) {
            $this->flushReviewQueue();
        }
    }

    private function reviewSourceAlreadyLinked(array $source): bool
    {
        if (DB::table('accounting_acc_trans_mappings')
            ->where('business_id', (int) $source['business_id'])
            ->where('source_record_type', (string) $source['source_record_type'])
            ->where('source_record_id', (int) $source['source_record_id'])
            ->exists()) {
            return true;
        }

        $context = $source['source_context'] ?? [];
        $legacyRows = DB::table('accounting_accounts_transactions');
        if ((string) $source['source_record_type'] === Transaction::class) {
            return $legacyRows->where('transaction_id', (int) $source['source_record_id'])
                ->whereNull('transaction_payment_id')
                ->where('sub_type', (string) ($context['transaction_type'] ?? ''))
                ->exists();
        }

        if ((string) $source['source_record_type'] === TransactionPayment::class) {
            $paymentType = (string) ($context['transaction_type'] ?? '');

            return $legacyRows->where('transaction_payment_id', (int) $source['source_record_id'])
                ->where('sub_type', $paymentType.'_payment')
                ->exists();
        }

        return false;
    }

    private function isActionableReviewReason(string $reason): bool
    {
        return $reason !== '' && ! in_array($reason, [
            'transaksi_bukan_posting',
            'akun_debit_kredit_sama',
            'akun_pembayaran_sama_dengan_akun_lawan',
        ], true);
    }

    private function flushReviewQueue(): void
    {
        if ($this->reviewQueueBuffer === []) {
            return;
        }

        $items = $this->reviewQueueBuffer;
        $this->reviewQueueBuffer = [];
        DB::transaction(function () use ($items) {
            foreach ($items as $source) {
                $now = now();
                $identity = [
                    'business_id' => (int) $source['business_id'],
                    'source_record_type' => (string) $source['source_record_type'],
                    'source_record_id' => (int) $source['source_record_id'],
                ];
                $initialEvent = [[
                    'event' => 'queued',
                    'at' => $now->toIso8601String(),
                    'reason' => (string) $source['skip_reason'],
                    'by' => 'accounting:backfill-general-ledger --apply',
                ]];
                $inserted = DB::table(self::REVIEW_QUEUE_TABLE)->insertOrIgnore($identity + [
                    'operation_date' => $this->nullableDate($source['operation_date'] ?? null),
                    'source_reference' => mb_substr((string) ($source['source_reference'] ?? ''), 0, 191),
                    'source_summary' => mb_substr((string) ($source['source_summary'] ?? ''), 0, 1000),
                    'source_context' => json_encode($source['source_context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'skip_reason' => (string) $source['skip_reason'],
                    'status' => 'pending',
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'audit_log' => json_encode($initialEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($inserted > 0) {
                    continue;
                }

                $existing = DB::table(self::REVIEW_QUEUE_TABLE)
                    ->where($identity)
                    ->lockForUpdate()
                    ->first();
                if (! $existing) {
                    continue;
                }

                $auditLog = $this->decodeAuditLog($existing->audit_log ?? null);
                $changes = [];
                $nextReason = (string) $source['skip_reason'];
                if ((string) $existing->skip_reason !== $nextReason) {
                    $changes['reason'] = ['from' => (string) $existing->skip_reason, 'to' => $nextReason];
                }
                $context = $source['source_context'] ?? [];
                $oldContext = json_decode((string) ($existing->source_context ?? '[]'), true);
                if (! is_array($oldContext)) {
                    $oldContext = [];
                }
                if ($oldContext != $context) {
                    $changes['source_details_changed'] = true;
                }
                $reference = mb_substr((string) ($source['source_reference'] ?? ''), 0, 191);
                if ((string) ($existing->source_reference ?? '') !== $reference) {
                    $changes['source_reference_changed'] = true;
                }
                $operationDate = $this->nullableDate($source['operation_date'] ?? null);
                if ((string) ($existing->operation_date ?? '') !== (string) ($operationDate ?? '')) {
                    $changes['operation_date_changed'] = true;
                }
                $sourceSummary = mb_substr((string) ($source['source_summary'] ?? ''), 0, 1000);
                if ((string) $existing->source_summary !== $sourceSummary) {
                    $changes['summary_changed'] = true;
                }
                $reopened = (string) $existing->status !== 'pending';
                if ($reopened) {
                    $changes['status'] = ['from' => (string) $existing->status, 'to' => 'pending'];
                }
                if ($changes !== []) {
                    $auditLog[] = [
                        'event' => $reopened ? 'reopened' : 'updated',
                        'at' => $now->toIso8601String(),
                        'changes' => $changes,
                        'by' => 'accounting:backfill-general-ledger --apply',
                    ];
                }

                DB::table(self::REVIEW_QUEUE_TABLE)->where($identity)->update([
                    'operation_date' => $operationDate,
                    'source_reference' => $reference,
                    'source_summary' => $sourceSummary,
                    'source_context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'skip_reason' => $nextReason,
                    'status' => 'pending',
                    'last_seen_at' => $now,
                    'resolved_at' => null,
                    'audit_log' => json_encode($auditLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function resolveExistingReviewSource(array $candidate): void
    {
        if (! Schema::hasTable(self::REVIEW_QUEUE_TABLE)) {
            return;
        }

        $identity = [
            'business_id' => (int) $candidate['business_id'],
            'source_record_type' => (string) $candidate['source_record_type'],
            'source_record_id' => (int) $candidate['source_record_id'],
        ];
        DB::transaction(function () use ($identity) {
            $review = DB::table(self::REVIEW_QUEUE_TABLE)
                ->where($identity)
                ->lockForUpdate()
                ->first();
            if (! $review || (string) $review->status === 'resolved') {
                return;
            }

            $auditLog = $this->decodeAuditLog($review->audit_log ?? null);
            $auditLog[] = [
                'event' => 'resolved',
                'at' => now()->toIso8601String(),
                'reason' => 'source_already_linked',
                'by' => 'accounting:backfill-general-ledger --apply',
            ];
            DB::table(self::REVIEW_QUEUE_TABLE)->where($identity)->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'audit_log' => json_encode($auditLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    private function decodeAuditLog($value): array
    {
        $decoded = json_decode((string) ($value ?? '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableDate($value): ?string
    {
        $date = trim((string) ($value ?? ''));

        return $date === '' ? null : $date;
    }

    private function processCandidate(array $candidate, int $businessId, bool $apply, array &$summary): void
    {
        $summary['candidate_groups']++;
        if (! empty($candidate['skip_reason'])) {
            $reason = (string) $candidate['skip_reason'];
            $this->skip($summary, $reason);
            $this->registerReviewSource($candidate['review_source'] ?? null, $apply, $summary);
            return;
        }

        $debitAccounts = [];
        $creditAccounts = [];
        foreach ($candidate['lines'] as $line) {
            if ((float) $line['amount'] <= 0) {
                continue;
            }

            $accountId = (int) $line['accounting_account_id'];
            if ($line['type'] === 'debit') {
                $debitAccounts[$accountId] = true;
            } elseif ($line['type'] === 'credit') {
                $creditAccounts[$accountId] = true;
            }
        }
        if (array_intersect_key($debitAccounts, $creditAccounts) !== []) {
            $this->skip($summary, 'akun_debit_kredit_sama');
            $this->registerReviewSource($this->reviewSourceFromGroup($candidate, 'akun_debit_kredit_sama'), $apply, $summary);
            return;
        }

        $debit = round(array_sum(array_map(fn ($line) => $line['type'] === 'debit' ? $line['amount'] : 0, $candidate['lines'])), 4);
        $credit = round(array_sum(array_map(fn ($line) => $line['type'] === 'credit' ? $line['amount'] : 0, $candidate['lines'])), 4);
        if ($candidate['lines'] === [] || round($debit - $credit, 4) !== 0.0) {
            $this->skip($summary, 'group_jurnal_tidak_seimbang');
            $this->registerReviewSource($this->reviewSourceFromGroup($candidate, 'group_jurnal_tidak_seimbang'), $apply, $summary);
            return;
        }

        if ($this->sourceAlreadyLinked($candidate) || $this->hasLegacyLedgerRows($candidate)) {
            $summary['already_linked']++;
            if ($apply) {
                $this->resolveExistingReviewSource($candidate);
            }
            return;
        }

        $summary['planned_groups']++;
        $summary['planned_rows'] += count($candidate['lines']);
        $date = substr((string) $candidate['operation_date'], 0, 10);
        $summary['date_start'] = $summary['date_start'] === null ? $date : min($summary['date_start'], $date);
        $summary['date_end'] = $summary['date_end'] === null ? $date : max($summary['date_end'], $date);
        $summary['debit_total'] = round($summary['debit_total'] + $debit, 4);
        $summary['credit_total'] = round($summary['credit_total'] + $credit, 4);
        if (! empty($candidate['uses_current_map'])) {
            $summary['current_map_groups']++;
            if (! empty($candidate['map_info'])) {
                $key = implode(':', [
                    $candidate['map_info']['location_id'],
                    $candidate['map_info']['mapping_key'],
                    $candidate['map_info']['payment_account_id'],
                    $candidate['map_info']['deposit_to_id'],
                ]);
                if (! isset($summary['current_map_details'][$key])) {
                    $summary['current_map_details'][$key] = $candidate['map_info'] + ['groups' => 0];
                }
                $summary['current_map_details'][$key]['groups']++;
            }
        }
        if (! empty($candidate['uses_auto_map'])) {
            $summary['auto_mapped_groups']++;
            if (! empty($candidate['map_info'])) {
                $info = $candidate['map_info'];
                $key = implode(':', [
                    $info['location_id'],
                    $info['mapping_key'],
                    $info['payment_account_id'],
                    $info['deposit_to_id'],
                ]);
                if (! isset($summary['auto_map_details'][$key])) {
                    $summary['auto_map_details'][$key] = $info + ['groups' => 0];
                }
                $summary['auto_map_details'][$key]['groups']++;
            }
        }
        if (! empty($candidate['requires_vat_account'])) {
            $summary['vat_split_groups'] = ($summary['vat_split_groups'] ?? 0) + 1;
        }
        foreach ($candidate['account_links'] as $link) {
            $key = $link['accounting_account_id'].':'.$link['account_id'];
            $summary['account_links'][$key] = $link;
        }

        if (! $apply) {
            return;
        }

        $applied = $this->applyGroup($candidate, $businessId);
        if ($applied) {
            $summary['applied_groups']++;
        } else {
            $summary['already_linked']++;
        }
    }

    private function applyGroup(array $candidate, int $businessId): bool
    {
        return DB::transaction(function () use ($candidate, $businessId) {
            if (! $this->lockSourceRecord($candidate)) {
                return false;
            }
            if ($this->sourceAlreadyLinked($candidate) || $this->hasLegacyLedgerRows($candidate)) {
                return false;
            }

            foreach ($candidate['account_links'] as $link) {
                $account = DB::table('accounting_accounts')
                    ->where('business_id', $businessId)
                    ->where('id', $link['accounting_account_id'])
                    ->lockForUpdate()
                    ->first(['id', 'account_id']);
                if (! $account || ($account->account_id !== null && (int) $account->account_id !== (int) $link['account_id'])) {
                    throw new \RuntimeException('Crosswalk akun berubah saat apply; jalankan dry-run ulang.');
                }
                if ($account->account_id === null) {
                    DB::table('accounting_accounts')
                        ->where('business_id', $businessId)
                        ->where('id', $link['accounting_account_id'])
                        ->whereNull('account_id')
                        ->update(['account_id' => $link['account_id'], 'updated_at' => now()]);
                }
            }

            $now = now();
            $mappingId = DB::table('accounting_acc_trans_mappings')->insertGetId([
                'business_id' => $businessId,
                'ref_no' => mb_substr((string) $candidate['ref_no'], 0, 100),
                'type' => 'general_ledger_backfill',
                'created_by' => max(1, (int) $candidate['created_by']),
                'operation_date' => $candidate['operation_date'],
                'note' => $candidate['mapping_note'],
                'source_record_type' => $candidate['source_record_type'],
                'source_record_id' => $candidate['source_record_id'],
                'source_link_status' => 'matched',
                'source_link_note' => $candidate['mapping_note'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($candidate['lines'] as $line) {
                DB::table('accounting_accounts_transactions')->insert([
                    'accounting_account_id' => $line['accounting_account_id'],
                    'acc_trans_mapping_id' => $mappingId,
                    'transaction_id' => $candidate['transaction_id'],
                    'transaction_payment_id' => $candidate['transaction_payment_id'],
                    'amount' => $line['amount'],
                    'type' => $line['type'],
                    'sub_type' => $candidate['sub_type'],
                    'map_type' => 'general_ledger_backfill',
                    'created_by' => max(1, (int) $candidate['created_by']),
                    'operation_date' => $candidate['operation_date'],
                    'note' => $candidate['mapping_note'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return true;
        });
    }

    private function lockSourceRecord(array $candidate): bool
    {
        $tables = [
            Transaction::class => 'transactions',
            TransactionPayment::class => 'transaction_payments',
            AccountTransaction::class => 'account_transactions',
        ];
        $table = $tables[$candidate['source_record_type']] ?? null;
        if ($table === null) {
            return false;
        }

        return DB::table($table)
            ->where('id', $candidate['source_record_id'])
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    private function sourceAlreadyLinked(array $candidate): bool
    {
        return DB::table('accounting_acc_trans_mappings')
            ->where('business_id', $candidate['business_id'])
            ->where('source_record_type', $candidate['source_record_type'])
            ->where('source_record_id', $candidate['source_record_id'])
            ->exists();
    }

    private function hasLegacyLedgerRows(array $candidate): bool
    {
        $query = DB::table('accounting_accounts_transactions');
        if ($candidate['transaction_id'] !== null) {
            $query->where('transaction_id', $candidate['transaction_id'])
                ->whereNull('transaction_payment_id');
        } elseif ($candidate['transaction_payment_id'] !== null) {
            $query->where('transaction_payment_id', $candidate['transaction_payment_id']);
        } else {
            return false;
        }

        return $query->where('sub_type', $candidate['sub_type'])->exists();
    }

    private function group(
        int $businessId,
        string $sourceType,
        int $sourceId,
        string $subType,
        string $date,
        int $createdBy,
        string $reference,
        string $note,
        array $lines,
        bool $usesCurrentMap,
        ?int $transactionId,
        ?int $paymentId,
        array $accountLinks,
        bool $requiresVatAccount,
        ?int $sourceAccountTransactionId = null,
        ?array $mapInfo = null
    ): array {
        return [
            'business_id' => $businessId,
            'source_record_type' => $sourceType,
            'source_record_id' => $sourceId,
            'source_account_transaction_id' => $sourceAccountTransactionId,
            'sub_type' => $subType,
            'operation_date' => $date,
            'created_by' => $createdBy,
            'ref_no' => $reference,
            'mapping_note' => $note,
            'lines' => $lines,
            'uses_current_map' => $usesCurrentMap,
            'transaction_id' => $transactionId,
            'transaction_payment_id' => $paymentId,
            'account_links' => $accountLinks,
            'requires_vat_account' => $requiresVatAccount,
            'map_info' => $mapInfo,
        ];
    }

    private function mapInfo(
        int $locationId,
        string $mappingKey,
        array $pair,
        string $mappingSource = 'location',
        ?float $paymentAccountScore = null,
        ?float $depositToScore = null,
        ?string $paymentAccountMethod = null,
        ?string $depositToMethod = null
    ): array {
        return [
            'location_id' => $locationId,
            'mapping_key' => $mappingKey,
            'mapping_source' => $mappingSource,
            'payment_account_id' => (int) $pair['payment_account']->id,
            'payment_account_name' => (string) $pair['payment_account']->name,
            'payment_account_score' => $paymentAccountScore,
            'payment_account_method' => $paymentAccountMethod,
            'deposit_to_id' => (int) $pair['deposit_to']->id,
            'deposit_to_name' => (string) $pair['deposit_to']->name,
            'deposit_to_score' => $depositToScore,
            'deposit_to_method' => $depositToMethod,
        ];
    }

    private function line(int $accountId, string $type, float $amount): array
    {
        return [
            'accounting_account_id' => $accountId,
            'type' => $type,
            'amount' => round($amount, 4),
        ];
    }

    private function resolveAccountingPair(int $businessId, array $map): array
    {
        $paymentId = (int) ($map['payment_account'] ?? 0);
        $depositId = (int) ($map['deposit_to'] ?? 0);
        if ($paymentId < 1 || $depositId < 1) {
            return ['reason' => 'mapping_lokasi_tidak_lengkap'];
        }
        if ($paymentId === $depositId) {
            return ['reason' => 'akun_lawan_sama'];
        }

        $accounts = collect($this->accountingAccountsCache[$businessId] ?? []);
        $payment = $accounts->firstWhere('id', $paymentId);
        $deposit = $accounts->firstWhere('id', $depositId);
        if (! $payment || ! $deposit) {
            return ['reason' => 'akun_mapping_tidak_ada_di_business'];
        }

        return ['payment_account' => $payment, 'deposit_to' => $deposit];
    }

    private function inferTransactionPair(int $businessId, string $type): array
    {
        if ($type === 'sell') {
            $revenue = $this->nearestAccountingAccount($businessId, 'Sales', ['income']);
            $receivable = $this->inferOperationalRoleAccount($businessId, 'receivable');
            if (! empty($revenue['reason']) || ! empty($receivable['reason'])) {
                return ['reason' => $revenue['reason'] ?? $receivable['reason']];
            }

            return [
                'pair' => ['payment_account' => $revenue['account'], 'deposit_to' => $receivable['account']],
                'payment_account_score' => $revenue['score'],
                'deposit_to_score' => $receivable['score'],
                'payment_account_method' => $revenue['method'] ?? 'nearest_name',
                'deposit_to_method' => $receivable['method'] ?? 'nearest_name',
                'account_links' => $receivable['crosswalk'] ? [$receivable['crosswalk']] : [],
            ];
        }

        if ($type === 'purchase') {
            $purchase = $this->nearestAccountingAccount($businessId, 'Purchases', ['expenses']);
            $payable = $this->inferOperationalRoleAccount($businessId, 'payable');
            if (! empty($purchase['reason']) || ! empty($payable['reason'])) {
                return ['reason' => $purchase['reason'] ?? $payable['reason']];
            }

            return [
                'pair' => ['payment_account' => $payable['account'], 'deposit_to' => $purchase['account']],
                'payment_account_score' => $payable['score'],
                'deposit_to_score' => $purchase['score'],
                'payment_account_method' => $payable['method'] ?? 'nearest_name',
                'deposit_to_method' => $purchase['method'] ?? 'nearest_name',
                'account_links' => $payable['crosswalk'] ? [$payable['crosswalk']] : [],
            ];
        }

        return ['reason' => 'jenis_transaksi_tidak_mendukung_pemetaan_otomatis'];
    }

    private function loadExpensePaymentGroups(int $businessId): array
    {
        if (! Schema::hasTable('transaction_payments')) {
            return [];
        }

        $query = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->where('transaction.business_id', $businessId)
            ->where('payment.business_id', $businessId)
            ->where('transaction.type', 'expense')
            ->where('transaction.status', 'final')
            ->groupBy('payment.transaction_id', 'payment.account_id')
            ->selectRaw('payment.transaction_id, payment.account_id, SUM(payment.amount) as amount');

        if (Schema::hasColumn('transaction_payments', 'deleted_at')) {
            $query->whereNull('payment.deleted_at');
        }
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('transaction.deleted_at');
        }
        if (Schema::hasColumn('transaction_payments', 'is_return')) {
            $query->where('payment.is_return', 0);
        }
        if (Schema::hasColumn('transaction_payments', 'is_advance')) {
            $query->where('payment.is_advance', 0);
        }

        return $query->get()->groupBy('transaction_id')->all();
    }

    private function inferExpensePair(int $businessId, object $transaction): array
    {
        $category = $this->expenseCategoriesCache[$businessId][(int) ($transaction->expense_category_id ?? 0)] ?? null;
        if (! $category) {
            return ['reason' => 'kategori_biaya_tidak_ditemukan'];
        }

        $expenseAccount = $this->nearestAccountingAccount(
            $businessId,
            (string) $category->name,
            $this->accountTypesForExpenseCategory((string) $category->name)
        );
        if (! empty($expenseAccount['reason'])) {
            return $expenseAccount;
        }

        $total = round((float) ($transaction->final_total ?? 0), 4);
        $payments = collect($this->expensePaymentGroupsCache[$businessId][(int) $transaction->id] ?? []);
        $paidTotal = round((float) $payments->sum(fn ($payment) => (float) ($payment->amount ?? 0)), 4);
        if ($paidTotal > $total + 0.0001) {
            return ['reason' => 'pembayaran_melebihi_total_transaksi'];
        }

        $paymentLines = [];
        $accountLinks = [];
        $paymentAccounts = [];
        foreach ($payments as $payment) {
            $amount = round((float) ($payment->amount ?? 0), 4);
            if ($amount == 0.0) {
                continue;
            }
            $operationalAccountId = (int) ($payment->account_id ?? 0);
            if ($operationalAccountId < 1) {
                return ['reason' => 'akun_pembayaran_operasional_kosong'];
            }

            $resolved = $this->mainAccountMapping($businessId, $operationalAccountId);
            if (! empty($resolved['reason'])) {
                return $resolved;
            }

            $paymentAccounts[] = [
                'account' => $resolved['account'],
                'score' => $resolved['score'] ?? 1.0,
                'source_name' => $resolved['source_name'] ?? '',
                    'method' => $resolved['method'] ?? (! empty($resolved['automatic_nearest']) ? 'nearest_name' : 'account_id'),
            ];
            if (! empty($resolved['crosswalk'])) {
                $accountLinks[] = $resolved['crosswalk'];
            }
            $paymentLines[] = $this->line(
                (int) $resolved['account']->id,
                $amount > 0 ? 'credit' : 'debit',
                abs($amount)
            );
        }

        if ($paidTotal < $total - 0.0001) {
            $payable = $this->inferOperationalRoleAccount($businessId, 'payable');
            if (! empty($payable['reason'])) {
                return $payable;
            }
            $paymentLines[] = $this->line((int) $payable['account']->id, 'credit', $total - $paidTotal);
            $paymentAccounts[] = [
                'account' => $payable['account'],
                'score' => $payable['score'],
                'source_name' => $payable['source_name'],
                'method' => $payable['method'] ?? 'nearest_name',
            ];
        }

        if ($paymentAccounts === []) {
            return ['reason' => 'akun_pembayaran_operasional_kosong'];
        }

        $primaryPayment = $paymentAccounts[0];
        return [
            'pair' => [
                'payment_account' => $primaryPayment['account'],
                'deposit_to' => $expenseAccount['account'],
            ],
            'payment_account_score' => $primaryPayment['score'],
            'deposit_to_score' => $expenseAccount['score'],
            'payment_account_method' => $primaryPayment['method'],
            'deposit_to_method' => $expenseAccount['method'] ?? 'nearest_name',
            'payment_lines' => $paymentLines,
            'account_links' => array_values(array_unique($accountLinks, SORT_REGULAR)),
            'payment_source_names' => array_values(array_unique(array_filter(array_column($paymentAccounts, 'source_name')))),
        ];
    }

    private function inferOperationalRoleAccount(int $businessId, string $role): array
    {
        $roles = [
            'receivable' => [
                'operational_names' => ['Account Receivable', 'Accounts Receivable', 'Piutang Usaha'],
                'coa_name' => 'Accounts Receivable',
                'account_types' => ['asset'],
            ],
            'payable' => [
                'operational_names' => ['Trade Payable', 'Account Payable', 'Accounts Payable', 'Utang Usaha', 'Hutang Usaha'],
                'coa_name' => 'Trade Payable',
                'account_types' => ['liability'],
            ],
        ];
        if (! isset($roles[$role])) {
            return ['reason' => 'peran_akun_operasional_tidak_dikenal'];
        }

        $roleConfig = $roles[$role];
        $aliases = array_map([$this, 'normalizeAccountName'], $roleConfig['operational_names']);
        $query = DB::table('accounts')->where('business_id', $businessId);
        if (Schema::hasColumn('accounts', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $operationalAccounts = $query->get(['id', 'name'])
            ->filter(fn ($account) => in_array($this->normalizeAccountName((string) $account->name), $aliases, true))
            ->values();

        if ($operationalAccounts->count() === 1) {
            $mapped = $this->mainAccountMapping($businessId, (int) $operationalAccounts[0]->id);
            if (! empty($mapped['reason'])) {
                return $mapped;
            }

            return [
                'account' => $mapped['account'],
                'score' => $mapped['score'] ?? 1.0,
                'source_name' => (string) $operationalAccounts[0]->name,
                'method' => $mapped['method'] ?? ($mapped['crosswalk'] === null ? 'account_id' : 'exact_name'),
                'automatic_nearest' => ! empty($mapped['automatic_nearest']),
                'crosswalk' => $mapped['crosswalk'] ?? null,
            ];
        }

        $nearest = $this->nearestAccountingAccount($businessId, $roleConfig['coa_name'], $roleConfig['account_types']);
        if (! empty($nearest['reason'])) {
            return $nearest;
        }

        return $nearest + ['source_name' => $roleConfig['coa_name']];
    }

    private function nearestAccountingAccount(int $businessId, string $sourceName, array $allowedTypes): array
    {
        $candidates = array_values(array_filter(
            $this->accountingAccountsCache[$businessId] ?? [],
            fn ($account) => in_array(strtolower((string) ($account->account_primary_type ?? '')), $allowedTypes, true)
        ));
        if ($candidates === []) {
            return ['reason' => 'akun_coa_untuk_tipe_tidak_ditemukan'];
        }

        $hintName = $this->semanticCoaHint($sourceName);
        if ($hintName !== null) {
            $hintedAccount = collect($candidates)->first(
                fn ($account) => $this->normalizeAccountName((string) $account->name) === $this->normalizeAccountName($hintName)
            );
            if ($hintedAccount) {
                return [
                    'account' => $hintedAccount,
                    'score' => 1.0,
                    'source_name' => $sourceName,
                    'method' => 'semantic_hint',
                ];
            }
        }

        $ranked = [];
        foreach ($candidates as $account) {
            $ranked[] = [
                'account' => $account,
                'score' => $this->accountNameSimilarity($sourceName, (string) $account->name),
            ];
        }
        usort($ranked, function ($left, $right) {
            $scoreOrder = $right['score'] <=> $left['score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            return (int) $left['account']->id <=> (int) $right['account']->id;
        });

        return [
            'account' => $ranked[0]['account'],
            'score' => round((float) $ranked[0]['score'], 4),
            'source_name' => $sourceName,
            'method' => 'nearest_name',
        ];
    }

    private function semanticCoaHint(string $sourceName): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', trim($sourceName)) ?: '');
        $key = trim(preg_replace('/\s+/', ' ', $key) ?: '');
        $hints = [
            'cash' => 'Cash and cash equivalents',
            'giro' => 'Cash and cash equivalents',
            'petty cash pool' => 'Cash and cash equivalents',
            'mandiri 9641' => 'MANDIRI - BMT',
            'mandiri pt' => 'MANDIRI - BMT',
            'employee receivables' => 'Accounts Receivable (A/R)',
            'employee receivables expenses' => 'Accounts Receivable (A/R)',
            'kasbon' => 'Accounts Receivable (A/R)',
            'accumulated depreciation vehicles' => 'Accumulated depreciation on property, plant and equipment',
            'accumulated depreciation machinery equipment' => 'Accumulated depreciation on property, plant and equipment',
            'purchase discounts' => 'Discounts given - COS',
            'repair and maintenance hpp' => 'Repair and maintenance',
            'donations' => 'Other general and administrative expenses',
            'transportation' => 'Travel expenses - general and admin expenses',
            'uang jalan' => 'Travel expenses - general and admin expenses',
            'travelling general' => 'Travel expenses - general and admin expenses',
            'fuel toll and parking general' => 'Travel expenses - general and admin expenses',
            'travelling sales' => 'Travel expenses - selling expense',
            'fuel toll and parking sales' => 'Travel expenses - selling expense',
            'employee benefit expense' => 'Wage expenses',
            'other benefit and allowances' => 'Wage expenses',
            'salaries' => 'Wage expenses',
            'overtime' => 'Wage expenses',
            'thr and bonus' => 'Wage expenses',
            'incentive' => 'Wage expenses',
            'uniform' => 'Wage expenses',
            'meals' => 'Meals and entertainment',
            'entertainment' => 'Meals and entertainment',
            'food' => 'Meals and entertainment',
            'meals and transport' => 'Meals and entertainment',
            'medical' => 'Insurance - General',
            'insurance' => 'Insurance - General',
            'bank administration fee' => 'Bank charges',
            'bank admin' => 'Bank charges',
            'document handling expense' => 'Office expenses',
            'taxes and licenses' => 'Other general and administrative expenses',
            'tunjangan pph' => 'Income tax expense',
            'bank loan' => 'Long-term debt',
            'depreciation vehicle' => 'Amortisation expense',
            'depreciation machinery equipment' => 'Amortisation expense',
            'maintenance general' => 'Repair and maintenance',
            'training developments' => 'Dues and Subscriptions',
            'cost of production' => 'Overhead - COS',
            'inventory adjustments' => 'Change in inventory - COS',
            'other miscellaneous expense' => 'Other general and administrative expenses',
            'securities and cleaning' => 'Other general and administrative expenses',
            'general affair general' => 'Other general and administrative expenses',
            'security general' => 'Other general and administrative expenses',
            'cleaning general' => 'Other general and administrative expenses',
            'souvenir' => 'Other Types of Expenses-Advertising Expenses',
            'training and developments' => 'Dues and Subscriptions',
            'shipping freight delivery' => 'Freight and delivery - COS',
            'shippingan' => 'Freight and delivery - COS',
            'utilities expense' => 'Utilities',
            'electricity' => 'Utilities',
            'water' => 'Utilities',
            'communication general' => 'Utilities',
            'communication sales' => 'Utilities',
        ];

        return $hints[$key] ?? null;
    }

    private function accountNameSimilarity(string $sourceName, string $candidateName): float
    {
        $source = $this->normalizeAccountName($sourceName);
        $candidate = $this->normalizeAccountName($candidateName);
        if ($source === '' || $candidate === '') {
            return 0.0;
        }
        if ($source === $candidate) {
            return 1.0;
        }

        $sourceTokens = array_values(array_unique(explode(' ', $source)));
        $candidateTokens = array_values(array_unique(explode(' ', $candidate)));
        $intersection = count(array_intersect($sourceTokens, $candidateTokens));
        $union = count(array_unique(array_merge($sourceTokens, $candidateTokens)));
        $tokenScore = $union > 0 ? $intersection / $union : 0.0;
        $maxLength = max(strlen($source), strlen($candidate));
        $stringScore = $maxLength > 0 ? 1 - (levenshtein($source, $candidate) / $maxLength) : 0.0;

        return (0.7 * $tokenScore) + (0.3 * $stringScore);
    }

    private function normalizeAccountName(string $name): string
    {
        $name = strtolower(str_replace('&', ' and ', trim($name)));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?: '';
        $tokens = array_filter(explode(' ', trim($name)), fn ($token) => $token !== '');
        $aliases = [
            'accounts' => 'account',
            'receivables' => 'receivable',
            'payables' => 'payable',
            'purchases' => 'purchase',
            'sales' => 'sale',
            'expenses' => 'expense',
            'travelling' => 'travel',
            'transportation' => 'travel',
            'transport' => 'travel',
            'fuel' => 'travel',
            'toll' => 'travel',
            'parking' => 'travel',
            'jalan' => 'travel',
            'freight' => 'shipping',
            'delivery' => 'shipping',
            'shippingan' => 'shipping',
            'repair' => 'maintenance',
            'meals' => 'meal',
            'food' => 'meal',
            'entertainment' => 'meal',
            'electricity' => 'utilities',
            'water' => 'utilities',
            'communication' => 'utilities',
            'salaries' => 'wage',
            'salary' => 'wage',
            'overtime' => 'wage',
            'bonus' => 'wage',
            'thr' => 'wage',
            'incentive' => 'wage',
            'allowances' => 'wage',
            'benefit' => 'wage',
            'commission' => 'commission',
            'fees' => 'commission',
            'fee' => 'commission',
            'administration' => 'charge',
            'admin' => 'charge',
            'charges' => 'charge',
            'charge' => 'charge',
            'amortisation' => 'depreciation',
            'amortization' => 'depreciation',
            'license' => 'tax',
            'licenses' => 'tax',
            'taxes' => 'tax',
            'pph' => 'tax',
            'loan' => 'debt',
            'kasbon' => 'receivable',
            'miscellaneous' => 'other',
            'misc' => 'other',
            'donations' => 'other',
            'donation' => 'other',
            'securities' => 'other',
            'production' => 'cost',
            'hpp' => 'cost',
            'cos' => 'cost',
            'discounts' => 'discount',
            'souvenir' => 'advertising',
        ];

        return implode(' ', array_map(fn ($token) => $aliases[$token] ?? $token, $tokens));
    }

    private function accountTypesForOperationalAccount(string $name): array
    {
        $normalized = $this->normalizeAccountName($name);
        if (preg_match('/\b(receivable|depreciation|cash|bank|giro|mandiri|petty)\b/', $normalized)) {
            return ['asset'];
        }
        if (preg_match('/\b(payable|debt|utang|hutang)\b/', $normalized)) {
            return ['liability'];
        }
        if (preg_match('/\b(expense|wage|discount|commission|charge)\b/', $normalized)) {
            return ['expenses'];
        }

        return ['asset', 'liability', 'expenses', 'income', 'equity'];
    }

    private function accountTypesForExpenseCategory(string $name): array
    {
        $normalized = $this->normalizeAccountName($name);
        if (preg_match('/\b(receivable)\b/', $normalized)) {
            return ['asset'];
        }
        if (preg_match('/\b(debt|loan)\b/', $normalized)) {
            return ['liability', 'expenses'];
        }

        return ['expenses'];
    }

    private function resolveVatAccount(int $businessId, string $direction): array
    {
        $side = $direction === 'input' ? 'debit' : 'credit';
        $candidates = [];
        foreach ($this->accountingAccountsCache[$businessId] ?? [] as $account) {
            $namedDirection = $this->explicitVatDirection(
                (string) ($account->gl_code ?? ''),
                (string) $account->name
            );
            if ($namedDirection !== $direction) {
                continue;
            }

            $components = $this->accountComponentService->groupJournalLines([
                'lines' => [[
                    'account_no' => (string) ($account->gl_code ?? ''),
                    'account_name' => (string) $account->name,
                    'type' => $side,
                    'amount' => 1,
                ]],
            ], $businessId);
            $component = $components[0] ?? [];
            if (! empty($component['is_tax'])
                && ($component['tax_direction'] ?? null) === $direction
                && empty($component['tax_direction_conflict'])) {
                $candidates[] = $account;
            }
        }

        if (count($candidates) > 1) {
            return ['reason' => 'akun_vat_ambigu'];
        }
        if ($candidates === []) {
            return ['reason' => 'akun_vat_tidak_ditemukan'];
        }

        return ['account' => $candidates[0]];
    }

    private function explicitVatDirection(string $accountNo, string $accountName): ?string
    {
        $label = mb_strtolower(trim($accountNo.' '.$accountName));
        $taxTerm = '(?:vat|ppn|gst|pajak|tax)';
        if (preg_match('/\b'.$taxTerm.'\b.*\b(?:in|input|masukan)\b|\b(?:in|input|masukan)\b.*\b'.$taxTerm.'\b/u', $label) === 1) {
            return 'input';
        }
        if (preg_match('/\b'.$taxTerm.'\b.*\b(?:out|output|keluaran)\b|\b(?:out|output|keluaran)\b.*\b'.$taxTerm.'\b/u', $label) === 1) {
            return 'output';
        }

        return null;
    }

    private function mainAccountMapping(int $businessId, int $accountId): array
    {
        $cacheKey = $businessId.':'.$accountId;
        if (array_key_exists($cacheKey, $this->mainAccountCache)) {
            return $this->mainAccountCache[$cacheKey];
        }

        $mainAccount = DB::table('accounts')
            ->where('business_id', $businessId)
            ->where('id', $accountId)
            ->when(Schema::hasColumn('accounts', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->first(['id', 'name']);
        if (! $mainAccount) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'akun_utama_tidak_ada_di_business'];
        }

        $accounts = $this->accountingAccountsCache[$businessId] ?? [];
        $direct = array_values(array_filter($accounts, fn ($account) => (int) ($account->account_id ?? 0) === $accountId));
        if (count($direct) > 1) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'crosswalk_account_id_ambigu'];
        }
        if (count($direct) === 1) {
            return $this->mainAccountCache[$cacheKey] = [
                'account' => $direct[0],
                'crosswalk' => null,
                'method' => 'account_id',
            ];
        }

        $mainAccountName = (string) $mainAccount->name;
        $matches = array_values(array_filter($accounts, fn ($account) => (string) $account->name === $mainAccountName));
        if (count($matches) > 1) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'nama_akun_utama_ambigu'];
        }
        if ($matches === []) {
            $nearest = $this->nearestAccountingAccount(
                $businessId,
                $mainAccountName,
                $this->accountTypesForOperationalAccount($mainAccountName)
            );
            if (! empty($nearest['reason'])) {
                return $this->mainAccountCache[$cacheKey] = ['reason' => 'akun_utama_belum_dimapping'];
            }

            return $this->mainAccountCache[$cacheKey] = [
                'account' => $nearest['account'],
                'crosswalk' => null,
                'automatic_nearest' => true,
                'score' => $nearest['score'],
                'source_name' => $mainAccountName,
                'method' => $nearest['method'],
            ];
        }
        if (! empty($matches[0]->account_id) && (int) $matches[0]->account_id !== $accountId) {
            return $this->mainAccountCache[$cacheKey] = ['reason' => 'crosswalk_akun_bertentangan'];
        }

        $account = $matches[0];
        return $this->mainAccountCache[$cacheKey] = [
            'account' => $account,
            'crosswalk' => [
                'accounting_account_id' => (int) $account->id,
                'account_id' => $accountId,
            ],
            'method' => 'exact_name',
        ];
    }

    private function locationMap(int $businessId, int $locationId): array
    {
        return $this->locationMapsCache[$businessId][$locationId] ?? [];
    }

    private function isPostedTransaction(object $transaction): bool
    {
        $type = (string) ($transaction->type ?? '');
        $status = strtolower(trim((string) ($transaction->status ?? '')));

        if ($type === 'sell' || $type === 'expense') {
            return $status === 'final';
        }

        return $type === 'purchase' && $status === 'received';
    }

    private function skip(array &$summary, string $reason): void
    {
        $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + 1;
        if ($reason === 'akun_vat_ambigu') {
            $summary['ambiguous_vat_accounts']++;
        } elseif ($reason === 'akun_vat_tidak_ditemukan') {
            $summary['missing_vat_accounts']++;
        }
    }
}
