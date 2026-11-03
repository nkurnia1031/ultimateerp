<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Services\JournalAccountTypeResolver;
use Modules\Accounting\Services\JournalAlignedCoaProvisioner;

return new class extends Migration
{
    private const BUSINESS_ID = 2;

    public function up(): void
    {
        if (! Schema::hasTable('business')
            || ! Schema::hasTable('accounting_accounts')
            || ! Schema::hasTable('accounting_general_ledger_cash_bank_mappings')) {
            return;
        }

        app(JournalAlignedCoaProvisioner::class)->provisionBusiness(self::BUSINESS_ID);

        $classifications = [
            '1-10001' => 'cash',
            '1-10004' => 'bank',
            '1-10005' => 'cash',
            '1-10006' => 'bank',
            '1-10007' => 'bank',
            '1-10008' => 'bank',
            '1-10100' => 'excluded',
            '1-10103' => 'excluded',
            '1-10753' => 'excluded',
            '1-10754' => 'excluded',
            '2-20100' => 'excluded',
            '5-50100' => 'excluded',
            '6-60210' => 'excluded',
            '6-60222' => 'excluded',
        ];

        foreach ($classifications as $glCode => $classification) {
            $accounts = DB::table('accounting_accounts')
                ->where('business_id', self::BUSINESS_ID)
                ->where('gl_code', $glCode)
                ->get(['id', 'name', 'account_id', 'account_primary_type']);

            if ($accounts->count() !== 1) {
                continue;
            }
            $account = $accounts->first();
            $expectedPrimaryType = JournalAccountTypeResolver::fromGlCode($glCode);
            if ($expectedPrimaryType === null
                || strtolower((string) $account->account_primary_type) !== $expectedPrimaryType
                || ($classification !== 'excluded' && $expectedPrimaryType !== 'asset')) {
                continue;
            }

            if ($glCode === '1-10008') {
                if ((string) DB::table('accounting_accounts')->where('id', $account->id)->value('name') !== 'Giro 0965'
                    || ! Schema::hasTable('accounts')
                    || ! Schema::hasColumn('accounts', 'business_id')
                    || ! Schema::hasColumn('accounts', 'name')
                    || ! Schema::hasColumn('accounts', 'account_number')) {
                    continue;
                }
                $giroAccounts = DB::table('accounts')
                    ->where('business_id', self::BUSINESS_ID)
                    ->where('name', 'Giro')
                    ->where('account_number', '0965')
                    ->pluck('id');
                if ($giroAccounts->count() !== 1 || (int) $account->account_id !== (int) $giroAccounts->first()) {
                    continue;
                }
            }

            if ($classification === 'excluded' && empty($account->account_id)) {
                continue;
            }

            $exists = DB::table('accounting_general_ledger_cash_bank_mappings')
                ->where('business_id', self::BUSINESS_ID)
                ->where('accounting_account_id', $account->id)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('accounting_general_ledger_cash_bank_mappings')->insert([
                'business_id' => self::BUSINESS_ID,
                'accounting_account_id' => (int) $account->id,
                'classification' => $classification,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Keep provisioning and account classifications after rollback because
     * journals or user decisions may already depend on them.
     */
    public function down(): void
    {
        // Intentionally no-op.
    }
};
