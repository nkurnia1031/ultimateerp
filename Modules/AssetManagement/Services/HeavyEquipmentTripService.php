<?php

namespace Modules\AssetManagement\Services;

use App\BonusAyak;
use App\BonusAyakKasbon;
use App\Contact;
use App\BusinessLocation;
use App\Events\SellCreatedOrModified;
use App\InvoiceScheme;
use App\Product;
use App\Transaction;
use App\User;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentDriverSettlement;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\AssetManagement\Entities\HeavyEquipmentTripBilling;
use Modules\AssetManagement\Entities\HeavyEquipmentRouteRate;
use Modules\Project\Entities\ProjectTransaction;

class HeavyEquipmentTripService
{
    public function __construct(
        private HeavyEquipmentRateService $rateService,
        private TransactionUtil $transactionUtil,
        private ProductUtil $productUtil
    ) {
    }

    public function create(array $input, int $businessId, int $userId): HeavyEquipmentTrip
    {
        $date = Carbon::parse($input['trip_date'] ?? $input['load_date'] ?? now())->startOfDay();
        $loadDate = ! empty($input['load_date']) ? Carbon::parse($input['load_date'])->toDateString() : $date->toDateString();
        $unloadDate = ! empty($input['unload_date']) ? Carbon::parse($input['unload_date'])->toDateString() : null;
        $route = HeavyEquipmentRoute::where('business_id', $businessId)->findOrFail($input['route_id']);
        $asset = Asset::where('business_id', $businessId)->findOrFail($input['asset_id']);
        $driver = User::where('business_id', $businessId)->findOrFail($input['driver_id']);
        $grossTons = $this->nullableNumber($input['gross_tons'] ?? null);
        $tareTons = $this->nullableNumber($input['tare_tons'] ?? null);
        $receivedTons = $this->nullableNumber($input['received_tons'] ?? null);
        $tons = $this->nullableNumber($input['actual_tons'] ?? null);
        if (($tons === null || $tons <= 0) && $grossTons !== null && $tareTons !== null) {
            $tons = round($grossTons - $tareTons, 4);
        }
        if (($tons === null || $tons <= 0) && $receivedTons !== null) {
            $tons = $receivedTons;
        }
        if ($tons <= 0) {
            throw new \InvalidArgumentException('Ton aktual harus lebih besar dari nol.');
        }
        $billableTons = $this->nullableNumber($input['billable_tons'] ?? null)
            ?: (($receivedTons !== null && $receivedTons > 0) ? $receivedTons : $tons);

        $rate = $this->rateService->findRate($businessId, $route->id, $asset->id, $date);
        $overrideReason = trim((string) ($input['rate_override_reason'] ?? ''));
        $explicitSaleRate = array_key_exists('sale_rate_per_ton', $input) && $input['sale_rate_per_ton'] !== null && $input['sale_rate_per_ton'] !== '';
        if (! $rate && $overrideReason === '' && ! $explicitSaleRate) {
            throw new \RuntimeException('Tarif aktif untuk rute, unit, dan tanggal trip tidak ditemukan. Isi alasan override bila memang disetujui.');
        }
        if ($rate && $rate->sale_rate_per_ton === null && $overrideReason === '' && ! $explicitSaleRate) {
            throw new \RuntimeException('Harga/Ton belum tersedia pada tarif. Isi alasan override bila trip tetap boleh dibuat.');
        }

        $customerId = $input['customer_id'] ?? null;
        if (! empty($customerId)) {
            Contact::where('business_id', $businessId)->findOrFail($customerId);
        }
        $projectId = $input['project_id'] ?? null;
        if (! empty($projectId)) {
            $project = \Modules\Project\Entities\Project::where('business_id', $businessId)->findOrFail($projectId);
            $customerId = $customerId ?: $project->contact_id;
        }

        $driverRate = array_key_exists('driver_rate_per_ton', $input)
            ? (float) $input['driver_rate_per_ton']
            : (float) ($rate->driver_rate_per_ton ?? 0);
        $travelAllowance = array_key_exists('travel_allowance', $input)
            ? (float) $input['travel_allowance']
            : (float) ($rate->travel_allowance ?? 0);
        $saleRate = array_key_exists('sale_rate_per_ton', $input) && $input['sale_rate_per_ton'] !== null && $input['sale_rate_per_ton'] !== ''
            ? (float) $input['sale_rate_per_ton']
            : ($rate?->sale_rate_per_ton === null ? null : (float) $rate->sale_rate_per_ton);
        if ($saleRate === null && $overrideReason !== '') {
            $saleRate = $this->nullableNumber($input['sale_rate_per_ton'] ?? null);
        }
        $variablePay = round($tons * $driverRate, 4);
        $driverPay = round($travelAllowance + $variablePay, 4);
        $baseRevenue = round($billableTons * (float) ($saleRate ?? 0), 4);
        $varianceKg = (float) ($input['variance_kg'] ?? 0);
        $toleranceKg = (float) ($input['tolerance_kg'] ?? 0);
        $claimRate = (float) ($input['claim_rate_per_kg'] ?? 0);
        $claimAdjustment = round(($varianceKg + $toleranceKg) * $claimRate, 4);
        $otherDeduction = round((float) ($input['other_deduction_total'] ?? 0), 4);
        $revenue = round($baseRevenue - $otherDeduction + $claimAdjustment, 4);

        if (! empty($input['ticket_no']) && HeavyEquipmentTrip::where('business_id', $businessId)
            ->where('ticket_no', trim((string) $input['ticket_no']))->exists()) {
            throw new \RuntimeException('Nomor tiket tersebut sudah digunakan pada SPB lain.');
        }
        if (! empty($input['source_fingerprint']) && HeavyEquipmentTrip::where('business_id', $businessId)
            ->where('source_fingerprint', $input['source_fingerprint'])->exists()) {
            throw new \RuntimeException('Baris workbook tersebut sudah pernah diimpor.');
        }

        $trip = DB::transaction(function () use ($businessId, $userId, $date, $loadDate, $unloadDate, $input, $customerId, $rate, $tons, $grossTons, $tareTons, $receivedTons, $billableTons, $driverRate, $travelAllowance, $saleRate, $baseRevenue, $claimAdjustment, $otherDeduction, $revenue, $varianceKg, $toleranceKg, $claimRate, $variablePay, $driverPay) {
            return HeavyEquipmentTrip::create([
                'business_id' => $businessId,
                'trip_no' => $input['trip_no'] ?? $this->nextNumber($businessId, $date),
                'ticket_no' => $input['ticket_no'] ?? null,
                'trip_date' => $date->toDateString(),
                'load_date' => $loadDate,
                'unload_date' => $unloadDate,
                'project_id' => $input['project_id'] ?? null,
                'customer_id' => $customerId,
                'route_id' => $input['route_id'],
                'asset_id' => $input['asset_id'],
                'driver_id' => $input['driver_id'],
                'route_rate_id' => $rate?->id,
                'actual_tons' => $tons,
                'gross_tons' => $grossTons,
                'tare_tons' => $tareTons,
                'received_tons' => $receivedTons,
                'billable_tons' => $billableTons,
                'variance_kg' => $varianceKg,
                'tolerance_kg' => $toleranceKg,
                'claim_rate_per_kg' => $claimRate,
                'snapshot_driver_rate_per_ton' => $driverRate,
                'snapshot_travel_allowance' => $travelAllowance,
                'snapshot_sale_rate_per_ton' => $saleRate,
                'revenue_total' => $revenue,
                'base_revenue_total' => $baseRevenue,
                'claim_adjustment_total' => $claimAdjustment,
                'other_deduction_total' => $otherDeduction,
                'final_revenue_total' => $revenue,
                'variable_driver_pay' => $variablePay,
                'driver_pay_total' => $driverPay,
                'gross_contribution' => round($revenue - $driverPay, 4),
                'rate_override_reason' => $input['rate_override_reason'] ?? null,
                'notes' => $input['notes'] ?? null,
                'source_workbook' => $input['source_workbook'] ?? null,
                'source_sheet' => $input['source_sheet'] ?? null,
                'source_row' => $input['source_row'] ?? null,
                'source_fingerprint' => $input['source_fingerprint'] ?? null,
                'status' => 'draft',
                'created_by' => $userId,
            ]);
        });

        return $trip->load(['route', 'asset', 'driver', 'project', 'customer', 'rate']);
    }

