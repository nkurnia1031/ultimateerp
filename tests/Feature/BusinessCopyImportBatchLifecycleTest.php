<?php

namespace Tests\Feature;

use App\Contact;
use App\ExpenseCategory;
use App\Jobs\ProcessBusinessCopyImportBatch;
use App\Services\BusinessCopyImportBatchService;
use App\Services\UserImportBatchService;
use App\User;
use App\UserImportBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessCopyImportBatchLifecycleTest extends TestCase
{
    private int $sourceBusinessId;

    private int $targetBusinessId;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Event::fake();
        Storage::fake('local');
        $this->withoutMiddleware([
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\AdminSidebarMenu::class,
        ]);
        $this->createBusinessFixtures();
    }

    public function test_contact_copy_is_queued_deduplicated_recoverable_and_business_scoped(): void
    {
        $source = $this->contact($this->sourceBusinessId, 'Kontak sumber');
        $response = $this->uploadContact();
        $response->assertStatus(202)->assertJsonPath('success', true);
        $batchId = (int) $response->json('batch_id');
        $this->uploadContact()->assertStatus(202)->assertJsonPath('batch_id', $batchId);
        Bus::assertDispatchedTimes(ProcessBusinessCopyImportBatch::class, 1);

        $batch = UserImportBatch::findOrFail($batchId);
        $options = json_decode($batch->options_json, true);
        $this->assertSame($this->sourceBusinessId, (int) $options['source_business_id']);
        $this->assertSame($this->targetBusinessId, (int) $options['target_business_id']);

        // Model the crash window after the destination commit and before the
        // progress payload write. The batch marker lets retry recover the row.
        $copied = $this->contact($this->targetBusinessId, 'Kontak sumber', $source->contact_id, $this->adminId, [
            'user_import_batch_id' => $batch->id,
            'user_import_source_id' => $source->id,
        ]);
        app(UserImportBatchService::class)->storePayload($batch, 'results', [
            'summary' => ['source_rows' => 1, 'created_rows' => 0, 'skipped_rows' => 0, 'failed_rows' => 1],
            'row_outcomes' => [['source_id' => $source->id, 'source_label' => $source->name, 'status' => 'failed', 'error' => 'worker restarted']],
            'created_records' => [],
        ]);
        $batch->forceFill(['status' => 'partial', 'stage' => 'partial'])->save();

        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.retry', ['type' => 'contacts', 'batch' => $batch->id]))
            ->assertOk()->assertJsonPath('status', 'queued_preview');
        app(BusinessCopyImportBatchService::class)->process($batch->fresh());
        $batch->refresh();
        $this->assertSame('imported', $batch->status);
        $this->assertSame(1, (int) (json_decode($batch->summary_json, true)['created_rows'] ?? 0));
        $this->assertSame(1, Contact::where('business_id', $this->targetBusinessId)
            ->where('user_import_batch_id', $batch->id)->count());

        $history = $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->getJson(route('import-copies.history', ['type' => 'contacts', 'draw' => 7, 'start' => 0, 'length' => 10, 'status' => 'imported']));
        $history->assertOk()->assertJsonPath('draw', 7)->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.can_revert', true);
        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->sourceBusinessId))
            ->getJson(route('import-copies.detail', ['type' => 'contacts', 'batch' => $batch->id]))
            ->assertNotFound();
        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->getJson(route('import-copies.detail', ['type' => 'contacts', 'batch' => $batch->id]))
            ->assertOk()->assertJsonPath('results.row_outcomes.0.target_id', $copied->id);

        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.revert', ['type' => 'contacts', 'batch' => $batch->id]))
            ->assertOk()->assertJsonPath('status', 'reverted');
        $this->assertSoftDeleted('contacts', ['id' => $copied->id]);
    }

    public function test_expense_category_copy_is_queued_and_revert_blocks_new_dependencies(): void
    {
        $source = ExpenseCategory::create([
            'business_id' => $this->sourceBusinessId,
            'name' => 'Kategori sumber '.bin2hex(random_bytes(3)),
            'code' => 'SRC-'.strtoupper(bin2hex(random_bytes(3))),
        ]);
        $response = $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('expense-categories.import.business'), [
                'source_business_id' => $this->sourceBusinessId,
                'skip_existing' => true,
            ]);
        $response->assertStatus(202)->assertJsonPath('success', true);
        $batch = UserImportBatch::where('business_id', $this->targetBusinessId)
            ->where('importer_key', 'business_copy_expense_categories')->firstOrFail();
        Bus::assertDispatched(ProcessBusinessCopyImportBatch::class, fn ($job) => true);
        app(BusinessCopyImportBatchService::class)->process($batch);
        $copied = ExpenseCategory::where('business_id', $this->targetBusinessId)
            ->where('user_import_batch_id', $batch->id)->firstOrFail();

        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->getJson(route('import-copies.detail', ['type' => 'expense_categories', 'batch' => $batch->id]))
            ->assertOk()->assertJsonPath('results.row_outcomes.0.source_id', $source->id)
            ->assertJsonPath('results.row_outcomes.0.target_id', $copied->id);

        $child = ExpenseCategory::create([
            'business_id' => $this->targetBusinessId,
            'name' => 'Subkategori '.$copied->name,
            'code' => null,
            'parent_id' => $copied->id,
        ]);
        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.revert', ['type' => 'expense_categories', 'batch' => $batch->id]))
            ->assertStatus(422);
        $this->assertDatabaseHas('expense_categories', ['id' => $copied->id]);
        $this->assertDatabaseHas('expense_categories', ['id' => $child->id]);
    }

    public function test_user_copy_is_queued_and_revert_checks_created_user_dependencies(): void
    {
        $source = User::create([
            'business_id' => $this->sourceBusinessId,
            'surname' => 'Sumber',
            'first_name' => 'User',
            'last_name' => 'Impor',
            'username' => 'source-user-'.bin2hex(random_bytes(4)),
            'password' => bcrypt('test'),
            'user_type' => 'user',
            'allow_login' => 1,
            'is_cmmsn_agnt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->mock(\App\Utils\ModuleUtil::class, function ($mock) {
            $mock->shouldReceive('isSubscribed')->andReturn(true);
            $mock->shouldReceive('isQuotaAvailable')->andReturn(true);
            $mock->shouldReceive('activityLog')->zeroOrMoreTimes()->andReturn(null);
        });

        $batch = $this->queueUserCopy($source, 'copied-user-'.bin2hex(random_bytes(4)));
        Bus::assertDispatched(ProcessBusinessCopyImportBatch::class);
        app(BusinessCopyImportBatchService::class)->process($batch);
        $result = app(UserImportBatchService::class)->readPayload($batch->fresh(), 'results');
        $this->assertSame('imported', $batch->fresh()->status, json_encode($result));
        $copied = User::where('business_id', $this->targetBusinessId)
            ->where('user_import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('imported', $batch->fresh()->status);

        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.revert', ['type' => 'users', 'batch' => $batch->id]))
            ->assertOk()->assertJsonPath('status', 'reverted');
        $this->assertDatabaseMissing('users', ['id' => $copied->id]);

        $blockedBatch = $this->queueUserCopy($source, 'copied-user-blocked-'.bin2hex(random_bytes(4)));
        app(BusinessCopyImportBatchService::class)->process($blockedBatch);
        $blockedCopy = User::where('business_id', $this->targetBusinessId)
            ->where('user_import_batch_id', $blockedBatch->id)->firstOrFail();
        $this->contact($this->targetBusinessId, 'Dibuat oleh salinan', null, $blockedCopy->id);
        $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.revert', ['type' => 'users', 'batch' => $blockedBatch->id]))
            ->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $blockedCopy->id]);
    }

    public function test_every_history_action_rejects_users_without_copy_permissions(): void
    {
        $limited = new class extends \Illuminate\Foundation\Auth\User
        {
            public function can($ability, $arguments = [])
            {
                return false;
            }
        };
        $limited->forceFill(['id' => $this->adminId, 'business_id' => $this->targetBusinessId]);

        $this->actingAs($limited)->withSession($this->businessSessionData($this->targetBusinessId))
            ->getJson(route('import-copies.history', 'contacts'))->assertForbidden();
        $this->actingAs($limited)->withSession($this->businessSessionData($this->targetBusinessId))
            ->getJson(route('import-copies.detail', ['type' => 'contacts', 'batch' => 1]))->assertForbidden();
        $this->actingAs($limited)->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.retry', ['type' => 'contacts', 'batch' => 1]))->assertForbidden();
        $this->actingAs($limited)->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('import-copies.revert', ['type' => 'contacts', 'batch' => 1]))->assertForbidden();
    }

    private function uploadContact()
    {
        return $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson(route('contacts.import.business'), [
                'source_business_id' => $this->sourceBusinessId,
                'type' => 'customer',
                'skip_existing' => true,
            ]);
    }

    private function queueUserCopy(User $source, string $username): UserImportBatch
    {
        $response = $this->actingAs($this->admin())->withSession($this->businessSessionData($this->targetBusinessId))
            ->postJson('/users/import-business', [
                'source_business_id' => $this->sourceBusinessId,
                'source_user_id' => $source->id,
                'username' => $username,
            ]);
        $response->assertStatus(202)->assertJsonPath('success', true);

        return UserImportBatch::findOrFail($response->json('batch_id'));
    }

    private function contact(int $businessId, string $name, ?string $contactId = null, ?int $createdBy = null, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'business_id' => $businessId,
            'type' => 'customer',
            'name' => $name,
            'mobile' => '08'.random_int(100000000, 999999999),
            'created_by' => $createdBy ?: $this->adminId,
            'contact_id' => $contactId ?: 'CUS-'.strtoupper(bin2hex(random_bytes(5))),
            'is_default' => 0,
            'balance' => 0,
            'is_advance' => 0,
        ], $extra));
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
        $admin->forceFill([
            'id' => $this->adminId,
            'business_id' => $this->targetBusinessId,
            'first_name' => 'Import Admin',
            'user_type' => 'user',
            'allow_login' => 1,
        ]);

        return $admin;
    }

    private function businessSessionData(int $businessId): array
    {
        return [
            'user' => ['business_id' => $businessId, 'id' => $this->adminId],
            'business' => ['time_zone' => 'UTC'],
        ];
    }

    private function createBusinessFixtures(): void
    {
        $currencyId = DB::table('currencies')->value('id');
        if (! $currencyId) {
            $currencyId = DB::table('currencies')->insertGetId([
                'country' => 'Test', 'currency' => 'Test', 'code' => 'TST', 'symbol' => 'T',
                'thousand_separator' => ',', 'decimal_separator' => '.', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->adminId = (int) DB::table('users')->insertGetId([
            'surname' => 'Test', 'first_name' => 'Import Admin', 'username' => 'copy-import-'.bin2hex(random_bytes(6)),
            'password' => bcrypt('test'), 'user_type' => 'user', 'allow_login' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sourceBusinessId = $this->business('Source Business', $currencyId);
        $this->targetBusinessId = $this->business('Target Business', $currencyId);
        DB::table('users')->where('id', $this->adminId)->update(['business_id' => $this->targetBusinessId]);
    }

    private function business(string $name, int $currencyId): int
    {
        return (int) DB::table('business')->insertGetId([
            'name' => $name.' '.bin2hex(random_bytes(3)), 'currency_id' => $currencyId, 'tax_number_1' => '', 'tax_label_1' => 'VAT',
            'owner_id' => $this->adminId, 'time_zone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
