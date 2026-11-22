<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivatizeUserImportStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('user_imports');
    }

    public function test_existing_public_import_files_are_verified_moved_and_removed_from_public_storage(): void
    {
        $snapshot = json_encode(['rows' => [['contact', 'private data']]], JSON_THROW_ON_ERROR);
        Storage::disk('local')->put('user-imports/17/source_snapshot.json', $snapshot);
        Storage::disk('local')->put('solar-imports/2/batch.xlsx', 'workbook bytes');
        Storage::disk('local')->put('bonus-ayak-import-previews/999-7-legacy-token.json', $snapshot);
        Storage::disk('local')->put('temp/1791234567_legacy-sales.xlsx', 'legacy sales workbook');
        Storage::disk('local')->put('temp/journal_imports/legacy-journal.json', $snapshot);
        Storage::disk('local')->put('temp/rendering-cache.tmp', 'temporary rendering file');

        $this->artisan('user-imports:privatize-storage')->assertExitCode(0);

        Storage::disk('user_imports')->assertExists('user-imports/17/source_snapshot.json');
        Storage::disk('user_imports')->assertExists('solar-imports/2/batch.xlsx');
        Storage::disk('user_imports')->assertExists('bonus-ayak-import-previews/999-7-legacy-token.json');
        Storage::disk('user_imports')->assertExists('temp/1791234567_legacy-sales.xlsx');
        Storage::disk('user_imports')->assertExists('temp/journal_imports/legacy-journal.json');
        $this->assertSame($snapshot, Storage::disk('user_imports')->get('user-imports/17/source_snapshot.json'));
        Storage::disk('local')->assertMissing('user-imports/17/source_snapshot.json');
        Storage::disk('local')->assertMissing('solar-imports/2/batch.xlsx');
        Storage::disk('local')->assertMissing('bonus-ayak-import-previews/999-7-legacy-token.json');
        Storage::disk('local')->assertMissing('temp/1791234567_legacy-sales.xlsx');
        Storage::disk('local')->assertMissing('temp/journal_imports/legacy-journal.json');
        Storage::disk('local')->assertExists('temp/rendering-cache.tmp');
    }
}