    public function transition(HeavyEquipmentTrip $trip, string $status, int $userId): HeavyEquipmentTrip
    {
        $allowed = [
            'dispatched' => ['draft'],
            'loading' => ['draft', 'dispatched'],
            'in_transit' => ['loading', 'dispatched'],
            'completed' => ['draft', 'dispatched', 'loading', 'in_transit'],
            'verified' => ['completed'],
        ];
        if (! isset($allowed[$status]) || ! in_array($trip->status, $allowed[$status], true)) {
            throw new \RuntimeException("Trip {$trip->trip_no} tidak dapat dipindahkan ke status {$status}.");
        }
        $trip->status = $status;
        if ($status === 'completed') {
            $trip->completed_at = now();
        }
        if ($status === 'verified') {
            $trip->verified_at = now();
            $trip->verified_by = $userId;
        }
        $trip->save();

        return $trip->fresh(['route', 'asset', 'driver', 'project', 'customer', 'billing']);
    }

    public function delete(HeavyEquipmentTrip $trip, int $businessId): void
    {
        DB::transaction(function () use ($trip, $businessId) {
            $lockedTrip = HeavyEquipmentTrip::where('business_id', $businessId)
                ->lockForUpdate()
                ->findOrFail($trip->id);

            if (! $lockedTrip->canBeChanged()) {
                throw new \RuntimeException('SPB yang sudah ditagihkan, masuk settlement, verified, atau dibatalkan tidak dapat dihapus.');
            }

            $lockedTrip->delete();
        });
    }

