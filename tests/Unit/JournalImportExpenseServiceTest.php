<?php

namespace Tests\Unit;

use App\Utils\TransactionUtil;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Mockery;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportAccountComponentService;
use Modules\Accounting\Services\JournalImportExpenseService;
use Modules\Accounting\Services\JournalImportTaxResolver;
use PHPUnit\Framework\TestCase;

class JournalImportExpenseServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_ambiguous_expense_requires_an_explicit_source_decision(): void
    {
        $this->setValidatorContainer();
        $service = $this->makeService();
        $row = (object) [
            'source_type' => 'Expense',
            'source_action' => 'review',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => '{}',
        ];
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'ambiguous',
            'source_link_candidate_details' => [
                ['record_type' => 'App\\Transaction', 'id' => 10],
                ['record_type' => 'App\\Transaction', 'id' => 11],
            ],
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertStringContainsString('Pilih sumber Expense', $issue);
    }

    public function test_new_expense_requires_a_location_when_business_has_no_active_default(): void
    {
        $this->setValidatorContainer();
        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('isValidSourceDecision')->once()->andReturnTrue();
        $draftService->shouldReceive('validateDraftData')->once()->andReturn([]);
        $draftService->shouldReceive('defaultActiveLocationId')->once()->with(3)->andReturnNull();
        $service = new JournalImportExpenseService(
            $draftService,
            new JournalImportTaxResolver(),
            Mockery::mock(TransactionUtil::class)
        );
        $row = (object) [
            'source_type' => 'Expense',
            'source_action' => 'create',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => '{}',
        ];
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'source_number' => 'EXP-TEST-1',
            'date' => '2026-10-25',
            'debit_total' => 110,
            'lines' => [],
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertSame('Pilih lokasi sebelum membuat Expense.', $issue);
    }

    public function test_tax_account_is_detected_automatically_without_a_rate_mapping(): void
    {
        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('isValidSourceDecision')->once()->andReturnTrue();
        $draftService->shouldReceive('validateDraftData')->once()->andReturn([
            'location_id' => 2,
            'expense_category_id' => 57,
            'contact_id' => null,
        ]);

        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')
            ->once()
            ->with(Mockery::type('array'), 3, 100.0)
            ->andReturn(['id' => 9, 'rate' => 10]);

        $service = new JournalImportExpenseService(
            $draftService,
            $taxResolver,
            Mockery::mock(TransactionUtil::class)
        );
        $row = (object) [
            'source_type' => 'Expense',
            'source_action' => 'create',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => '{"location_id":2,"expense_category_id":57}',
        ];
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'source_number' => 'EXP-TEST-TAX-1',
            'date' => '2026-10-25',
            'debit_total' => 110,
            'lines' => [
                ['account_no' => '5-50000', 'account_name' => 'Cost of Sales', 'type' => 'debit', 'amount' => 100],
                ['account_no' => '1-10502', 'account_name' => 'VAT In', 'type' => 'debit', 'amount' => 10],
            ],
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertNull($issue);
    }

    public function test_multiple_expense_accounts_require_and_accept_a_category_per_group(): void
    {
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'source_number' => 'EXP-GROUP-1',
            'date' => '2026-10-25',
            'debit_total' => 130,
            'lines' => [
                ['account_no' => '6101', 'account_name' => 'Office Supplies', 'type' => 'debit', 'amount' => 100],
                ['account_no' => '6201', 'account_name' => 'Transportation', 'type' => 'debit', 'amount' => 30],
            ],
        ];
        $componentService = new JournalImportAccountComponentService();
        $components = $componentService->groupJournalLines($entry, 3);
        $categoryMappings = [
            $components[0]['account_key'] => 57,
            $components[1]['account_key'] => 58,
        ];

        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('isValidSourceDecision')->once()->andReturnTrue();
        $draftService->shouldReceive('validateDraftData')->once()->andReturn([
            'location_id' => 2,
            'expense_category_mappings' => $categoryMappings,
        ]);
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')->once()->with($entry, 3, 130.0)->andReturn(null);
        $service = new JournalImportExpenseService($draftService, $taxResolver, Mockery::mock(TransactionUtil::class));
        $row = (object) [
            'source_type' => 'Expense',
            'source_action' => 'create',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => json_encode(['location_id' => 2, 'expense_category_mappings' => $categoryMappings]),
        ];

        $this->assertNull($service->readinessIssue($row, $entry, 3));
    }

    public function test_multiple_expense_accounts_are_not_merged_into_the_single_fallback_category(): void
    {
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'source_number' => 'EXP-GROUP-2',
            'date' => '2026-10-25',
            'debit_total' => 130,
            'lines' => [
                ['account_no' => '6101', 'account_name' => 'Office Supplies', 'type' => 'debit', 'amount' => 100],
                ['account_no' => '6201', 'account_name' => 'Transportation', 'type' => 'debit', 'amount' => 30],
            ],
        ];
        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('isValidSourceDecision')->once()->andReturnTrue();
        $draftService->shouldReceive('validateDraftData')->once()->andReturn([
            'location_id' => 2,
            'expense_category_id' => 57,
        ]);
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')->once()->with($entry, 3, 130.0)->andReturn(null);
        $service = new JournalImportExpenseService($draftService, $taxResolver, Mockery::mock(TransactionUtil::class));
        $row = (object) [
            'source_type' => 'Expense',
            'source_action' => 'create',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => '{"location_id":2,"expense_category_id":57}',
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertStringContainsString('Pilih kategori Expense untuk akun Journal', $issue);
    }

    public function test_vat_in_is_accounted_for_separately_from_expense_base(): void
    {
        $entry = [
            'source_type' => 'Expense',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
            'source_number' => 'EXP-VAT-1',
            'date' => '2026-10-25',
            'debit_total' => 110,
            'lines' => [
                ['account_no' => '6101', 'account_name' => 'Office Supplies', 'type' => 'debit', 'amount' => 100],
                ['account_no' => '2101', 'account_name' => 'VAT IN', 'type' => 'debit', 'amount' => 10],
            ],
        ];
        $components = (new JournalImportAccountComponentService())->groupJournalLines($entry, 3);
        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('isValidSourceDecision')->once()->andReturnTrue();
        $draftService->shouldReceive('validateDraftData')->once()->andReturn([
            'location_id' => 2,
            'expense_category_mappings' => [$components[0]['account_key'] => 57],
        ]);
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')->once()->with($entry, 3, 100.0)->andReturn(['id' => 9, 'rate' => 10]);
        $transactionUtil = Mockery::mock(TransactionUtil::class);
        $transactionUtil->shouldNotReceive('calc_percentage_base');
        $service = new JournalImportExpenseService($draftService, $taxResolver, $transactionUtil);
        $row = (object) [
            'source_type' => 'Expense',
            'source_action' => 'create',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => json_encode(['location_id' => 2, 'expense_category_mappings' => [$components[0]['account_key'] => 57]]),
        ];

        $this->assertSame(110.0, $service->draftTotal($row, $entry, (object) ['business_id' => 3]));
    }

    private function makeService(): JournalImportExpenseService
    {
        return new JournalImportExpenseService(
            new JournalImportDraftService(),
            new JournalImportTaxResolver(),
            Mockery::mock(TransactionUtil::class)
        );
    }

    private function setValidatorContainer(): void
    {
        $container = new Container();
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);
    }
}
