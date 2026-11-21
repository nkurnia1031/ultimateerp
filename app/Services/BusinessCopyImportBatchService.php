<?php

namespace App\Services;

use App\Business;
use App\Contact;
use App\ExpenseCategory;
use App\User;
use App\UserImportBatch;
use App\Events\UserCreatedOrModified;
use App\Jobs\ProcessBusinessCopyImportBatch;
use App\Utils\ModuleUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Crm\Entities\SuratKontrak;
use RuntimeException;

class BusinessCopyImportBatchService
{
    private const IMPORTERS = [
        'contacts' => 'business_copy_contacts',
        'expense_categories' => 'business_copy_expense_categories',
        'users' => 'business_copy_users',
    ];

    public function __construct(
        private UserImportBatchService $batches,
        private ContactBusinessImportService $contacts,
        private ExpenseCategoryBusinessImportService $categories,
        private UserBusinessImportService $users
    ) {
    }

    public function queue(string $type, int $sourceBusinessId, int $targetBusinessId, int $createdBy, array $options): array
    {
        $this->assertType($type);
        $this->assertBusinesses($sourceBusinessId, $targetBusinessId);
        $options = array_merge($options, [
            'type' => $type,
            'source_business_id' => $sourceBusinessId,
            'target_business_id' => $targetBusinessId,
        ]);
        $identity = [
            'options' => $this->sortRecursive($options),
            'source_fingerprint' => $this->sourceFingerprint($type, $sourceBusinessId, $options),
        ];
        $sourceKey = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $importerKey = self::IMPORTERS[$type];
        $existing = UserImportBatch::where('business_id', $targetBusinessId)
            ->where('importer_key', $importerKey)->where('source_key', $sourceKey)->first();
        if ($existing) {
            if ($existing->status === 'reverted') {
                throw new RuntimeException('Salinan dengan data dan pemetaan ini sudah pernah direvert. Perbarui sumber atau pemetaan sebelum mengimpor ulang.');
            }

            return ['batch' => $existing, 'duplicate' => true];
        }

        $batch = $this->batches->create([
            'business_id' => $targetBusinessId,
            'created_by' => $createdBy,
            'importer_key' => $importerKey,
            'source_key' => $sourceKey,
            'file_name' => $this->label($type, $sourceBusinessId),
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status' => 'queued_preview',
            'stage' => 'queued',
            'last_message' => 'Salinan data tersimpan dan menunggu worker.',
        ]);
        ProcessBusinessCopyImportBatch::dispatch((int) $batch->id, $targetBusinessId);

        return ['batch' => $batch, 'duplicate' => false];
    }

