<?php

namespace App\Services;

use App\UserImportBatch;
use App\BonusAyak;
use App\Imports\BonusAyakImportPreview;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class BonusAyakImportHistoryService
{
    private const IMPORTER_KEY = 'bonus_ayak';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function start(int $businessId, int $userId, string $fileName, string $stagingPath): UserImportBatch
    {
        $batch = $this->createBatch($businessId, $userId, $fileName, (string) Str::uuid());
        $batch->forceFill([
            'status' => 'queued_preview',
            'stage' => 'queued',
            'staging_path' => $stagingPath,
            'last_message' => 'Workbook Bonus Ayak masuk antrean analisis.',
        ])->save();

        return $batch;
    }

    private function createBatch(int $businessId, int $userId, string $fileName, string $token): UserImportBatch
    {
        return $this->batches->create([
            'business_id' => $businessId,
            'created_by' => $userId,
            'importer_key' => self::IMPORTER_KEY,
            'source_key' => $token,
            'file_name' => $fileName,
            'status' => 'queued_preview',
            'stage' => 'queued',
            'last_message' => 'Menunggu worker untuk menganalisis workbook Bonus Ayak.',
        ]);
    }

    public function storePreview(UserImportBatch $batch, array $preview): void
    {
        $entries = $preview['entries'] ?? [];
        $errors = $preview['errors'] ?? [];
        $sourceRows = count($entries) + count($errors);
        $validRows = count(array_filter($entries, fn (array $entry) => ! empty($entry['valid'])));
        $summary = [
            'source_rows' => $sourceRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $sourceRows - $validRows,
        ];

        DB::transaction(function () use ($batch, $preview, $sourceRows, $summary) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', 'bonus_ayak')
                ->lockForUpdate()->findOrFail($batch->id);
            if ($locked->status === 'cancelled') {
                return;
            }

            $this->batches->storePayload($locked, 'preview', $preview);
            $locked->forceFill([
                'status' => 'preview_ready',
                'stage' => 'preview_ready',
                'progress_current' => $sourceRows,
                'progress_total' => $sourceRows,
                'progress_percent' => 100,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'search_text' => $locked->file_name,
                'last_message' => 'Preview import siap diperiksa.',
                'error_message' => null,
                'heartbeat_at' => null,
                'completed_at' => now(),
                'failed_at' => null,
            ])->save();
        });
        $batch->refresh();
    }

    public function analyze(UserImportBatch $batch, string $path, int $userId): array
    {
        $import = new BonusAyakImportPreview((int) $batch->business_id, $userId);
        $import->parseSheets(Excel::toCollection($import, $path));

        $entries = collect($import->entries)->values();
        $seen = [];
        $entries = $entries->map(function (array $entry, int $index) use (&$seen, $batch) {
            $entry['preview_index'] = $index;
            $fingerprint = (string) ($entry['source_fingerprint'] ?? '');
            $reason = null;

            if ($fingerprint === '' || isset($seen[$fingerprint])) {
                $reason = 'Duplikat di dalam file upload.';
            } elseif (BonusAyak::where('business_id', $batch->business_id)
                ->where('source_fingerprint', $fingerprint)
                ->exists()) {
                $reason = 'Data yang sama sudah ada pada business aktif.';
            }

            if ($reason !== null) {
                $entry['valid'] = false;
                $entry['error'] = "Baris {$entry['source_row']}: {$reason}";
            } else {
                $seen[$fingerprint] = true;
                $entry['valid'] = true;
                $entry['error'] = null;
            }

            return $entry;
        })->all();

        $payload = [
            'token' => $batch->source_key,
            'user_id' => $userId,
            'business_id' => (int) $batch->business_id,
            'created_at' => now()->toIso8601String(),
            'original_name' => $batch->file_name,
            'entries' => $entries,
            'errors' => array_values($import->errors),
        ];

        if ($entries === [] && $payload['errors'] === []) {
            throw new \RuntimeException('File tidak berisi baris yang dapat dipreview.');
        }

        return $payload;
    }

    public function history(Request $request, int $businessId): array
    {
        $table = $this->batches->dataTable($request, $businessId, self::IMPORTER_KEY);
        $table['data'] = collect($table['data'])->map(function (array $row) {
            $row['detail_url'] = route('bonus-ayak.import-history.detail', $row['id']);
            $row['restore_url'] = $row['has_snapshot'] ? route('bonus-ayak.import-history.source', $row['id']) : null;
            $row['retry_url'] = route('bonus-ayak.import-history.retry', $row['id']);
            $row['revert_url'] = route('bonus-ayak.import-history.revert', $row['id']);
            $row['can_retry'] = $row['status'] === 'failed' && ($row['has_snapshot'] || filled($row['staging_path'] ?? null));
            $row['can_revert'] = $row['status'] === 'imported';
            $row['active'] = in_array($row['status'], ['queued_preview', 'analyzing', 'queued_import', 'importing'], true);

            return $row;
        })->values()->all();

        return $table;
    }

    public function detail(int $batchId, int $businessId): array
    {
        $batch = $this->batches->findForBusiness($batchId, $businessId, self::IMPORTER_KEY);
        $results = $batch->results_path ? $this->batches->readPayload($batch, 'results') : [];
        $preview = $batch->preview_path ? $this->batches->readPayload($batch, 'preview') : [];
        $summary = [
            'id' => (int) $batch->id,
            'file_name' => $batch->file_name,
            'status' => $batch->status,
            'stage' => $batch->stage,
            'progress_current' => (int) $batch->progress_current,
            'progress_total' => (int) $batch->progress_total,
            'progress_percent' => (int) $batch->progress_percent,
            'last_message' => $batch->last_message,
            'error_message' => $batch->error_message,
            'created_at' => optional($batch->created_at)->toDateTimeString(),
            'completed_at' => optional($batch->completed_at)->toDateTimeString(),
        ];

        return ['batch' => $summary, 'results' => $results, 'preview' => $preview];
    }

    public function queueImport(UserImportBatch $batch, array $selectedIndexes): void
    {
        DB::transaction(function () use ($batch, $selectedIndexes) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTER_KEY)
                ->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'preview_ready', 422, 'Preview Bonus Ayak tidak siap untuk dikonfirmasi.');
            $preview = $this->batches->readPayload($locked, 'preview');
            $selected = collect($selectedIndexes)->map(fn ($index) => (int) $index)->unique()->values();
            $valid = collect($preview['entries'] ?? [])->filter(fn ($entry, $index) =>
                $selected->contains((int) $index) && ! empty($entry['valid'])
            );
            abort_if($valid->isEmpty(), 422, 'Pilih minimal satu baris valid untuk diimpor.');

            $this->batches->storePayload($locked, 'results', ['selected_preview_indexes' => $valid->map(fn ($entry) => (int) $entry['preview_index'])->values()->all()]);
            $locked->forceFill([
                'status' => 'queued_import',
                'stage' => 'queued_import',
                'progress_current' => 0,
                'progress_total' => $valid->count(),
                'progress_percent' => 0,
                'last_message' => 'Baris Bonus Ayak menunggu worker untuk disimpan.',
                'error_message' => null,
                'heartbeat_at' => now(),
                'completed_at' => null,
                'failed_at' => null,
            ])->save();
        });
    }

    public function importSelected(UserImportBatch $batch): array
    {
        $preview = $this->batches->readPayload($batch, 'preview');
        $selection = $this->batches->readPayload($batch, 'results');
        $selectedIndexes = collect($selection['selected_preview_indexes'] ?? [])->map(fn ($index) => (int) $index);
        $entries = collect($preview['entries'] ?? [])->filter(fn ($entry) =>
            $selectedIndexes->contains((int) ($entry['preview_index'] ?? -1)) && ! empty($entry['valid'])
        )->values();
        abort_if($entries->isEmpty(), 422, 'Tidak ada baris valid yang dipilih untuk diimpor.');

        $outcomes = [];
        foreach ($entries as $entry) {
            $duplicate = BonusAyak::where('business_id', $batch->business_id)
                ->where('source_fingerprint', $entry['source_fingerprint'])
                ->exists();
            if ($duplicate) {
                throw new \RuntimeException("Baris {$entry['source_row']} sudah diimpor oleh proses lain atau sudah ada sebelumnya.");
            }

            $record = BonusAyak::create([
                'business_id' => $batch->business_id,
                'tanggal' => $entry['tanggal'],
                'nama_operator' => $entry['nama_operator'],
                'driver_id' => $entry['driver_id'],
                'unit' => $entry['unit'],
                'asset_id' => $entry['asset_id'],
                'trip' => $entry['trip'],
                'bonus' => $entry['bonus'],
                'created_by' => $batch->created_by,
                'source_fingerprint' => $entry['source_fingerprint'],
                'user_import_batch_id' => $batch->id,
            ]);
            $record->refresh();
            $outcomes[] = [
                'source_sheet' => $entry['source_sheet'] ?? 'Sheet 1',
                'source_row' => (int) $entry['source_row'],
                'status' => 'created',
                'record_id' => (int) $record->id,
                'attributes' => $this->modelAttributes($record),
            ];
        }

        return [
            'summary' => ['created_rows' => count($outcomes), 'skipped_rows' => 0, 'failed_rows' => 0],
            'row_outcomes' => $outcomes,
            'selected_preview_indexes' => $selectedIndexes->values()->all(),
        ];
    }

    public function revert(UserImportBatch $batch): void
    {
        abort_unless($batch->status === 'imported', 422, 'Batch Bonus Ayak ini tidak berada pada status imported.');
        $results = $this->batches->readPayload($batch, 'results');
        $outcomes = $results['row_outcomes'] ?? [];
        abort_if($outcomes === [], 422, 'Hasil Bonus Ayak untuk batch ini tidak tersedia.');

        DB::transaction(function () use ($batch, $outcomes) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', self::IMPORTER_KEY)
                ->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'imported', 422, 'Batch Bonus Ayak ini sudah berubah status.');

            foreach ($outcomes as $outcome) {
                $record = BonusAyak::where('business_id', $batch->business_id)
                    ->where('user_import_batch_id', $batch->id)
                    ->lockForUpdate()->find((int) ($outcome['record_id'] ?? 0));
                abort_unless($record, 422, 'Data Bonus Ayak hasil import berubah atau tidak ditemukan.');
                abort_unless($this->modelAttributes($record) === ($outcome['attributes'] ?? []), 422, 'Data Bonus Ayak hasil import sudah berubah. Revert diblokir.');
                abort_unless(! $record->settlements()->exists(), 422, 'Bonus Ayak hasil import sudah masuk settlement. Revert diblokir.');
            }

            BonusAyak::where('business_id', $batch->business_id)->where('user_import_batch_id', $batch->id)->delete();
            $locked->forceFill([
                'status' => 'reverted',
                'stage' => 'reverted',
                'last_message' => 'Batch Bonus Ayak berhasil direvert.',
                'progress_percent' => 100,
                'heartbeat_at' => null,
                'reverted_at' => now(),
            ])->save();
        });
    }

    private function modelAttributes(BonusAyak $record): array
    {
        return json_decode(json_encode($record->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function loadPreview(string $token, int $businessId, int $userId): array
    {
        abort_unless((bool) preg_match('/^[a-f0-9-]{36}$/i', $token), 404);

        $batch = UserImportBatch::where('business_id', $businessId)
            ->where('created_by', $userId)
            ->where('importer_key', self::IMPORTER_KEY)
            ->where('source_key', $token)
            ->first();

        if (! $batch) {
            $batch = $this->adoptLegacyPreview($token, $businessId, $userId);
        }

        abort_unless($batch && in_array($batch->status, ['preview_ready', 'failed'], true) && filled($batch->preview_path), 404);

        try {
            $preview = $this->batches->readPayload($batch, 'preview');
            $createdAtValue = $preview['created_at'] ?? null;
            if (! is_string($createdAtValue) || $createdAtValue === '') {
                throw new \UnexpectedValueException('Preview import tidak memiliki waktu pembuatan.');
            }
            $createdAt = Carbon::parse($createdAtValue);
        } catch (\Throwable) {
            $this->fail($batch, 'Data preview import tidak tersedia.');
            $this->discardPreview($batch);
            abort(404);
        }

        if (($preview['user_id'] ?? null) !== $userId
            || ($preview['business_id'] ?? null) !== $businessId) {
            abort(404);
        }

        return [$batch, $preview];
    }

    private function adoptLegacyPreview(string $token, int $businessId, int $userId): ?UserImportBatch
    {
        $legacyPath = 'bonus-ayak-import-previews/'.$userId.'-'.$businessId.'-'.$token.'.json';
        if (! Storage::disk('local')->exists($legacyPath)) {
            return null;
        }

        try {
            $preview = json_decode(Storage::disk('local')->get($legacyPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($preview)) {
                throw new \UnexpectedValueException('Preview import tidak lagi tersedia.');
            }
            $createdAtValue = $preview['created_at'] ?? null;
            if (($preview['user_id'] ?? null) !== $userId
                || ($preview['business_id'] ?? null) !== $businessId
                || ! is_string($createdAtValue)
                || $createdAtValue === ''
                || Carbon::parse($createdAtValue)->lt(now()->subHours(2))) {
                throw new \UnexpectedValueException('Preview import tidak lagi tersedia.');
            }
        } catch (\Throwable) {
            Storage::disk('local')->delete($legacyPath);
            abort(404);
        }

        try {
            $batch = $this->createBatch(
                $businessId,
                $userId,
                (string) ($preview['original_name'] ?? 'bonus_ayak.xlsx'),
                $token
            );
        } catch (QueryException $exception) {
            $batch = UserImportBatch::where('business_id', $businessId)
                ->where('created_by', $userId)
                ->where('importer_key', self::IMPORTER_KEY)
                ->where('source_key', $token)
                ->first();
            if (! $batch) {
                throw $exception;
            }
        }

        if (! filled($batch->preview_path)) {
            $this->storePreview($batch, $preview);
        }
        Storage::disk('local')->delete($legacyPath);

        return $batch;
    }

    public function beginImport(UserImportBatch $batch, int $rowCount): void
    {
        $batch->forceFill([
            'status' => 'importing',
            'stage' => 'saving',
            'progress_current' => 0,
            'progress_total' => $rowCount,
            'progress_percent' => 0,
            'last_message' => 'Menyimpan baris Bonus Ayak.',
            'error_message' => null,
            'heartbeat_at' => now(),
            'completed_at' => null,
            'failed_at' => null,
        ])->save();
    }

    public function completeImport(UserImportBatch $batch, int $rowCount): void
    {
        $summary = json_decode((string) $batch->summary_json, true) ?: [];
        $summary['imported_rows'] = $rowCount;

        $batch->forceFill([
            'status' => 'imported',
            'stage' => 'imported',
            'progress_current' => $rowCount,
            'progress_total' => $rowCount,
            'progress_percent' => 100,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'last_message' => "Import Bonus Ayak berhasil: {$rowCount} baris disimpan.",
            'error_message' => null,
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    public function fail(UserImportBatch $batch, string $message): void
    {
        $batch->forceFill([
            'status' => 'failed',
            'stage' => 'failed',
            'last_message' => $message,
            'error_message' => $message,
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => now(),
        ])->save();
    }

    public function cancel(UserImportBatch $batch, string $message = 'Preview import dibatalkan.'): void
    {
        DB::transaction(function () use ($batch, $message) {
            $locked = UserImportBatch::where('business_id', $batch->business_id)
                ->where('importer_key', 'bonus_ayak')
                ->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['queued_preview', 'analyzing', 'preview_ready'], true), 422, 'Batch Bonus Ayak tidak dapat dibatalkan pada status ini.');
            $locked->forceFill([
                'status' => 'cancelled',
                'stage' => 'cancelled',
                'last_message' => $message,
                'error_message' => null,
                'heartbeat_at' => null,
                'completed_at' => now(),
            ])->save();
            $batch->setRawAttributes($locked->getAttributes());
        });

        $this->discardPreview($batch);
    }

    public function discardPreview(UserImportBatch $batch): void
    {
        if (filled($batch->preview_path) && Storage::disk('local')->delete($batch->preview_path)) {
            $batch->forceFill(['preview_path' => null])->save();
        }
    }
}
