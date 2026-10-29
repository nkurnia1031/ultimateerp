<?php

namespace Tests\Unit;

use Modules\Accounting\Services\JournalImportDraftService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use PHPUnit\Framework\TestCase;

class JournalImportDraftServiceTest extends TestCase
{
    private ?Capsule $database = null;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_it_exposes_all_workbook_types_in_preview_order(): void
    {
        $this->assertSame([
            'Journal Entry',
            'Sales Invoice',
            'Purchase Invoice',
            'Expense',
            'Receive Payment',
            'Purchase Payment',
            'Bank Deposit',
            'Bank Transfer',
            'Bank Withdrawal',
            'Stock Adjustment',
            'Stock In/Out',
            'Opening Balance',
            'Reversal',
        ], (new JournalImportDraftService())->sourceTypes());
    }

    public function test_default_action_reflects_source_match_state(): void
    {
        $service = new JournalImportDraftService();

        $this->assertSame('journal', $service->defaultActionFor(['source_type' => 'Journal Entry']));
        $this->assertSame('link', $service->defaultActionFor(['source_type' => 'Sales Invoice', 'source_link_status' => 'matched']));
        $this->assertSame('update', $service->defaultActionFor(['source_type' => 'Expense', 'source_link_status' => 'matched', 'source_link_default_action' => 'update']));
        $this->assertSame('create', $service->defaultActionFor(['source_type' => 'Expense', 'source_link_status' => 'unmatched']));
        $this->assertSame('review', $service->defaultActionFor(['source_type' => 'Receive Payment', 'source_link_status' => 'ambiguous']));
        $this->assertSame('review', $service->defaultActionFor(['source_type' => 'Expense', 'source_link_status' => 'suggested']));
    }

    public function test_ambiguous_source_requires_a_known_candidate_or_explicit_create(): void
    {
        $service = new JournalImportDraftService();
        $entry = [
            'source_type' => 'Sales Invoice',
            'source_link_status' => 'ambiguous',
            'source_link_candidate_details' => [
                ['record_type' => 'App\\Transaction', 'id' => 31],
                ['record_type' => 'App\\Transaction', 'id' => 32],
            ],
        ];

        $this->assertTrue($service->isValidSourceDecision($entry, 'update', 'App\\Transaction', 32));
        $this->assertTrue($service->isValidSourceDecision($entry, 'create', null, null));
        $this->assertFalse($service->isValidSourceDecision($entry, 'update', 'App\\Transaction', 99));
        $this->assertFalse($service->isValidSourceDecision($entry, 'create', 'App\\Transaction', 32));
    }

    public function test_suggested_candidate_allows_explicit_source_choice_or_create(): void
    {
        $service = new JournalImportDraftService();
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'suggested',
            'source_link_candidate_details' => [
                ['record_type' => 'App\\Transaction', 'id' => 63],
            ],
        ];