    public function historyForType(Request $request, string $type, int $targetBusinessId): array
    {
        $this->assertType($type);
        $table = $this->batches->dataTable($request, $targetBusinessId, self::IMPORTERS[$type]);
        $table['data'] = collect($table['data'])->map(function (array $row) use ($type) {
            $row['detail_url'] = route('import-copies.detail', [$type, $row['id']]);
            $row['retry_url'] = route('import-copies.retry', [$type, $row['id']]);
            $row['revert_url'] = route('import-copies.revert', [$type, $row['id']]);
            $row['has_snapshot'] = false;
            $row['can_preview'] = false;
            $row['can_revert'] = in_array($row['status'], ['imported', 'partial'], true);
            $row['can_retry'] = in_array($row['status'], ['failed', 'partial'], true);

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(string $type, int $batchId, int $targetBusinessId): array
    {
        $this->assertType($type);
        $batch = $this->batches->findForBusiness($batchId, $targetBusinessId, self::IMPORTERS[$type]);

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'file_name' => (string) $batch->file_name,
                'status' => (string) $batch->status,
                'options' => json_decode((string) $batch->options_json, true) ?: [],
                'summary' => json_decode((string) $batch->summary_json, true) ?: [],
                'progress_current' => (int) $batch->progress_current,
                'progress_total' => (int) $batch->progress_total,
                'progress_percent' => (int) $batch->progress_percent,
                'error_message' => $batch->error_message,
            ],
            'results' => $batch->results_path ? $this->batches->readPayload($batch, 'results') : [],
        ];
    }

    public function process(UserImportBatch $batch): void
    {
        $options = json_decode((string) $batch->options_json, true, 512, JSON_THROW_ON_ERROR);
        $type = (string) ($options['type'] ?? '');
        $sourceBusinessId = (int) ($options['source_business_id'] ?? 0);
        $targetBusinessId = (int) ($options['target_business_id'] ?? 0);
        $this->assertType($type);
        $this->assertBusinesses($sourceBusinessId, $targetBusinessId);
        abort_unless((int) $batch->business_id === $targetBusinessId, 404, 'Bisnis tujuan batch tidak cocok.');

        $previous = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
        $batch->forceFill(['status' => 'importing', 'stage' => 'copying_records', 'started_at' => $batch->started_at ?: now(),
            'heartbeat_at' => now(), 'progress_percent' => 1, 'last_message' => 'Menyalin record dari bisnis sumber.'])->save();
        $processingBatchId = (int) $batch->id;
        $onProgress = function (int $current, int $total, array $outcomes, array $created) use ($processingBatchId): void {
            $payload = $this->resultPayload($outcomes, $created);
            $batch = UserImportBatch::findOrFail($processingBatchId);
            $this->batches->storePayload($batch, 'results', $payload);
            $batch->forceFill(['progress_current' => $current, 'progress_total' => $total,
                'progress_percent' => $total > 0 ? min(99, (int) floor($current / $total * 100)) : 100,
                'summary_json' => json_encode($payload['summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'heartbeat_at' => now(), 'last_message' => 'Menyalin record '.$current.' dari '.$total.'.'])->save();
        };
        if ($type === 'contacts') {
            $result = $this->contacts->importTracked(
                $sourceBusinessId, $targetBusinessId, (int) $batch->created_by, (string) $options['contact_type'],
                (bool) ($options['skip_existing'] ?? true), $previous, $onProgress, (int) $batch->id
            );
        } elseif ($type === 'expense_categories') {
            $result = $this->categories->importTracked(
                $sourceBusinessId, $targetBusinessId, (int) $batch->created_by,
                (bool) ($options['skip_existing'] ?? true), $previous, $onProgress, (int) $batch->id
            );
        } else {
            $outcomes = collect($previous['row_outcomes'] ?? []);
            if ($outcomes->contains(fn ($row) => ($row['status'] ?? '') === 'created')) {
                $result = $previous;
            } else {
                try {
                    $sourceUserId = (int) ($options['source_user_id'] ?? 0);
                    $user = User::where('business_id', $targetBusinessId)
                        ->where('user_import_batch_id', $batch->id)
                        ->where('user_import_source_id', $sourceUserId)->first();
                    if (! $user) {
                        $user = $this->users->import($sourceBusinessId, $targetBusinessId, $sourceUserId, (array) ($options['mapping'] ?? []), (int) $batch->id);
                        app(ModuleUtil::class)->activityLog($user, 'imported', null, [
                            'source_business_id' => $sourceBusinessId,
                            'source_user_id' => $sourceUserId,
                        ], true, $targetBusinessId);
                        event(new UserCreatedOrModified($user, 'added'));
                    }
                    $snapshot = $this->userSnapshot($user->fresh());
                    $result = [
                        'summary' => ['source_rows' => 1, 'created_rows' => 1, 'skipped_rows' => 0, 'failed_rows' => 0],
                        'row_outcomes' => [[
                            'source_id' => $sourceUserId,
                            'source_label' => trim($user->first_name.' '.$user->last_name),
                            'status' => 'created', 'target_id' => (int) $user->id,
                            'target_label' => (string) $user->username,
                        ]],
                        'created_records' => [$snapshot],
                    ];
                } catch (\Throwable $exception) {
                    $result = [
                        'summary' => ['source_rows' => 1, 'created_rows' => 0, 'skipped_rows' => 0, 'failed_rows' => 1],
                        'row_outcomes' => [[
                            'source_id' => (int) ($options['source_user_id'] ?? 0),
                            'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000),
                        ]],
                        'created_records' => [],
                    ];
                }
                $this->batches->storePayload($batch, 'results', $result);
            }
        }

        $failed = (int) ($result['summary']['failed_rows'] ?? 0);
        $created = (int) ($result['summary']['created_rows'] ?? 0);
        $skipped = (int) ($result['summary']['skipped_rows'] ?? 0);
        $status = $failed > 0 ? (($created + $skipped) > 0 ? 'partial' : 'failed') : 'imported';
        $this->batches->storePayload($batch, 'results', $result);
        $batch->forceFill(['status' => $status, 'stage' => $status,
            'progress_current' => (int) ($result['summary']['source_rows'] ?? 1),
            'progress_total' => (int) ($result['summary']['source_rows'] ?? 1),
            'progress_percent' => 100,
            'summary_json' => json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'last_message' => $status === 'failed' ? 'Tidak ada record yang berhasil disalin.' : 'Salin data bisnis selesai.',
            'error_message' => $failed > 0 ? 'Sebagian record gagal. Periksa detail batch.' : null,
            'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => $status === 'failed' ? now() : null])->save();
    }

    public function retry(UserImportBatch $batch): void
    {
        abort_unless(in_array($batch->status, ['failed', 'partial'], true) && filled($batch->options_json), 422, 'Batch salin ini tidak dapat dicoba ulang.');
        $batch->forceFill(['status' => 'queued_preview', 'stage' => 'retry_queued', 'failed_at' => null, 'completed_at' => null,
            'error_message' => null, 'last_message' => 'Record yang gagal dijadwalkan ulang.'])->save();
        ProcessBusinessCopyImportBatch::dispatch((int) $batch->id, (int) $batch->business_id);
    }

    public function fail(UserImportBatch $batch, \Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 65000);
        try {
            $payload = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
            if (($payload['row_outcomes'] ?? []) === []) {
                $payload = ['summary' => ['failed_rows' => 1], 'row_outcomes' => [['source_id' => null, 'status' => 'failed', 'error' => $message]], 'created_records' => []];
            }
            $this->batches->storePayload($batch, 'results', $payload);
        } catch (\Throwable $storageException) {
            report($storageException);
        }
        $batch->forceFill(['status' => 'failed', 'stage' => 'failed', 'last_message' => 'Salin data bisnis gagal.',
            'error_message' => $message, 'heartbeat_at' => null, 'completed_at' => now(), 'failed_at' => now()])->save();
    }

    public function revert(UserImportBatch $batch, string $type): void
    {
        $this->assertType($type);
        abort_unless(in_array($batch->status, ['imported', 'partial'], true), 422, 'Batch salin ini belum dapat direvert.');
        $result = $this->batches->readPayload($batch, 'results');
        DB::transaction(function () use ($batch, $type, $result) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTERS[$type])->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['imported', 'partial'], true), 422, 'Status batch salin sudah berubah.');
            $records = $result['created_records'] ?? [];
            if ($type === 'contacts') {
                $this->revertContacts($batch, $records);
            } elseif ($type === 'expense_categories') {
                $this->revertExpenseCategories($batch, $records);
            } else {
                $this->revertUser($batch, $records[0] ?? null);
            }
            $locked->forceFill(['status' => 'reverted', 'stage' => 'reverted', 'progress_percent' => 100,
                'last_message' => 'Record hasil salin berhasil dikembalikan.', 'error_message' => null,
                'heartbeat_at' => null, 'reverted_at' => now()])->save();
        });
    }

    public function importerKey(string $type): string
    {
        $this->assertType($type);

        return self::IMPORTERS[$type];
    }

    private function revertContacts(UserImportBatch $batch, array $records): void
    {
        foreach ($records as $snapshot) {
            $contact = Contact::where('business_id', $batch->business_id)->lockForUpdate()->find((int) ($snapshot['target_id'] ?? 0));
            abort_if(! $contact, 422, 'Contact salinan tidak ditemukan.');
            abort_if($this->modelAttributes($contact) !== ($snapshot['attributes'] ?? [])
                || (string) $contact->updated_at !== (string) ($snapshot['updated_at'] ?? ''),
                422, 'Contact '.$contact->contact_id.' berubah setelah disalin.');
            abort_unless((int) $contact->user_import_batch_id === (int) $batch->id, 422, 'Contact bukan hasil batch salin ini.');
            if (DB::table('transactions')->where('contact_id', $contact->id)->exists()
                || (Schema::hasTable('user_contact_access') && DB::table('user_contact_access')->where('contact_id', $contact->id)->exists())
                || (Schema::hasTable('document_and_notes') && DB::table('document_and_notes')->where('notable_type', Contact::class)->where('notable_id', $contact->id)->exists())
                || (Schema::hasTable('surat_kontrak') && SuratKontrak::where('contact_id', $contact->id)->exists())) {
                abort(422, 'Contact '.$contact->contact_id.' sudah dipakai transaksi atau record lain.');
            }
        }
        foreach ($records as $snapshot) {
            Contact::where('business_id', $batch->business_id)->whereKey($snapshot['target_id'])->delete();
        }
    }

    private function revertExpenseCategories(UserImportBatch $batch, array $records): void
    {
        $ids = collect($records)->pluck('target_id')->map(fn ($id) => (int) $id)->all();
        foreach ($records as $snapshot) {
            $category = ExpenseCategory::where('business_id', $batch->business_id)->lockForUpdate()->find((int) ($snapshot['target_id'] ?? 0));
            abort_if(! $category, 422, 'Kategori biaya salinan tidak ditemukan.');
            abort_if($this->modelAttributes($category) !== ($snapshot['attributes'] ?? [])
                || (string) $category->updated_at !== (string) ($snapshot['updated_at'] ?? ''),
                422, 'Kategori biaya '.$category->name.' berubah setelah disalin.');
            abort_unless((int) $category->user_import_batch_id === (int) $batch->id, 422, 'Kategori biaya bukan hasil batch salin ini.');
            if ((Schema::hasColumn('transactions', 'expense_category_id')
                    && DB::table('transactions')->where('expense_category_id', $category->id)->exists())
                || (Schema::hasColumn('transactions', 'category_id')
                    && DB::table('transactions')->where('category_id', $category->id)->exists())
                || ExpenseCategory::where('parent_id', $category->id)->whereNotIn('id', $ids ?: [0])->exists()) {
                abort(422, 'Kategori biaya '.$category->name.' sudah digunakan atau memiliki subkategori lain.');
            }
        }
        foreach (array_reverse($records) as $snapshot) {
            ExpenseCategory::where('business_id', $batch->business_id)->whereKey($snapshot['target_id'])->delete();
        }
    }

    private function revertUser(UserImportBatch $batch, ?array $snapshot): void
    {
        abort_if(! $snapshot, 422, 'Data user hasil salin tidak ditemukan.');
        $user = User::where('business_id', $batch->business_id)->with(['roles', 'contactAccess'])->lockForUpdate()->find((int) ($snapshot['id'] ?? 0));
        abort_if(! $user, 422, 'User hasil salin tidak ditemukan.');
        abort_if($this->userSnapshot($user) !== $snapshot, 422, 'User atau pemetaan aksesnya berubah setelah disalin.');
        abort_unless((int) $user->user_import_batch_id === (int) $batch->id, 422, 'User bukan hasil batch salin ini.');

        $dependencies = DB::table('transactions')->where('created_by', $user->id)->exists()
            || Contact::where('created_by', $user->id)->exists()
            || UserImportBatch::where('created_by', $user->id)->where('id', '<>', $batch->id)->exists();
        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'causer_id')) {
            $dependencies = $dependencies || DB::table('activity_log')->where('causer_id', $user->id)->where('description', '<>', 'imported')->exists();
        }
        abort_if($dependencies || $this->hasUserReference($user->id), 422, 'User hasil salin sudah membuat atau memiliki record lain.');

        $user->roles()->detach();
        $user->permissions()->detach();
        $user->contactAccess()->detach();
        $user->forceDelete();
    }

