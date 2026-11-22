<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use App\Contact;
use App\InvoiceScheme;
use App\Product;
use App\Services\UserImportBatchService;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentTrip;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentSpbImportJob;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentSpbPreviewJob;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportBatchService;
use Modules\AssetManagement\Services\HeavyEquipmentSpbImportService;
use Modules\AssetManagement\Services\HeavyEquipmentTripService;
use Modules\AssetManagement\Utils\AssetUtil;
use Modules\Project\Entities\Project;

class HeavyEquipmentTripController extends Controller
{
    public function __construct(
        private HeavyEquipmentTripService $tripService,
        private HeavyEquipmentSpbImportService $spbImportService,
        private HeavyEquipmentSpbImportBatchService $spbImportBatchService,
        private UserImportBatchService $userImportBatchService,
        private ModuleUtil $moduleUtil,
        private Util $commonUtil,
        private AssetUtil $assetUtil
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

    public function importForm(Request $request)
    {
        $this->authorizeManage();
        $businessId = $this->businessId();
        $routes = HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $token = trim((string) $request->query('token'));
        $batchId = $request->integer('batch_id') ?: null;
        $batch = null;
        $preview = null;
        $viewData = [
            'assets' => collect(),
            'drivers' => collect(),
            'assetCategories' => collect(),
            'businessLocations' => collect(),
            'canCreateAsset' => false,
            'canCreateDriver' => false,
        ];

        if ($batchId) {
            $batch = $this->userImportBatchService->findForBusiness($batchId, $businessId, 'spb');
            if ($batch->status !== 'preview_ready' || ! $batch->preview_path) {
                abort(404, 'Preview SPB belum siap atau batch tidak dapat diperiksa.');
            }
            $preview = $this->userImportBatchService->readPayload($batch, 'preview');
            $token = (string) Str::uuid();
            Cache::put($this->importCacheKey($token), [
                'business_id' => $businessId,
                'user_id' => auth()->id(),
                'batch_id' => $batch->id,
                'preview' => $preview,
            ], now()->addHours(2));
            $viewData = $this->importViewData($businessId);
        } elseif ($token !== '') {
            $payload = Cache::get($this->importCacheKey($token));
            if (! $payload || (int) ($payload['business_id'] ?? 0) !== $businessId || (int) ($payload['user_id'] ?? 0) !== (int) auth()->id()) {
                abort(404, 'Preview import SPB sudah kedaluwarsa atau tidak dapat diakses.');
            }
            $preview = $payload['preview'] ?? null;
            $viewData = $this->importViewData($businessId);
        }

        return view('assetmanagement::fleet.trips.import', array_merge($viewData, compact('routes', 'preview', 'token', 'batch')));
    }

    public function importPreview(Request $request)
    {
        $this->authorizeManage();
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:51200']);

        $batch = null;
        $stagingPath = null;
        try {
            $file = $request->file('file');
            $businessId = $this->businessId();
            $batch = $this->userImportBatchService->create([
                'business_id' => $businessId,
                'created_by' => auth()->id(),
                'importer_key' => 'spb',
                'file_name' => $file->getClientOriginalName(),
                'status' => 'queued_preview',
                'stage' => 'queued',
                'last_message' => 'Upload diterima. Menunggu analisis workbook.',
                'heartbeat_at' => now(),
            ]);
            $extension = strtolower($file->getClientOriginalExtension());
            $stagingPath = $file->storeAs('user-import-staging', 'spb-'.$batch->id.'.'.$extension, 'user_imports');
            if (! $stagingPath) {
                throw new \RuntimeException('File upload tidak dapat disimpan ke staging privat.');
            }
            $batch->staging_path = $stagingPath;
            $batch->save();

            ProcessHeavyEquipmentSpbPreviewJob::dispatch((int) $batch->id);

            return response()->json([
                'success' => true,
                'batch' => [
                    'id' => $batch->id,
                    'file_name' => $batch->file_name,
                    'status' => $batch->status,
                    'progress_percent' => 0,
                    'last_message' => $batch->last_message,
                ],
                'message' => 'Upload diterima; analisis workbook berjalan di background.',
            ], 202);
        } catch (\Throwable $e) {
            report($e);
            if ($stagingPath) {
                Storage::disk('user_imports')->delete($stagingPath);
            }
            if ($batch) {
                $this->spbImportBatchService->markFailed((int) $batch->id, $e->getMessage());
            }

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function updateImportPreviewRow(Request $request, int $rowIndex)
    {
        $this->authorizeManage();
        $businessId = $this->businessId();
        $validated = $request->validate([
            'token' => ['required', 'string', 'uuid'],
            'ticket_no' => ['present', 'nullable', 'string', 'max:100'],
            'asset_label' => ['present', 'nullable', 'string', 'max:255'],
            'asset_id' => ['nullable', 'integer'],
            'asset_mode' => ['required', 'in:existing,label,new'],
            'driver_label' => ['present', 'nullable', 'string', 'max:255'],
            'driver_id' => ['nullable', 'integer'],
            'driver_mode' => ['required', 'in:existing,label,new'],
            'trip_date' => ['present', 'nullable', 'date'],
            'load_date' => ['present', 'nullable', 'date'],
            'unload_date' => ['present', 'nullable', 'date', 'after_or_equal:load_date'],
            'gross_tons' => ['present', 'nullable', 'numeric', 'min:0'],
            'tare_tons' => ['present', 'nullable', 'numeric', 'min:0'],
            'actual_tons' => ['present', 'nullable', 'numeric', 'min:0'],
            'received_tons' => ['present', 'nullable', 'numeric', 'min:0'],
            'billable_tons' => ['present', 'nullable', 'numeric', 'min:0'],
            'sale_rate_per_ton' => ['present', 'nullable', 'numeric', 'min:0'],
            'origin' => ['present', 'nullable', 'string', 'max:255'],
            'destination' => ['present', 'nullable', 'string', 'max:255'],
            'route_name' => ['present', 'nullable', 'string', 'max:255'],
            'variance_kg' => ['present', 'nullable', 'numeric'],
            'tolerance_kg' => ['present', 'nullable', 'numeric', 'min:0'],
            'claim_rate_per_kg' => ['present', 'nullable', 'numeric', 'min:0'],
            'other_deduction_total' => ['present', 'nullable', 'numeric', 'min:0'],
            'rate_override_reason' => ['present', 'nullable', 'string', 'max:1000'],
            'asset_new_name' => ['required_if:asset_mode,new', 'nullable', 'string', 'max:255'],
            'asset_new_code' => [
                'nullable', 'string', 'max:255',
                Rule::unique('assets', 'asset_code')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'asset_new_location_id' => ['nullable', 'integer', Rule::exists('business_locations', 'id')->where(fn ($query) => $query->where('business_id', $businessId))],
            'asset_new_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('business_id', $businessId)->where('category_type', 'asset'))],
            'asset_new_model' => ['nullable', 'string', 'max:255'],
            'asset_new_serial_no' => ['nullable', 'string', 'max:255'],
            'asset_new_quantity' => ['required_if:asset_mode,new', 'nullable', 'numeric', 'min:1'],
            'asset_new_unit_price' => ['required_if:asset_mode,new', 'nullable', 'numeric', 'min:0'],
            'asset_new_purchase_date' => ['nullable', 'date'],
            'asset_new_purchase_type' => ['nullable', Rule::in(['owned', 'rented', 'leased'])],
            'asset_new_depreciation' => ['nullable', 'numeric', 'min:0'],
            'asset_new_description' => ['nullable', 'string', 'max:5000'],
            'driver_new_name' => ['required_if:driver_mode,new', 'nullable', 'string', 'max:255'],
        ]);

        $token = $validated['token'];
        $cacheKey = $this->importCacheKey($token);
        $payload = Cache::get($cacheKey);
        if (! $payload || (int) ($payload['business_id'] ?? 0) !== $businessId || (int) ($payload['user_id'] ?? 0) !== (int) auth()->id()) {
            abort(422, 'Preview import SPB sudah kedaluwarsa atau tidak valid.');
        }
        if (! array_key_exists($rowIndex, $payload['preview']['rows'] ?? [])) {
            throw ValidationException::withMessages(['row' => 'Baris preview tidak ditemukan. Muat ulang workbook dan coba lagi.']);
        }

        $oldPreview = $payload['preview'];
        $oldRows = $oldPreview['rows'];
        $newPreview = DB::transaction(function () use ($validated, $oldPreview, $oldRows, $rowIndex, $businessId) {
            $changes = $validated;
            unset($changes['token'], $changes['asset_mode'], $changes['driver_mode']);

            if ($validated['asset_mode'] === 'existing' && ! empty($validated['asset_id'])) {
                $asset = Asset::where('business_id', $businessId)->find($validated['asset_id']);
                if (! $asset) {
                    throw ValidationException::withMessages(['asset_id' => 'Unit yang dipilih tidak tersedia pada business ini.']);
                }
                $changes['asset_id'] = $asset->id;
                $changes['asset_label'] = $asset->name;
            } elseif ($validated['asset_mode'] === 'new') {
                $this->authorizeNewAsset($businessId);
                $asset = $this->createImportAsset($validated, $businessId);
                $changes['asset_id'] = $asset->id;
                $changes['asset_label'] = $asset->name;
            } else {
                $changes['asset_id'] = null;
            }

            if ($validated['driver_mode'] === 'existing' && ! empty($validated['driver_id'])) {
                $driver = User::where('business_id', $businessId)->where('user_type', 'user')->find($validated['driver_id']);
                if (! $driver) {
                    throw ValidationException::withMessages(['driver_id' => 'Supir yang dipilih tidak tersedia pada business ini.']);
                }
                $changes['driver_id'] = $driver->id;
                $changes['driver_label'] = trim((string) $driver->user_full_name);
            } elseif ($validated['driver_mode'] === 'new') {
                $this->authorizeNewDriver($businessId);
                $driver = $this->createImportDriver($validated['driver_new_name'], $businessId);
                $changes['driver_id'] = $driver->id;
                $changes['driver_label'] = trim((string) $driver->user_full_name);
            } else {
                $changes['driver_id'] = null;
            }

            return $this->spbImportService->updatePreviewRow(
                $oldRows,
                $rowIndex,
                $changes,
                $businessId,
                (int) ($oldPreview['summary']['sheets'] ?? 0)
            );
        });
        $payload['preview'] = $newPreview;
        if (! empty($payload['batch_id'])) {
            $batch = $this->userImportBatchService->findForBusiness((int) $payload['batch_id'], $businessId, 'spb');
            if ($batch->status !== 'preview_ready') {
                abort(422, 'Preview batch sudah tidak dapat diubah.');
            }
            $this->userImportBatchService->storePayload($batch, 'preview', $newPreview);
            $batch->summary_json = json_encode($newPreview['summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $batch->last_message = 'Koreksi preview tersimpan.';
            $batch->save();
        }
        Cache::put($cacheKey, $payload, now()->addHours(2));

        $updatedRows = [];
        foreach ($newPreview['rows'] as $index => $row) {
            if (($oldRows[$index] ?? null) !== $row) {
                $updatedRows[] = ['index' => $index, 'row' => $row];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Koreksi preview disimpan. '.$this->affectedRowsMessage(count($updatedRows)),
            'updated_rows' => $updatedRows,
            'summary' => $newPreview['summary'],
            'errors' => $newPreview['errors'],
        ]);
    }

    public function importConfirm(Request $request)
    {
        $this->authorizeManage();
        $request->validate(['token' => 'required|string', 'default_route_id' => 'nullable|integer']);
        $payload = Cache::get($this->importCacheKey($request->input('token')));
        if (! $payload || (int) $payload['business_id'] !== $this->businessId() || (int) $payload['user_id'] !== (int) auth()->id()) {
            abort(422, 'Preview import SPB sudah kedaluwarsa atau tidak valid.');
        }

        if (! empty($payload['batch_id'])) {
            $batch = $this->userImportBatchService->findForBusiness((int) $payload['batch_id'], $this->businessId(), 'spb');
            if ($batch->status !== 'preview_ready' || ! $batch->preview_path) {
                abort(422, 'Batch SPB tidak berada pada status preview siap.');
            }
            $defaultRouteId = $request->integer('default_route_id') ?: null;
            if ($defaultRouteId) {
                HeavyEquipmentRoute::where('business_id', $this->businessId())->where('is_active', true)->findOrFail($defaultRouteId);
            }

            $batch->forceFill([
                'status' => 'queued_import',
                'stage' => 'queued',
                'options_json' => json_encode(['default_route_id' => $defaultRouteId], JSON_UNESCAPED_UNICODE),
                'progress_current' => 0,
                'progress_total' => (int) (($payload['preview']['summary']['source_rows'] ?? 0)),
                'progress_percent' => 0,
                'last_message' => 'Konfirmasi diterima. Menunggu worker queue imports.',
                'error_message' => null,
                'failed_at' => null,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ])->save();

            try {
                ProcessHeavyEquipmentSpbImportJob::dispatch((int) $batch->id);
            } catch (\Throwable $exception) {
                report($exception);
                $this->spbImportBatchService->markFailed((int) $batch->id, $exception->getMessage());

                return response()->json(['success' => false, 'message' => 'Import belum dapat dimasukkan ke queue.'], 503);
            }

            Cache::forget($this->importCacheKey($request->input('token')));
            $batch->refresh();
            $response = [
                'success' => true,
                'batch_id' => $batch->id,
                'status' => $batch->status,
                'progress_percent' => (int) $batch->progress_percent,
                'message' => 'Import SPB masuk ke queue. Progres dapat dipantau pada riwayat batch.',
            ];

            return $request->expectsJson() ? response()->json($response, 202) : redirect()->route('asset.fleet.trips.import.form')->with('status', ['success' => true, 'msg' => $response['message']]);
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

            return redirect()->route('asset.fleet.trips.import.form', ['token' => $request->input('token')])
                ->with('status', ['success' => false, 'msg' => $e->getMessage()]);
        }

        Cache::forget($this->importCacheKey($request->input('token')));
        $message = "{$created} SPB berhasil diimpor.";
        if ($errors !== []) {
            $message .= ' Baris yang belum siap: '.implode(' | ', array_slice($errors, 0, 5));
        }

        return redirect()->route('asset.fleet.trips.index')->with('status', ['success' => true, 'msg' => $message]);
    }

    public function importBatchHistory(Request $request)
    {
        $this->authorizeManage();

        return response()->json($this->userImportBatchService->dataTable($request, $this->businessId(), 'spb'));
    }

    public function importBatchStatus(int $id)
    {
        $this->authorizeManage();
        $batch = $this->userImportBatchService->findForBusiness($id, $this->businessId(), 'spb');
        $summary = json_decode((string) $batch->summary_json, true) ?: [];

        return response()->json([
            'id' => $batch->id,
            'file_name' => $batch->file_name,
            'status' => $batch->status,
            'stage' => $batch->stage,
            'progress_current' => (int) $batch->progress_current,
            'progress_total' => (int) $batch->progress_total,
            'progress_percent' => (int) $batch->progress_percent,
            'summary' => $summary,
            'message' => $batch->last_message,
            'error' => $batch->error_message,
            'heartbeat_at' => optional($batch->heartbeat_at)->toIso8601String(),
            'has_snapshot' => $batch->hasSourceSnapshot(),
            'active' => $batch->isActive(),
        ]);
    }

    public function importBatchDetails(int $id)
    {
        $this->authorizeManage();
        $batch = $this->userImportBatchService->findForBusiness($id, $this->businessId(), 'spb');
        $preview = $batch->preview_path ? $this->userImportBatchService->readPayload($batch, 'preview') : ['rows' => []];
        $results = [];
        if ($batch->results_path) {
            try {
                $results = $this->userImportBatchService->readPayload($batch, 'results');
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $rows = [];
        foreach (($preview['rows'] ?? []) as $index => $row) {
            $key = (string) ($row['source_fingerprint'] ?? 'row-'.$index);
            $result = $results[$key] ?? null;
            $rows[] = [
                'sheet' => $row['source_sheet'] ?? '',
                'source_row' => $row['source_row'] ?? null,
                'ticket_no' => $row['ticket_no'] ?? null,
                'asset' => $row['asset_label'] ?? null,
                'driver' => $row['driver_label'] ?? null,
                'status' => $result['status'] ?? (empty($row['row_errors']) ? 'ready' : 'error'),
                'trip_id' => $result['trip_id'] ?? null,
                'trip_no' => $result['trip_no'] ?? null,
                'trip_url' => ! empty($result['trip_id']) ? route('asset.fleet.trips.show', $result['trip_id']) : null,
                'errors' => $result['error'] ?? implode(' ', $row['row_errors'] ?? []),
            ];
        }

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'file_name' => $batch->file_name,
                'status' => $batch->status,
                'summary' => json_decode((string) $batch->summary_json, true) ?: [],
                'message' => $batch->last_message,
                'error' => $batch->error_message,
                'created_at' => optional($batch->created_at)->format('Y-m-d H:i:s'),
            ],
            'rows' => $rows,
        ]);
    }

    public function downloadImportBatch(int $id)
    {
        $this->authorizeManage();
        $batch = $this->userImportBatchService->findForBusiness($id, $this->businessId(), 'spb');
        if (! $batch->hasSourceSnapshot()) {
            abort(404, 'Snapshot workbook belum tersedia.');
        }

        return $this->userImportBatchService->workbookDownload($batch);
    }

    public function retryImportBatch(int $id)
    {
        $this->authorizeManage();
        $batch = $this->userImportBatchService->findForBusiness($id, $this->businessId(), 'spb');
        $summary = json_decode((string) $batch->summary_json, true) ?: [];
        if (! in_array($batch->status, ['failed', 'partial'], true) || ! $batch->preview_path || (int) ($summary['source_rows'] ?? 0) < 1) {
            return response()->json(['success' => false, 'message' => 'Batch ini tidak memiliki baris import yang dapat dicoba ulang.'], 422);
        }

        $batch->forceFill([
            'status' => 'queued_import',
            'stage' => 'queued',
            'progress_current' => 0,
            'progress_percent' => 0,
            'error_message' => null,
            'failed_at' => null,
            'last_message' => 'Percobaan ulang diterima. Menunggu worker queue imports.',
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ])->save();

        try {
            ProcessHeavyEquipmentSpbImportJob::dispatch((int) $batch->id);
        } catch (\Throwable $exception) {
            report($exception);
            $this->spbImportBatchService->markFailed((int) $batch->id, $exception->getMessage());

            return response()->json(['success' => false, 'message' => 'Percobaan ulang belum dapat dimasukkan ke queue.'], 503);
        }

        return response()->json(['success' => true, 'message' => 'Percobaan ulang dimasukkan ke queue.']);
    }

    public function revertImportBatch(int $id)
    {
        $this->authorizeManage();
        $batch = $this->userImportBatchService->findForBusiness($id, $this->businessId(), 'spb');

        try {
            $result = $this->spbImportBatchService->revert($batch, $this->businessId());

            return response()->json([
                'success' => true,
                'message' => $result['reverted_trips'].' SPB berhasil di-revert.',
                'trip_numbers' => $result['trip_numbers'],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
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

    private function importViewData(int $businessId): array
    {
        $assets = Asset::where('business_id', $businessId)
            ->orderBy('name')
            ->get(['id', 'name', 'asset_code'])
            ->map(fn (Asset $asset) => [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'code' => (string) ($asset->asset_code ?? ''),
                'label' => trim($asset->name.' '.($asset->asset_code ? '('.$asset->asset_code.')' : '')),
            ])
            ->values();
        $drivers = User::where('business_id', $businessId)
            ->where('user_type', 'user')
            ->orderBy('first_name')
            ->get(['id', 'surname', 'first_name', 'last_name', 'username'])
            ->map(fn (User $driver) => [
                'id' => (int) $driver->id,
                'name' => trim((string) $driver->user_full_name),
                'username' => (string) ($driver->username ?? ''),
                'label' => trim((string) $driver->user_full_name).($driver->username ? ' ('.$driver->username.')' : ''),
            ])
            ->filter(fn (array $driver) => $driver['name'] !== '')
            ->values();
        $duplicateDriverNames = $drivers->countBy(fn (array $driver) => $this->spbImportService->canonicalDriverName($driver['name']));
        $drivers = $drivers->map(function (array $driver) use ($duplicateDriverNames) {
            $canonicalName = $this->spbImportService->canonicalDriverName($driver['name']);
            if ($canonicalName !== '' && $duplicateDriverNames->get($canonicalName, 0) > 1 && $driver['username'] === '') {
                $driver['label'] .= ' · User #'.$driver['id'];
            }

            return $driver;
        });

        $isSuperadmin = auth()->user()->can('superadmin');
        $canCreateAsset = ($isSuperadmin || auth()->user()->can('asset.create'))
            && $this->moduleUtil->hasThePermissionInSubscription($businessId, 'assetmanagement_module');
        $canCreateDriver = ($isSuperadmin || auth()->user()->can('user.create'))
            && $this->moduleUtil->isSubscribed($businessId)
            && $this->moduleUtil->isQuotaAvailable('users', $businessId);

        return [
            'assets' => $assets,
            'drivers' => $drivers,
            'assetCategories' => Category::forDropdown($businessId, 'asset'),
            'businessLocations' => BusinessLocation::forDropdown($businessId),
            'canCreateAsset' => $canCreateAsset,
            'canCreateDriver' => $canCreateDriver,
        ];
    }

    private function authorizeNewAsset(int $businessId): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.create')) {
            abort(403, 'Anda tidak memiliki izin untuk menambahkan Unit ke data Assets.');
        }
        if (! $this->moduleUtil->hasThePermissionInSubscription($businessId, 'assetmanagement_module')) {
            throw ValidationException::withMessages(['asset_mode' => 'Modul Asset Management tidak aktif untuk business ini.']);
        }
    }

    private function createImportAsset(array $input, int $businessId): Asset
    {
        $name = trim((string) $input['asset_new_name']);
        $normalizedName = strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
        $existing = Asset::where('business_id', $businessId)->get(['id', 'name', 'asset_code'])
            ->filter(fn (Asset $asset) => strtolower(trim((string) preg_replace('/\s+/', ' ', $asset->name))) === $normalizedName);
        if ($existing->count() > 1) {
            throw ValidationException::withMessages(['asset_mode' => 'Ada beberapa unit dengan nama tersebut. Pilih salah satu dari daftar Unit yang tersedia.']);
        }
        if ($existing->isNotEmpty()) {
            return $existing->first();
        }

        $assetCode = trim((string) ($input['asset_new_code'] ?? ''));
        if ($assetCode === '') {
            $referenceCount = $this->commonUtil->setAndGetReferenceCount('asset_code', $businessId);
            $assetSettings = $this->assetUtil->getAssetSettings($businessId);
            $assetCode = $this->commonUtil->generateReferenceNumber('asset_code', $referenceCount, null, $assetSettings['asset_code_prefix'] ?? null);
        }

        return Asset::create([
            'business_id' => $businessId,
            'asset_code' => $assetCode,
            'name' => $name,
            'quantity' => (float) $input['asset_new_quantity'],
            'model' => $input['asset_new_model'] ?? null,
            'serial_no' => $input['asset_new_serial_no'] ?? null,
            'unit_price' => (float) $input['asset_new_unit_price'],
            'category_id' => ! empty($input['asset_new_category_id']) ? (int) $input['asset_new_category_id'] : null,
            'location_id' => ! empty($input['asset_new_location_id']) ? (int) $input['asset_new_location_id'] : null,
            'purchase_date' => $input['asset_new_purchase_date'] ?? null,
            'purchase_type' => $input['asset_new_purchase_type'] ?? 'owned',
            'depreciation' => $input['asset_new_depreciation'] ?? null,
            'description' => $input['asset_new_description'] ?? null,
            'is_allocatable' => false,
            'created_by' => (int) auth()->id(),
        ]);
    }

    private function authorizeNewDriver(int $businessId): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('user.create')) {
            abort(403, 'Anda tidak memiliki izin untuk menambahkan User sopir.');
        }
        if (! $this->moduleUtil->isSubscribed($businessId) || ! $this->moduleUtil->isQuotaAvailable('users', $businessId)) {
            throw ValidationException::withMessages(['driver_mode' => 'Kuota User business tidak tersedia untuk menambahkan sopir.']);
        }
    }

    private function createImportDriver(string $name, int $businessId): User
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        $normalizedName = strtolower($name);
        $existing = User::where('business_id', $businessId)
            ->where('user_type', 'user')
            ->get(['id', 'surname', 'first_name', 'last_name', 'username'])
            ->filter(fn (User $driver) => strtolower(trim((string) $driver->user_full_name)) === $normalizedName);
        if ($existing->count() > 1) {
            throw ValidationException::withMessages(['driver_mode' => 'Ada beberapa User dengan nama tersebut. Pilih salah satu dari daftar Supir yang tersedia.']);
        }
        if ($existing->isNotEmpty()) {
            return $existing->first();
        }

        return User::create([
            'business_id' => $businessId,
            'user_type' => 'user',
            'surname' => '',
            'first_name' => $name,
            'last_name' => null,
            'email' => null,
            'username' => null,
            'password' => null,
            'allow_login' => false,
            'status' => 'active',
            'is_cmmsn_agnt' => false,
            'selected_contacts' => false,
        ]);
    }

    private function affectedRowsMessage(int $count): string
    {
        return $count === 1 ? '1 baris diperbarui.' : $count.' baris diperbarui.';
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