        $this->assertSame('review', $service->defaultActionFor($entry));
        $this->assertTrue($service->isValidSourceDecision($entry, 'update', 'App\\Transaction', 63));
        $this->assertTrue($service->isValidSourceDecision($entry, 'link', 'App\\Transaction', 63));
        $this->assertTrue($service->isValidSourceDecision($entry, 'create', null, null));
    }

    public function test_default_location_is_the_first_active_location_in_the_same_business(): void
    {
        $this->useSqliteDatabase();
        $this->database->schema()->create('business_locations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->boolean('is_active')->default(true);
            $table->dateTime('deleted_at')->nullable();
        });
        DB::table('business_locations')->insert([
            ['id' => 8, 'business_id' => 3, 'is_active' => 1, 'deleted_at' => null],
            ['id' => 4, 'business_id' => 3, 'is_active' => 0, 'deleted_at' => null],
            ['id' => 2, 'business_id' => 4, 'is_active' => 1, 'deleted_at' => null],
        ]);

        $this->assertSame(8, (new JournalImportDraftService())->defaultActiveLocationId(3));
        $this->assertNull((new JournalImportDraftService())->defaultActiveLocationId(5));
    }

    public function test_persisting_entries_twice_keeps_one_resumable_row(): void
    {
        $this->useSqliteDatabase();
        $this->database->schema()->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('entry_index');
            $table->string('source_type', 100);
            $table->string('source_number', 191)->nullable();
            $table->string('source_key', 191)->nullable();
            $table->string('source_action', 16)->default('review');
            $table->string('source_record_type', 64)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->longText('draft_data')->nullable();
            $table->longText('entry_data');
            $table->boolean('is_reviewed')->default(false);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['journal_import_id', 'entry_index']);
        });

        $entry = [
            'source_type' => 'Sales Invoice',
            'source_number' => 'INV-DRAFT-1',
            'source_key' => 'Sales Invoice|INV-DRAFT-1',
            'source_link_status' => 'unmatched',
            'date' => '2025-01-15',
            'description' => 'Draft invoice',
            'debit_total' => 100,
            'credit_total' => 100,
        ];
        $service = new JournalImportDraftService();
        $service->persistEntries(44, 3, [$entry]);
        $service->persistEntries(44, 3, [$entry]);

        $this->assertSame(1, DB::table('accounting_journal_import_rows')->count());
        $row = DB::table('accounting_journal_import_rows')->first();
        $this->assertSame('create', $row->source_action);
        $this->assertSame('INV-DRAFT-1', $row->source_number);
        $this->assertSame('Draft invoice', json_decode($row->entry_data, true)['description']);
        $this->assertSame([], json_decode($row->draft_data, true));
    }

    public function test_selected_sources_are_rechecked_against_active_business(): void
    {
        $this->useSqliteDatabase();
        $this->database->schema()->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('type');
        });
        DB::table('transactions')->insert([
            ['id' => 10, 'business_id' => 3, 'type' => 'sell'],
            ['id' => 11, 'business_id' => 4, 'type' => 'sell'],
            ['id' => 12, 'business_id' => 3, 'type' => 'purchase'],
        ]);

        $service = new JournalImportDraftService();

        $this->assertTrue($service->isCurrentSourceRecordAllowed('Sales Invoice', 'App\\Transaction', 10, 3));
        $this->assertFalse($service->isCurrentSourceRecordAllowed('Sales Invoice', 'App\\Transaction', 11, 3));
        $this->assertFalse($service->isCurrentSourceRecordAllowed('Sales Invoice', 'App\\Transaction', 12, 3));
    }

    public function test_incomplete_detail_rows_remain_saveable_as_drafts(): void
    {
        $this->useSqliteDatabase();

        $draftData = [
            'details' => [[
                'variation_id' => null,
                'quantity' => 2,
                'unit_price' => null,
                'account_id' => null,
            ]],
        ];

        $validated = (new JournalImportDraftService())->validateDraftData($draftData, 3, 'Sales Invoice');

        $this->assertSame($draftData, $validated);
    }

    public function test_empty_detail_rows_are_preserved_until_the_user_fills_them(): void
    {
        $this->useSqliteDatabase();

        $draftData = [
            'details' => [[
                'variation_id' => null,
                'quantity' => null,
                'unit_price' => null,
                'account_id' => null,
            ]],
        ];

        $validated = (new JournalImportDraftService())->validateDraftData($draftData, 3, 'Sales Invoice');

        $this->assertSame($draftData, $validated);
    }

    public function test_expense_account_category_mapping_accepts_only_parent_categories_in_the_active_business(): void
    {
        $this->useSqliteDatabase();
        $this->database->schema()->create('expense_categories', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        DB::table('expense_categories')->insert([
            ['id' => 57, 'business_id' => 3, 'parent_id' => null, 'deleted_at' => null],
            ['id' => 58, 'business_id' => 4, 'parent_id' => null, 'deleted_at' => null],
            ['id' => 59, 'business_id' => 3, 'parent_id' => 57, 'deleted_at' => null],
        ]);
        $key = md5('6101|office supplies');
        $service = new JournalImportDraftService();

        $validated = $service->validateDraftData([
            'expense_category_mappings' => [$key => 57],
        ], 3, 'Expense');
        $this->assertSame(57, (int) $validated['expense_category_mappings'][$key]);

        foreach ([58, 59] as $invalidCategoryId) {
            try {
                $service->validateDraftData([
                    'expense_category_mappings' => [$key => $invalidCategoryId],
                ], 3, 'Expense');
                $this->fail('A cross-business or child expense category must not be accepted.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('draft_data.expense_category_mappings', $exception->errors());
            }
        }
    }

    private function useSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $container = new Container();
        $this->database = new Capsule($container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->database->setAsGlobal();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('date', new DateFactory());
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);
    }
}
