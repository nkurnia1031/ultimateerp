<?php

namespace Tests\Feature;

use App\Transaction;
use App\User;
use App\Utils\ModuleUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class JournalImportSourceGroupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
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
        $this->createSchema();

        DB::table('accounting_journal_imports')->insert([
            'id' => 5,
            'business_id' => 7,
            'status' => 'analyzed',
            'import_options' => '{}',
        ]);
        DB::table('business_locations')->insert([
            'id' => 70,
            'business_id' => 7,
            'name' => 'Lokasi test',
            'is_active' => 1,
        ]);

        $moduleUtil = Mockery::mock(ModuleUtil::class);
        $moduleUtil->shouldReceive('hasThePermissionInSubscription')->byDefault()->andReturn(true);
        $this->app->instance(ModuleUtil::class, $moduleUtil);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_public_preview_suggests_only_one_exact_expense_group_and_confirms_all_members_idempotently(): void
    {
        $this->insertSplitSources('Expense', 'EXP-SPLIT', 10, [60, 40], [6, 4]);
        $this->insertJournalRow(1, 'Expense', 'EXP-SPLIT', [10, 11]);

        $preview = $this->request('get', '/accounting/journal-entry/import/5/source-groups/preview');
        $preview->assertOk()
            ->assertJsonPath('unique_group_count', 1)
            ->assertJsonPath('source_record_count', 2)
            ->assertJsonPath('summary.source_base', 100)
            ->assertJsonPath('summary.source_tax', 10)
            ->assertJsonPath('summary.source_total', 110)
            ->assertJsonPath('summary.journal_base', 100)
            ->assertJsonPath('summary.journal_tax', 10)
            ->assertJsonPath('summary.journal_total', 110);

        $fingerprint = $preview->json('fingerprint');
        $this->request('post', '/accounting/journal-entry/import/5/source-groups/confirm', ['fingerprint' => $fingerprint])
            ->assertOk()
            ->assertJsonPath('confirmed_rows', 1)
            ->assertJsonPath('source_record_count', 2);

        $this->assertSame(2, DB::table('accounting_journal_import_source_links')->count());
        $this->assertSame('link', DB::table('accounting_journal_import_rows')->where('id', 1)->value('source_action'));
        $this->assertSame(10, (int) DB::table('accounting_journal_import_rows')->where('id', 1)->value('source_record_id'));
        $this->assertSame(1, (int) DB::table('accounting_journal_import_rows')->where('id', 1)->value('is_reviewed'));
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count(), 'Group confirmation must not post or duplicate a GL Journal.');

        $this->request('post', '/accounting/journal-entry/import/5/source-groups/confirm', ['fingerprint' => $fingerprint])
            ->assertOk()
            ->assertJsonPath('confirmed_rows', 0)
            ->assertJsonPath('already_confirmed_rows', 1);
        $this->assertSame(2, DB::table('accounting_journal_import_source_links')->count());
    }

    public function test_multiple_exact_groups_remain_unselected_until_user_chooses_one(): void
    {
        $this->insertSplitSources('Expense', 'EXP-AMBIG', 20, [60, 40, 50, 50], [6, 4, 5, 5]);
        $this->insertJournalRow(1, 'Expense', 'EXP-AMBIG', [20, 21, 22, 23]);

        $this->request('get', '/accounting/journal-entry/import/5/source-groups/preview')
            ->assertOk()
            ->assertJsonPath('unique_group_count', 0)
            ->assertJsonPath('ambiguous_row_count', 1);
        $this->assertSame('review', DB::table('accounting_journal_import_rows')->where('id', 1)->value('source_action'));
        $this->assertSame(0, DB::table('accounting_journal_import_source_links')->count());

        $this->request('post', '/accounting/journal-entry/import/5/draft-rows/1/source-group', ['member_ids' => [22, 23]])
            ->assertOk()
            ->assertJsonPath('row.source_link_group.status', 'confirmed')
            ->assertJsonPath('row.source_link_group.method', 'manual_choice');
        $this->assertSame([22, 23], DB::table('accounting_journal_import_source_links')->orderBy('source_record_id')->pluck('source_record_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('link', DB::table('accounting_journal_import_rows')->where('id', 1)->value('source_action'));
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->count());
        $this->request('post', '/accounting/journal-entry/import/5/draft-rows/1/source-group', ['member_ids' => [22, 23]])->assertOk();
        $this->assertSame(2, DB::table('accounting_journal_import_source_links')->count());
    }

    public function test_purchase_invoice_group_is_supported_and_foreign_business_candidate_is_ignored(): void
    {
        $this->insertSplitSources('Purchase Invoice', 'PUR-SPLIT', 30, [60, 40], [6, 4]);
        DB::table('transactions')->insert([
            'id' => 90,
            'business_id' => 8,
            'type' => 'purchase',
            'invoice_no' => 'PUR-SPLIT',
            'transaction_date' => '2022-01-01 00:00:00',
            'total_before_tax' => 100,
            'tax_amount' => 10,
            'final_total' => 110,
        ]);
        $this->insertJournalRow(1, 'Purchase Invoice', 'PUR-SPLIT', [30, 31, 90]);

        $this->request('get', '/accounting/journal-entry/import/5/source-groups/preview')
            ->assertOk()
            ->assertJsonPath('unique_group_count', 1)
            ->assertJsonPath('source_record_count', 2);
        $this->request('post', '/accounting/journal-entry/import/5/source-groups/confirm', [
            'fingerprint' => $this->request('get', '/accounting/journal-entry/import/5/source-groups/preview')->json('fingerprint'),
        ])->assertOk();

        $this->assertSame([30, 31], DB::table('accounting_journal_import_source_links')->orderBy('source_record_id')->pluck('source_record_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_legacy_single_source_link_remains_available_through_the_public_draft_rows_endpoint(): void
    {
        $this->insertSplitSources('Expense', 'EXP-LEGACY', 40, [100], [10]);
        $this->insertJournalRow(1, 'Expense', 'EXP-LEGACY', [40]);
        $entry = json_decode((string) DB::table('accounting_journal_import_rows')->where('id', 1)->value('entry_data'), true);
        $entry['source_link_status'] = 'matched';
        DB::table('accounting_journal_import_rows')->where('id', 1)->update([
            'source_action' => 'link',
            'source_record_type' => Transaction::class,
            'source_record_id' => 40,
            'entry_data' => json_encode($entry),
        ]);

        $this->request('get', '/accounting/journal-entry/import/5/draft-rows?type=Expense&per_page=100')
            ->assertOk()
            ->assertJsonPath('rows.0.source_action', 'link')
            ->assertJsonPath('rows.0.source_record_type', Transaction::class)
            ->assertJsonPath('rows.0.source_record_id', 40)
            ->assertJsonPath('rows.0.source_link_candidate_details.0.id', 40);
    }

    public function test_group_amount_difference_above_one_cent_is_not_treated_as_an_exact_match(): void
    {
        $this->insertSplitSources('Expense', 'EXP-TOLERANCE', 50, [60, 40.014], [6, 4]);
        $this->insertJournalRow(1, 'Expense', 'EXP-TOLERANCE', [50, 51]);

        $this->request('get', '/accounting/journal-entry/import/5/source-groups/preview')
            ->assertOk()
            ->assertJsonPath('unique_group_count', 0)
            ->assertJsonPath('ambiguous_row_count', 0);
        $this->assertSame(0, DB::table('accounting_journal_import_source_links')->count());
    }

    public function test_bulk_preview_leaves_groups_that_reuse_a_source_across_journal_rows_unresolved(): void
    {
        $this->insertSplitSources('Expense', 'EXP-REUSED', 60, [60, 40], [6, 4]);
        $this->insertJournalRow(1, 'Expense', 'EXP-REUSED', [60, 61]);
        $this->insertJournalRow(2, 'Expense', 'EXP-REUSED', [60, 61]);

        $this->request('get', '/accounting/journal-entry/import/5/source-groups/preview')
            ->assertOk()
            ->assertJsonPath('unique_group_count', 0)
            ->assertJsonPath('conflicting_row_count', 2);
        $this->assertSame(0, DB::table('accounting_journal_import_source_links')->count());
    }

    private function request(string $method, string $uri, array $data = [])
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 55;
        $user->shouldReceive('can')->with('superadmin')->andReturn(false);
        $user->shouldReceive('can')->with('accounting.add_journal')->andReturn(true);

        $request = $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ])->actingAs($user)->withSession(['user' => ['business_id' => 7]]);

        return match (strtolower($method)) {
            'get' => $request->getJson($uri),
            'post' => $request->postJson($uri, $data),
            default => throw new \InvalidArgumentException('HTTP test verb tidak didukung.'),
        };
    }

    private function insertSplitSources(string $sourceType, string $reference, int $firstId, array $bases, array $taxes): void
    {
        $transactionType = $sourceType === 'Expense' ? 'expense' : 'purchase';
        foreach ($bases as $index => $base) {
            $tax = $taxes[$index];
            DB::table('transactions')->insert([
                'id' => $firstId + $index,
                'business_id' => 7,
                'type' => $transactionType,
                'invoice_no' => $reference,
                'transaction_date' => '2022-01-01 00:00:00',
                'total_before_tax' => $base,
                'tax_amount' => $tax,
                'final_total' => $base + $tax,
            ]);
        }
    }

    private function insertJournalRow(int $id, string $sourceType, string $reference, array $candidateIds): void
    {
        $transactionType = $sourceType === 'Expense' ? 'expense' : 'purchase';
        $candidates = array_map(fn (int $candidateId): array => [
            'id' => $candidateId,
            'record_type' => Transaction::class,
        ], $candidateIds);
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $id,
            'journal_import_id' => 5,
            'business_id' => 7,
            'entry_index' => $id,
            'source_type' => $sourceType,
            'source_number' => $reference,
            'source_action' => 'review',
            'entry_data' => json_encode([
                'source_type' => $sourceType,
                'source_number' => $reference,
                'date' => '2022-01-01',
                'description' => 'Journal split test',
                'debit_total' => 110,
                'credit_total' => 110,
                'lines' => [
                    ['account_name' => 'Office cost', 'type' => 'debit', 'debit' => 100],
                    ['account_name' => 'VAT In', 'type' => 'debit', 'debit' => 10],
                    ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 110],
                ],
                'source_link_status' => 'ambiguous',
                'source_link_candidate_details' => $candidates,
            ]),
            'draft_data' => '{}',
            'is_reviewed' => 0,
            'processing_status' => 'pending',
            'reconciliation_status' => 'not_checked',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('accounting_journal_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('status');
            $table->longText('import_options')->nullable();
        });
        Schema::create('accounting_journal_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type');
            $table->text('source_number')->nullable();
            $table->string('source_action');
            $table->string('source_record_type')->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->longText('entry_data');
            $table->longText('draft_data')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('processing_status')->default('pending');
            $table->string('reconciliation_status')->default('not_checked');
            $table->timestamps();
        });
        Schema::create('accounting_journal_import_source_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedBigInteger('journal_import_row_id');
            $table->string('journal_source_type', 100);
            $table->string('source_record_type', 64);
            $table->unsignedBigInteger('source_record_id');
            $table->text('source_reference')->nullable();
            $table->date('source_date')->nullable();
            $table->decimal('base_amount', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('total_amount', 22, 4)->nullable();
            $table->string('link_method', 32)->default('single');
            $table->timestamps();
            $table->unique(['journal_import_row_id', 'source_record_type', 'source_record_id']);
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('expense_category_id')->nullable();
            $table->text('additional_notes')->nullable();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('supplier_business_name')->nullable();
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
        });
        Schema::create('accounting_acc_trans_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
        });
    }
}
