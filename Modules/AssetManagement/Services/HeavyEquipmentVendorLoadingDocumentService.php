<?php

namespace Modules\AssetManagement\Services;

use App\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoadingDocument;

class HeavyEquipmentVendorLoadingDocumentService
{
    public function __construct(private HeavyEquipmentVendorLoadingService $loadingService)
    {
    }

    public function createManual(array $input, int $businessId, int $userId): HeavyEquipmentVendorLoadingDocument
    {
        $lines = $input['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException('Tambahkan minimal satu line angkutan.');
        }

        return DB::transaction(function () use ($input, $businessId, $userId) {
            $header = $this->normaliseHeader($input, $businessId);
            $firstLine = array_values($input['lines'])[0];
            $document = HeavyEquipmentVendorLoadingDocument::create(array_merge($header, [
                'business_id' => $businessId,
                'document_no' => $this->nextDocumentNo($businessId, $firstLine['loading_date'] ?? now()),
                'created_by' => $userId,
            ]));

            foreach ($input['lines'] as $line) {
                $this->loadingService->create($line, $businessId, $userId, $document);
            }

            return $document->fresh(['supplier', 'customer', 'route', 'lines']);
        });
    }

    public function createImported(array $metadata, array $lines, int $businessId, int $userId): HeavyEquipmentVendorLoadingDocument
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('Sheet tidak memiliki line angkutan yang siap diimpor.');
        }

        return DB::transaction(function () use ($metadata, $lines, $businessId, $userId) {
            $header = $this->normaliseHeader($metadata, $businessId);
            $document = HeavyEquipmentVendorLoadingDocument::create(array_merge($header, [
                'business_id' => $businessId,
                'document_no' => $this->nextDocumentNo($businessId, $lines[0]['loading_date'] ?? now()),
                'source_workbook' => $metadata['source_workbook'] ?? null,
                'source_sheet' => $metadata['source_sheet'] ?? null,
                'source_file_hash' => $metadata['source_file_hash'] ?? null,
                'import_batch_id' => $metadata['import_batch_id'] ?? null,
                'created_by' => $userId,
            ]));

            foreach ($lines as $line) {
                unset($line['route_id'], $line['route_name_snapshot']);
                $this->loadingService->create($line, $businessId, $userId, $document, true);
            }

            return $document->fresh(['supplier', 'customer', 'route', 'lines']);
        });
    }

    public function addLine(HeavyEquipmentVendorLoadingDocument $document, array $input, int $businessId, int $userId): HeavyEquipmentVendorLoading
    {
        $this->assertBusiness($document, $businessId);
        return DB::transaction(function () use ($document, $input, $businessId, $userId) {
            $line = $this->loadingService->create($input, $businessId, $userId, $document);

            return $line->fresh();
        });
    }

    public function updateHeader(HeavyEquipmentVendorLoadingDocument $document, array $input, int $businessId): HeavyEquipmentVendorLoadingDocument
    {
        $this->assertBusiness($document, $businessId);
        $header = $this->normaliseHeader($input, $businessId);

        return DB::transaction(function () use ($document, $header) {
            $document->update($header);

            return $document->fresh(['supplier', 'customer', 'route', 'lines']);
        });
    }

    public function delete(HeavyEquipmentVendorLoadingDocument $document, int $businessId): void
    {
        $this->assertBusiness($document, $businessId);
        DB::transaction(fn () => $document->delete());
    }

    public function headerValues(array $input, int $businessId): array
    {
        return $this->normaliseHeader($input, $businessId);
    }

    private function normaliseHeader(array $input, int $businessId): array
    {
        $supplierId = ! empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;
        $supplier = $supplierId ? Contact::where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->where('contact_status', 'active')->findOrFail($supplierId) : null;
        $customerId = ! empty($input['customer_id']) ? (int) $input['customer_id'] : null;
        $customer = $customerId ? Contact::where('business_id', $businessId)->whereIn('type', ['customer', 'both'])->where('contact_status', 'active')->findOrFail($customerId) : null;
        $routeId = ! empty($input['route_id']) ? (int) $input['route_id'] : null;
        $route = $routeId ? HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->findOrFail($routeId) : null;

        return [
            'supplier_id' => $supplier?->id,
            'supplier_name_snapshot' => $supplier ? Contact::supplierDisplayName($supplier, false) : (trim((string) ($input['supplier_name_snapshot'] ?? $input['supplier_label'] ?? '')) ?: null),
            'customer_id' => $customer?->id,
            'customer_name_snapshot' => $customer?->name ?: (trim((string) ($input['customer_name_snapshot'] ?? $input['customer_label'] ?? '')) ?: null),
            'route_id' => $route?->id,
            'route_name_snapshot' => $route?->name ?: (trim((string) ($input['route_name_snapshot'] ?? '')) ?: null),
            'goods_name' => trim((string) ($input['goods_name'] ?? '')) ?: null,
            'goods_type' => trim((string) ($input['goods_type'] ?? '')) ?: null,
            'vessel_name' => trim((string) ($input['vessel_name'] ?? '')) ?: null,
            'destination' => trim((string) ($input['destination'] ?? '')) ?: null,
        ];
    }

    private function nextDocumentNo(int $businessId, $date): string
    {
        $prefix = 'LVD-'.Carbon::parse($date)->format('Ym').'-';
        $last = HeavyEquipmentVendorLoadingDocument::where('business_id', $businessId)
            ->where('document_no', 'like', $prefix.'%')->orderByDesc('id')->value('document_no');
        $sequence = $last && preg_match('/(\d+)$/', $last, $match) ? ((int) $match[1]) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function assertBusiness(HeavyEquipmentVendorLoadingDocument $document, int $businessId): void
    {
        if ((int) $document->business_id !== $businessId) {
            throw new \RuntimeException('Dokumen Loading Vendor tidak berada pada business aktif.');
        }
    }
}
