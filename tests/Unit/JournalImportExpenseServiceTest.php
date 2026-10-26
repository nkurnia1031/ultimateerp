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

    public function test_new_expense_requires_a_selected_location_before_it_can_be_queued(): void
    {
        $this->setValidatorContainer();
        $service = $this->makeService();
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

    public function test_tax_account_without_a_tax_decision_stays_in_review(): void
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
            ->with(Mockery::type('array'), 3, [], true)
            ->andThrow(new \RuntimeException('Akun Journal "Prepaid Income Tax - PPh 23" tampak sebagai akun pajak tetapi belum dipetakan.'));

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
                ['account_no' => '1-10502', 'account_name' => 'Prepaid Income Tax - PPh 23', 'type' => 'debit', 'amount' => 10],
            ],
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertStringContainsString('akun pajak tetapi belum dipetakan', $issue);
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
