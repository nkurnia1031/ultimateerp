<?php

namespace Database\Seeders;

use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Accounting\Services\LegacyReconciliationFindingSnapshotService;
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
                $this->seedReconciliationFixture($businessId);
                $this->seedJournalImportReviewFixture($businessId, $userId, $now);
                $this->seedSpbImportFixture($businessId, $userId, $now);

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
            $this->seedReconciliationFixture($businessId);
            $this->seedJournalImportReviewFixture($businessId, $userId, $now);
            $this->seedSpbImportFixture($businessId, $userId, $now);
        });
    }

    private function seedSpbImportFixture(int $businessId, int $userId, $now): void
    {
        $assetValues = [
            'name' => 'CI SPB Truck',
            'quantity' => 1,
            'unit_price' => 0,
            'model' => null,
            'serial_no' => null,
            'category_id' => null,
            'location_id' => null,
            'purchase_date' => null,
            'purchase_type' => 'owned',
            'depreciation' => null,
            'is_allocatable' => 0,
            'description' => 'Isolated Playwright SPB fixture',
            'created_by' => $userId,
            'updated_at' => $now,
        ];
        $asset = DB::table('assets')
            ->where('business_id', $businessId)
            ->where('asset_code', 'CI-SPB-TRUCK')
            ->first();
        if ($asset) {
            DB::table('assets')->where('id', $asset->id)->update($assetValues);
        } else {
            DB::table('assets')->insert($assetValues + [
                'business_id' => $businessId,
                'asset_code' => 'CI-SPB-TRUCK',
                'created_at' => $now,
            ]);
        }

        $driverValues = [
            'surname' => 'CI',
            'first_name' => 'SPB',
            'last_name' => 'Driver',
            'email' => 'spb-driver-ci@example.invalid',
            'password' => Hash::make('ci-spb-driver-not-for-login'),
            'language' => 'en',
            'business_id' => $businessId,
            'user_type' => 'user',
            'allow_login' => 0,
            'updated_at' => $now,
        ];
        $driver = DB::table('users')->where('username', 'spb-driver-ci')->first();
        if ($driver) {
            DB::table('users')->where('id', $driver->id)->update($driverValues);
        } else {
            DB::table('users')->insert($driverValues + [
                'username' => 'spb-driver-ci',
                'created_at' => $now,
            ]);
        }
    }

    private function seedJournalImportReviewFixture(int $businessId, int $userId, $now): void
    {
        $fileName = 'journal-import-bulk-review-fixture.xlsx';
        $batch = DB::table('accounting_journal_imports')
            ->where('business_id', $businessId)
            ->where('file_name', $fileName)
            ->first();
        $summary = [
            'source_date_range' => '2025-01-01 — 2025-01-01',
            'unbalanced_count' => 0,
            'duplicate_count' => 0,
            'existing_count' => 0,
            'journal_entry_count' => 0,
            'non_journal_count' => 3,
            'missing_accounts' => [],
        ];
        $batchValues = [
            'file_name' => $fileName,
            'source_date_range' => '2025-01-01 — 2025-01-01',
            'total_entries' => 3,
            'imported_entries' => 0,
            'skipped_entries' => 0,
            'unbalanced_entries' => 0,
            'duplicate_entries' => 0,
            'missing_accounts' => 0,
            'unsupported_entries' => 0,
            'processed_entries' => 0,
            'last_message' => null,
            'error_message' => null,
            'file_path' => null,
            'analysis_path' => null,
            'import_options' => '{}',
            'status' => 'analyzed',
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $userId,
            'reverted_by' => null,
            'reverted_at' => null,
            'updated_at' => $now,
        ];

        if ($batch) {
            $batchId = (int) $batch->id;
            DB::table('accounting_journal_imports')->where('id', $batchId)->update($batchValues);
        } else {
            $batchId = (int) DB::table('accounting_journal_imports')->insertGetId($batchValues + [
                'business_id' => $businessId,
                'created_at' => $now,
            ]);
        }
        DB::table('accounting_journal_import_rows')->where('journal_import_id', $batchId)->delete();

        $entries = [
            ['number' => 'CI-BULK-EXPLAINED', 'reconciliation' => 'auto_approved'],
            ['number' => 'CI-BULK-UNEXPLAINED', 'reconciliation' => 'mismatch'],
            ['number' => 'CI-BULK-NO-DIFFERENCE', 'reconciliation' => 'not_checked'],
        ];
        foreach ($entries as $index => $fixture) {
            $entry = [
                'source_type' => 'Expense',
                'source_number' => $fixture['number'],
                'source_key' => 'Expense|'.$fixture['number'].'|2025-01-01',
                'source_link_status' => 'unmatched',
                'date' => '2025-01-01',
                'description' => 'Journal Import bulk review fixture '.$fixture['number'],
                'debit_total' => 100,
                'credit_total' => 100,
                'lines' => [],
            ];
            DB::table('accounting_journal_import_rows')->insert([
                'journal_import_id' => $batchId,
                'business_id' => $businessId,
                'entry_index' => $index + 1,
                'source_type' => 'Expense',
                'source_number' => $fixture['number'],
                'source_key' => $entry['source_key'],
                'source_action' => 'create',
                'source_record_type' => null,
                'source_record_id' => null,
                'draft_data' => '{}',
                'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_reviewed' => 0,
                'processing_status' => 'pending',
                'processing_attempts' => 0,
                'reconciliation_status' => $fixture['reconciliation'],
                'journal_total' => 100,
                'business_total' => $fixture['reconciliation'] === 'not_checked' ? 100 : 99,
                'difference_amount' => $fixture['reconciliation'] === 'not_checked' ? 0 : 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedReconciliationFixture(int $businessId): void
    {
        app(LegacyReconciliationFindingSnapshotService::class)->persistReport($businessId, [
            'business_id' => $businessId,
            'year' => 2022,
            'generated_at' => '2026-11-04T10:00:00+00:00',
            'source_manifest_sha256' => str_repeat('c', 64),
            'transaction_reconciliation' => [[
                'date' => '2022-01-15',
                'source_type' => 'Journal Entry',
                'source_number' => 'CI-JRN-UNBALANCED',
                'description' => 'Fixture Playwright temuan tidak seimbang',
                'operational_identity' => null,
                'journal_debit' => 125,
                'journal_credit' => 100,
                'journal_balance_variance' => 25,
            ]],
            'account_balances' => [],
            'control_comparison' => [],
        ]);
    }

    private function ensurePermissions(int $userId): void
    {
        $permissions = collect([
            'accounting.add_journal',
            'accounting.view_reports',
            'accounting.manage_accounts',
            'product.create',
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
