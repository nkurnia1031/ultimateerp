<?php

namespace Database\Seeders;

use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class JournalImportE2ESeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $existingUser = User::where('username', 'journal-ci')->first();
            if ($existingUser) {
                $businessId = (int) $existingUser->business_id;
                $userId = (int) $existingUser->id;
                $now = now();

                $this->ensurePermissions($userId);
                $this->seedGeneralLedgerFixture($businessId, $userId, $now);

                return;
            }

            $now = now();
            $userId = DB::table('users')->insertGetId([
                'surname' => 'CI',
                'first_name' => 'Journal Import',
                'username' => 'journal-ci',
                'email' => 'journal-ci@example.invalid',
                'password' => Hash::make('ci-journal-import-only'),
                'language' => 'en',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $businessId = DB::table('business')->insertGetId([
                'name' => 'Journal Import CI',
                'currency_id' => 2,
                'tax_number_1' => 'CI-TAX',
                'tax_label_1' => 'VAT',
                'owner_id' => $userId,
                'time_zone' => 'UTC',
                'accounting_method' => 'fifo',
                'sell_price_tax' => 'excludes',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')->where('id', $userId)->update([
                'business_id' => $businessId,
                'updated_at' => $now,
            ]);

            $invoiceSchemeId = DB::table('invoice_schemes')->insertGetId([
                'business_id' => $businessId,
                'name' => 'CI Invoice Scheme',
                'scheme_type' => 'year',
                'prefix' => 'CI',
                'start_number' => 1,
                'total_digits' => 5,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $invoiceLayoutId = DB::table('invoice_layouts')->insertGetId([
                'name' => 'CI Invoice Layout',
                'business_id' => $businessId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('business_locations')->insert([
                'business_id' => $businessId,
                'name' => 'CI Location',
                'country' => 'United States',
                'state' => 'California',
                'city' => 'CI City',
                'zip_code' => '0000000',
                'invoice_scheme_id' => $invoiceSchemeId,
                'sale_invoice_scheme_id' => $invoiceSchemeId,
                'invoice_layout_id' => $invoiceLayoutId,
                'sale_invoice_layout_id' => $invoiceLayoutId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->ensurePermissions($userId);
            $this->seedGeneralLedgerFixture($businessId, $userId, $now);
        });
    }

    private function ensurePermissions(int $userId): void
    {
        $permissions = collect([
            'accounting.add_journal',
            'accounting.view_reports',
            'accounting.manage_accounts',
            'superadmin',
        ])->map(fn ($name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

        User::findOrFail($userId)->givePermissionTo($permissions);
    }

    private function seedGeneralLedgerFixture(int $businessId, int $userId, $now): void
    {
        $fixtureAccounts = [
            ['name' => 'CI Test Cash', 'code' => 'CI-1000', 'type' => 'asset', 'classification' => 'cash'],
            ['name' => 'CI Test Bank', 'code' => 'CI-1001', 'type' => 'asset', 'classification' => 'bank'],
            ['name' => 'CI Test Equity', 'code' => 'CI-3000', 'type' => 'equity', 'classification' => 'excluded'],
        ];
        $accountIds = [];

        foreach ($fixtureAccounts as $account) {
            $mainAccount = DB::table('accounts')
                ->where('business_id', $businessId)
                ->where('account_number', $account['code'])
                ->first();
            $mainAccountId = $mainAccount ? (int) $mainAccount->id : DB::table('accounts')->insertGetId([
                'business_id' => $businessId,
                'name' => $account['name'],
                'account_number' => $account['code'],
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('accounting_accounts')->updateOrInsert([
                'business_id' => $businessId,
                'gl_code' => $account['code'],
            ], [
                'name' => $account['name'],
                'account_id' => $mainAccountId,
                'account_primary_type' => $account['type'],
                'status' => 'active',
                'created_by' => $userId,
                'updated_at' => $now,
                'created_at' => $now,
            ]);

            $accountingAccount = DB::table('accounting_accounts')
                ->where('business_id', $businessId)
                ->where('gl_code', $account['code'])
                ->first();
            $accountIds[$account['code']] = (int) $accountingAccount->id;

            DB::table('accounting_general_ledger_cash_bank_mappings')->updateOrInsert([
                'business_id' => $businessId,
                'accounting_account_id' => $accountIds[$account['code']],
            ], [
                'classification' => $account['classification'],
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $postings = [
            ['accounting_account_id' => $accountIds['CI-1000'], 'amount' => 1000, 'type' => 'debit', 'sub_type' => 'opening_balance', 'operation_date' => '2021-12-31 00:00:00', 'note' => 'CI GL opening cash'],
            ['accounting_account_id' => $accountIds['CI-1001'], 'amount' => 500, 'type' => 'debit', 'sub_type' => 'opening_balance', 'operation_date' => '2021-12-31 00:00:00', 'note' => 'CI GL opening bank'],
            ['accounting_account_id' => $accountIds['CI-1000'], 'amount' => 200, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-03 10:00:00', 'note' => 'CI GL cash movement'],
            ['accounting_account_id' => $accountIds['CI-1001'], 'amount' => 50, 'type' => 'credit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-04 10:00:00', 'note' => 'CI GL bank movement'],
            ['accounting_account_id' => $accountIds['CI-3000'], 'amount' => 900, 'type' => 'debit', 'sub_type' => 'journal_entry', 'operation_date' => '2022-01-05 10:00:00', 'note' => 'CI GL excluded movement'],
        ];

        foreach ($postings as $posting) {
            $exists = DB::table('accounting_accounts_transactions')
                ->where('accounting_account_id', $posting['accounting_account_id'])
                ->where('note', $posting['note'])
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('accounting_accounts_transactions')->insert($posting + [
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
