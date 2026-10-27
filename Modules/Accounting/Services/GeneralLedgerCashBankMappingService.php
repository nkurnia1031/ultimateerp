<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GeneralLedgerCashBankMappingService
{
    private const TABLE = 'accounting_general_ledger_cash_bank_mappings';

    private const INCLUDED_CLASSIFICATIONS = ['cash', 'bank'];

    private const VALID_CLASSIFICATIONS = ['cash', 'bank', 'excluded'];

    public function accountIds(int $businessId, ?int $accountId = null): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $query = DB::table(self::TABLE)
            ->where('business_id', $businessId)
            ->whereIn('classification', self::INCLUDED_CLASSIFICATIONS);

        if ($accountId !== null) {
            $query->where('accounting_account_id', $accountId);
        }

        return $query->orderBy('accounting_account_id')
            ->pluck('accounting_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function mappedAccounts(int $businessId)
    {
        if (! Schema::hasTable(self::TABLE)) {
            return collect();
        }

        return DB::table(self::TABLE.' as mapping')
            ->join('accounting_accounts as aa', function ($join) {
                $join->on('aa.id', '=', 'mapping.accounting_account_id')
                    ->on('aa.business_id', '=', 'mapping.business_id');
            })
            ->where('mapping.business_id', $businessId)
            ->whereIn('mapping.classification', self::INCLUDED_CLASSIFICATIONS)
            ->orderBy('aa.gl_code')
            ->orderBy('aa.name')
            ->get([
                'aa.id',
                'aa.name',
                'aa.gl_code',
                'mapping.classification',
            ]);
    }

    public function reviewAccounts(int $businessId, string $startDate, string $endDate)
    {
        $postingCounts = DB::table('accounting_accounts_transactions')
            ->where('operation_date', '>=', $startDate.' 00:00:00')
            ->where('operation_date', '<=', \Carbon::parse($endDate)->endOfDay()->format('Y-m-d H:i:s'))
            ->select('accounting_account_id')
            ->selectRaw('COUNT(*) as posting_count')
            ->groupBy('accounting_account_id');
        if (Schema::hasTable('accounting_journal_imports')
            && Schema::hasColumn('accounting_accounts_transactions', 'journal_import_id')) {
            $postingCounts->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('accounting_journal_imports as reverted_import')
                    ->whereColumn('reverted_import.id', 'accounting_accounts_transactions.journal_import_id')
                    ->where('reverted_import.status', 'reverted');
            });
        }

        $query = DB::table('accounting_accounts as aa')
            ->leftJoinSub($postingCounts, 'postings', function ($join) {
                $join->on('postings.accounting_account_id', '=', 'aa.id');
            })
            ->where('aa.business_id', $businessId)
            ->orderBy('aa.gl_code')
            ->orderBy('aa.name');

        if (Schema::hasTable(self::TABLE)) {
            $query->leftJoin(self::TABLE.' as mapping', function ($join) {
                $join->on('mapping.accounting_account_id', '=', 'aa.id')
                    ->on('mapping.business_id', '=', 'aa.business_id');
            });
        }

        return $query->get([
            'aa.id',
            'aa.name',
            'aa.gl_code',
            'postings.posting_count',
            Schema::hasTable(self::TABLE) ? 'mapping.classification' : DB::raw('NULL as classification'),
        ]);
    }

    public function save(int $businessId, int $userId, array $mappings): int
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw ValidationException::withMessages([
                'mappings' => 'Pemetaan akun kas/bank belum tersedia. Jalankan migration Accounting terlebih dahulu.',
            ]);
        }

        $accountIds = array_map('intval', array_keys($mappings));
        $validAccountIds = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->whereIn('id', $accountIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count(array_unique($accountIds)) !== count($validAccountIds)) {
            throw ValidationException::withMessages([
                'mappings' => 'Salah satu akun tidak ditemukan di business aktif.',
            ]);
        }

        foreach ($mappings as $classification) {
            if ($classification !== null && $classification !== '' && ! in_array($classification, self::VALID_CLASSIFICATIONS, true)) {
                throw ValidationException::withMessages([
                    'mappings' => 'Klasifikasi akun tidak valid.',
                ]);
            }
        }

        return DB::transaction(function () use ($businessId, $userId, $mappings, $accountIds) {
            $saved = 0;
            $now = now();
            $existingMappings = DB::table(self::TABLE)
                ->where('business_id', $businessId)
                ->whereIn('accounting_account_id', $accountIds)
                ->pluck('classification', 'accounting_account_id')
                ->mapWithKeys(fn ($classification, $accountId) => [(int) $accountId => $classification])
                ->all();

            foreach ($mappings as $accountId => $classification) {
                $accountId = (int) $accountId;
                if ($classification === null || $classification === '') {
                    if (isset($existingMappings[$accountId])) {
                        $saved += DB::table(self::TABLE)
                            ->where('business_id', $businessId)
                            ->where('accounting_account_id', $accountId)
                            ->delete();
                    }

                    continue;
                }

                if (($existingMappings[$accountId] ?? null) === $classification) {
                    continue;
                }

                if (array_key_exists($accountId, $existingMappings)) {
                    DB::table(self::TABLE)
                        ->where('business_id', $businessId)
                        ->where('accounting_account_id', $accountId)
                        ->update([
                            'classification' => $classification,
                            'updated_by' => $userId,
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table(self::TABLE)->insert([
                        'business_id' => $businessId,
                        'accounting_account_id' => $accountId,
                        'classification' => $classification,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $saved++;
            }

            return $saved;
        });
    }
}
