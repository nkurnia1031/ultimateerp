<?php

namespace Tests\Unit;

use App\Services\ExpenseImportService;
use App\Services\ExpenseImportJournalMatcher;
use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ExpenseImportTaxTest extends TestCase
{
    private Capsule $database;

    private Container $container;

    private ExpenseImportService $service;

    private ReflectionMethod $validateRow;

    private ReflectionMethod $validateGroupTaxes;

    private ReflectionMethod $importGroup;

    private ReflectionMethod $applyJournalMatches;

    private ReflectionMethod $applyExistingExpenseMatches;

    private ReflectionMethod $journalMatchNeedsRefresh;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite tidak tersedia pada PHP runtime ini.');
        }

        $this->container = new Container();
        $this->database = new Capsule($this->container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->database->setAsGlobal();
        EloquentModel::setConnectionResolver($this->database->getDatabaseManager());
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->container->instance('db.schema', $this->database->schema());
        $this->container->instance('date', new DateFactory());
        Facade::setFacadeApplication($this->container);

        $this->createSchema();
        $this->createMasterData();
        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function payment_types($location_id = null, $show_advance = false, $business_id = null)
                {
                    return [];
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );
        $this->validateRow = new ReflectionMethod(ExpenseImportService::class, 'validateRow');
        $this->validateRow->setAccessible(true);
        $this->validateGroupTaxes = new ReflectionMethod(ExpenseImportService::class, 'validateGroupTaxes');
        $this->validateGroupTaxes->setAccessible(true);
        $this->importGroup = new ReflectionMethod(ExpenseImportService::class, 'importGroup');
        $this->importGroup->setAccessible(true);
        $this->applyJournalMatches = new ReflectionMethod(ExpenseImportService::class, 'applyJournalMatches');
        $this->applyJournalMatches->setAccessible(true);
        $this->applyExistingExpenseMatches = new ReflectionMethod(ExpenseImportService::class, 'applyExistingExpenseMatches');
        $this->applyExistingExpenseMatches->setAccessible(true);
        $this->journalMatchNeedsRefresh = new ReflectionMethod(ExpenseImportService::class, 'journalMatchNeedsRefresh');
        $this->journalMatchNeedsRefresh->setAccessible(true);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        EloquentModel::unsetConnectionResolver();
        parent::tearDown();
    }

    public function test_known_tax_is_matched_and_unknown_tax_is_flagged_for_preview_mapping(): void
    {
        $known = $this->validate('VAT');
        $unknown = $this->validate('Special Tax');
        $zero = $this->validate('0');
        $mapped = $this->validate('Special Tax', 'REF-MAPPED', 110, 'Mapped expense', ['tax_name:special tax' => 3]);

        $this->assertSame('matched', $known['normalized_payload']['tax_status']);
        $this->assertSame(2, (int) $known['normalized_payload']['tax_id']);
        $this->assertTrue($known['is_importable'], implode(' | ', $known['errors']));
        $this->assertSame('unknown', $unknown['normalized_payload']['tax_status']);
        $this->assertFalse($unknown['is_importable']);
        $this->assertStringContainsString('Pilih TaxRate yang tersedia dari preview', implode(' ', $unknown['errors']));
        $this->assertSame('none', $zero['normalized_payload']['tax_status']);
        $this->assertTrue($zero['is_importable'], implode(' | ', $zero['errors']));
        $this->assertSame('mapped', $mapped['normalized_payload']['tax_status']);
        $this->assertSame(3, (int) $mapped['normalized_payload']['tax_id']);
        $this->assertTrue($mapped['is_importable']);
        $this->assertSame(2, DB::table('tax_rates')->count(), 'Preview mapping must not create TaxRate rows.');
    }

    public function test_journal_expense_match_uses_exact_reference_and_holds_duplicates_for_selection(): void
    {
        $this->insertJournalExpense(100, 10, 25, 'REF-JOURNAL', 10);
        $matcher = new ExpenseImportJournalMatcher(
            new \Modules\Accounting\Services\JournalImportAccountComponentService(),
            new \Modules\Accounting\Services\JournalImportTaxResolver()
        );

        $unique = $matcher->matchReferences(1, ['REF-JOURNAL'], [], ['ref-journal' => 100])['ref-journal'];
        $this->assertSame('matched', $unique['status']);
        $this->assertSame(10.0, (float) $unique['selected']['tax_amount']);
        $this->assertSame(2, (int) $unique['selected']['tax_id']);

        $this->insertJournalExpense(101, 11, 26, 'REF-JOURNAL', 10);
        $ambiguous = $matcher->matchReferences(1, ['REF-JOURNAL'], [], ['ref-journal' => 100])['ref-journal'];
        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertCount(2, $ambiguous['candidates']);

        $selected = $matcher->matchReferences(1, ['REF-JOURNAL'], [
            'journal_candidate:ref-journal' => 26,
        ], ['ref-journal' => 100])['ref-journal'];
        $this->assertSame('matched', $selected['status']);
        $this->assertTrue($selected['selected_by_user']);
        $this->assertSame(26, (int) $selected['selected']['mapping_id']);

        $this->insertJournalExpense(102, 12, 27, 'REF-NO-TAX', 0);
        $noTax = $matcher->matchReferences(1, ['REF-NO-TAX'], [], ['ref-no-tax' => 100])['ref-no-tax'];
        $this->assertSame('matched', $noTax['status']);
        $this->assertSame(0.0, (float) $noTax['selected']['tax_amount']);
        $this->assertSame('Journal: tanpa pajak', $noTax['selected']['tax_label']);
    }

    public function test_one_ready_journal_posting_is_selected_when_other_same_reference_row_has_no_gl_mapping(): void
    {
        $this->insertJournalExpense(100, 10, 25, 'REF-ONE-READY', 0);
        $this->insertJournalExpense(101, 11, null, 'REF-ONE-READY', 0);
        $matcher = new ExpenseImportJournalMatcher(
            new \Modules\Accounting\Services\JournalImportAccountComponentService(),
            new \Modules\Accounting\Services\JournalImportTaxResolver()
        );

        $match = $matcher->matchReferences(1, ['REF-ONE-READY'], [], ['ref-one-ready' => 100])['ref-one-ready'];

        $this->assertSame('matched', $match['status']);
        $this->assertSame(25, (int) $match['selected']['mapping_id']);
        $this->assertCount(2, $match['candidates']);
        $this->assertCount(1, $match['selectable_candidates']);
        $unmapped = array_values(array_filter($match['candidates'], fn ($candidate) => (int) $candidate['journal_row_id'] === 101))[0];
        $this->assertFalse($unmapped['ready']);
        $this->assertSame('5-100', $unmapped['lines'][0]['account_no']);
        $this->assertSame(100.0, (float) $unmapped['lines'][0]['debit']);

        $previewRows = $this->applyJournalMatches->invoke($this->service, [[
            'normalized_payload' => [
                'reference_no' => 'REF-ONE-READY',
                'expense_date' => '2026-10-21',
                'total_amount' => 100,
                'excel_tax_rate' => 0,
            ],
            'errors' => [],
            'status' => 'valid',
            'is_importable' => true,
        ]], 1, []);
        $this->assertCount(2, $previewRows[0]['normalized_payload']['journal_match']['candidates']);
        $this->assertCount(1, $previewRows[0]['normalized_payload']['journal_match']['selectable_candidates']);

        $staleSelection = $matcher->matchReferences(1, ['REF-ONE-READY'], [
            'journal_candidate:ref-one-ready' => 99,
        ], ['ref-one-ready' => 100])['ref-one-ready'];
        $this->assertSame('matched', $staleSelection['status']);
        $this->assertSame(25, (int) $staleSelection['selected']['mapping_id']);
        $this->assertFalse($staleSelection['selected_by_user']);
    }

    public function test_stale_ambiguous_preview_refreshes_when_exactly_one_candidate_is_ready(): void
    {
        $stalePreview = [
            'status' => 'ambiguous',
            'candidates' => [
                ['mapping_id' => null, 'ready' => false],
                ['mapping_id' => 44068, 'ready' => true],
            ],
        ];
        $twoReadyCandidates = [
            'status' => 'ambiguous',
            'selectable_candidates' => [
                ['mapping_id' => 44068, 'ready' => true],
                ['mapping_id' => 44069, 'ready' => true],
            ],
        ];
        $noReadyCandidate = [
            'status' => 'unavailable',
            'candidates' => [['mapping_id' => null, 'ready' => false]],
        ];

        $this->assertTrue($this->journalMatchNeedsRefresh->invoke($this->service, $stalePreview));
        $this->assertFalse($this->journalMatchNeedsRefresh->invoke($this->service, $twoReadyCandidates));
        $this->assertFalse($this->journalMatchNeedsRefresh->invoke($this->service, $noReadyCandidate));
    }

    public function test_ambiguous_ready_journal_postings_include_account_breakdowns_for_comparison(): void
    {
        $this->insertJournalExpense(200, 20, 40, 'REF-COMPARE', 0, 100, '5-100', 'Office Expense');
        $this->insertJournalExpense(201, 21, 41, 'REF-COMPARE', 0, 175, '5-225', 'Vehicle Repair');
        $matcher = new ExpenseImportJournalMatcher(
            new \Modules\Accounting\Services\JournalImportAccountComponentService(),
            new \Modules\Accounting\Services\JournalImportTaxResolver()
        );

        $match = $matcher->matchReferences(1, ['REF-COMPARE'], [], ['ref-compare' => 100])['ref-compare'];

        $this->assertSame('ambiguous', $match['status']);
        $this->assertCount(2, $match['candidates']);
        $this->assertCount(2, $match['selectable_candidates']);
        $first = $match['candidates'][0];
        $second = $match['candidates'][1];
        $this->assertSame('5-100', $first['lines'][0]['account_no']);
        $this->assertSame('Office Expense', $first['lines'][0]['account_name']);
        $this->assertSame('5-225', $second['lines'][0]['account_no']);
        $this->assertSame('Vehicle Repair', $second['lines'][0]['account_name']);
        $this->assertNotSame($first['debit_total'], $second['debit_total']);
    }

    public function test_journal_match_reuses_a_unique_source_link_and_blocks_conflicting_links(): void
    {
        $this->insertJournalExpense(100, 10, 25, 'REF-LINKED', 10);
        DB::table('transactions')->insert([
            'id' => 88,
            'business_id' => 1,
            'ref_no' => 'REF-LINKED',
            'transaction_date' => '2026-10-21 00:00:00',
            'type' => 'expense',
            'status' => 'final',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('accounting_journal_import_source_links')->insert([
            'business_id' => 1,
            'journal_import_id' => 10,
            'journal_import_row_id' => 100,
            'journal_source_type' => 'Expense',
            'source_record_type' => Transaction::class,
            'source_record_id' => 88,
            'source_reference' => 'REF-LINKED',
            'link_method' => 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matcher = new ExpenseImportJournalMatcher(
            new \Modules\Accounting\Services\JournalImportAccountComponentService(),
            new \Modules\Accounting\Services\JournalImportTaxResolver()
        );
        $unique = $matcher->matchReferences(1, ['REF-LINKED'], [], ['ref-linked' => 100])['ref-linked']['selected'];
        $this->assertTrue($unique['ready']);
        $this->assertSame(88, $unique['source_transaction_id']);

        DB::table('accounting_journal_import_source_links')->insert([
            'business_id' => 1,
            'journal_import_id' => 10,
            'journal_import_row_id' => 100,
            'journal_source_type' => 'Expense',
            'source_record_type' => Transaction::class,
            'source_record_id' => 89,
            'source_reference' => 'REF-LINKED',
            'link_method' => 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $conflictingMatch = $matcher->matchReferences(1, ['REF-LINKED'], [], ['ref-linked' => 100])['ref-linked'];
        $this->assertSame('unavailable', $conflictingMatch['status']);
        $conflicting = $conflictingMatch['candidates'][0];
        $this->assertFalse($conflicting['ready']);
        $this->assertStringContainsString('lebih dari satu relasi', $conflicting['issue']);
    }

    public function test_expense_import_uses_workbook_zero_tax_and_links_existing_gl(): void
    {
        $this->insertJournalExpense(100, 10, 25, 'REF-JOURNAL', 10);
        $validated = $this->validate('0', 'REF-JOURNAL', 100, 'Expense from Excel');
        $matchedRows = $this->applyJournalMatches->invoke($this->service, [$validated], 1, []);

        $this->assertTrue($matchedRows[0]['is_importable'], implode(' | ', $matchedRows[0]['errors']));
        $this->assertSame('none', $matchedRows[0]['normalized_payload']['tax_status']);

        $this->importGroup->invoke($this->service, collect([$this->previewRow($matchedRows[0])]), 1, 9, 'batch-1', 1);

        $transaction = Transaction::where('business_id', 1)->where('type', 'expense')->first();
        $this->assertNotNull($transaction);
        $this->assertSame(100.0, (float) $transaction->total_before_tax);
        $this->assertSame(0.0, (float) $transaction->tax_amount);
        $this->assertSame(100.0, (float) $transaction->final_total);
        $mapping = DB::table('accounting_acc_trans_mappings')->where('id', 25)->first();
        $journalRow = DB::table('accounting_journal_import_rows')->where('id', 100)->first();
        $this->assertSame(\App\Transaction::class, $mapping->source_record_type);
        $this->assertSame((int) $transaction->id, (int) $mapping->source_record_id);
        $this->assertSame((int) $transaction->id, (int) $journalRow->source_record_id);
        $this->assertSame(1, DB::table('accounting_journal_import_source_links')->count());
        $this->assertSame(2, DB::table('accounting_accounts_transactions')->count(), 'Expense import must reuse the existing Journal GL posting.');
    }

    public function test_journal_without_tax_preserves_workbook_tax_and_total(): void
    {
        $this->insertJournalExpense(100, 10, 25, 'REF-NO-TAX', 0);
        $validated = $this->validate('VAT', 'REF-NO-TAX', 110, 'No tax in Journal');
        $matchedRows = $this->applyJournalMatches->invoke($this->service, [$validated], 1, []);

        $this->assertTrue($matchedRows[0]['is_importable'], implode(' | ', $matchedRows[0]['errors']));
        $this->importGroup->invoke($this->service, collect([$this->previewRow($matchedRows[0])]), 1, 9, 'batch-1', 1);

        $transaction = Transaction::where('business_id', 1)->where('type', 'expense')->first();
        $this->assertSame(100.0, (float) $transaction->total_before_tax);
        $this->assertSame(10.0, (float) $transaction->tax_amount);
        $this->assertSame(110.0, (float) $transaction->final_total);
    }

    public function test_reference_group_inherits_one_known_tax_but_rejects_conflicting_rates(): void
    {
        $known = $this->validate('VAT', 'REF-INHERIT');
        $blank = $this->validate('', 'REF-INHERIT');
        $inherited = $this->validateGroupTaxes->invoke($this->service, [$known, $blank]);

        $this->assertSame(2, (int) $inherited[1]['normalized_payload']['tax_id']);
        $this->assertSame('inherited', $inherited[1]['normalized_payload']['tax_status']);
        $this->assertTrue($inherited[0]['is_importable'], implode(' | ', $inherited[0]['errors']));
        $this->assertTrue($inherited[1]['is_importable'], implode(' | ', $inherited[1]['errors']));

        $conflict = $this->validateGroupTaxes->invoke($this->service, [
            $this->validate('VAT', 'REF-CONFLICT'),
            $this->validate('Reduced VAT', 'REF-CONFLICT'),
        ]);

        $this->assertFalse($conflict[0]['is_importable']);
        $this->assertFalse($conflict[1]['is_importable']);
        $this->assertStringContainsString('satu TaxRate atau grup pajak', implode(' ', $conflict[0]['errors']));
    }

    public function test_import_group_preserves_inclusive_total_and_calculates_tax_from_existing_rate(): void
    {
        $rows = collect([
            $this->previewRow($this->validate('VAT', 'REF-110', 110, 'Biaya A')),
            $this->previewRow($this->validate('VAT', 'REF-110', 110, 'Biaya B')),
        ]);

        $this->importGroup->invoke($this->service, $rows, 1, 9, 'batch-1', 1);

        $transaction = Transaction::where('business_id', 1)->where('type', 'expense')->first();
        $this->assertNotNull($transaction);
        $this->assertSame(2, (int) $transaction->tax_id);
        $this->assertSame(200.0, (float) $transaction->total_before_tax);
        $this->assertSame(20.0, (float) $transaction->tax_amount);
        $this->assertSame(220.0, (float) $transaction->final_total);
        $this->assertSame(2, DB::table('expense_lines')->where('transaction_id', $transaction->id)->count());
    }

    public function test_reimport_with_same_reference_updates_the_existing_expense(): void
    {
        DB::table('transactions')->insert([
            'id' => 77,
            'business_id' => 1,
            'location_id' => 1,
            'ref_no' => 'REF-REIMPORT',
            'transaction_date' => '2026-10-21 00:00:00',
            'type' => 'expense',
            'status' => 'final',
            'payment_status' => 'due',
            'total_before_tax' => 100,
            'tax_amount' => 0,
            'final_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function createOrUpdatePaymentLines($transaction, $payments, $business_id = null, $user_id = null, $uf_data = true)
                {
                    return true;
                }

                public function updatePaymentStatus($transaction_id, $final_amount = null)
                {
                    return 'due';
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );

        $firstImport = $this->applyExistingExpenseMatches->invoke($this->service, [
            $this->validate('', 'REF-REIMPORT', 125, 'Excel truth'),
        ], 1, []);
        $this->assertTrue($firstImport[0]['is_importable'], implode(' | ', $firstImport[0]['errors']));
        $this->assertSame('merge:77', $firstImport[0]['normalized_payload']['existing_expense_match']['action']);
        $this->importGroup->invoke($this->service, collect([$this->previewRow($firstImport[0])]), 1, 9, 'batch-1', 2);

        $secondImport = $this->applyExistingExpenseMatches->invoke($this->service, [
            $this->validate('', 'REF-REIMPORT', 140, 'Updated from next workbook'),
        ], 1, []);
        $this->assertTrue($secondImport[0]['is_importable'], implode(' | ', $secondImport[0]['errors']));
        $this->importGroup->invoke($this->service, collect([$this->previewRow($secondImport[0])]), 1, 9, 'batch-1', 3);

        $this->assertSame(1, Transaction::where('business_id', 1)->where('type', 'expense')->count());
        $transaction = Transaction::find(77);
        $this->assertSame(140.0, (float) $transaction->final_total);
        $this->assertSame('Updated from next workbook', DB::table('expense_lines')->where('transaction_id', 77)->value('description'));
    }

    public function test_import_rechecks_reference_after_preview_before_creating_a_new_expense(): void
    {
        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function createOrUpdatePaymentLines($transaction, $payments, $business_id = null, $user_id = null, $uf_data = true)
                {
                    return true;
                }

                public function updatePaymentStatus($transaction_id, $final_amount = null)
                {
                    return 'due';
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );

        $preview = $this->applyExistingExpenseMatches->invoke($this->service, [
            $this->validate('', 'REF-STALE-PREVIEW', 110, 'Latest workbook value'),
        ], 1, []);
        $this->assertTrue($preview[0]['is_importable']);
        $this->assertSame('none', $preview[0]['normalized_payload']['existing_expense_match']['status']);

        DB::table('transactions')->insert([
            'id' => 79,
            'business_id' => 1,
            'location_id' => 1,
            'ref_no' => 'REF-STALE-PREVIEW',
            'transaction_date' => '2026-10-21 00:00:00',
            'type' => 'expense',
            'status' => 'final',
            'payment_status' => 'due',
            'total_before_tax' => 100,
            'tax_amount' => 0,
            'final_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->importGroup->invoke($this->service, collect([$this->previewRow($preview[0])]), 1, 9, 'batch-1', 4);

        $this->assertSame(1, Transaction::where('business_id', 1)->where('type', 'expense')->count());
        $this->assertSame(110.0, (float) Transaction::find(79)->final_total);
        $this->assertSame('Latest workbook value', DB::table('expense_lines')->where('transaction_id', 79)->value('description'));
    }

    public function test_import_requires_preview_again_when_existing_reference_changes_beyond_review_threshold(): void
    {
        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function createOrUpdatePaymentLines($transaction, $payments, $business_id = null, $user_id = null, $uf_data = true)
                {
                    return true;
                }

                public function updatePaymentStatus($transaction_id, $final_amount = null)
                {
                    return 'due';
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );

        $preview = $this->applyExistingExpenseMatches->invoke($this->service, [
            $this->validate('', 'REF-STALE-LARGE-DIFF', 1200000, 'Latest workbook value'),
        ], 1, []);
        $this->assertTrue($preview[0]['is_importable']);

        DB::table('transactions')->insert([
            'id' => 80,
            'business_id' => 1,
            'location_id' => 1,
            'ref_no' => 'REF-STALE-LARGE-DIFF',
            'transaction_date' => '2026-10-21 00:00:00',
            'type' => 'expense',
            'status' => 'final',
            'payment_status' => 'due',
            'total_before_tax' => 1000000,
            'tax_amount' => 0,
            'final_total' => 1000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('berubah setelah preview');
        $this->importGroup->invoke($this->service, collect([$this->previewRow($preview[0])]), 1, 9, 'batch-1', 5);
    }

    public function test_large_existing_reference_difference_uses_the_workbook_without_a_review_gate(): void
    {
        DB::table('transactions')->insert([
            'id' => 78,
            'business_id' => 1,
            'location_id' => 1,
            'ref_no' => 'REF-LARGE-DIFF',
            'transaction_date' => '2026-10-21 00:00:00',
            'type' => 'expense',
            'status' => 'final',
            'payment_status' => 'due',
            'total_before_tax' => 1000000,
            'tax_amount' => 0,
            'final_total' => 1000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = $this->validate('', 'REF-LARGE-DIFF', 1200000, 'Large difference');
        $pending = $this->applyExistingExpenseMatches->invoke($this->service, [$row], 1, []);

        $this->assertTrue($pending[0]['is_importable']);
        $this->assertFalse($pending[0]['normalized_payload']['existing_expense_match']['review_required']);
        $this->assertSame('merge:78', $pending[0]['normalized_payload']['existing_expense_match']['action']);

        $merged = $this->applyExistingExpenseMatches->invoke($this->service, [$row], 1, [
            'expense_existing_action:ref-large-diff' => 'merge:78',
        ]);
        $created = $this->applyExistingExpenseMatches->invoke($this->service, [$row], 1, [
            'expense_existing_action:ref-large-diff' => 'new',
        ]);
        $skipped = $this->applyExistingExpenseMatches->invoke($this->service, [$row], 1, [
            'expense_existing_action:ref-large-diff' => 'skip',
        ]);

        $this->assertTrue($merged[0]['is_importable']);
        $this->assertSame('merge:78', $merged[0]['normalized_payload']['existing_expense_match']['action']);
        $this->assertTrue($created[0]['is_importable']);
        $this->assertSame('new', $created[0]['normalized_payload']['existing_expense_match']['action']);
        $this->assertFalse($skipped[0]['is_importable']);
        $this->assertSame('skipped', $skipped[0]['status']);
    }

    public function test_public_preview_uses_the_linked_expense_and_leaves_same_reference_on_other_date_alone(): void
    {
        $this->insertExistingExpense(77, 'REF-AUTHORITY', '2026-10-21 00:00:00', 110);
        $this->insertExistingExpense(78, 'REF-AUTHORITY', '2026-10-22 00:00:00', 900);
        $this->insertJournalExpense(101, 11, 26, 'REF-AUTHORITY', 0, 100);
        DB::table('accounting_acc_trans_mappings')->where('id', 26)->update([
            'source_record_type' => Transaction::class,
            'source_record_id' => 77,
        ]);
        DB::table('accounting_journal_import_rows')->where('id', 101)->update([
            'source_record_type' => Transaction::class,
            'source_record_id' => 77,
        ]);
        DB::table('accounting_journal_import_source_links')->insert([
            'business_id' => 1,
            'journal_import_id' => 11,
            'journal_import_row_id' => 101,
            'journal_source_type' => 'Expense',
            'source_record_type' => Transaction::class,
            'source_record_id' => 77,
            'source_reference' => 'REF-AUTHORITY',
            'source_date' => '2026-10-21',
            'base_amount' => 110,
            'tax_amount' => 0,
            'total_amount' => 110,
            'link_method' => 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->container->instance('excel', new class
        {
            public function toArray($import, $path, $disk = null, $readerType = null): array
            {
                return [[
                    ['LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'REFERENCE NO', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID', 'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT', 'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT', 'PAYMENT NOTE'],
                    ['Main Location', 'Office', '', 'REF-AUTHORITY', '2026-10-21', '', '', '', 0, 'Workbook is authoritative', 125, 0, '', '', '', ''],
                ]];
            }
        });
        DB::table('expense_import_batches')->where('id', 'batch-1')->update([
            'business_id' => 1,
            'status' => 'preview',
            'operation' => 'import',
            'process_state' => 'completed',
        ]);

        $summary = $this->service->preview('batch-1', 1, 'ignored.xlsx');

        $this->assertSame(1, $summary['valid']);
        $this->assertSame(0, $summary['errors']);
        $previewRow = DB::table('expense_import_preview_rows')->where('batch_id', 'batch-1')->first();
        $payload = json_decode((string) $previewRow->normalized_payload, true);
        $this->assertSame('merge:77', $payload['existing_expense_match']['action']);
        $this->assertSame([77], array_column($payload['existing_expense_match']['candidates'], 'id'));
        $this->assertSame(125.0, (float) $payload['journal_expense_allocation']['total_amount']);
        $this->assertSame(900.0, (float) Transaction::find(78)->final_total);
    }

    public function test_public_preview_auto_consolidates_same_date_expenses_with_proven_backfill_gl(): void
    {
        $this->insertExistingExpense(77, 'REF-DUPLICATE-BACKFILL', '2026-10-21 00:00:00', 110);
        $this->insertExistingExpense(78, 'REF-DUPLICATE-BACKFILL', '2026-10-21 00:00:00', 90);
        $this->insertBackfillExpenseMapping(27, 77, 'REF-DUPLICATE-BACKFILL', '2026-10-21', 110);
        $this->insertBackfillExpenseMapping(28, 78, 'REF-DUPLICATE-BACKFILL', '2026-10-21', 90);
        $this->insertJournalExpense(101, 11, 26, 'REF-DUPLICATE-BACKFILL', 0, 100);
        DB::table('transaction_payments')->insert([
            [
                'id' => 501,
                'transaction_id' => 77,
                'business_id' => 1,
                'amount' => 110,
                'paid_on' => '2026-10-21 00:00:00',
                'method' => 'cash',
                'created_by' => 9,
            ],
            [
                'id' => 502,
                'transaction_id' => 78,
                'business_id' => 1,
                'amount' => 90,
                'paid_on' => '2026-10-21 00:00:00',
                'method' => 'bank_transfer',
                'created_by' => 9,
            ],
        ]);
        $this->container->instance('excel', new class
        {
            public function toArray($import, $path, $disk = null, $readerType = null): array
            {
                return [[
                    ['LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'REFERENCE NO', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID', 'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT', 'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT', 'PAYMENT NOTE'],
                    ['Main Location', 'Office', '', 'REF-DUPLICATE-BACKFILL', '2026-10-21', '', '', '', 0, 'Workbook owns the amount', 125, 120, '2026-10-21', '', '', 'Workbook payment note'],
                ]];
            }
        });
        DB::table('expense_import_batches')->where('id', 'batch-1')->update([
            'business_id' => 1,
            'status' => 'preview',
            'operation' => 'import',
            'process_state' => 'completed',
        ]);

        $summary = $this->service->preview('batch-1', 1, 'ignored.xlsx');

        $this->assertSame(1, $summary['valid']);
        $this->assertSame(0, $summary['errors']);
        $previewRow = DB::table('expense_import_preview_rows')->where('batch_id', 'batch-1')->first();
        $payload = json_decode((string) $previewRow->normalized_payload, true);
        $match = $payload['existing_expense_match'];
        $this->assertStringStartsWith('group:', $match['action']);
        $this->assertSame([77, 78], $match['selected_group']['member_ids']);
        $this->assertTrue($match['selected_group']['consolidation_safe']);

        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function payment_types($location_id = null, $show_advance = false, $business_id = null)
                {
                    return [];
                }

                public function createOrUpdatePaymentLines($transaction, $payments, $business_id = null, $user_id = null, $uf_data = true)
                {
                    return true;
                }

                public function updatePaymentStatus($transaction_id, $final_amount = null)
                {
                    return 'partial';
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );
        $result = $this->service->import('batch-1', 1, 9);

        $this->assertSame(['transactions' => 1, 'rows' => 1], $result);
        $this->assertSame(125.0, (float) Transaction::find(77)->final_total);
        $this->assertNull(Transaction::find(78));
        $this->assertSame(2, DB::table('transaction_payments')->where('transaction_id', 77)->count());
        $this->assertSame(501, (int) DB::table('transaction_payments')->where('transaction_id', 77)->orderBy('id')->value('id'));
        $this->assertSame(120.0, (float) DB::table('transaction_payments')->where('transaction_id', 77)->sum('amount'));
        $this->assertSame(125.0, (float) DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', 26)->where('type', 'debit')->sum('amount'));
        $this->assertSame(125.0, (float) DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', 26)->where('type', 'credit')->sum('amount'));
        $this->assertSame(0, DB::table('accounting_acc_trans_mappings')->whereIn('id', [27, 28])->count());
    }

    public function test_public_import_updates_expense_journal_and_existing_payment_history_from_workbook(): void
    {
        $this->insertExistingExpense(77, 'REF-IMPORT-AUTHORITY', '2026-10-21 00:00:00', 100);
        $this->insertJournalExpense(101, 11, 26, 'REF-IMPORT-AUTHORITY', 0, 100);
        DB::table('accounting_acc_trans_mappings')->where('id', 26)->update([
            'source_record_type' => Transaction::class,
            'source_record_id' => 77,
        ]);
        DB::table('accounting_journal_import_rows')->where('id', 101)->update([
            'source_record_type' => Transaction::class,
            'source_record_id' => 77,
        ]);
        DB::table('accounting_journal_import_source_links')->insert([
            'business_id' => 1,
            'journal_import_id' => 11,
            'journal_import_row_id' => 101,
            'journal_source_type' => 'Expense',
            'source_record_type' => Transaction::class,
            'source_record_id' => 77,
            'source_reference' => 'REF-IMPORT-AUTHORITY',
            'source_date' => '2026-10-21',
            'base_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'link_method' => 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('transaction_payments')->insert([
            [
                'id' => 501,
                'transaction_id' => 77,
                'business_id' => 1,
                'amount' => 30,
                'paid_on' => '2026-10-20 00:00:00',
                'method' => 'cash',
                'account_id' => 9,
                'note' => 'First historical payment',
                'created_by' => 9,
            ],
            [
                'id' => 502,
                'transaction_id' => 77,
                'business_id' => 1,
                'amount' => 50,
                'paid_on' => '2026-10-21 00:00:00',
                'method' => 'bank_transfer',
                'account_id' => 9,
                'note' => 'Second historical payment',
                'created_by' => 9,
            ],
        ]);
        $this->container->instance('excel', new class
        {
            public function toArray($import, $path, $disk = null, $readerType = null): array
            {
                return [[
                    ['LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'REFERENCE NO', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID', 'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT', 'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT', 'PAYMENT NOTE'],
                    ['Main Location', 'Office', '', 'REF-IMPORT-AUTHORITY', '2026-10-22', '', '', '', 0, 'Workbook line description', 125, 120, '2026-10-21', '', '', 'Workbook payment note'],
                ]];
            }
        });
        DB::table('expense_import_batches')->where('id', 'batch-1')->update([
            'business_id' => 1,
            'status' => 'preview',
            'operation' => 'import',
            'process_state' => 'completed',
        ]);
        $summary = $this->service->preview('batch-1', 1, 'ignored.xlsx');
        $this->assertSame(1, $summary['valid']);
        $this->assertSame(0, $summary['errors']);

        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function payment_types($location_id = null, $show_advance = false, $business_id = null)
                {
                    return [];
                }

                public function createOrUpdatePaymentLines($transaction, $payments, $business_id = null, $user_id = null, $uf_data = true)
                {
                    foreach ($payments as $payment) {
                        if (! empty($payment['payment_id'])) {
                            DB::table('transaction_payments')->where('id', $payment['payment_id'])->update([
                                'amount' => $payment['amount'],
                            ]);
                        }
                    }

                    return true;
                }

                public function updatePaymentStatus($transaction_id, $final_amount = null)
                {
                    return 'partial';
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );

        $result = $this->service->import('batch-1', 1, 9);

        $this->assertSame(['transactions' => 1, 'rows' => 1], $result);
        $this->assertSame(125.0, (float) Transaction::find(77)->final_total);
        $this->assertSame('2026-10-22', substr((string) Transaction::find(77)->transaction_date, 0, 10));
        $this->assertSame('Workbook line description', DB::table('expense_lines')->where('transaction_id', 77)->value('description'));
        $this->assertSame(2, DB::table('transaction_payments')->where('transaction_id', 77)->count());
        $this->assertSame(501, (int) DB::table('transaction_payments')->where('transaction_id', 77)->value('id'));
        $this->assertSame(45.0, (float) DB::table('transaction_payments')->where('id', 501)->value('amount'));
        $this->assertSame(75.0, (float) DB::table('transaction_payments')->where('id', 502)->value('amount'));
        $this->assertSame(120.0, (float) DB::table('transaction_payments')->where('transaction_id', 77)->sum('amount'));
        $this->assertSame(125.0, (float) DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', 26)->where('type', 'debit')->sum('amount'));
        $this->assertSame(125.0, (float) DB::table('accounting_accounts_transactions')->where('acc_trans_mapping_id', 26)->where('type', 'credit')->sum('amount'));
        $journal = DB::table('accounting_journal_import_rows')->where('id', 101)->value('entry_data');
        $journalEntry = json_decode((string) $journal, true);
        $this->assertSame('2026-10-22', $journalEntry['date']);
        $this->assertSame(125.0, (float) $journalEntry['debit_total']);
        $this->assertSame(125.0, (float) $journalEntry['credit_total']);
    }

    public function test_public_import_keeps_non_vat_tax_expense_as_an_expense_line(): void
    {
        DB::table('expense_categories')->insert([
            'id' => 2,
            'business_id' => 1,
            'name' => 'Taxes and Licenses',
            'parent_id' => null,
        ]);
        $this->insertJournalExpense(101, 11, 26, 'REF-TAX-EXPENSE', 0, 100, '6-60214', 'Taxes and Licenses');
        $this->container->instance('excel', new class
        {
            public function toArray($import, $path, $disk = null, $readerType = null): array
            {
                return [[
                    ['LOCATION', 'CATEGORY', 'SUB-CATEGORY', 'REFERENCE NO', 'EXPENSE DATE', 'EXPENSE FOR', 'EXPENSE FOR CONTACT ID', 'ATTACH DOCUMENT', 'APPLICABLE TAX', 'EXPENSE NOTE', 'TOTAL AMOUNT', 'PAID AMOUNT', 'PAID ON DATE', 'PAYMENT METHOD', 'PAYMENT ACCOUNT', 'PAYMENT NOTE'],
                    ['Main Location', 'Taxes and Licenses', '', 'REF-TAX-EXPENSE', '2026-10-21', '', '', '', '', 'PAJAK BULAN Desember 2023 CV BMT', 100, 0, '', '', '', ''],
                ]];
            }
        });
        DB::table('expense_import_batches')->where('id', 'batch-1')->update([
            'business_id' => 1,
            'status' => 'preview',
            'operation' => 'import',
            'process_state' => 'completed',
        ]);
        $summary = $this->service->preview('batch-1', 1, 'ignored.xlsx');
        $this->assertSame(1, $summary['valid']);

        $this->service = new ExpenseImportService(
            new class extends TransactionUtil
            {
                public function payment_types($location_id = null, $show_advance = false, $business_id = null)
                {
                    return [];
                }

                public function createOrUpdatePaymentLines($transaction, $payments, $business_id = null, $user_id = null, $uf_data = true)
                {
                    return true;
                }

                public function updatePaymentStatus($transaction_id, $final_amount = null)
                {
                    return 'due';
                }
            },
            new ExpenseImportJournalMatcher(
                new \Modules\Accounting\Services\JournalImportAccountComponentService(),
                new \Modules\Accounting\Services\JournalImportTaxResolver()
            ),
            new \App\Services\ExpenseImportJournalLinkService()
        );

        $result = $this->service->import('batch-1', 1, 9);

        $this->assertSame(['transactions' => 1, 'rows' => 1], $result);
        $transaction = Transaction::where('business_id', 1)->where('type', 'expense')->first();
        $this->assertSame(100.0, (float) $transaction->total_before_tax);
        $this->assertSame(0.0, (float) $transaction->tax_amount);
        $this->assertSame(100.0, (float) $transaction->final_total);
        $line = DB::table('expense_lines')->where('transaction_id', $transaction->id)->first();
        $this->assertSame('PAJAK BULAN Desember 2023 CV BMT', $line->description);
        $this->assertSame(100.0, (float) $line->amount);
    }

    private function validate(string $taxName, string $reference = 'REF-1', float $total = 110, string $description = 'Test expense', array $overrides = []): array
    {
        return $this->validateRow->invoke($this->service, [
            'LOCATION' => 'Main Location',
            'CATEGORY' => 'Office',
            'SUB-CATEGORY' => '',
            'DESCRIPTION' => $description,
            'REFERENCE NO' => $reference,
            'TAGS' => '',
            'EXPENSE DATE' => '2026-10-21',
            'EXPENSE FOR' => '',
            'EXPENSE FOR CONTACT ID' => '',
            'ATTACH DOCUMENT' => '',
            'APPLICABLE TAX' => $taxName,
            'EXPENSE NOTE' => '',
            'TOTAL AMOUNT' => $total,
            'PAID AMOUNT' => 0,
            'PAID ON DATE' => '',
            'PAYMENT METHOD' => '',
            'PAYMENT ACCOUNT' => '',
            'PAYMENT NOTE' => '',
        ], 2, $this->validationContext(), $overrides);
    }

    private function validationContext(): array
    {
        $location = (object) ['id' => 1, 'name' => 'Main Location'];
        $category = (object) ['id' => 1, 'name' => 'Office', 'parent_id' => null];
        $taxes = collect([
            (object) ['id' => 2, 'name' => 'VAT', 'amount' => 10, 'is_tax_group' => false],
            (object) ['id' => 3, 'name' => 'Reduced VAT', 'amount' => 5, 'is_tax_group' => false],
        ]);

        return [
            'businessId' => 1,
            'locations' => collect([$location])->keyBy(fn ($item) => strtolower($item->name)),
            'categories' => collect([$category]),
            'categoryByName' => collect([$category])->groupBy(fn ($item) => strtolower($item->name)),
            'contacts' => collect(),
            'users' => collect(),
            'taxesByName' => $taxes->keyBy(fn ($tax) => strtolower($tax->name)),
            'taxesById' => $taxes->keyBy('id'),
            'paymentTypes' => [],
        ];
    }

    private function previewRow(array $validated): object
    {
        return (object) [
            'normalized_payload' => json_encode($validated['normalized_payload'], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function insertExistingExpense(int $id, string $reference, string $date, float $total): void
    {
        DB::table('transactions')->insert([
            'id' => $id,
            'business_id' => 1,
            'location_id' => 1,
            'ref_no' => $reference,
            'transaction_date' => $date,
            'type' => 'expense',
            'status' => 'final',
            'payment_status' => 'due',
            'total_before_tax' => $total,
            'tax_amount' => 0,
            'final_total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('expense_lines')->insert([
            'transaction_id' => $id,
            'category_id' => 1,
            'amount' => $total,
            'description' => 'Old app value',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBackfillExpenseMapping(int $mappingId, int $transactionId, string $reference, string $date, float $amount): void
    {
        DB::table('accounting_acc_trans_mappings')->insert([
            'id' => $mappingId,
            'business_id' => 1,
            'source_record_type' => Transaction::class,
            'source_record_id' => $transactionId,
            'source_link_status' => 'matched',
            'ref_no' => $reference,
            'type' => 'general_ledger_backfill',
            'operation_date' => $date,
        ]);
        foreach (['debit', 'credit'] as $type) {
            DB::table('accounting_accounts_transactions')->insert([
                'acc_trans_mapping_id' => $mappingId,
                'transaction_id' => $transactionId,
                'transaction_payment_id' => null,
                'amount' => $amount,
                'type' => $type,
                'sub_type' => 'expense',
                'map_type' => 'general_ledger_backfill',
            ]);
        }
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        $schema->create('business_locations', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        $schema->create('contacts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('users', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('expense_categories', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->unsignedInteger('parent_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('tax_rates', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->decimal('amount', 10, 4);
            $table->boolean('is_tax_group')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('group_sub_taxes', function ($table) {
            $table->unsignedInteger('group_tax_id');
            $table->unsignedInteger('tax_id');
        });
        $schema->create('transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('expense_category_id')->nullable();
            $table->unsignedInteger('expense_sub_category_id')->nullable();
            $table->unsignedInteger('expense_for')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date');
            $table->text('additional_notes')->nullable();
            $table->string('document')->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('total_before_tax', 22, 4)->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('type')->nullable();
            $table->unsignedInteger('import_batch')->nullable();
            $table->dateTime('import_time')->nullable();
            $table->string('import_file')->nullable();
            $table->timestamps();
        });
        $schema->create('expense_lines', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('sub_category_id')->nullable();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('document')->nullable();
            $table->timestamps();
        });
        $schema->create('transaction_payments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('business_id')->nullable();
            $table->decimal('amount', 22, 4);
            $table->dateTime('paid_on')->nullable();
            $table->string('method')->nullable();
            $table->unsignedInteger('account_id')->nullable();
            $table->text('note')->nullable();
            $table->string('payment_ref_no')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('transaction_no')->nullable();
        });
        $schema->create('transaction_tags', function ($table) {
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('tag_id');
        });
        $schema->create('expense_import_reference_locks', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('reference_hash', 64);
            $table->timestamps();
            $table->unique(['business_id', 'reference_hash']);
        });
        $schema->create('expense_import_batches', function ($table) {
            $table->string('id')->primary();
            $table->string('file_name')->nullable();
            $table->string('business_id')->nullable();
            $table->string('status')->nullable();
            $table->string('operation')->nullable();
            $table->string('process_state')->nullable();
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_current')->default(0);
            $table->text('summary')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
        $schema->create('expense_import_preview_rows', function ($table) {
            $table->increments('id');
            $table->string('batch_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('source_row')->nullable();
            $table->string('group_key')->nullable();
            $table->string('status')->nullable();
            $table->text('errors')->nullable();
            $table->text('raw_payload')->nullable();
            $table->text('normalized_payload')->nullable();
            $table->boolean('is_importable')->default(false);
            $table->string('skip_reason')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'source_row']);
        });
        $schema->create('accounting_journal_imports', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('status');
        });
        $schema->create('accounting_journal_import_rows', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('business_id');
            $table->string('source_type');
            $table->string('source_number')->nullable();
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
            $table->text('entry_data');
            $table->string('processing_status');
            $table->unsignedInteger('journal_mapping_id')->nullable();
            $table->text('processing_message')->nullable();
            $table->timestamps();
        });
        $schema->create('accounting_acc_trans_mappings', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id')->nullable();
            $table->string('type');
            $table->string('source_record_type')->nullable();
            $table->unsignedInteger('source_record_id')->nullable();
            $table->string('source_link_status')->nullable();
            $table->text('source_link_note')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('operation_date')->nullable();
        });
        $schema->create('accounting_accounts_transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('acc_trans_mapping_id')->nullable();
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('transaction_payment_id')->nullable();
            $table->string('map_type')->nullable();
            $table->string('sub_type')->nullable();
            $table->unsignedInteger('accounting_account_id')->nullable();
            $table->decimal('amount', 22, 4);
            $table->string('type');
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('operation_date')->nullable();
            $table->text('note')->nullable();
        });
        $schema->create('accounting_journal_import_source_links', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('journal_import_id');
            $table->unsignedInteger('journal_import_row_id');
            $table->string('journal_source_type');
            $table->string('source_record_type');
            $table->unsignedInteger('source_record_id');
            $table->text('source_reference')->nullable();
            $table->date('source_date')->nullable();
            $table->decimal('base_amount', 22, 4)->nullable();
            $table->decimal('tax_amount', 22, 4)->nullable();
            $table->decimal('total_amount', 22, 4)->nullable();
            $table->string('link_method')->default('single');
            $table->timestamps();
            $table->unique(['journal_import_row_id', 'source_record_type', 'source_record_id']);
        });
    }

    private function createMasterData(): void
    {
        DB::table('business_locations')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Main Location']);
        DB::table('expense_categories')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Office', 'parent_id' => null]);
        DB::table('tax_rates')->insert([
            ['id' => 2, 'business_id' => 1, 'name' => 'VAT', 'amount' => 10, 'is_tax_group' => false],
            ['id' => 3, 'business_id' => 1, 'name' => 'Reduced VAT', 'amount' => 5, 'is_tax_group' => false],
        ]);
        DB::table('expense_import_batches')->insert(['id' => 'batch-1', 'file_name' => 'unit-test.xlsx']);
    }

    private function insertJournalExpense(
        int $rowId,
        int $importId,
        ?int $mappingId,
        string $reference,
        float $taxAmount,
        float $baseAmount = 100,
        string $expenseAccountNo = '5-100',
        string $expenseAccountName = 'Office Expense'
    ): void
    {
        $entry = [
            'source_type' => 'Expense',
            'source_number' => $reference,
            'date' => '2026-10-21',
            'description' => 'Journal expense test',
            'debit_total' => $baseAmount + $taxAmount,
            'credit_total' => $baseAmount + $taxAmount,
            'lines' => [
                ['account_no' => $expenseAccountNo, 'account_name' => $expenseAccountName, 'debit' => $baseAmount, 'credit' => 0],
                ['account_no' => '1-120', 'account_name' => 'VAT In', 'debit' => $taxAmount, 'credit' => 0],
                ['account_no' => '1-100', 'account_name' => 'Bank', 'debit' => 0, 'credit' => $baseAmount + $taxAmount],
            ],
        ];
        DB::table('accounting_journal_imports')->insert([
            'id' => $importId, 'business_id' => 1, 'status' => 'imported',
        ]);
        if ($mappingId !== null) {
            DB::table('accounting_acc_trans_mappings')->insert([
                'id' => $mappingId, 'business_id' => 1, 'journal_import_id' => $importId, 'type' => 'journal_entry',
            ]);
        }
        DB::table('accounting_journal_import_rows')->insert([
            'id' => $rowId,
            'journal_import_id' => $importId,
            'business_id' => 1,
            'source_type' => 'Expense',
            'source_number' => $reference,
            'entry_data' => json_encode($entry),
            'processing_status' => 'completed',
            'journal_mapping_id' => $mappingId,
        ]);
        if ($mappingId !== null) {
            DB::table('accounting_accounts_transactions')->insert([
                'acc_trans_mapping_id' => $mappingId, 'amount' => $baseAmount + $taxAmount, 'type' => 'debit',
            ]);
            DB::table('accounting_accounts_transactions')->insert([
                'acc_trans_mapping_id' => $mappingId, 'amount' => $baseAmount + $taxAmount, 'type' => 'credit',
            ]);
        }
    }
}
