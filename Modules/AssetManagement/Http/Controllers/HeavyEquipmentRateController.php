<?php

namespace Modules\AssetManagement\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRateBatch;
use Modules\AssetManagement\Entities\HeavyEquipmentRateUnit;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentRouteRate;
use Modules\AssetManagement\Services\HeavyEquipmentRateService;

class HeavyEquipmentRateController extends Controller
{
    public function __construct(private HeavyEquipmentRateService $rateService)
    {
    }

    public function index()
    {
        $this->authorizeView();
        $businessId = $this->businessId();
        $batches = HeavyEquipmentRateBatch::where('business_id', $businessId)->with('uploader')->latest()->get();
        $rates = HeavyEquipmentRouteRate::where('business_id', $businessId)
            ->whereIn('status', ['active', 'expired', 'draft'])
            ->with(['route', 'units.asset', 'batch', 'trips'])
            ->orderByDesc('effective_from')
            ->orderBy('route_id')
            ->get();
        $allAssets = Asset::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');

        return view('assetmanagement::fleet.rates.index', compact('batches', 'rates', 'allAssets'));
    }

    public function create(Request $request)
    {
        $this->authorizeManage();

        $copyRate = null;
        if ($request->filled('from_rate')) {
            $copyRate = $this->rate($request->integer('from_rate'))->load(['route', 'units.asset', 'batch']);
        }

        return view('assetmanagement::fleet.rates.form', $this->formData(compact('copyRate')));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $validated = $this->validateRate($request);

        try {
            $rate = $this->saveRate($validated);

            return redirect()->route('asset.fleet.rates.index')
                ->with('status', ['success' => true, 'msg' => "Tarif manual #{$rate->id} berhasil dibuat dan langsung aktif."]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => 'Tarif belum disimpan. Periksa data dan coba lagi.']);
        }
    }

    public function edit($id)
    {
        $this->authorizeManage();
        $rate = $this->rate($id)->load(['route', 'units.asset', 'batch', 'trips']);
        if ($rate->status === 'cancelled') {
            return redirect()->route('asset.fleet.rates.index')->with('status', [
                'success' => false,
                'msg' => 'Tarif yang sudah dibatalkan tidak dapat diedit. Buat tarif atau revisi baru.',
            ]);
        }
        if ($rate->trips->isNotEmpty()) {
            return redirect()->route('asset.fleet.rates.index')->with('status', [
                'success' => false,
                'msg' => 'Tarif yang sudah dipakai SPB tidak dapat diedit karena histori tarif harus tetap aman. Gunakan Buat Revisi Tarif.',
            ]);
        }

        return view('assetmanagement::fleet.rates.form', $this->formData(['rate' => $rate]));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeManage();
        $rate = $this->rate($id)->load(['units', 'trips', 'batch']);
        if ($rate->status === 'cancelled') {
            throw ValidationException::withMessages(['rate' => 'Tarif yang sudah dibatalkan tidak dapat diedit. Buat tarif atau revisi baru.']);
        }
        if ($rate->trips->isNotEmpty()) {
            throw ValidationException::withMessages(['rate' => 'Tarif yang sudah dipakai SPB tidak dapat diedit. Gunakan Buat Revisi Tarif.']);
        }
        $validated = $this->validateRate($request);

        try {
            $this->saveRate($validated, $rate);

            return redirect()->route('asset.fleet.rates.index')
                ->with('status', ['success' => true, 'msg' => "Tarif #{$rate->id} berhasil diperbarui."]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => 'Tarif belum diperbarui. Periksa data dan coba lagi.']);
        }
    }

    public function destroy($id)
    {
        $this->authorizeManage();
        $rate = $this->rate($id)->load(['trips', 'batch']);
        if ($rate->status === 'cancelled') {
            return back()->with('status', [
                'success' => false,
                'msg' => 'Tarif tersebut sudah dibatalkan.',
            ]);
        }
        if ($rate->trips->isNotEmpty()) {
            return back()->with('status', [
                'success' => false,
                'msg' => 'Tarif tidak dapat dihapus karena sudah dipakai SPB. Histori transaksi harus dipertahankan; gunakan Buat Revisi Tarif.',
            ]);
        }

        if (! $this->isManualRate($rate)) {
            DB::transaction(function () use ($rate) {
                $rate->update([
                    'status' => 'cancelled',
                    'source_status' => 'cancelled',
                    'source_modified_at' => now(),
                    'source_modified_by' => auth()->id(),
                ]);
            });

            return back()->with('status', ['success' => true, 'msg' => 'Tarif hasil import dibatalkan dan tetap tersimpan sebagai histori sumber.']);
        }

        DB::transaction(function () use ($rate) {
            $batch = $rate->batch;
            $rate->delete();

            if ($batch && str_starts_with((string) $batch->file_name, 'Manual - ') && ! $batch->rates()->exists()) {
                $batch->delete();
            }
        });

        return back()->with('status', ['success' => true, 'msg' => 'Tarif berhasil dihapus.']);
    }

    public function upload(Request $request)
    {
        $this->authorizeManage();
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
            'effective_from' => 'required|date',
        ]);

        try {
            $batch = $this->rateService->import(
                $request->file('file'),
                Carbon::parse($request->input('effective_from')),
                $this->businessId(),
                auth()->id()
            );

            return redirect()->route('asset.fleet.rates.index')->with('status', [
                'success' => true,
                'msg' => "Preview batch {$batch->id} dibuat. Periksa pencocokan unit lalu konfirmasi.",
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function confirm($id)
    {
        $this->authorizeManage();
        $batch = HeavyEquipmentRateBatch::where('business_id', $this->businessId())->findOrFail($id);

        try {
            $this->rateService->confirm($batch);

            return back()->with('status', ['success' => true, 'msg' => 'Tarif berhasil diaktifkan.']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function cancel($id)
    {
        $this->authorizeManage();
        $batch = HeavyEquipmentRateBatch::where('business_id', $this->businessId())->findOrFail($id);

        try {
            $this->rateService->cancel($batch);

            return back()->with('status', ['success' => true, 'msg' => 'Batch tarif dibatalkan.']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function mapUnit(Request $request, $unitId)
    {
        $this->authorizeManage();
        $request->validate(['asset_id' => 'required|integer']);
        $unit = HeavyEquipmentRateUnit::whereHas('rate', function ($query) {
            $query->where('business_id', $this->businessId())->where('status', 'draft');
        })->findOrFail($unitId);
        Asset::where('business_id', $this->businessId())->findOrFail($request->input('asset_id'));
        $unit->update(['asset_id' => $request->input('asset_id')]);

        return back()->with('status', ['success' => true, 'msg' => "Unit {$unit->unit_label} berhasil dicocokkan ke Asset."]);
    }

    public function export()
    {
        $this->authorizeView();
        $writer = $this->rateService->writer($this->rateService->buildExport($this->businessId()));

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'tarif-angkutan-' . now()->format('YmdHis') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function formData(array $extra = []): array
    {
        $businessId = $this->businessId();

        return array_merge([
            'routes' => HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->get(),
            'assets' => Asset::where('business_id', $businessId)->orderBy('name')->get(),
        ], $extra);
    }

    private function validateRate(Request $request): array
    {
        return $request->validate([
            'route_name' => ['required', 'string', 'max:255'],
            'origin' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'driver_rate_per_ton' => ['required', 'numeric', 'min:0'],
            'travel_allowance' => ['required', 'numeric', 'min:0'],
            'sale_rate_per_ton' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function saveRate(array $validated, ?HeavyEquipmentRouteRate $existing = null): HeavyEquipmentRouteRate
    {
        $businessId = $this->businessId();
        $assetIds = array_values(array_unique(array_map('intval', $validated['asset_ids'])));
        $assets = Asset::where('business_id', $businessId)->whereIn('id', $assetIds)->orderBy('name')->get();
        if ($assets->count() !== count($assetIds)) {
            throw ValidationException::withMessages(['asset_ids' => 'Salah satu unit tidak berasal dari business aktif.']);
        }

        $effectiveFrom = Carbon::parse($validated['effective_from'])->startOfDay();
        $routeName = trim($validated['route_name']);

        return DB::transaction(function () use ($validated, $existing, $businessId, $assetIds, $assets, $effectiveFrom, $routeName) {
            $route = HeavyEquipmentRoute::where('business_id', $businessId)->where('name', $routeName)->first();
            if (! $route) {
                $route = HeavyEquipmentRoute::create([
                    'business_id' => $businessId,
                    'name' => $routeName,
                    'origin' => $validated['origin'] ?? null,
                    'destination' => $validated['destination'] ?? null,
                    'is_active' => true,
                    'created_by' => auth()->id(),
                ]);
            } else {
                $route->update([
                    'origin' => $validated['origin'] ?? $route->origin,
                    'destination' => $validated['destination'] ?? $route->destination,
                    'is_active' => true,
                ]);
            }

            $this->assertNoDuplicateRate($route->id, $assetIds, $effectiveFrom, $existing?->id);

            $batch = $existing?->batch;
            if (! $batch) {
                $batch = HeavyEquipmentRateBatch::create([
                    'business_id' => $businessId,
                    'file_name' => 'Manual - ' . mb_substr($route->name, 0, 220),
                    'effective_from' => $effectiveFrom->toDateString(),
                    'status' => 'active',
                    'source_row_count' => 1,
                    'valid_row_count' => 1,
                    'unit_count' => count($assetIds),
                    'route_count' => 1,
                    'uploaded_by' => auth()->id(),
                    'imported_at' => now(),
                ]);
            }

            if ($existing) {
                $existing = HeavyEquipmentRouteRate::where('business_id', $businessId)
                    ->lockForUpdate()
                    ->findOrFail($existing->id);

                if ($existing->status === 'cancelled') {
                    throw ValidationException::withMessages(['rate' => 'Tarif yang sudah dibatalkan tidak dapat diedit.']);
                }
                if ($existing->trips()->exists()) {
                    throw ValidationException::withMessages(['rate' => 'Tarif sudah dipakai SPB dan tidak dapat diedit. Gunakan Buat Revisi Tarif.']);
                }
                $existing->load('batch');
            }

            $rate = $existing ?: new HeavyEquipmentRouteRate();
            $isImportedSource = $existing && ! $this->isManualRate($existing);
            $sourceUnitText = $existing?->source_unit_text ?: $assets->pluck('name')->implode(' ; ');
            $sourceStatus = $existing
                ? ($isImportedSource ? 'modified' : 'manual')
                : 'manual';
            $rate->fill([
                'business_id' => $businessId,
                'route_id' => $route->id,
                'batch_id' => $batch->id,
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => ! empty($validated['effective_to']) ? Carbon::parse($validated['effective_to'])->toDateString() : null,
                'driver_rate_per_ton' => $validated['driver_rate_per_ton'],
                'travel_allowance' => $validated['travel_allowance'],
                'sale_rate_per_ton' => $validated['sale_rate_per_ton'] ?? null,
                'source_row' => $rate->source_row,
                'source_unit_text' => $sourceUnitText,
                'source_status' => $sourceStatus,
                'source_modified_at' => $isImportedSource ? now() : $rate->source_modified_at,
                'source_modified_by' => $isImportedSource ? auth()->id() : $rate->source_modified_by,
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
            ]);
            $rate->save();

            $this->closePreviousRates($rate, $assetIds, $effectiveFrom);
            HeavyEquipmentRateUnit::where('route_rate_id', $rate->id)->delete();
            foreach ($assets as $asset) {
                HeavyEquipmentRateUnit::create([
                    'route_rate_id' => $rate->id,
                    'asset_id' => $asset->id,
                    'unit_label' => $asset->name,
                ]);
            }

            if (str_starts_with((string) $batch->file_name, 'Manual - ')) {
                $this->syncManualBatchSummary($batch->fresh());
            }

            return $rate->fresh(['route', 'units.asset', 'batch']);
        });
    }

    private function assertNoDuplicateRate(int $routeId, array $assetIds, Carbon $effectiveFrom, ?int $exceptId = null): void
    {
        $query = HeavyEquipmentRouteRate::where('business_id', $this->businessId())
            ->where('route_id', $routeId)
            ->whereDate('effective_from', $effectiveFrom->toDateString())
            ->whereIn('status', ['active', 'expired', 'draft'])
            ->when($exceptId, fn ($builder) => $builder->where('id', '!=', $exceptId))
            ->whereHas('units', fn ($builder) => $builder->whereIn('asset_id', $assetIds));

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'asset_ids' => 'Tarif untuk rute, unit, dan tanggal berlaku tersebut sudah ada.',
            ]);
        }
    }

    private function closePreviousRates(HeavyEquipmentRouteRate $rate, array $assetIds, Carbon $effectiveFrom): void
    {
        HeavyEquipmentRouteRate::where('business_id', $this->businessId())
            ->where('route_id', $rate->route_id)
            ->where('id', '!=', $rate->id)
            ->where('status', 'active')
            ->whereDate('effective_from', '<', $effectiveFrom->toDateString())
            ->where(function ($query) use ($effectiveFrom) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $effectiveFrom->toDateString());
            })
            ->whereHas('units', fn ($builder) => $builder->whereIn('asset_id', $assetIds))
            ->get()
            ->each(function (HeavyEquipmentRouteRate $previous) use ($effectiveFrom) {
                $previous->update([
                    'effective_to' => $effectiveFrom->copy()->subDay()->toDateString(),
                    'status' => 'expired',
                ]);
            });
    }

    private function syncManualBatchSummary(HeavyEquipmentRateBatch $batch): void
    {
        $rates = $batch->rates()->with('units')->get();
        $batch->update([
            'source_row_count' => $rates->count(),
            'valid_row_count' => $rates->count(),
            'unit_count' => $rates->flatMap(fn ($rate) => $rate->units->pluck('unit_label'))->unique()->count(),
            'route_count' => $rates->pluck('route_id')->unique()->count(),
        ]);
    }

    private function rate($id): HeavyEquipmentRouteRate
    {
        return HeavyEquipmentRouteRate::where('business_id', $this->businessId())->findOrFail($id);
    }

    private function isManualRate(HeavyEquipmentRouteRate $rate): bool
    {
        return str_starts_with((string) optional($rate->batch)->file_name, 'Manual - ');
    }

    private function authorizeView(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_rates.manage')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_rates.manage') && ! auth()->user()->can('asset.create') && ! auth()->user()->can('asset.update')) {
            abort(403, 'Unauthorized action.');
        }
    }
}
