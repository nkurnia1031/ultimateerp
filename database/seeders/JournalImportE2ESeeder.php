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

            foreach ([
                ['name' => 'CI Test Cash', 'code' => 'CI-1000', 'type' => 'asset'],
                ['name' => 'CI Test Equity', 'code' => 'CI-3000', 'type' => 'equity'],
            ] as $account) {
                $mainAccountId = DB::table('accounts')->insertGetId([
                    'business_id' => $businessId,
                    'name' => $account['name'],
                    'account_number' => $account['code'],
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('accounting_accounts')->insert([
                    'name' => $account['name'],
                    'gl_code' => $account['code'],
                    'business_id' => $businessId,
                    'account_id' => $mainAccountId,
                    'account_primary_type' => $account['type'],
                    'status' => 'active',
                    'created_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissions = collect(['accounting.add_journal', 'superadmin'])
                ->map(fn ($name) => Permission::firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                ]));

            User::findOrFail($userId)->givePermissionTo($permissions);
        });
    }
}
