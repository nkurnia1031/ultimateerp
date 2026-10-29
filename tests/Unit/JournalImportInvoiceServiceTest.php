<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Mockery;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\JournalImportInvoiceService;
use PHPUnit\Framework\TestCase;

class JournalImportInvoiceServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_create_invoice_allows_empty_contact_but_stays_in_draft_until_items_are_selected(): void
    {
        $container = new Container();
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);

        $draftService = Mockery::mock(JournalImportDraftService::class);
        $draftService->shouldReceive('isValidSourceDecision')->once()->andReturnTrue();
        $draftService->shouldReceive('validateDraftData')->once()->andReturn([]);
        $draftService->shouldReceive('defaultActiveLocationId')->once()->with(3)->andReturn(10);

        $service = new JournalImportInvoiceService(
            $draftService,
            Mockery::mock(ProductUtil::class),
            Mockery::mock(TransactionUtil::class)
        );
        $row = (object) [
            'source_type' => 'Sales Invoice',
            'source_action' => 'create',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => '{}',
        ];
        $entry = [
            'source_type' => 'Sales Invoice',
            'source_link_status' => 'unmatched',
            'source_link_candidate_details' => [],
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertSame('Tambahkan minimal satu detail produk dengan jumlah dan harga.', $issue);
    }

    public function test_ambiguous_invoice_requires_a_selected_candidate_or_explicit_create_action(): void
    {
        $container = new Container();
        $container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);

        $service = new JournalImportInvoiceService(
            new JournalImportDraftService(),
            Mockery::mock(ProductUtil::class),
            Mockery::mock(TransactionUtil::class)
        );
        $row = (object) [
            'source_type' => 'Purchase Invoice',
            'source_action' => 'review',
            'source_record_type' => null,
            'source_record_id' => null,
            'draft_data' => '{}',
        ];
        $entry = [
            'source_type' => 'Purchase Invoice',
            'source_link_status' => 'ambiguous',
            'source_link_candidate_details' => [
                ['record_type' => 'App\\Transaction', 'id' => 10],
                ['record_type' => 'App\\Transaction', 'id' => 11],
            ],
        ];

        $issue = $service->readinessIssue($row, $entry, 3);

        $this->assertStringContainsString('Pilih sumber invoice', $issue);
    }
}
