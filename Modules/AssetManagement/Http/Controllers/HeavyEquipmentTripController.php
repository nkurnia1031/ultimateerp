<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\InvoiceScheme;
use App\Product;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportService;
use Modules\AssetManagement\Services\HeavyEquipmentTripService;
use Modules\Project\Entities\Project;

class HeavyEquipmentTripController extends Controller
{
    public function __construct(
        private HeavyEquipmentTripService $tripService,
        private HeavyEquipmentSpbImportService $spbImportService
    )
    {
    }

    public function index(Request $request)
    {
        $this->authorizeView();
        $businessId = $this->businessId();
        $query = HeavyEquipmentTrip::where('business_id', $businessId)
            ->with(['route', 'asset', 'driver', 'project', 'customer', 'billing'])
            ->withCount('settlements')
            ->when($request->input('project_id'), fn ($q, $value) => $q->where('project_id', $value))
            ->when($request->input('asset_id'), fn ($q, $value) => $q->where('asset_id', $value))
            ->when($request->input('driver_id'), fn ($q, $value) => $q->where('driver_id', $value))
            ->when($request->input('ticket_no'), fn ($q, $value) => $q->where('ticket_no', 'like', '%'.$value.'%'))
            ->when($request->input('status'), fn ($q, $value) => $q->where('status', $value))
            ->when($request->input('start_date'), fn ($q, $value) => $q->whereDate('trip_date', '>=', $value))
            ->when($request->input('end_date'), fn ($q, $value) => $q->whereDate('trip_date', '<=', $value));

        $trips = $query->latest('trip_date')->latest('id')->get();
        $routes = HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $assets = Asset::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $drivers = User::where('business_id', $businessId)->where('user_type', 'user')->orderBy('first_name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->user_full_name]);
        $projects = Project::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $statuses = ['draft' => 'Draft', 'dispatched' => 'Dijadwalkan', 'loading' => 'Muat', 'in_transit' => 'Berjalan', 'completed' => 'Selesai', 'verified' => 'Verified', 'cancelled' => 'Dibatalkan'];
        $canManageTrips = $this->canManageTrips();

        return view('assetmanagement::fleet.trips.index', compact('trips', 'routes', 'assets', 'drivers', 'projects', 'statuses', 'canManageTrips'));
    }

    public function create(Request $request)
    {
        $this->authorizeManage();
        $businessId = $this->businessId();
        $routes = HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $assets = Asset::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $drivers = User::where('business_id', $businessId)->where('user_type', 'user')->orderBy('first_name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->user_full_name]);
        $projects = Project::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $customers = Contact::customersDropdown($businessId, false);

        return view('assetmanagement::fleet.trips.create', compact('routes', 'assets', 'drivers', 'projects', 'customers'));
    }

    public function edit($id)
    {
        $this->authorizeManage();
        $trip = $this->trip($id)->load(['route', 'asset', 'driver', 'project', 'customer']);
        if (! $trip->canBeChanged()) {
            abort(422, 'SPB yang sudah ditagihkan, disettle, verified, atau dibatalkan tidak dapat diedit.');
        }
        $businessId = $this->businessId();
        $routes = HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $assets = Asset::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $drivers = User::where('business_id', $businessId)->where('user_type', 'user')->orderBy('first_name')->get()->mapWithKeys(fn ($user) => [$user->id => $user->user_full_name]);
        $projects = Project::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        $customers = Contact::customersDropdown($businessId, false);

        return view('assetmanagement::fleet.trips.create', compact('trip', 'routes', 'assets', 'drivers', 'projects', 'customers'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $request->validate([
            'trip_date' => 'required|date',
            'ticket_no' => 'nullable|string|max:100',
            'load_date' => 'nullable|date',
            'unload_date' => 'nullable|date|after_or_equal:load_date',
            'route_id' => 'required|integer',
            'asset_id' => 'required|integer',
            'driver_id' => 'required|integer',
            'actual_tons' => 'nullable|numeric|min:0',
            'gross_tons' => 'nullable|numeric|min:0',
            'tare_tons' => 'nullable|numeric|min:0',
            'received_tons' => 'nullable|numeric|min:0',
            'billable_tons' => 'nullable|numeric|min:0',
            'sale_rate_per_ton' => 'nullable|numeric|min:0',
            'variance_kg' => 'nullable|numeric',
            'tolerance_kg' => 'nullable|numeric|min:0',
            'claim_rate_per_kg' => 'nullable|numeric|min:0',
            'other_deduction_total' => 'nullable|numeric|min:0',
            'project_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'return_context' => 'nullable|in:project,sell',
            'return_id' => 'nullable|integer',
        ]);

        try {
            $trip = $this->tripService->create($request->all(), $this->businessId(), auth()->id());

            return $this->redirectAfterCreate($trip, $request, 'SPB berhasil dibuat.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function update(Request $request, $id)
    {
        $this->authorizeManage();
        $request->validate([
            'trip_date' => 'required|date',
            'ticket_no' => 'nullable|string|max:100',
            'load_date' => 'nullable|date',
            'unload_date' => 'nullable|date|after_or_equal:load_date',
            'route_id' => 'required|integer',
            'asset_id' => 'required|integer',
            'driver_id' => 'required|integer',
            'actual_tons' => 'nullable|numeric|min:0',
            'gross_tons' => 'nullable|numeric|min:0',
            'tare_tons' => 'nullable|numeric|min:0',
            'received_tons' => 'nullable|numeric|min:0',
            'billable_tons' => 'nullable|numeric|min:0',
            'sale_rate_per_ton' => 'nullable|numeric|min:0',
            'variance_kg' => 'nullable|numeric',
            'tolerance_kg' => 'nullable|numeric|min:0',
            'claim_rate_per_kg' => 'nullable|numeric|min:0',
            'other_deduction_total' => 'nullable|numeric|min:0',
            'project_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'return_context' => 'nullable|in:project,sell',
            'return_id' => 'nullable|integer',
        ]);

        try {
            $trip = $this->tripService->update($this->trip($id), $request->all(), $this->businessId(), auth()->id());

            return redirect()->route('asset.fleet.trips.show', $trip->id)->with('status', ['success' => true, 'msg' => 'SPB berhasil diperbarui.']);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        $this->authorizeManage();
        $trip = $this->trip($id);
        if (! $trip->canBeChanged()) {
            abort(422, 'SPB yang sudah ditagihkan, masuk settlement, verified, atau dibatalkan tidak dapat dihapus.');
        }

        try {
            $this->tripService->delete($trip, $this->businessId());

            return redirect()->route('asset.fleet.trips.index')
                ->with('status', ['success' => true, 'msg' => 'SPB berhasil dihapus.']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('asset.fleet.trips.index')
                ->with('status', ['success' => false, 'msg' => 'SPB gagal dihapus. Silakan coba lagi.']);
        }
    }

    public function importForm()
    {
        $this->authorizeManage();
        $businessId = $this->businessId();
        $routes = HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');

        return view('assetmanagement::fleet.trips.import', compact('routes'));
    }

    public function importPreview(Request $request)
    {
        $this->authorizeManage();
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:51200']);

        try {
            $preview = $this->spbImportService->preview($request->file('file'), $this->businessId());
            $token = (string) Str::uuid();
            Cache::put($this->importCacheKey($token), [
                'business_id' => $this->businessId(),
                'user_id' => auth()->id(),
                'preview' => $preview,
            ], now()->addMinutes(30));
            $routes = HeavyEquipmentRoute::where('business_id', $this->businessId())->where('is_active', true)->orderBy('name')->pluck('name', 'id');

            return view('assetmanagement::fleet.trips.import', compact('routes', 'preview', 'token'));
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function importConfirm(Request $request)
    {
        $this->authorizeManage();
        $request->validate(['token' => 'required|string', 'default_route_id' => 'nullable|integer']);
        $payload = Cache::get($this->importCacheKey($request->input('token')));
        if (! $payload || (int) $payload['business_id'] !== $this->businessId() || (int) $payload['user_id'] !== (int) auth()->id()) {
            abort(422, 'Preview import SPB sudah kedaluwarsa atau tidak valid.');
        }

        $rows = $payload['preview']['rows'] ?? [];
        $defaultRouteId = $request->integer('default_route_id') ?: null;
        if ($defaultRouteId) {
            HeavyEquipmentRoute::where('business_id', $this->businessId())->findOrFail($defaultRouteId);
        }

        $errors = [];
        $created = 0;
        try {
            DB::transaction(function () use (&$errors, &$created, $rows, $defaultRouteId) {
                foreach ($rows as $row) {
                    $row = $this->spbImportService->prepareForImport($row, $this->businessId(), $defaultRouteId);
                    if (! empty($row['row_errors'])) {
                        $errors[] = "Sheet {$row['source_sheet']} baris {$row['source_row']}: ".implode(' ', $row['row_errors']);
                        continue;
                    }
                    if (empty($row['route_id']) || empty($row['asset_id']) || empty($row['driver_id'])) {
                        $errors[] = "Sheet {$row['source_sheet']} baris {$row['source_row']}: rute, unit, dan sopir wajib cocok sebelum import.";
                        continue;
                    }

                    try {
                        $this->tripService->create($row, $this->businessId(), auth()->id());
                        $created++;
                    } catch (\Throwable $rowException) {
                        report($rowException);
                        $errors[] = "Sheet {$row['source_sheet']} baris {$row['source_row']}: {$rowException->getMessage()}";
                    }
                }
                if ($created === 0) {
                    $detail = $errors !== [] ? ' '.implode(' | ', array_slice($errors, 0, 5)) : '';
                    throw new \RuntimeException('Tidak ada baris SPB yang siap diimpor.'.$detail);
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('asset.fleet.trips.import.form')
                ->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        Cache::forget($this->importCacheKey($request->input('token')));
        $message = "{$created} SPB berhasil diimpor.";
        if ($errors !== []) {
            $message .= ' Baris yang belum siap: '.implode(' | ', array_slice($errors, 0, 5));
        }

        return redirect()->route('asset.fleet.trips.index')->with('status', ['success' => true, 'msg' => $message]);
    }

    public function show($id)
    {
        $this->authorizeView();
        $trip = $this->trip($id)->load(['route', 'asset', 'driver', 'project', 'customer', 'rate.batch', 'billing.transaction', 'settlements']);
        $canManageTrips = $this->canManageTrips();

        return view('assetmanagement::fleet.trips.show', compact('trip', 'canManageTrips'));
    }

    public function status(Request $request, $id)
    {
        $this->authorizeManage();
        $request->validate(['status' => 'required|in:dispatched,loading,in_transit,completed,verified']);

        try {
            $this->tripService->transition($this->trip($id), $request->input('status'), auth()->id());

            return back()->with('status', ['success' => true, 'msg' => 'Status trip diperbarui.']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function projectInvoiceForm(Request $request)
    {
        $this->authorizeBilling();
        try {
            $trips = $this->selectedForBilling($request->input('trip_ids', []), true);
        } catch (\Throwable $e) {
            return redirect()->route('asset.fleet.trips.index')
                ->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
        $businessId = $this->businessId();
        $invoiceSchemes = InvoiceScheme::forDropdown($businessId);
        $defaultScheme = InvoiceScheme::getDefault($businessId);
        $locations = BusinessLocation::forDropdown($businessId);

        return view('assetmanagement::fleet.billing.project_invoice', compact('trips', 'invoiceSchemes', 'defaultScheme', 'locations'));
    }

    public function projectInvoiceChooser(Request $request)
    {
        $this->authorizeBilling();
        $businessId = $this->businessId();
        $project = Project::where('business_id', $businessId)->findOrFail($request->integer('project_id'));
        $trips = HeavyEquipmentTrip::with(['route', 'asset', 'driver', 'customer'])
            ->where('business_id', $businessId)
            ->where('project_id', $project->id)
            ->where('status', 'verified')
            ->where('invoice_status', 'unbilled')
            ->latest('trip_date')->latest('id')->get();

        return view('assetmanagement::fleet.billing.chooser', [
            'title' => 'Pilih SPB untuk Invoice Project',
            'description' => 'Hanya SPB Project ini yang sudah verified dan belum masuk invoice.',
            'trips' => $trips,
            'project' => $project,
            'submitRoute' => route('asset.fleet.trips.project-invoice.form'),
            'backUrl' => action([\Modules\Project\Http\Controllers\ProjectController::class, 'show'], [$project->id]).'?view=heavy_equipment_trips',
        ]);
    }

    public function sellChooser(Request $request)
    {
        $this->authorizeBilling();
        $businessId = $this->businessId();
        $trips = HeavyEquipmentTrip::with(['route', 'asset', 'driver', 'project', 'customer'])
            ->where('business_id', $businessId)
            ->where('status', 'verified')
            ->where('invoice_status', 'unbilled')
            ->when($request->integer('project_id'), fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($request->integer('customer_id'), fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->latest('trip_date')->latest('id')->limit(500)->get();

        return view('assetmanagement::fleet.billing.chooser', [
            'title' => 'Angkutan dari SPB ke Sell',
            'description' => 'Pilih SPB verified yang belum ditagihkan untuk membuat transaksi Sell jasa angkutan.',
            'trips' => $trips,
            'project' => null,
            'submitRoute' => route('asset.fleet.trips.sell.form'),
            'backUrl' => url()->previous(),
        ]);
    }

    public function projectInvoice(Request $request)
    {
        $this->authorizeBilling();
        $request->validate(['trip_ids' => 'required|array|min:1', 'location_id' => 'required|integer', 'status' => 'required|in:draft,final']);

        try {
            $transaction = $this->tripService->createProjectInvoice($request->all(), $this->businessId(), auth()->id());

            return redirect()->route('asset.fleet.trips.index')->with('status', ['success' => true, 'msg' => "Invoice Project {$transaction->invoice_no} berhasil dibuat."]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function sellForm(Request $request)
    {
        $this->authorizeBilling();
        try {
            $trips = $this->selectedForBilling($request->input('trip_ids', []), false);
        } catch (\Throwable $e) {
            return redirect()->route('asset.fleet.trips.index')
                ->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
        $businessId = $this->businessId();
        $customers = Contact::customersDropdown($businessId, false);
        $locations = BusinessLocation::forDropdown($businessId);
        $variations = [];
        Product::where('business_id', $businessId)->where('enable_stock', 0)->with('variations')->orderBy('name')->get()->each(function ($product) use (&$variations) {
            foreach ($product->variations as $variation) {
                $variations[$variation->id] = $product->name . ($variation->sub_sku ? ' (' . $variation->sub_sku . ')' : '');
            }
        });

        return view('assetmanagement::fleet.billing.sell', compact('trips', 'customers', 'locations', 'variations'));
    }

    public function sell(Request $request)
    {
        $this->authorizeBilling();
        $request->validate(['trip_ids' => 'required|array|min:1', 'customer_id' => 'required|integer', 'variation_id' => 'required|integer', 'location_id' => 'required|integer', 'status' => 'required|in:draft,final']);

        try {
            $transaction = $this->tripService->createSell($request->all(), $this->businessId(), auth()->id());

            return redirect()->route('asset.fleet.trips.index')->with('status', ['success' => true, 'msg' => "Sell {$transaction->invoice_no} berhasil dibuat."]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    private function selectedForBilling(array $ids, bool $requireProject)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            abort(422, 'Pilih minimal satu trip.');
        }
        $query = HeavyEquipmentTrip::with(['route', 'asset', 'driver', 'project', 'customer'])
            ->where('business_id', $this->businessId())
            ->whereIn('id', $ids)
            ->where('status', 'verified')
            ->where('invoice_status', 'unbilled');
        $trips = $query->get();
        if ($trips->count() !== count($ids)) {
            abort(422, 'Trip harus verified dan belum ditagihkan.');
        }
        if ($requireProject && ($trips->pluck('project_id')->filter()->unique()->count() !== 1 || $trips->contains(fn ($trip) => empty($trip->project_id)))) {
            abort(422, 'Invoice Project hanya dapat dibuat dari trip satu Project.');
        }

        return $trips;
    }

    private function redirectAfterCreate(HeavyEquipmentTrip $trip, Request $request, string $message)
    {
        if ($request->input('return_context') === 'project' && $request->integer('return_id')) {
            return redirect()->action([\Modules\Project\Http\Controllers\ProjectController::class, 'show'], [$request->integer('return_id')])
                ->with('status', ['success' => true, 'msg' => $message]);
        }

        if ($request->input('return_context') === 'sell') {
            return redirect()->route('sells.create')
                ->with('status', ['success' => true, 'msg' => $message.' Silakan pilih SPB tersebut melalui tombol Angkutan dari SPB.']);
        }

        return redirect()->route('asset.fleet.trips.show', $trip->id)
            ->with('status', ['success' => true, 'msg' => $message]);
    }

    private function trip($id): HeavyEquipmentTrip
    {
        return HeavyEquipmentTrip::where('business_id', $this->businessId())->findOrFail($id);
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function importCacheKey(string $token): string
    {
        return 'heavy_equipment_spb_import:'.(int) auth()->id().':'.$token;
    }

    private function authorizeView(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.fleet_trips.create') && ! auth()->user()->can('asset.fleet_billing.manage')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeManage(): void
    {
        if (! $this->canManageTrips()) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function canManageTrips(): bool
    {
        $user = auth()->user();

        return $user->can('superadmin')
            || $user->can('asset.fleet_trips.manage')
            || $user->can('asset.fleet_trips.create')
            || $user->can('asset.create')
            || $user->can('asset.update');
    }

    private function authorizeBilling(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_billing.manage') && ! auth()->user()->can('asset.fleet_trips.manage')) {
            abort(403, 'Unauthorized action.');
        }
    }
}
