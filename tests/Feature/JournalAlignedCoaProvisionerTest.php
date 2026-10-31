<?php

namespace Tests\Feature;

use App\Business;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalAccountTypeResolver;
use Modules\Accounting\Services\JournalAlignedCoaProvisioner;
use Tests\TestCase;

class JournalAlignedCoaProvisionerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for isolated CoA provisioning tests.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('business', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->unsignedInteger('owner_id')->nullable();
            $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id')->nullable();
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->unsignedInteger('account_type_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_id')->nullable();
            $table->string('name');
            $table->string('gl_code')->nullable();
            $table->unsignedInteger('business_id');
            $table->string('account_primary_type')->nullable();
            $table->unsignedInteger('account_sub_type_id')->nullable();
            $table->unsignedInteger('detail_type_id')->nullable();
            $table->unsignedInteger('parent_account_id')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('created_by');
            $table->timestamps();
        });
    }

    public function test_provisions_journal_accounts_with_business_specific_bank_accounts_and_safe_crosswalks(): void
    {
        DB::table('business')->insert([
            ['id' => 2, 'name' => 'CV BM TRANSPORT', 'owner_id' => 1],
            ['id' => 3, 'name' => 'Business Lain', 'owner_id' => 2],
        ]);

        $this->insertOperationalAccounts(2, [
            [7, 'Mandiri BMT'],
            [8, 'Cash'],
            [9, 'Mandiri - 9641'],
            [10, 'Mandiri PT'],
            [11, 'Giro'],
            [12, 'Petty Cash Pool'],
            [14, 'Employee Receivables'],
            [15, 'Accumulated Depreciation - Vehicles'],
            [16, 'Accumulated Depreciation - Machinery & Equipment'],
            [17, 'Account Receivable'],
            [18, 'Trade Payable'],
            [19, 'Purchase Discounts'],
            [20, 'Employee Receivables (Expenses)'],
            [21, 'Employee Benefit Expense'],
            [25, 'Giro'],
        ]);

        DB::table('accounting_accounts')->insert([
            [
                'account_id' => 7,
                'name' => 'MANDIRI - BMT',
                'gl_code' => '1-10004',
                'business_id' => 2,
                'account_primary_type' => 'asset',
                'created_by' => 1,
            ],
            [
                'account_id' => 17,
                'name' => 'Accounts Receivable (A/R)',
                'gl_code' => null,
                'business_id' => 2,
                'account_primary_type' => 'asset',
                'created_by' => 1,
            ],
            [
                'account_id' => 18,
                'name' => 'Trade Payable',
                'gl_code' => '2-20100',
                'business_id' => 2,
                'account_primary_type' => 'liability',
                'created_by' => 1,
            ],
        ]);

        $service = new JournalAlignedCoaProvisioner();
        $bmResult = $service->provisionBusiness(2);
        $otherResult = $service->provisionBusiness(3);

        $bmAccounts = DB::table('accounting_accounts')->where('business_id', 2)->get()->keyBy('gl_code');
        $otherAccounts = DB::table('accounting_accounts')->where('business_id', 3)->get()->keyBy('gl_code');

        $this->assertSame(70, $bmAccounts->count());
        $this->assertSame(65, $otherAccounts->count());
        $this->assertSame('expenses', $bmAccounts['5-50000']->account_primary_type);
        $this->assertSame('expenses', $bmAccounts['5-50100']->account_primary_type);
        $this->assertSame('expenses', $bmAccounts['8-80005']->account_primary_type);
        $this->assertSame('income', $bmAccounts['4-40000']->account_primary_type);
        $this->assertSame('asset', $bmAccounts['1-10500']->account_primary_type);
        $this->assertSame('Cash and cash equivalents', $otherAccounts['1-10001']->name);

        foreach (['1-10004', '1-10005', '1-10006', '1-10007', '8-80005'] as $businessSpecificCode) {
            $this->assertFalse($otherAccounts->has($businessSpecificCode));
        }
        $this->assertSame(7, (int) $bmAccounts['1-10004']->account_id);
        $this->assertSame(17, (int) $bmAccounts['1-10100']->account_id);
        $this->assertSame('1-10100', $bmAccounts['1-10100']->gl_code);
        $this->assertSame(18, (int) $bmAccounts['2-20100']->account_id);
        $this->assertSame(0, DB::table('accounting_accounts')->where('business_id', 2)->whereIn('account_id', [11, 25])->count());
        $this->assertSame(0, DB::table('accounting_accounts')->where('business_id', 3)->whereNotNull('account_id')->count());
        $this->assertSame([], $bmResult['conflicts']);
        $this->assertSame([], $otherResult['conflicts']);
    }

    public function test_provisioning_is_idempotent_and_leaves_ambiguous_operational_names_unmapped(): void
    {
        DB::table('business')->insert(['id' => 2, 'name' => 'CV BM TRANSPORT', 'owner_id' => 1]);
        $this->insertOperationalAccounts(2, [
            [14, 'Employee Receivables'],
            [99, 'Employee Receivables'],
        ]);

        $service = new JournalAlignedCoaProvisioner();
        $first = $service->provisionBusiness(2);
        $second = $service->provisionBusiness(2);

        $this->assertSame(70, DB::table('accounting_accounts')->where('business_id', 2)->count());
        $this->assertSame(0, DB::table('accounting_accounts')->where('business_id', 2)->where('gl_code', '1-10103')->whereNotNull('account_id')->count());
        $this->assertContains('operational_account_name_not_unique', array_column($first['mapping_skips'], 'reason'));
        $this->assertSame(0, $second['created']);
        $this->assertSame([], $second['conflicts']);
    }

    public function test_conflicting_existing_account_type_is_reported_without_overwriting_it(): void
    {
        DB::table('business')->insert(['id' => 4, 'name' => 'Business Konflik', 'owner_id' => 4]);
        DB::table('accounting_accounts')->insert([
            'name' => 'Cost of Sales',
            'gl_code' => '5-50000',
            'business_id' => 4,
            'account_primary_type' => 'income',
            'created_by' => 4,
        ]);

        $result = (new JournalAlignedCoaProvisioner())->provisionBusiness(4);
        $account = DB::table('accounting_accounts')
            ->where('business_id', 4)
            ->where('gl_code', '5-50000')
            ->first();

        $this->assertSame('income', $account->account_primary_type);
        $this->assertContains('account_primary_type_mismatch', array_column($result['conflicts'], 'reason'));
    }

    public function test_business_creation_path_provisions_the_shared_template(): void
    {
        $business = Business::create([
            'name' => 'New business',
            'owner_id' => 900,
        ]);

        $accounts = DB::table('accounting_accounts')->where('business_id', $business->id)->get();

        $this->assertSame(65, $accounts->count());
        $this->assertNotNull($accounts->firstWhere('gl_code', '5-50000'));
        $this->assertNull($accounts->firstWhere('gl_code', '1-10004'));
        $this->assertNull($accounts->firstWhere('gl_code', '8-80005'));
    }

    public function test_journal_account_type_resolver_classifies_series_five_as_expenses(): void
    {
        $this->assertSame('asset', JournalAccountTypeResolver::fromGlCode('1-10001'));
        $this->assertSame('liability', JournalAccountTypeResolver::fromGlCode('2-20001'));
        $this->assertSame('equity', JournalAccountTypeResolver::fromGlCode('3-30001'));
        $this->assertSame('income', JournalAccountTypeResolver::fromGlCode('4-40000'));
        $this->assertSame('expenses', JournalAccountTypeResolver::fromGlCode('5-50000'));
        $this->assertSame('expenses', JournalAccountTypeResolver::fromGlCode('6-60000'));
        $this->assertSame('expenses', JournalAccountTypeResolver::fromGlCode('8-80005'));
        $this->assertNull(JournalAccountTypeResolver::fromGlCode('7-70000'));
        $this->assertNull(JournalAccountTypeResolver::fromGlCode(''));
        $this->assertNull(JournalAccountTypeResolver::fromGlCode(null));
    }

    public function test_journal_import_rejects_unsupported_prefix_without_leaving_guessed_accounts(): void
    {
        $entry = [
            'source_type' => 'Journal Entry',
            'source_number' => 'JE-UNSUPPORTED-1',
            'date' => '2026-10-01',
            'description' => 'Unsupported GL prefix',
            'is_unbalanced' => false,
            'is_duplicate' => false,
            'exists_in_system' => false,
            'lines' => [[
                'account_no' => '7-70000',
                'account_name' => 'Suspense account',
                'debit' => 100,
                'credit' => 0,
            ]],
        ];
        $import = (object) ['id' => 44, 'business_id' => 8, 'created_by' => 9];

        try {
            DB::transaction(function () use ($entry, $import) {
                app(JournalEntryController::class)->processJournalImportEntriesForBatch($import, [$entry], [
                    'create_missing_accounts' => true,
                    'create_missing_main_accounts' => true,
                ]);
            });
            $this->fail('Unsupported GL prefixes must require a manual CoA mapping.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('7-70000', $exception->getMessage());
            $this->assertStringContainsString('mapping CoA secara manual', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('accounting_accounts')->count());
        $this->assertSame(0, DB::table('accounts')->count());
    }

    private function insertOperationalAccounts(int $businessId, array $accounts): void
    {
        foreach ($accounts as [$id, $name]) {
            DB::table('accounts')->insert([
                'id' => $id,
                'business_id' => $businessId,
                'name' => $name,
            ]);
        }
    }
}
