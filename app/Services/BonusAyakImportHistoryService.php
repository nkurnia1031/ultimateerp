<?php

namespace App\Services;

use App\UserImportBatch;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class BonusAyakImportHistoryService
{
    private const IMPORTER_KEY = 'bonus_ayak';

    public function __construct(private UserImportBatchService $batches)
    {
    }

    public function start(int $businessId, int $userId, string $fileName): UserImportBatch
    {
        return $this->createBatch($businessId, $userId, $fileName, (string) Str::uuid());
    }

    private function createBatch(int $businessId, int $userId, string $fileName, string $token): UserImportBatch
    {
        return $this->batches->create([
            'business_id' => $businessId,
            'created_by' => $userId,
            'importer_key' => self::IMPORTER_KEY,
            'source_key' => $token,
            'file_name' => $fileName,
            'status' => 'analyzing',
            'stage' => 'analysis',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    public function storePreview(UserImportBatch $batch, array $preview): void
    {
        $this->batches->storePayload($batch, 'preview', $preview);

        $entries = $preview['entries'] ?? [];
        $errors = $preview['errors'] ?? [];
        $sourceRows = count($entries) + count($errors);
        $validRows = count(array_filter($entries, fn (array $entry) => ! empty($entry['valid'])));
        $summary = [
            'source_rows' => $sourceRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $sourceRows - $validRows,
        ];

        $batch->forceFill([
            'status' => 'preview_ready',
            'stage' => 'preview_ready',
            'progress_current' => $sourceRows,
            'progress_total' => $sourceRows,
            'progress_percent' => 100,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'search_text' => $batch->file_name,
            'last_message' => 'Preview import siap diperiksa.',
            'error_message' => null,
            'heartbeat_at' => null,
            'completed_at' => now(),
            'failed_at' => null,
        ])->save();
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
            || ($preview['business_id'] ?? null) !== $businessId
            || $createdAt->lt(now()->subHours(2))) {
            if ($createdAt->lt(now()->subHours(2))) {
                $this->cancel($batch, 'Preview kedaluwarsa.');
            }

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
        $batch->forceFill([
            'status' => 'cancelled',
            'stage' => 'cancelled',
            'last_message' => $message,
            'error_message' => null,
            'heartbeat_at' => null,
            'completed_at' => now(),
        ])->save();

        $this->discardPreview($batch);
    }

    public function discardPreview(UserImportBatch $batch): void
    {
        if (filled($batch->preview_path) && Storage::disk('local')->delete($batch->preview_path)) {
            $batch->forceFill(['preview_path' => null])->save();
        }
    }
}
