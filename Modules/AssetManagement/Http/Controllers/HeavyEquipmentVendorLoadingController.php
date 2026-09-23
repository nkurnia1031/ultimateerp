<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\Contact;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingService;
use Modules\Project\Entities\Project;

class HeavyEquipmentVendorLoadingController extends Controller
{
    public function __construct(
        private HeavyEquipmentVendorLoadingService $loadingService,
        private HeavyEquipmentVendorLoadingImportService $importService
    ) {
    }

    public function index()
    {
        $this->authorizeView();

        return view('assetmanagement::fleet.vendor_loadings.index', $this->formData());
    }

    public function data(Request $request)
    {
        $this->authorizeView();
        $businessId = $this->businessId();
        $base = HeavyEquipmentVendorLoading::query()
            ->where('business_id', $businessId)
            ->with(['supplier', 'route', 'project', 'customer'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('supplier_search'), function ($q) use ($request) {
                $like = '%'.trim((string) $request->input('supplier_search')).'%';
                $q->where(function ($supplierQuery) use ($like) {
                    $supplierQuery->where('supplier_name_snapshot', 'like', $like)
                        ->orWhereHas('supplier', function ($contactQuery) use ($like) {
                            $contactQuery->where('name', 'like', $like)
                                ->orWhere('supplier_business_name', 'like', $like)
                                ->orWhere('contact_id', 'like', $like);
                        });
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('expense_status'), fn ($q) => $q->where('expense_status', $request->input('expense_status')))
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('loading_date', '>=', $request->input('start_date')))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('loading_date', '<=', $request->input('end_date')));

        $recordsTotal = (clone $base)->count();
        $search = trim((string) data_get($request->input('search'), 'value', ''));
        if ($search !== '') {
            $base->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('loading_no', 'like', $like)
                    ->orWhere('ticket_no', 'like', $like)
                    ->orWhere('supplier_name_snapshot', 'like', $like)
                    ->orWhere('vendor_unit_name', 'like', $like)
                    ->orWhere('vendor_driver_name', 'like', $like)
                    ->orWhere('route_name_snapshot', 'like', $like);
            });
        }
        $recordsFiltered = (clone $base)->count();
        $length = (int) $request->input('length', 25);
        $start = max(0, (int) $request->input('start', 0));
        $orderColumn = (int) data_get($request->input('order'), '0.column', 1);
        $orderDirection = strtolower((string) data_get($request->input('order'), '0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderable = [1 => 'loading_date', 2 => 'loading_no', 3 => 'supplier_name_snapshot', 4 => 'vendor_unit_name', 5 => 'vendor_driver_name', 6 => 'status'];
        $base->orderBy($orderable[$orderColumn] ?? 'loading_date', $orderDirection)->orderByDesc('id');
        if ($length !== -1) {
            $base->skip($start)->take(min($length, 100));
        }

        $data = $base->get()->map(function (HeavyEquipmentVendorLoading $loading) {
            $editable = ! in_array($loading->status, ['verified', 'cancelled'], true) && ! $loading->expense_transaction_id;
            $actions = '<a class="btn btn-xs btn-info" href="'.route('asset.fleet.vendor-loadings.show', $loading->id).'">Detail</a> ';
            if ($editable) {
                $actions .= '<a class="btn btn-xs btn-warning" href="'.route('asset.fleet.vendor-loadings.edit', $loading->id).'">Edit</a> ';
                $actions .= '<button type="button" class="btn btn-xs btn-danger vendor-loading-delete" data-id="'.$loading->id.'" data-url="'.route('asset.fleet.vendor-loadings.destroy', $loading->id).'">Hapus</button>';
            } elseif ($loading->status === 'verified') {
                $actions .= '<span class="label label-default">Terkunci</span>';
            }

            $nextStatus = match ($loading->status) {
                'draft' => 'loading',
                'loading' => 'in_transit',
                'in_transit' => 'completed',
                'completed' => 'verified',
                default => null,
            };
            if ($nextStatus) {
                $actions .= ' <button type="button" class="btn btn-xs btn-success vendor-loading-status" data-id="'.$loading->id.'" data-status="'.$nextStatus.'" data-url="'.route('asset.fleet.vendor-loadings.status', $loading->id).'">'.($nextStatus === 'verified' ? 'Verifikasi' : 'Lanjut').'</button>';
            }

            return [
                'loading_no' => e($loading->loading_no).'<br><small>'.e($loading->ticket_no ?: '-').'</small>',
                'loading_date' => optional($loading->loading_date)->format('d/m/Y') ?: '-',
                'supplier' => e($loading->supplier_label),
                'unit' => e($loading->vendor_unit_name ?: '-'),
                'driver' => e($loading->vendor_driver_name ?: '-'),
                'route' => e($loading->route_label),
                'project' => e(optional($loading->project)->name ?: '-'),
                'tons' => number_format((float) ($loading->billable_tons ?: $loading->net_tons), 4, ',', '.'),
                'amount' => $loading->vendor_total_amount === null ? '-' : number_format((float) $loading->vendor_total_amount, 0, ',', '.'),
                'status' => e($this->statusLabel($loading->status)).'<br><small>'.e($loading->expense_status).'</small>',
                'actions' => $actions,
            ];
        })->values();

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function create()
    {
        $this->authorizeManage();

        return view('assetmanagement::fleet.vendor_loadings.form', $this->formData());
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $this->validateInput($request);

        try {
            $loading = $this->loadingService->create($request->all(), $this->businessId(), auth()->id());

            return $this->respond($request, true, 'Loading Vendor berhasil dibuat.', [
                'id' => $loading->id,
                'redirect' => route('asset.fleet.vendor-loadings.show', $loading->id),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function show($id)
    {
        $this->authorizeView();
        $loading = $this->loading($id)->load(['supplier', 'route', 'project', 'customer', 'expenseTransaction']);

        return view('assetmanagement::fleet.vendor_loadings.show', compact('loading'));
    }

    public function edit($id)
    {
        $this->authorizeManage();
        $loading = $this->loading($id)->load(['supplier', 'route', 'project', 'customer']);
        if (in_array($loading->status, ['verified', 'cancelled'], true) || $loading->expense_transaction_id) {
            abort(422, 'Loading Vendor yang sudah terverifikasi, dibatalkan, atau dikaitkan Expense tidak dapat diedit.');
        }

        return view('assetmanagement::fleet.vendor_loadings.form', array_merge($this->formData(), compact('loading')));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeManage();
        $this->validateInput($request);

        try {
            $loading = $this->loadingService->update($this->loading($id), $request->all(), $this->businessId());

            return $this->respond($request, true, 'Loading Vendor berhasil diperbarui.', [
                'id' => $loading->id,
                'redirect' => route('asset.fleet.vendor-loadings.show', $loading->id),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeManage();

        try {
            $this->loadingService->delete($this->loading($id), $this->businessId());
            return $this->respond($request, true, 'Loading Vendor berhasil dihapus.');
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function status(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:loading,in_transit,completed,verified,cancelled']);
        if ($request->input('status') === 'verified') {
            $this->authorizeVerify();
        } else {
            $this->authorizeManage();
        }

        try {
            $this->loadingService->transition($this->loading($id), $request->input('status'), $this->businessId(), auth()->id());
            return $this->respond($request, true, 'Status Loading Vendor berhasil diperbarui.');
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function importForm()
    {
        $this->authorizeImport();

        return view('assetmanagement::fleet.vendor_loadings.import', $this->formData());
    }

    public function importPreview(Request $request)
    {
        $this->authorizeImport();
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:51200']);

        try {
            $preview = $this->importService->preview($request->file('file'), $this->businessId());
            $token = (string) Str::uuid();
            Cache::put($this->importCacheKey($token), [
                'business_id' => $this->businessId(),
                'user_id' => auth()->id(),
                'preview' => $preview,
            ], now()->addMinutes(30));

            return response()->json(['success' => true, 'msg' => 'Preview Loading Vendor berhasil dibuat.', 'token' => $token, 'preview' => $preview]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    public function importConfirm(Request $request)
    {
        $this->authorizeImport();
        $request->validate(['token' => 'required|string', 'supplier_selections' => 'nullable|string', 'new_supplier_rows' => 'nullable|string']);
        $payload = Cache::get($this->importCacheKey($request->input('token')));
        if (! $payload || (int) $payload['business_id'] !== $this->businessId() || (int) $payload['user_id'] !== (int) auth()->id()) {
            return response()->json(['success' => false, 'msg' => 'Preview import sudah kedaluwarsa atau tidak valid.'], 422);
        }

        $selections = $this->decodeMap($request->input('supplier_selections'));
        $newSupplierRows = $this->decodeMap($request->input('new_supplier_rows'));
        if ($newSupplierRows !== [] && ! $this->canCreateMinimalSupplier()) {
            return response()->json(['success' => false, 'msg' => 'Anda tidak memiliki izin untuk membuat supplier baru dari import.'], 403);
        }

        $created = 0;
        $errors = [];
        foreach (($payload['preview']['rows'] ?? []) as $row) {
            try {
                DB::transaction(function () use (&$created, $row, $selections, $newSupplierRows) {
                    $prepared = $this->importService->prepareRow($row, $this->businessId(), $selections, $newSupplierRows);
                    $this->loadingService->create($prepared, $this->businessId(), auth()->id());
                    $created++;
                });
            } catch (\Throwable $e) {
                report($e);
                $errors[] = "Sheet {$row['source_sheet']} baris {$row['source_row']}: {$e->getMessage()}";
            }
        }

        if ($created === 0) {
            return response()->json(['success' => false, 'msg' => 'Tidak ada baris yang berhasil diimpor.', 'errors' => $errors], 422);
        }
        Cache::forget($this->importCacheKey($request->input('token')));

        return response()->json([
            'success' => true,
            'msg' => "{$created} Loading Vendor berhasil diimpor.".($errors !== [] ? ' Sebagian baris perlu diperiksa kembali.' : ''),
            'created' => $created,
            'errors' => $errors,
            'redirect' => route('asset.fleet.vendor-loadings.index'),
        ]);
    }

    public function supplierOptions(Request $request)
    {
        $this->authorizeView();

        return response()->json(['results' => $this->loadingService->supplierMatches($this->businessId(), (string) $request->input('term', ''))]);
    }

    public function createSupplier(Request $request)
    {
        $this->authorizeView();
        if (! $this->canCreateMinimalSupplier()) {
            abort(403, 'Anda tidak memiliki izin membuat supplier.');
        }
        $request->validate(['name' => 'required|string|max:191']);

        try {
            $supplier = $this->loadingService->createMinimalSupplier($request->input('name'), $this->businessId(), auth()->id());
            return response()->json(['success' => true, 'msg' => 'Supplier minimal berhasil dibuat.', 'supplier' => ['id' => $supplier->id, 'text' => Contact::supplierDisplayName($supplier, true)]]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    private function formData(): array
    {
        $businessId = $this->businessId();

        return [
            'routes' => HeavyEquipmentRoute::where('business_id', $businessId)->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'projects' => Project::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id'),
            'customers' => Contact::customersDropdown($businessId, false),
            'vendorUnits' => HeavyEquipmentVendorLoading::where('business_id', $businessId)->whereNotNull('vendor_unit_name')->where('vendor_unit_name', '<>', '')->distinct()->orderBy('vendor_unit_name')->pluck('vendor_unit_name'),
            'vendorDrivers' => HeavyEquipmentVendorLoading::where('business_id', $businessId)->whereNotNull('vendor_driver_name')->where('vendor_driver_name', '<>', '')->distinct()->orderBy('vendor_driver_name')->pluck('vendor_driver_name'),
        ];
    }

    private function validateInput(Request $request): void
    {
        $request->validate([
            'loading_no' => 'nullable|string|max:100',
            'ticket_no' => 'nullable|string|max:100',
            'loading_date' => 'required|date',
            'load_date' => 'nullable|date',
            'unload_date' => 'nullable|date|after_or_equal:load_date',
            'supplier_id' => 'nullable|integer',
            'vendor_driver_name' => 'nullable|string|max:191',
            'vendor_unit_name' => 'nullable|string|max:191',
            'vendor_driver_code' => 'nullable|string|max:100',
            'vendor_unit_code' => 'nullable|string|max:100',
            'route_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'gross_tons' => 'nullable|numeric|min:0',
            'tare_tons' => 'nullable|numeric|min:0',
            'net_tons' => 'nullable|numeric|min:0',
            'received_tons' => 'nullable|numeric|min:0',
            'billable_tons' => 'nullable|numeric|min:0',
            'vendor_rate_per_ton' => 'nullable|numeric|min:0',
            'claim_adjustment' => 'nullable|numeric',
            'other_deduction' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    private function loading($id): HeavyEquipmentVendorLoading
    {
        return HeavyEquipmentVendorLoading::where('business_id', $this->businessId())->findOrFail($id);
    }

    private function decodeMap(?string $value): array
    {
        if (! $value) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function statusLabel(string $status): string
    {
        return [
            'draft' => 'Draft',
            'loading' => 'Loading',
            'in_transit' => 'Dalam Perjalanan',
            'completed' => 'Selesai',
            'verified' => 'Terverifikasi',
            'cancelled' => 'Dibatalkan',
        ][$status] ?? $status;
    }

    private function respond(Request $request, bool $success, string $message, array $extra = [], int $status = 200)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(array_merge(['success' => $success, 'msg' => $message], $extra), $status);
        }
        return $success ? redirect()->route('asset.fleet.vendor-loadings.index')->with('status', ['success' => true, 'msg' => $message]) : back()->withInput()->with('status', ['success' => false, 'msg' => $message]);
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function importCacheKey(string $token): string
    {
        return 'heavy_equipment_vendor_loading_import:'.(int) auth()->id().':'.$token;
    }

    private function canCreateMinimalSupplier(): bool
    {
        return auth()->user()->can('superadmin') || auth()->user()->can('supplier.create');
    }

    private function authorizeView(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_vendor_loadings.view') && ! auth()->user()->can('asset.fleet_vendor_loadings.manage') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.fleet_trips.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_vendor_loadings.manage') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.fleet_trips.create') && ! auth()->user()->can('asset.create') && ! auth()->user()->can('asset.update')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeImport(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_vendor_loadings.import') && ! auth()->user()->can('asset.fleet_vendor_loadings.manage') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.fleet_trips.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeVerify(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_vendor_loadings.verify') && ! auth()->user()->can('asset.fleet_vendor_loadings.manage') && ! auth()->user()->can('asset.fleet_trips.manage')) {
            abort(403, 'Anda tidak memiliki izin untuk memverifikasi Loading Vendor.');
        }
    }
}
