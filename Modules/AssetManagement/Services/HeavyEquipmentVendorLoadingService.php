<?php

namespace Modules\AssetManagement\Services;

use App\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use Modules\Project\Entities\Project;

class HeavyEquipmentVendorLoadingService
{
    public function create(array $input, int $businessId, int $userId): HeavyEquipmentVendorLoading
    {
        $data = $this->normalise($input, $businessId);
        $data['loading_no'] = $data['loading_no'] ?: $this->nextNumber($businessId, $data['loading_date']);
        $this->assertUnique($data, $businessId);

        $loading = HeavyEquipmentVendorLoading::create(array_merge($data, [
            'business_id' => $businessId,
            'created_by' => $userId,
            'status' => 'draft',
            'expense_status' => 'not_linked',
        ]));

        return $loading->fresh(['supplier', 'route', 'project', 'customer']);
    }

    public function update(HeavyEquipmentVendorLoading $loading, array $input, int $businessId): HeavyEquipmentVendorLoading
    {
        $this->assertBusiness($loading, $businessId);
        $this->assertEditable($loading);
        $data = $this->normalise($input, $businessId);
        $data['loading_no'] = $data['loading_no'] ?: $loading->loading_no;
        $this->assertUnique($data, $businessId, $loading->id);
        $loading->update($data);

        return $loading->fresh(['supplier', 'route', 'project', 'customer']);
    }

    public function delete(HeavyEquipmentVendorLoading $loading, int $businessId): void
    {
        $this->assertBusiness($loading, $businessId);
        $this->assertEditable($loading);
        $loading->delete();
    }

    public function transition(HeavyEquipmentVendorLoading $loading, string $status, int $businessId, int $userId): HeavyEquipmentVendorLoading
    {
        $this->assertBusiness($loading, $businessId);
        $allowed = [
            'loading' => ['draft'],
            'in_transit' => ['draft', 'loading'],
            'completed' => ['draft', 'loading', 'in_transit'],
            'verified' => ['completed'],
            'cancelled' => ['draft', 'loading', 'in_transit', 'completed'],
        ];
        if (! isset($allowed[$status]) || ! in_array($loading->status, $allowed[$status], true)) {
            throw new \RuntimeException("Loading Vendor {$loading->loading_no} tidak dapat dipindahkan ke status tersebut.");
        }
        if ($status === 'verified') {
            if (! $loading->supplier_id) {
                throw new \RuntimeException('Supplier/Bendera wajib dipilih sebelum verifikasi.');
            }
            if (trim((string) $loading->vendor_unit_name) === '' || trim((string) $loading->vendor_driver_name) === '') {
                throw new \RuntimeException('Unit dan sopir vendor wajib diisi sebelum verifikasi.');
            }
            if ((float) ($loading->billable_tons ?: $loading->net_tons) <= 0) {
                throw new \RuntimeException('Tonase tagihan harus lebih besar dari nol sebelum verifikasi.');
            }
        }
        if (in_array($loading->status, ['verified', 'cancelled'], true)) {
            throw new \RuntimeException('Loading Vendor yang sudah final tidak dapat diubah.');
        }

        $loading->status = $status;
        if ($status === 'verified') {
            $loading->verified_at = now();
            $loading->verified_by = $userId;
        }
        $loading->save();

        return $loading->fresh(['supplier', 'route', 'project', 'customer']);
    }

