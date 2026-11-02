<?php

namespace Tests\Feature;

use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\LegacyOperationalDraftEnricher;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyOperationalDraftEnricherTest extends TestCase
{
    public function test_standalone_draft_preparation_supports_products_without_soft_deletes(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'deleted_at'));

        $enricher = new LegacyOperationalDraftEnricher();
        $draftService = new JournalImportDraftService();

        $this->assertSame([], $enricher->prepareStandaloneDrafts([], 2147483647, $draftService));
    }
}