    public function update(HeavyEquipmentTrip $trip, array $input, int $businessId, int $userId): HeavyEquipmentTrip
    {
        if ((int) $trip->business_id !== $businessId) {
            throw new \RuntimeException('SPB tidak berada pada business aktif.');
        }
        if (! $trip->canBeChanged()) {
            throw new \RuntimeException('SPB yang sudah ditagihkan, masuk settlement, verified, atau dibatalkan tidak dapat diubah.');
        }

        $date = Carbon::parse($input['trip_date'] ?? $input['load_date'] ?? now())->startOfDay();
        $loadDate = ! empty($input['load_date']) ? Carbon::parse($input['load_date'])->toDateString() : $date->toDateString();
        $unloadDate = ! empty($input['unload_date']) ? Carbon::parse($input['unload_date'])->toDateString() : null;
        $route = HeavyEquipmentRoute::where('business_id', $businessId)->findOrFail($input['route_id']);
        $asset = Asset::where('business_id', $businessId)->findOrFail($input['asset_id']);
        $driver = User::where('business_id', $businessId)->findOrFail($input['driver_id']);
        $grossTons = $this->nullableNumber($input['gross_tons'] ?? null);
        $tareTons = $this->nullableNumber($input['tare_tons'] ?? null);
        $receivedTons = $this->nullableNumber($input['received_tons'] ?? null);
        $tons = $this->nullableNumber($input['actual_tons'] ?? null);
        if (($tons === null || $tons <= 0) && $grossTons !== null && $tareTons !== null) {
            $tons = round($grossTons - $tareTons, 4);
        }
        if (($tons === null || $tons <= 0) && $receivedTons !== null) {
            $tons = $receivedTons;
        }
        if ($tons <= 0) {
            throw new \InvalidArgumentException('Ton aktual harus lebih besar dari nol.');
        }
        $billableTons = $this->nullableNumber($input['billable_tons'] ?? null)
            ?: (($receivedTons !== null && $receivedTons > 0) ? $receivedTons : $tons);

        $rate = $this->rateService->findRate($businessId, $route->id, $asset->id, $date);
        $overrideReason = trim((string) ($input['rate_override_reason'] ?? ''));
        $explicitSaleRate = array_key_exists('sale_rate_per_ton', $input) && $input['sale_rate_per_ton'] !== null && $input['sale_rate_per_ton'] !== '';
        if (! $rate && $overrideReason === '' && ! $explicitSaleRate) {
            throw new \RuntimeException('Tarif aktif untuk rute, unit, dan tanggal trip tidak ditemukan. Isi alasan override bila memang disetujui.');
        }
        if ($rate && $rate->sale_rate_per_ton === null && $overrideReason === '' && ! $explicitSaleRate) {
            throw new \RuntimeException('Harga/Ton belum tersedia pada tarif. Isi alasan override bila trip tetap boleh dibuat.');
        }

        $customerId = $input['customer_id'] ?? null;
        if (! empty($customerId)) {
            Contact::where('business_id', $businessId)->findOrFail($customerId);
        }
        $projectId = $input['project_id'] ?? null;
        if (! empty($projectId)) {
            $project = \Modules\Project\Entities\Project::where('business_id', $businessId)->findOrFail($projectId);
            $customerId = $customerId ?: $project->contact_id;
        }

        $driverRate = array_key_exists('driver_rate_per_ton', $input) ? (float) $input['driver_rate_per_ton'] : (float) ($rate->driver_rate_per_ton ?? 0);
        $travelAllowance = array_key_exists('travel_allowance', $input) ? (float) $input['travel_allowance'] : (float) ($rate->travel_allowance ?? 0);
        $saleRate = $explicitSaleRate ? (float) $input['sale_rate_per_ton'] : ($rate?->sale_rate_per_ton === null ? null : (float) $rate->sale_rate_per_ton);
        if ($saleRate === null && $overrideReason !== '') {
            $saleRate = $this->nullableNumber($input['sale_rate_per_ton'] ?? null);
        }
        $variablePay = round($tons * $driverRate, 4);
        $driverPay = round($travelAllowance + $variablePay, 4);
        $baseRevenue = round($billableTons * (float) ($saleRate ?? 0), 4);
        $varianceKg = (float) ($input['variance_kg'] ?? 0);
        $toleranceKg = (float) ($input['tolerance_kg'] ?? 0);
        $claimRate = (float) ($input['claim_rate_per_kg'] ?? 0);
        $claimAdjustment = round(($varianceKg + $toleranceKg) * $claimRate, 4);
        $otherDeduction = round((float) ($input['other_deduction_total'] ?? 0), 4);
        $revenue = round($baseRevenue - $otherDeduction + $claimAdjustment, 4);

        if (! empty($input['ticket_no']) && HeavyEquipmentTrip::where('business_id', $businessId)
            ->where('ticket_no', trim((string) $input['ticket_no']))->where('id', '<>', $trip->id)->exists()) {
            throw new \RuntimeException('Nomor tiket tersebut sudah digunakan pada SPB lain.');
        }

        $trip->fill([
            'ticket_no' => $input['ticket_no'] ?? null,
            'trip_date' => $date->toDateString(),
            'load_date' => $loadDate,
            'unload_date' => $unloadDate,
            'project_id' => $projectId ?: null,
            'customer_id' => $customerId,
            'route_id' => $route->id,
            'asset_id' => $asset->id,
            'driver_id' => $driver->id,
            'route_rate_id' => $rate?->id,
            'actual_tons' => $tons,
            'gross_tons' => $grossTons,
            'tare_tons' => $tareTons,
            'received_tons' => $receivedTons,
            'billable_tons' => $billableTons,
            'variance_kg' => $varianceKg,
            'tolerance_kg' => $toleranceKg,
            'claim_rate_per_kg' => $claimRate,
            'snapshot_driver_rate_per_ton' => $driverRate,
            'snapshot_travel_allowance' => $travelAllowance,
            'snapshot_sale_rate_per_ton' => $saleRate,
            'revenue_total' => $revenue,
            'base_revenue_total' => $baseRevenue,
            'claim_adjustment_total' => $claimAdjustment,
            'other_deduction_total' => $otherDeduction,
            'final_revenue_total' => $revenue,
            'variable_driver_pay' => $variablePay,
            'driver_pay_total' => $driverPay,
            'gross_contribution' => round($revenue - $driverPay, 4),
            'rate_override_reason' => $input['rate_override_reason'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);
        $trip->save();

        return $trip->fresh(['route', 'asset', 'driver', 'project', 'customer', 'rate']);
    }

    public function createProjectInvoice(array $input, int $businessId, int $userId): Transaction
    {
        $trips = $this->selectedTrips($input['trip_ids'] ?? [], $businessId, true);
        if ($trips->contains(fn ($trip) => empty($trip->project_id))) {
            throw new \RuntimeException('Semua trip untuk invoice Project harus terhubung ke Project.');
        }
        if ($trips->pluck('project_id')->unique()->count() !== 1) {
            throw new \RuntimeException('Trip yang dipilih harus berasal dari satu Project yang sama.');
        }
        if ($trips->pluck('customer_id')->filter()->unique()->count() > 1) {
            throw new \RuntimeException('Trip yang dipilih harus memiliki customer yang sama.');
        }

        $project = $trips->first()->project;
        $customerId = ($input['customer_id'] ?? null) ?: ($trips->first()->customer_id ?: $project->contact_id);
        if (empty($customerId)) {
            throw new \RuntimeException('Customer wajib tersedia pada trip atau Project.');
        }
        $locationId = $input['location_id'] ?? null;
        if (empty($locationId)) {
            throw new \RuntimeException('Lokasi bisnis wajib dipilih untuk Invoice Project.');
        }
        BusinessLocation::where('business_id', $businessId)->findOrFail($locationId);
        if (! empty($input['invoice_scheme_id'])) {
            InvoiceScheme::where('business_id', $businessId)->findOrFail($input['invoice_scheme_id']);
        }
        $status = in_array($input['status'] ?? 'draft', ['draft', 'final'], true) ? $input['status'] : 'draft';
        $date = Carbon::parse($input['transaction_date'] ?? now());
        $total = (float) $trips->sum('revenue_total');

        $transaction = DB::transaction(function () use ($trips, $project, $customerId, $locationId, $status, $date, $total, $businessId, $userId, $input) {
            $schemeId = $input['invoice_scheme_id'] ?? null;
            $transaction = ProjectTransaction::create([
                'business_id' => $businessId,
                'location_id' => $locationId,
                'type' => 'sell',
                'status' => $status,
                'payment_status' => 'due',
                'contact_id' => $customerId,
                'invoice_no' => $this->transactionUtil->getInvoiceNumber($businessId, $status, $locationId, $schemeId),
                'transaction_date' => $date->format('Y-m-d H:i:s'),
                'total_before_tax' => $total,
                'tax_amount' => 0,
                'discount_type' => 'fixed',
                'discount_amount' => 0,
                'final_total' => $total,
                'additional_notes' => $input['additional_notes'] ?? 'Dibuat dari Trip Angkutan.',
                'created_by' => $userId,
                'pjt_project_id' => $project->id,
                'pjt_title' => $input['pjt_title'] ?? ('Angkutan ' . $project->name),
                'sub_type' => 'project_invoice',
            ]);

            foreach ($trips as $trip) {
                $line = $transaction->project_invoice_lines()->create([
                    'task' => 'Angkutan ' . $trip->trip_no,
                    'description' => $this->tripDescription($trip),
                    'rate' => $trip->snapshot_sale_rate_per_ton ?? 0,
                    'tax_rate_id' => null,
                    'quantity' => $trip->billable_tons ?: $trip->actual_tons,
                    'total' => $trip->revenue_total,
                ]);
                HeavyEquipmentTripBilling::create([
                    'business_id' => $businessId,
                    'trip_id' => $trip->id,
                    'transaction_id' => $transaction->id,
                    'billing_type' => 'project_invoice',
                    'invoice_line_table' => 'pjt_invoice_lines',
                    'invoice_line_id' => $line->id,
                    'quantity' => $trip->billable_tons ?: $trip->actual_tons,
                    'amount' => $trip->revenue_total,
                    'created_by' => $userId,
                ]);
                $trip->update(['invoice_status' => 'billed']);
            }

            return $transaction;
        });

        return $transaction;
    }

    public function createSell(array $input, int $businessId, int $userId): Transaction
    {
        $trips = $this->selectedTrips($input['trip_ids'] ?? [], $businessId, false);
        $customerId = $input['customer_id'] ?? $trips->first()->customer_id;
        if (empty($customerId)) {
            throw new \RuntimeException('Customer wajib dipilih untuk Sell.');
        }
        Contact::where('business_id', $businessId)->findOrFail($customerId);
        $locationId = $input['location_id'] ?? null;
        if (empty($locationId)) {
            throw new \RuntimeException('Lokasi bisnis wajib dipilih untuk Sell.');
        }
        BusinessLocation::where('business_id', $businessId)->findOrFail($locationId);
        $variation = \App\Variation::with('product')->findOrFail($input['variation_id']);
        if (! $variation->product) {
            throw new \RuntimeException('Produk jasa untuk Sell tidak ditemukan.');
        }
        if ((int) $variation->product->business_id !== $businessId) {
            throw new \RuntimeException('Produk jasa tidak berasal dari business aktif.');
        }
        if ((int) $variation->product->enable_stock === 1) {
            throw new \RuntimeException('Produk untuk Sell Trip harus berupa jasa tanpa stok.');
        }

        $products = [];
        foreach ($trips as $trip) {
            $products[] = [
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $trip->billable_tons ?: $trip->actual_tons,
                'unit_price' => $trip->snapshot_sale_rate_per_ton,
                'unit_price_inc_tax' => $trip->snapshot_sale_rate_per_ton,
                'item_tax' => 0,
                'tax_id' => null,
                'enable_stock' => 0,
                'product_type' => $variation->product->type,
                'combo' => [],
                'sell_line_note' => $this->tripDescription($trip),
            ];
        }
        $discount = ['discount_type' => 'fixed', 'discount_amount' => 0];
        $invoiceTotal = $this->productUtil->calculateInvoiceTotal($products, null, $discount, false);
        $status = in_array($input['status'] ?? 'draft', ['draft', 'final'], true) ? $input['status'] : 'draft';
        $date = Carbon::parse($input['transaction_date'] ?? now());

        $transaction = DB::transaction(function () use ($products, $invoiceTotal, $status, $date, $customerId, $locationId, $businessId, $userId, $input, $trips) {
            $transactionInput = [
                'location_id' => $locationId,
                'contact_id' => $customerId,
                'status' => $status,
                'final_total' => $invoiceTotal['final_total'],
                'tax_rate_id' => null,
                'discount_type' => 'fixed',
                'discount_amount' => 0,
                'transaction_date' => $date,
                'commission_agent' => null,
                'sale_note' => 'Dibuat dari Trip Angkutan.',
                'staff_note' => $input['staff_note'] ?? null,
                'sub_type' => 'heavy_equipment_trip',
                'is_direct_sale' => 1,
                'is_quotation' => 0,
                'customer_group_id' => null,
                'selling_price_group_id' => null,
                'pay_term_number' => null,
                'pay_term_type' => null,
                'shipping_charges' => 0,
                'exchange_rate' => 1,
            ];
            $transaction = $this->transactionUtil->createSellTransaction($businessId, $transactionInput, $invoiceTotal, $userId, false);
            $this->transactionUtil->createOrUpdateSellLines($transaction, $products, $locationId, false, null, [], false);
            $transaction->load('sell_lines');

            foreach ($trips as $index => $trip) {
                $line = $transaction->sell_lines[$index] ?? null;
                HeavyEquipmentTripBilling::create([
                    'business_id' => $businessId,
                    'trip_id' => $trip->id,
                    'transaction_id' => $transaction->id,
                    'billing_type' => 'sell',
                    'invoice_line_table' => 'transaction_sell_lines',
                    'invoice_line_id' => $line?->id,
                    'quantity' => $trip->billable_tons ?: $trip->actual_tons,
                    'amount' => $trip->revenue_total,
                    'created_by' => $userId,
                ]);
                $trip->update(['invoice_status' => 'billed']);
            }

            return $transaction;
        });

        SellCreatedOrModified::dispatch($transaction);

        return $transaction;
    }

    public function createSettlement(array $input, int $businessId, int $userId): HeavyEquipmentDriverSettlement
    {
        $start = Carbon::parse($input['period_start'])->startOfDay();
        $end = Carbon::parse($input['period_end'])->endOfDay();
        if ($end->lt($start)) {
            throw new \InvalidArgumentException('Periode settlement tidak valid.');
        }
        $driver = User::where('business_id', $businessId)->findOrFail($input['driver_id']);
        $candidates = $this->settlementCandidates($businessId, $driver->id, $start, $end);
        $trips = HeavyEquipmentTrip::with(['route', 'asset', 'project'])
            ->where('business_id', $businessId)
            ->where('driver_id', $driver->id)
            ->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'verified')
            ->where('payroll_status', 'unsettled')
            ->whereDoesntHave('settlements')
            ->whereIn('id', $this->selectedIds($input, 'trip_ids', $candidates['trips']->pluck('id')->all()))
            ->get();
        $bonusAyaks = BonusAyak::where('business_id', $businessId)
            ->where('driver_id', $driver->id)
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->whereDoesntHave('settlements')
            ->whereIn('id', $this->selectedIds($input, 'bonus_ids', $candidates['bonusAyaks']->pluck('id')->all()))
            ->get();
        $kasbons = BonusAyakKasbon::where('business_id', $businessId)
            ->where('driver_id', $driver->id)
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->whereDoesntHave('settlements')
            ->whereIn('id', $this->selectedIds($input, 'kasbon_ids', $candidates['kasbons']->pluck('id')->all()))
            ->get();

        $this->assertSelectedCount($input, 'trip_ids', $trips, 'SPB');
        $this->assertSelectedCount($input, 'bonus_ids', $bonusAyaks, 'Bonus Ayak');
        $this->assertSelectedCount($input, 'kasbon_ids', $kasbons, 'Kasbon');
        if ($trips->isEmpty() && $bonusAyaks->isEmpty()) {
            throw new \RuntimeException('Pilih minimal satu SPB atau Bonus Ayak untuk settlement.');
        }

        $tripPay = (float) $trips->sum('driver_pay_total');
        $bonusTotal = (float) $bonusAyaks->sum('bonus');
        $grossPayable = round($tripPay + $bonusTotal, 4);
        $kasbonTotal = round((float) $kasbons->sum('jumlah_kasbon'), 4);
        $netPayable = round($grossPayable - $kasbonTotal, 4);
        if ($netPayable < 0) {
            throw new \RuntimeException('Total Kasbon melebihi hak payroll pada periode ini. Kurangi Kasbon atau bagi ke periode berikutnya.');
        }

        $settlement = DB::transaction(function () use ($businessId, $userId, $driver, $start, $end, $trips, $bonusAyaks, $kasbons, $grossPayable, $kasbonTotal, $netPayable) {
            $settlement = HeavyEquipmentDriverSettlement::create([
                'business_id' => $businessId,
                'settlement_no' => $this->nextSettlementNumber($businessId, $start),
                'driver_id' => $driver->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'trip_count' => $trips->count(),
                'total_tons' => $trips->sum('actual_tons'),
                'total_variable_pay' => $trips->sum('variable_driver_pay'),
                'total_travel_allowance' => $trips->sum('snapshot_travel_allowance'),
                'total_bonus_ayak' => $bonusAyaks->sum('bonus'),
                'gross_payable' => $grossPayable,
                'total_kasbon' => $kasbonTotal,
                'net_payable' => $netPayable,
                'total_payable' => $netPayable,
                'status' => 'draft',
                'created_by' => $userId,
            ]);
            if ($trips->isNotEmpty()) {
                $settlement->trips()->attach($trips->pluck('id')->all());
            }
            if ($bonusAyaks->isNotEmpty()) {
                $settlement->bonusAyaks()->attach($bonusAyaks->pluck('id')->mapWithKeys(fn ($id) => [$id => ['business_id' => $businessId]])->all());
            }
            if ($kasbons->isNotEmpty()) {
                $settlement->kasbons()->attach($kasbons->pluck('id')->mapWithKeys(fn ($id) => [$id => ['business_id' => $businessId]])->all());
            }

            return $settlement;
        });

        return $settlement->load(['driver', 'trips.route', 'trips.asset', 'bonusAyaks', 'kasbons']);
    }

    public function postSettlement(HeavyEquipmentDriverSettlement $settlement, int $userId): HeavyEquipmentDriverSettlement
    {
        if ($settlement->status !== 'draft') {
            throw new \RuntimeException('Settlement ini sudah diposting atau tidak berstatus draft.');
        }
        $settlement->load(['trips', 'bonusAyaks', 'kasbons']);

        $transaction = DB::transaction(function () use ($settlement, $userId) {
            $settlement = HeavyEquipmentDriverSettlement::where('business_id', $settlement->business_id)
                ->whereKey($settlement->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($settlement->status !== 'draft') {
                throw new \RuntimeException('Settlement ini sudah diposting atau tidak berstatus draft.');
            }
            $settlement->load(['trips', 'bonusAyaks', 'kasbons']);
            $tons = (float) $settlement->total_tons;
            $variableRate = $tons > 0 ? round((float) $settlement->total_variable_pay / $tons, 4) : 0;
            $bonusTotal = (float) ($settlement->total_bonus_ayak ?: $settlement->bonusAyaks->sum('bonus'));
            $kasbonTotal = (float) ($settlement->total_kasbon ?: $settlement->kasbons->sum('jumlah_kasbon'));
            $grossPayable = (float) ($settlement->gross_payable ?: ((float) $settlement->total_payable + $kasbonTotal));
            $netPayable = (float) ($settlement->net_payable ?: $settlement->total_payable);
            $allowanceNames = ['Uang Jalan'];
            $allowanceTypes = ['fixed'];
            $allowanceDates = [$settlement->period_end->toDateString()];
            $allowanceQtys = [$settlement->trip_count];
            $allowanceAmountPers = [(float) $settlement->total_travel_allowance];
            $allowanceUnits = ['trip'];
            $allowanceAmounts = [(float) $settlement->total_travel_allowance];
            if ($bonusTotal > 0) {
                $allowanceNames[] = 'Bonus Ayak';
                $allowanceTypes[] = 'fixed';
                $allowanceDates[] = $settlement->period_end->toDateString();
                $allowanceQtys[] = $settlement->bonusAyaks->count();
                $allowanceAmountPers[] = $bonusTotal;
                $allowanceUnits[] = 'bonus';
                $allowanceAmounts[] = $bonusTotal;
            }
            $deductionNames = [];
            $deductionTypes = [];
            $deductionDates = [];
            $deductionQtys = [];
            $deductionAmountPers = [];
            $deductionUnits = [];
            $deductionAmounts = [];
            if ($kasbonTotal > 0) {
                $deductionNames[] = 'Kasbon Bonus Ayak';
                $deductionTypes[] = 'fixed';
                $deductionDates[] = $settlement->period_end->toDateString();
                $deductionQtys[] = $settlement->kasbons->count();
                $deductionAmountPers[] = $kasbonTotal;
                $deductionUnits[] = 'kasbon';
                $deductionAmounts[] = $kasbonTotal;
            }
            $allowances = [
                'allowance_names' => $allowanceNames,
                'allowance_types' => $allowanceTypes,
                'allowance_percents' => array_fill(0, count($allowanceNames), 0),
                'allowance_tgls' => $allowanceDates,
                'allowance_qtys' => $allowanceQtys,
                'allowance_amountpers' => $allowanceAmountPers,
                'allowance_units' => $allowanceUnits,
                'allowance_amounts' => $allowanceAmounts,
            ];
            $transaction = Transaction::create([
                'business_id' => $settlement->business_id,
                'type' => 'payroll',
                'sub_type' => 'heavy_equipment_trip',
                'status' => 'final',
                'payment_status' => 'due',
                'contact_id' => null,
                'ref_no' => $settlement->settlement_no,
                'transaction_date' => $settlement->period_end->format('Y-m-d') . ' 00:00:00',
                'total_before_tax' => $netPayable,
                'final_total' => $netPayable,
                'created_by' => $userId,
                'expense_for' => $settlement->driver_id,
                'essentials_duration' => $tons,
                'essentials_duration_unit' => 'ton',
                'essentials_amount_per_unit_duration' => $variableRate,
                'essentials_allowances' => json_encode($allowances),
                'essentials_deductions' => json_encode([
                    'deduction_names' => $deductionNames, 'deduction_types' => $deductionTypes,
                    'deduction_percents' => array_fill(0, count($deductionNames), 0),
                    'deduction_tgls' => $deductionDates, 'deduction_qtys' => $deductionQtys,
                    'deduction_amountpers' => $deductionAmountPers, 'deduction_units' => $deductionUnits,
                    'deduction_amounts' => $deductionAmounts,
                ]),
            ]);
            $settlement->update(['status' => 'posted', 'payroll_transaction_id' => $transaction->id]);
            $settlement->trips()->update(['payroll_status' => 'settled']);

            return $transaction;
        });

        return $settlement->fresh(['driver', 'trips', 'payrollTransaction']);
    }

    public function settlementCandidates(int $businessId, int $driverId, Carbon $start, Carbon $end): array
    {
        return [
            'trips' => HeavyEquipmentTrip::with(['route', 'asset', 'project'])
                ->where('business_id', $businessId)
                ->where('driver_id', $driverId)
                ->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])
                ->where('status', 'verified')
                ->where('payroll_status', 'unsettled')
                ->whereDoesntHave('settlements')
                ->orderBy('trip_date')->orderBy('id')->get(),
            'bonusAyaks' => BonusAyak::with(['asset', 'project'])
                ->where('business_id', $businessId)
                ->where('driver_id', $driverId)
                ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
                ->whereDoesntHave('settlements')
                ->orderBy('tanggal')->orderBy('id')->get(),
            'kasbons' => BonusAyakKasbon::where('business_id', $businessId)
                ->where('driver_id', $driverId)
                ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
                ->whereDoesntHave('settlements')
                ->orderBy('tanggal')->orderBy('id')->get(),
        ];
    }