    public function createMinimalSupplier(string $label, int $businessId, int $userId): Contact
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Nama supplier baru wajib diisi.');
        }

        $existing = $this->findSupplier($businessId, $label);
        if ($existing) {
            return $existing;
        }

        return Contact::create([
            'business_id' => $businessId,
            'type' => 'supplier',
            'name' => $label,
            'supplier_business_name' => null,
            'mobile' => '-',
            'created_by' => $userId,
            'contact_status' => 'active',
        ]);
    }

    public function findSupplier(int $businessId, string $label): ?Contact
    {
        $needle = $this->normaliseText($label);
        if ($needle === '') {
            return null;
        }

        $matches = Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where('contact_status', 'active')
            ->get()
            ->filter(function (Contact $contact) use ($needle) {
                return in_array($needle, array_filter([
                    $this->normaliseText($contact->name),
                    $this->normaliseText($contact->supplier_business_name),
                    $this->normaliseText($contact->contact_id),
                    $this->normaliseText(Contact::supplierDisplayName($contact, false)),
                ]), true);
            });

        return $matches->first();
    }

    public function supplierMatches(int $businessId, string $term = '')
    {
        $query = Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where('contact_status', 'active')
            ->orderBy('name');
        if (trim($term) !== '') {
            $like = '%'.trim($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('supplier_business_name', 'like', $like)
                    ->orWhere('contact_id', 'like', $like);
            });
        }

        return $query->limit(30)->get()->map(fn ($supplier) => [
            'id' => $supplier->id,
            'text' => Contact::supplierDisplayName($supplier, true),
        ])->values();
    }

    private function normalise(array $input, int $businessId): array
    {
        $loadingDate = Carbon::parse($input['loading_date'] ?? $input['load_date'] ?? now())->toDateString();
        $loadDate = ! empty($input['load_date']) ? Carbon::parse($input['load_date'])->toDateString() : $loadingDate;
        $unloadDate = ! empty($input['unload_date']) ? Carbon::parse($input['unload_date'])->toDateString() : null;
        if ($unloadDate && $unloadDate < $loadDate) {
            throw new \InvalidArgumentException('Tanggal bongkar tidak boleh sebelum tanggal muat.');
        }

        $supplierId = ! empty($input['supplier_id']) ? (int) $input['supplier_id'] : null;
        $supplier = null;
        if ($supplierId) {
            $supplier = Contact::where('business_id', $businessId)
                ->whereIn('type', ['supplier', 'both'])
                ->where('contact_status', 'active')
                ->findOrFail($supplierId);
        }

        $routeId = ! empty($input['route_id']) ? (int) $input['route_id'] : null;
        $route = $routeId ? HeavyEquipmentRoute::where('business_id', $businessId)->findOrFail($routeId) : null;
        $projectId = ! empty($input['project_id']) ? (int) $input['project_id'] : null;
        $project = $projectId ? Project::where('business_id', $businessId)->findOrFail($projectId) : null;
        $customerId = ! empty($input['customer_id']) ? (int) $input['customer_id'] : null;
        if ($customerId) {
            Contact::where('business_id', $businessId)->findOrFail($customerId);
        }
        if ($project && ! $customerId) {
            $customerId = $project->contact_id ?: null;
        }

        $gross = $this->number($input['gross_tons'] ?? null);
        $tare = $this->number($input['tare_tons'] ?? null);
        $net = $this->number($input['net_tons'] ?? null);
        if (($net === null || $net <= 0) && $gross !== null && $tare !== null) {
            $net = round($gross - $tare, 4);
        }
        $received = $this->number($input['received_tons'] ?? null);
        $billable = $this->number($input['billable_tons'] ?? null);
        if ($billable === null || $billable <= 0) {
            $billable = ($received !== null && $received > 0) ? $received : $net;
        }
        if (($net === null || $net <= 0) && ($billable === null || $billable <= 0)) {
            throw new \InvalidArgumentException('Netto atau tonase tagihan harus lebih besar dari nol.');
        }

        $rateProvided = array_key_exists('vendor_rate_per_ton', $input) && $input['vendor_rate_per_ton'] !== '' && $input['vendor_rate_per_ton'] !== null;
        $rate = $rateProvided ? $this->number($input['vendor_rate_per_ton']) : null;
        $claim = round((float) ($this->number($input['claim_adjustment'] ?? 0) ?: 0), 4);
        $deduction = round((float) ($this->number($input['other_deduction'] ?? 0) ?: 0), 4);
        $base = $rate !== null ? round((float) $billable * $rate, 4) : null;
        $total = $base !== null ? round($base - $deduction + $claim, 4) : null;

        return [
            'loading_no' => trim((string) ($input['loading_no'] ?? '')) ?: null,
            'ticket_no' => trim((string) ($input['ticket_no'] ?? '')) ?: null,
            'loading_date' => $loadingDate,
            'load_date' => $loadDate,
            'unload_date' => $unloadDate,
            'supplier_id' => $supplier?->id,
            'supplier_name_snapshot' => $supplier ? Contact::supplierDisplayName($supplier, false) : (trim((string) ($input['supplier_name_snapshot'] ?? '')) ?: null),
            'vendor_driver_name' => trim((string) ($input['vendor_driver_name'] ?? '')) ?: null,
            'vendor_unit_name' => trim((string) ($input['vendor_unit_name'] ?? '')) ?: null,
            'vendor_driver_code' => trim((string) ($input['vendor_driver_code'] ?? '')) ?: null,
            'vendor_unit_code' => trim((string) ($input['vendor_unit_code'] ?? '')) ?: null,
            'route_id' => $route?->id,
            'route_name_snapshot' => $route?->name ?: (trim((string) ($input['route_name_snapshot'] ?? '')) ?: null),
            'project_id' => $project?->id,
            'customer_id' => $customerId,
            'gross_tons' => $gross,
            'tare_tons' => $tare,
            'net_tons' => $net,
            'received_tons' => $received,
            'billable_tons' => $billable,
            'vendor_rate_per_ton' => $rate,
            'vendor_base_amount' => $base,
            'claim_adjustment' => $claim,
            'other_deduction' => $deduction,
            'vendor_total_amount' => $total,
            'source_workbook' => $input['source_workbook'] ?? null,
            'source_sheet' => $input['source_sheet'] ?? null,
            'source_row' => $input['source_row'] ?? null,
            'source_fingerprint' => $input['source_fingerprint'] ?? null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
    }

    private function assertUnique(array $data, int $businessId, ?int $exceptId = null): void
    {
        if (! empty($data['loading_no']) && HeavyEquipmentVendorLoading::where('business_id', $businessId)->where('loading_no', $data['loading_no'])->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists()) {
            throw new \RuntimeException('Nomor Loading Vendor tersebut sudah digunakan.');
        }
        if (! empty($data['ticket_no']) && HeavyEquipmentVendorLoading::where('business_id', $businessId)->where('ticket_no', $data['ticket_no'])->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists()) {
            throw new \RuntimeException('Nomor tiket tersebut sudah digunakan pada Loading Vendor lain.');
        }
        if (! empty($data['source_fingerprint']) && HeavyEquipmentVendorLoading::where('business_id', $businessId)->where('source_fingerprint', $data['source_fingerprint'])->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists()) {
            throw new \RuntimeException('Baris workbook tersebut sudah pernah diimpor.');
        }
    }

    private function assertBusiness(HeavyEquipmentVendorLoading $loading, int $businessId): void
    {
        if ((int) $loading->business_id !== $businessId) {
            throw new \RuntimeException('Loading Vendor tidak berada pada business aktif.');
        }
    }

    private function assertEditable(HeavyEquipmentVendorLoading $loading): void
    {
        if (in_array($loading->status, ['verified', 'cancelled'], true) || $loading->expense_transaction_id) {
            throw new \RuntimeException('Loading Vendor yang sudah terverifikasi, dibatalkan, atau dikaitkan Expense tidak dapat diubah.');
        }
    }

    private function nextNumber(int $businessId, string $date): string
    {
        $prefix = 'LV-'.Carbon::parse($date)->format('Ym').'-';
        $last = HeavyEquipmentVendorLoading::where('business_id', $businessId)
            ->where('loading_no', 'like', $prefix.'%')
            ->orderByDesc('id')->value('loading_no');
        $sequence = $last && preg_match('/(\d+)$/', $last, $match) ? ((int) $match[1]) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function number($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $value = trim(str_ireplace(['rp', 'idr', ' '], '', (string) $value));
        $value = preg_replace('/[^0-9,.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $value = $lastComma > $lastDot ? str_replace(',', '.', str_replace('.', '', $value)) : str_replace(',', '', $value);
        } elseif ($lastComma !== false) {
            $value = strlen($value) - $lastComma - 1 <= 2 ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normaliseText($value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }
}
