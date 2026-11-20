<?php

namespace Modules\AssetManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoadingDocument;

class HeavyEquipmentVendorLoadingImportReverseService
{
    public function reverse(int $batchId, int $businessId, int $userId): array
    {
        return DB::transaction(function () use ($batchId, $businessId, $userId) {
            $batch = DB::table('heavy_equipment_vendor_loading_import_batches')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                abort(404, 'Batch import tidak ditemukan.');
            }
            if ($batch->status === 'reversed') {
                throw new \InvalidArgumentException('Batch ini sudah pernah di-reverse.');
            }
            if (! in_array($batch->status, ['imported', 'partial'], true)) {
                throw new \InvalidArgumentException('Reverse hanya tersedia untuk batch berstatus selesai atau selesai sebagian.');
            }

            $documents = HeavyEquipmentVendorLoadingDocument::query()
                ->where('business_id', $businessId)
                ->where('import_batch_id', $batchId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $legacyDocuments = $documents->isEmpty() && (int) $batch->imported_documents > 0;
            if ($legacyDocuments) {
                $documents = $this->resolveUnambiguousLegacyDocuments($batch, $businessId);
                if ($documents->count() !== (int) $batch->imported_documents) {
                    throw new \InvalidArgumentException(
                        'Dokumen batch lama tidak dapat dipetakan dengan aman. Preview sumber mungkin sudah kedaluwarsa atau workbook yang sama pernah diimpor lebih dari sekali; data tidak dihapus.'
                    );
                }
            }

            $blockedDocuments = $documents->filter(function ($document) {
                return ! empty($document->verified_at)
                    || in_array((string) $document->status, ['verified', 'cancelled'], true)
                    || ! empty($document->expense_transaction_id)
                    || (string) ($document->expense_status ?: 'not_linked') !== 'not_linked';
            })->pluck('document_no')->values()->all();
            if ($blockedDocuments !== []) {
                throw new \InvalidArgumentException(
                    'Reverse diblokir; dokumen sudah diverifikasi, dibatalkan, atau terkait dengan Expense: '.implode(', ', $blockedDocuments).'.'
                );
            }

            $documentIds = $documents->pluck('id')->all();
            $lineCount = $documentIds === [] ? 0 : HeavyEquipmentVendorLoading::whereIn('loading_document_id', $documentIds)->count();
            $preservedExpenseCount = $documents->filter(fn ($document) => $document->expense_transaction_id !== null)->count();

            foreach ($documents as $document) {
                if ($legacyDocuments) {
                    $document->import_batch_id = $batchId;
                    $document->save();
                }
                // Deleting the document cascades only to its Loading Vendor lines.
                // Expense transactions are independent and are deliberately untouched.
                $document->delete();
            }

            $message = "Reverse selesai: {$documents->count()} dokumen dan {$lineCount} line dihapus.";
            if ($preservedExpenseCount > 0) {
                $message .= " {$preservedExpenseCount} transaksi Expense tetap disimpan.";
            }

            DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->update([
                'status' => 'reversed',
                'stage' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reversed_documents' => $documents->count(),
                'reversed_lines' => $lineCount,
                'last_message' => $message,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'batch_id' => $batchId,
                'status' => 'reversed',
                'message' => $message,
                'reversed_documents' => $documents->count(),
                'reversed_lines' => $lineCount,
                'preserved_expense_transactions' => $preservedExpenseCount,
                'msg' => $message,
            ];
        });
    }

    /**
     * Older batches predate import_batch_id. Only map one when every successful
     * sheet resolves to exactly one document and no duplicate document shares
     * that workbook hash in the same business.
     */
    private function resolveUnambiguousLegacyDocuments(object $batch, int $businessId)
    {
        if (! $batch->preview_path || ! Storage::disk('local')->exists($batch->preview_path)) {
            return collect();
        }

        try {
            $preview = json_decode(Storage::disk('local')->get($batch->preview_path), true, 512, JSON_THROW_ON_ERROR);
            $results = json_decode($batch->results_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return collect();
        }

        $sheets = collect($preview['sheets'] ?? [])->keyBy('sheet_key');
        $successful = collect($results)->filter(fn ($result) => (int) ($result['documents'] ?? 0) > 0);
        if ($successful->count() !== (int) $batch->imported_documents) {
            return collect();
        }

        $documents = collect();
        $expectedBySource = [];
        foreach ($successful as $sheetKey => $result) {
            $sheet = $sheets->get($sheetKey);
            if (! $sheet) {
                return collect();
            }

            $workbook = (string) ($sheet['source_workbook'] ?? '');
            $sourceSheet = (string) ($sheet['source_sheet'] ?? $sheet['title'] ?? '');
            $hash = (string) ($sheet['source_file_hash'] ?? '');
            if ($workbook === '' || $sourceSheet === '' || ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                return collect();
            }

            $key = $hash.'|'.$workbook;
            $expectedBySource[$key] = ($expectedBySource[$key] ?? 0) + 1;
            if ($this->hasCompetingLegacyBatch($batch, $businessId, $hash, $workbook)) {
                return collect();
            }

            $matches = HeavyEquipmentVendorLoadingDocument::query()
                ->where('business_id', $businessId)
                ->where('source_file_hash', $hash)
                ->where('source_workbook', $workbook)
                ->where('source_sheet', $sourceSheet)
                ->lockForUpdate()
                ->get();

            if ($matches->count() !== 1 || $matches->first()->import_batch_id !== null) {
                return collect();
            }
            $documents->push($matches->first());
        }

        foreach ($expectedBySource as $key => $expectedCount) {
            [$hash, $workbook] = explode('|', $key, 2);
            $actualCount = HeavyEquipmentVendorLoadingDocument::query()
                ->where('business_id', $businessId)
                ->where('source_file_hash', $hash)
                ->where('source_workbook', $workbook)
                ->count();
            if ($actualCount !== $expectedCount) {
                return collect();
            }
        }

        return $documents->unique('id')->values();
    }

    private function hasCompetingLegacyBatch(object $batch, int $businessId, string $hash, string $workbook): bool
    {
        $otherBatches = DB::table('heavy_equipment_vendor_loading_import_batches')
            ->where('business_id', $businessId)
            ->where('id', '<>', $batch->id)
            ->whereIn('status', ['imported', 'partial'])
            ->where('imported_documents', '>', 0)
            ->get(['file_name', 'preview_path']);

        foreach ($otherBatches as $other) {
            if (! $other->preview_path || ! Storage::disk('local')->exists($other->preview_path)) {
                if ((string) $other->file_name === (string) $batch->file_name) {
                    return true;
                }
                continue;
            }

            try {
                $preview = json_decode(Storage::disk('local')->get($other->preview_path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $exception) {
                if ((string) $other->file_name === (string) $batch->file_name) {
                    return true;
                }
                continue;
            }

            foreach ($preview['sheets'] ?? [] as $sheet) {
                if ((string) ($sheet['source_file_hash'] ?? '') === $hash
                    && (string) ($sheet['source_workbook'] ?? '') === $workbook) {
                    return true;
                }
            }
        }

        return false;
    }
}
