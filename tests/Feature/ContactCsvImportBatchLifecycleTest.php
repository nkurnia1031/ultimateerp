<?php

namespace Tests\Feature;

use App\Contact;
use App\Jobs\ProcessContactCsvImportBatch;
use App\Services\ContactCsvImportBatchService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Crm\Services\SuratKontrakImportBatchService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ContactCsvImportBatchLifecycleTest extends TestCase
{
    private int $businessId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createBusinessFixture();
    }

    public function test_contact_upload_queues_one_batch_with_source_detail_and_safe_partial_revert(): void
    {
        $path = $this->workbook([
            $this->row('CUS-IMPORT-1', 'Pelanggan Baru', 'customer'),
            $this->row('CUS-IMPORT-2', '', 'customer'),
        ]);
        try {
            $first = $this->upload($path)->assertStatus(202)->assertJsonPath('success', true);
            $this->upload($path)->assertStatus(202)->assertJsonPath('batch_id', $first->json('batch_id'));
            Bus::assertDispatchedTimes(ProcessContactCsvImportBatch::class, 1);
            $batch = UserImportBatch::where('business_id', $this->businessId)
                ->where('importer_key', ContactCsvImportBatchService::IMPORTER_KEY)->firstOrFail();
            $this->assertTrue($batch->hasSourceSnapshot());

            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->getJson(route('contacts.import.history', ['draw' => 3, 'start' => 0, 'length' => 10, 'status' => 'queued_preview']))
                ->assertOk()->assertJsonPath('draw', 3)->assertJsonPath('recordsTotal', 1);
            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->get(route('contacts.import.source', $batch->id))->assertOk();

            app(ContactCsvImportBatchService::class)->process($batch->fresh());
            $batch->refresh();
            $this->assertSame('partial', $batch->status);
            $this->assertDatabaseHas('contacts', ['business_id' => $this->businessId, 'contact_id' => 'CUS-IMPORT-1']);

            $detail = $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->getJson(route('contacts.import.detail', $batch->id));
            $detail->assertOk()->assertJsonPath('results.summary.created_rows', 1)
                ->assertJsonPath('results.summary.failed_rows', 1)
                ->assertJsonPath('results.row_outcomes.0.sheet', 'Contacts')
                ->assertJsonPath('results.row_outcomes.0.source_row', 2);

            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->postJson(route('contacts.import.revert', $batch->id))->assertOk()->assertJsonPath('status', 'reverted');
            $this->assertSoftDeleted('contacts', ['business_id' => $this->businessId, 'contact_id' => 'CUS-IMPORT-1']);
        } finally {
            @unlink($path);
        }
    }

    public function test_contact_batch_revert_blocks_changed_contacts_and_foreign_business_cannot_read_details(): void
    {
        $path = $this->workbook([$this->row('CUS-IMPORT-3', 'Kontak Berubah', 'customer')]);
        try {
            $batch = $this->upload($path)->assertStatus(202)->json('batch_id');
            $batch = UserImportBatch::findOrFail($batch);
            app(ContactCsvImportBatchService::class)->process($batch);
            $contact = Contact::where('business_id', $this->businessId)->where('contact_id', 'CUS-IMPORT-3')->firstOrFail();
            $contact->name = 'Nama berubah setelah import';
            $contact->save();

            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
                ->postJson(route('contacts.import.revert', $batch->id))->assertStatus(422);
            $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId + 1))
                ->getJson(route('contacts.import.detail', $batch->id))->assertNotFound();
            $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => 'Nama berubah setelah import']);
        } finally {
            @unlink($path);
        }
    }

    private function upload(string $path)
    {
        return $this->actingAs($this->admin())->withSession($this->businessSession($this->businessId))
            ->postJson(route('contacts.import.upload'), [
                'contacts_csv' => new UploadedFile($path, 'contacts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);
    }

    private function workbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contacts');
        $sheet->fromArray(array_merge([array_fill(0, 27, 'Header')], $rows), null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'contact-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function row(string $contactId, string $firstName, string $type): array
    {
        $row = array_fill(0, 27, '');
        $row[0] = $type;
        $row[2] = $firstName;
        $row[6] = $contactId;
        $row[13] = '081234567890';

        return $row;
    }

    private function admin(): \Illuminate\Foundation\Auth\User
    {
        $admin = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return true;
            }
        };
        $admin->forceFill(['id' => $this->userId, 'business_id' => $this->businessId, 'first_name' => 'Admin', 'user_type' => 'user', 'allow_login' => 1]);

        return $admin;
    }

    private function businessSession(int $businessId): array
    {
        return ['user' => ['business_id' => $businessId, 'id' => $this->userId]];
    }

    private function createBusinessFixture(): void
    {
        $currencyId = DB::table('currencies')->value('id');
        if (! $currencyId) {
            $currencyId = DB::table('currencies')->insertGetId([
                'country' => 'Test', 'currency' => 'Test', 'code' => 'TST', 'symbol' => 'T',
                'thousand_separator' => ',', 'decimal_separator' => '.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->userId = (int) DB::table('users')->insertGetId([
            'surname' => 'Test', 'first_name' => 'Contact Importer', 'username' => 'contact-import-'.bin2hex(random_bytes(6)),
            'password' => bcrypt('test'), 'user_type' => 'user', 'allow_login' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->businessId = (int) DB::table('business')->insertGetId([
            'name' => 'Contact Import Test', 'currency_id' => $currencyId, 'tax_number_1' => '', 'tax_label_1' => 'VAT',
            'owner_id' => $this->userId, 'time_zone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $this->userId)->update(['business_id' => $this->businessId]);
    }
}