    private function selectedIds(array $input, string $key, array $fallback): array
    {
        if (! $this->inputHasSelection($input, $key)) {
            return array_values(array_unique(array_map('intval', $fallback)));
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) ($input[$key] ?? [])))));
    }

    private function assertSelectedCount(array $input, string $key, $models, string $label): void
    {
        if (! $this->inputHasSelection($input, $key)) {
            return;
        }

        $requested = $this->selectedIds($input, $key, []);
        if ($models->count() !== count($requested)) {
            throw new \RuntimeException("Sebagian {$label} tidak tersedia, sudah dipakai settlement, atau tidak berada pada periode/sopir yang dipilih.");
        }
    }

    private function inputHasSelection(array $input, string $key): bool
    {
        return array_key_exists($key, $input) || ! empty($input[$key.'_present']);
    }

    private function selectedTrips(array $ids, int $businessId, bool $requireProject): \Illuminate\Support\Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            throw new \InvalidArgumentException('Minimal satu trip harus dipilih.');
        }
        $trips = HeavyEquipmentTrip::with(['route', 'asset', 'driver', 'project', 'customer', 'billing'])
            ->where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->where('status', 'verified')
            ->where('invoice_status', 'unbilled')
            ->get();
        if ($trips->count() !== count($ids)) {
            throw new \RuntimeException('Sebagian trip tidak ditemukan, belum verified, atau sudah ditagihkan.');
        }
        if ($requireProject && $trips->pluck('project_id')->filter()->unique()->count() !== 1) {
            throw new \RuntimeException('Pilih trip dari satu Project yang sama.');
        }
        if ($trips->contains(fn ($trip) => $trip->snapshot_sale_rate_per_ton === null)) {
            throw new \RuntimeException('Trip dengan Harga/Ton kosong tidak dapat ditagihkan.');
        }

        return $trips;
    }

    private function tripDescription(HeavyEquipmentTrip $trip): string
    {
        return "Rute: {$trip->route->name}; Unit: {$trip->asset->name}; Sopir: {$trip->driver->user_full_name}; Ton: {$trip->actual_tons}";
    }

    private function nextNumber(int $businessId, Carbon $date): string
    {
        $prefix = 'TRIP-' . $date->format('Ym') . '-';
        $latest = HeavyEquipmentTrip::where('business_id', $businessId)->where('trip_no', 'like', $prefix . '%')->orderByDesc('id')->value('trip_no');
        $number = $latest ? ((int) substr($latest, -4)) + 1 : 1;

        return $prefix . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function nextSettlementNumber(int $businessId, Carbon $date): string
    {
        $prefix = 'SET-' . $date->format('Ym') . '-';
        $latest = HeavyEquipmentDriverSettlement::where('business_id', $businessId)->where('settlement_no', 'like', $prefix . '%')->orderByDesc('id')->value('settlement_no');
        $number = $latest ? ((int) substr($latest, -4)) + 1 : 1;

        return $prefix . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function nullableNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