    private function hasUserReference(int $userId): bool
    {
        $skipTables = ['users', 'user_import_batches', 'model_has_roles', 'model_has_permissions', 'user_contact_access'];
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0] ?? null;
            if (! is_string($table) || in_array($table, $skipTables, true)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            foreach (['created_by', 'user_id'] as $column) {
                if (in_array($column, $columns, true) && DB::table($table)->where($column, $userId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function userSnapshot(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'attributes' => $this->modelAttributes($user),
            'updated_at' => (string) $user->updated_at,
            'role_ids' => $user->roles->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'permission_ids' => $user->getDirectPermissions()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'contact_ids' => $user->contactAccess->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    private function resultPayload(array $outcomes, array $createdRecords): array
    {
        $rows = collect($outcomes);

        return [
            'summary' => [
                'source_rows' => $rows->count(),
                'created_rows' => $rows->where('status', 'created')->count(),
                'skipped_rows' => $rows->where('status', 'skipped')->count(),
                'failed_rows' => $rows->where('status', 'failed')->count(),
            ],
            'row_outcomes' => $rows->values()->all(),
            'created_records' => $createdRecords,
        ];
    }

    private function sourceFingerprint(string $type, int $sourceBusinessId, array $options): string
    {
        if ($type === 'users') {
            $record = User::where('business_id', $sourceBusinessId)
                ->with(['roles', 'contactAccess'])
                ->findOrFail((int) ($options['source_user_id'] ?? 0));
            $fingerprint = [
                'attributes' => $record->getAttributes(),
                'roles' => $record->roles->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                'permissions' => $record->getAllPermissions()->pluck('name')->sort()->values()->all(),
                'contacts' => $record->contactAccess->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            ];

            return hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $model = $type === 'contacts' ? Contact::query() : ExpenseCategory::query();
        $query = $model->where('business_id', $sourceBusinessId);
        if ($type === 'contacts') {
            $contactType = ($options['contact_type'] ?? 'customer') === 'supplier' ? ['supplier', 'both'] : ['customer', 'both'];
            $query->whereIn('type', $contactType);
        }

        $context = hash_init('sha256');
        (clone $query)->orderBy('id')->chunkById(500, function ($records) use ($context) {
            foreach ($records as $record) {
                hash_update($context, json_encode($record->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                hash_update($context, "\n");
            }
        });

        return hash_final($context);
    }

    private function assertBusinesses(int $sourceBusinessId, int $targetBusinessId): void
    {
        if ($sourceBusinessId < 1 || $targetBusinessId < 1 || $sourceBusinessId === $targetBusinessId) {
            throw new RuntimeException('Bisnis sumber dan tujuan harus berbeda.');
        }
        if (! Business::whereKey($sourceBusinessId)->exists() || ! Business::whereKey($targetBusinessId)->exists()) {
            throw new RuntimeException('Bisnis sumber atau tujuan tidak ditemukan.');
        }
    }

    private function assertType(string $type): void
    {
        if (! isset(self::IMPORTERS[$type])) {
            throw new RuntimeException('Jenis salin bisnis tidak dikenal.');
        }
    }

    private function label(string $type, int $sourceBusinessId): string
    {
        $labels = ['contacts' => 'Salin kontak', 'expense_categories' => 'Salin kategori biaya', 'users' => 'Salin user'];

        return ($labels[$type] ?? 'Salin data').' dari bisnis #'.$sourceBusinessId;
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursive($item);
            }
        }
        unset($item);
        ksort($value);

        return $value;
    }

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
