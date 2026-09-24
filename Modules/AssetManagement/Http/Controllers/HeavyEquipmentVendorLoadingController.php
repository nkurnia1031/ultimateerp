<?php

namespace Modules\AssetManagement\Http\Controllers;

use App\Contact;
use App\Business;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoading;
use Modules\AssetManagement\Entities\HeavyEquipmentVendorLoadingDocument;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingImportReverseService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingDocumentService;
use Modules\AssetManagement\Services\HeavyEquipmentVendorLoadingService;
use Modules\Crm\Entities\SuratKontrakTemplate;
use Modules\Crm\Services\SuratKontrakTemplateService;
use Modules\Project\Entities\Project;

class HeavyEquipmentVendorLoadingController extends Controller
{
    public function __construct(
        private HeavyEquipmentVendorLoadingService $loadingService,
        private HeavyEquipmentVendorLoadingImportService $importService,
        private HeavyEquipmentVendorLoadingDocumentService $documentService,
        private HeavyEquipmentVendorLoadingImportReverseService $importReverseService
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
        $this->validateReportFilters($request, false);
        $businessId = $this->businessId();
        $canManage = $this->canManage();
        $query = $this->filteredDocumentQuery($request, $businessId);
        $search = trim((string) data_get($request->input('search'), 'value', ''));
        $query = $this->applyDocumentGlobalSearch($query, $search);
        $recordsTotal = HeavyEquipmentVendorLoadingDocument::where('business_id', $businessId)->count();
        $recordsFiltered = (clone $query)->count();
        $summary = $this->summariseDocuments(clone $query);

        $lineTotals = $this->documentLineTotalsQuery($request);
        $dataQuery = (clone $query)->leftJoinSub($lineTotals, 'line_totals', function ($join) {
            $join->on('heavy_equipment_vendor_loading_documents.id', '=', 'line_totals.loading_document_id');
        })->select('heavy_equipment_vendor_loading_documents.*')
            ->selectRaw('COALESCE(line_totals.line_count, 0) as line_count, COALESCE(line_totals.gross_total, 0) as gross_total, COALESCE(line_totals.tare_total, 0) as tare_total, COALESCE(line_totals.net_total, 0) as net_total, COALESCE(line_totals.billable_total, 0) as billable_total, COALESCE(line_totals.amount_total, 0) as amount_total')
            ->with(['supplier', 'customer', 'route']);

        $orderColumn = (int) data_get($request->input('order'), '0.column', 0);
        $orderDirection = strtolower((string) data_get($request->input('order'), '0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderable = [0 => 'document_no', 1 => 'source_sheet', 2 => 'supplier_name_snapshot', 3 => 'customer_name_snapshot'];
        $dataQuery->orderBy($orderable[$orderColumn] ?? 'created_at', $orderDirection)->orderByDesc('id');
        $length = (int) $request->input('length', 25);
        if ($length !== -1) {
            $dataQuery->skip(max(0, (int) $request->input('start', 0)))->take(min($length, 100));
        }

        $data = $dataQuery->get()->map(function (HeavyEquipmentVendorLoadingDocument $document) use ($canManage) {
            $actions = '<a class="btn btn-xs btn-info" href="'.route('asset.fleet.vendor-loadings.documents.show', $document->id).'">Buka Line</a> ';
            if ($canManage) {
                $actions .= '<button type="button" class="btn btn-xs btn-danger vendor-loading-delete" data-id="'.$document->id.'" data-loading-no="'.e($document->document_no).'" data-url="'.route('asset.fleet.vendor-loadings.documents.destroy', $document->id).'">Hapus</button>';
            }

            $manifest = [];
            foreach (['Barang' => $document->goods_name, 'Jenis' => $document->goods_type, 'Kapal' => $document->vessel_name, 'Tujuan' => $document->destination, 'Rute' => $document->route_label] as $label => $value) {
                if (trim((string) $value) !== '' && trim((string) $value) !== '-') {
                    $manifest[] = '<strong>'.$label.':</strong> '.e($value);
                }
            }

            return [
                'document_no' => e($document->document_no),
                'source_sheet' => e($document->source_sheet ?: 'Manual'),
                'supplier' => e($document->supplier_label),
                'customer' => e($document->customer_label),
                'manifest' => $manifest ? implode('<br>', $manifest) : '-',
                'line_count' => number_format((int) $document->line_count, 0, ',', '.'),
                'tons' => 'G '.number_format((float) $document->gross_total, 4, ',', '.').' / T '.number_format((float) $document->tare_total, 4, ',', '.').' / N '.number_format((float) $document->net_total, 4, ',', '.'),
                'amount' => 'Rp '.number_format((float) $document->amount_total, 0, ',', '.'),
                'actions' => $actions,
            ];
        })->values();

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
            'summary' => $summary,
        ]);
    }

    public function print(Request $request, SuratKontrakTemplateService $contractTemplateService)
    {
        $this->authorizeView();
        $this->validateReportFilters($request, true);
        $businessId = $this->businessId();
        $search = trim((string) $request->input('search', ''));
        $lineFilter = function ($lineQuery) use ($request) {
            $lineQuery->when($request->filled('start_date'), fn ($q) => $q->whereDate('loading_date', '>=', $request->input('start_date')))
                ->when($request->filled('end_date'), fn ($q) => $q->whereDate('loading_date', '<=', $request->input('end_date')));
        };
        $documents = $this->applyDocumentGlobalSearch($this->filteredDocumentQuery($request, $businessId), $search)
            ->with(['supplier', 'customer', 'route', 'lines' => $lineFilter])
            ->orderBy('supplier_name_snapshot')->orderBy('source_sheet')->orderBy('document_no')->get()
            ->filter(fn ($document) => $document->lines->isNotEmpty())->values();

        if ($search !== '') {
            $documents->each(function ($document) use ($search) {
                $matchingLines = $document->lines->filter(function ($line) use ($search) {
                    foreach ([$line->loading_no, $line->ticket_no, $line->vendor_unit_name, $line->vendor_driver_name] as $value) {
                        if (stripos((string) $value, $search) !== false) {
                            return true;
                        }
                    }
                    return false;
                });
                if ($matchingLines->isNotEmpty()) {
                    $document->setRelation('lines', $matchingLines->values());
                }
            });
        }

        $business = Business::with('locations')->findOrFail($businessId);
        $template = $contractTemplateService->getActiveForBusiness($businessId);
        if (! $template) {
            $template = new SuratKontrakTemplate([
                'components' => $contractTemplateService->defaultComponents(),
                'body_html' => $contractTemplateService->defaultBodyHtml(),
                'letterhead_mode' => 'components',
            ]);
        }
        $renderedComponents = $contractTemplateService->prepareComponents($template, $business);
        $summary = $this->summariseDocumentsForPrint($documents);

        return view('assetmanagement::fleet.vendor_loadings.print', compact(
            'documents', 'summary', 'request', 'business', 'template', 'renderedComponents'
        ));
    }

    public function printDocument($id)
    {
        $this->authorizeView();
        $document = $this->document($id);

        return redirect()->route('asset.fleet.vendor-loadings.print', ['document_id' => $document->id]);
    }

    private function documentLineTotalsQuery(?Request $request = null)
    {
        return DB::table('heavy_equipment_vendor_loadings')
            ->select('loading_document_id')
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(gross_tons), 0) as gross_total, COALESCE(SUM(tare_tons), 0) as tare_total, COALESCE(SUM(net_tons), 0) as net_total, COALESCE(SUM(CASE WHEN billable_tons IS NULL OR billable_tons = 0 THEN net_tons ELSE billable_tons END), 0) as billable_total, COALESCE(SUM(vendor_total_amount), 0) as amount_total')
            ->whereNotNull('loading_document_id')
            ->when($request && $request->filled('start_date'), fn ($q) => $q->whereDate('loading_date', '>=', $request->input('start_date')))
            ->when($request && $request->filled('end_date'), fn ($q) => $q->whereDate('loading_date', '<=', $request->input('end_date')))
            ->groupBy('loading_document_id');
    }

    private function filteredDocumentQuery(Request $request, int $businessId)
    {
        return HeavyEquipmentVendorLoadingDocument::query()->where('business_id', $businessId)
            ->when($request->filled('document_id'), fn ($q) => $q->where('id', $request->integer('document_id')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('route_id'), fn ($q) => $q->where('route_id', $request->integer('route_id')))
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
            ->when($request->filled('start_date') || $request->filled('end_date'), function ($q) use ($request) {
                $q->whereHas('lines', function ($lineQuery) use ($request) {
                    $lineQuery->when($request->filled('start_date'), fn ($line) => $line->whereDate('loading_date', '>=', $request->input('start_date')))
                        ->when($request->filled('end_date'), fn ($line) => $line->whereDate('loading_date', '<=', $request->input('end_date')));
                });
            });
    }

    private function applyDocumentGlobalSearch($query, string $search)
    {
        if ($search === '') {
            return $query;
        }

        $like = '%'.$search.'%';
        return $query->where(function ($q) use ($like) {
            $q->where('document_no', 'like', $like)
                ->orWhere('source_sheet', 'like', $like)
                ->orWhere('supplier_name_snapshot', 'like', $like)
                ->orWhere('customer_name_snapshot', 'like', $like)
                ->orWhere('goods_name', 'like', $like)
                ->orWhere('goods_type', 'like', $like)
                ->orWhere('vessel_name', 'like', $like)
                ->orWhere('destination', 'like', $like)
                ->orWhere('route_name_snapshot', 'like', $like)
                ->orWhereHas('route', fn ($routeQuery) => $routeQuery->where('name', 'like', $like))
                ->orWhereHas('lines', function ($lineQuery) use ($like) {
                    $lineQuery->where('loading_no', 'like', $like)
                        ->orWhere('ticket_no', 'like', $like)
                        ->orWhere('vendor_unit_name', 'like', $like)
                        ->orWhere('vendor_driver_name', 'like', $like);
                });
        });
    }

    private function summariseDocuments($query): array
    {
        $groups = $query->leftJoinSub($this->documentLineTotalsQuery(request()), 'line_totals', function ($join) {
            $join->on('heavy_equipment_vendor_loading_documents.id', '=', 'line_totals.loading_document_id');
        })->selectRaw("COALESCE(NULLIF(heavy_equipment_vendor_loading_documents.supplier_name_snapshot, ''), 'Tanpa Bendera') as supplier_name, COUNT(DISTINCT heavy_equipment_vendor_loading_documents.id) as document_count, COALESCE(SUM(line_totals.line_count), 0) as record_count, COALESCE(SUM(line_totals.gross_total), 0) as gross_tons, COALESCE(SUM(line_totals.tare_total), 0) as tare_tons, COALESCE(SUM(line_totals.net_total), 0) as net_tons, COALESCE(SUM(line_totals.billable_total), 0) as billable_tons, COALESCE(SUM(line_totals.amount_total), 0) as vendor_total_amount")
            ->groupBy('heavy_equipment_vendor_loading_documents.supplier_name_snapshot')
            ->orderBy('heavy_equipment_vendor_loading_documents.supplier_name_snapshot')
            ->get()->map(fn ($row) => [
                'supplier_name' => $row->supplier_name,
                'document_count' => (int) $row->document_count,
                'record_count' => (int) $row->record_count,
                'gross_tons' => (float) $row->gross_tons,
                'tare_tons' => (float) $row->tare_tons,
                'net_tons' => (float) $row->net_tons,
                'billable_tons' => (float) $row->billable_tons,
                'vendor_total_amount' => (float) $row->vendor_total_amount,
            ])->values();

        return ['groups' => $groups, 'totals' => $this->sumGroups($groups)];
    }

    private function summariseDocumentsForPrint($documents): array
    {
        $groups = $documents->map(fn ($document) => [
            'supplier_name' => trim((string) $document->supplier_label) ?: 'Tanpa Bendera',
            'record_count' => $document->lines->count(),
            'gross_tons' => (float) $document->lines->sum(fn ($line) => (float) $line->gross_tons),
            'tare_tons' => (float) $document->lines->sum(fn ($line) => (float) $line->tare_tons),
            'net_tons' => (float) $document->lines->sum(fn ($line) => (float) $line->net_tons),
            'billable_tons' => (float) $document->lines->sum(fn ($line) => (float) ($line->billable_tons ?: $line->net_tons)),
            'vendor_total_amount' => (float) $document->lines->sum(fn ($line) => (float) $line->vendor_total_amount),
        ]);

        return ['groups' => $groups, 'totals' => $this->sumGroups($groups)];
    }

    private function sumGroups($groups): array
    {
        return $groups->reduce(function ($totals, $group) {
            foreach (['document_count', 'record_count', 'gross_tons', 'tare_tons', 'net_tons', 'billable_tons', 'vendor_total_amount'] as $field) {
                $totals[$field] += (float) ($group[$field] ?? 0);
            }

            return $totals;
        }, ['document_count' => 0, 'record_count' => 0, 'gross_tons' => 0.0, 'tare_tons' => 0.0, 'net_tons' => 0.0, 'billable_tons' => 0.0, 'vendor_total_amount' => 0.0]);
    }

    private function validateReportFilters(Request $request, bool $includeSearch): void
    {
        $rules = [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'supplier_search' => 'nullable|string|max:191',
            'supplier_id' => 'nullable|integer',
            'route_id' => 'nullable|integer',
            'document_id' => 'nullable|integer',
        ];
        if ($includeSearch) {
            $rules['search'] = 'nullable|string|max:191';
        } else {
            $rules['search.value'] = 'nullable|string|max:191';
        }

        $request->validate($rules);
    }

    public function create()
    {
        $this->authorizeManage();

        return view('assetmanagement::fleet.vendor_loadings.document_create', array_merge($this->formData(), [
            'canCreateSupplier' => $this->canCreateMinimalSupplier(),
            'canCreateCustomer' => $this->canCreateMinimalCustomer(),
        ]));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();
        $request->validate([
            'goods_name' => 'required|string|max:191',
            'customer_id' => 'required|integer',
            'supplier_id' => 'required|integer',
            'vessel_name' => 'required|string|max:191',
            'destination' => 'required|string|max:191',
            'goods_type' => 'nullable|string|max:191',
            'route_id' => 'nullable|integer',
            'lines' => 'required|array|min:1',
            'lines.*.loading_date' => 'required|date',
            'lines.*.load_date' => 'nullable|date',
            'lines.*.unload_date' => 'nullable|date',
            'lines.*.ticket_no' => 'nullable|string|max:100',
            'lines.*.vendor_unit_name' => 'nullable|string|max:191',
            'lines.*.vendor_driver_name' => 'nullable|string|max:191',
            'lines.*.vendor_unit_code' => 'nullable|string|max:100',
            'lines.*.vendor_driver_code' => 'nullable|string|max:100',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.gross_tons' => 'nullable|numeric|min:0',
            'lines.*.tare_tons' => 'nullable|numeric|min:0',
            'lines.*.net_tons' => 'nullable|numeric|min:0',
            'lines.*.received_tons' => 'nullable|numeric|min:0',
            'lines.*.billable_tons' => 'nullable|numeric|min:0',
            'lines.*.vendor_rate_per_ton' => 'nullable|numeric|min:0',
            'lines.*.claim_adjustment' => 'nullable|numeric',
            'lines.*.other_deduction' => 'nullable|numeric|min:0',
            'lines.*.notes' => 'nullable|string|max:2000',
        ]);
        try {
            $loading = $this->documentService->createManual($request->all(), $this->businessId(), auth()->id());

            return $this->respond($request, true, 'Loading Vendor berhasil dibuat.', [
                'id' => $loading->id,
                'redirect' => route('asset.fleet.vendor-loadings.documents.show', $loading->id),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function show($id)
    {
        $this->authorizeView();
        $loading = $this->loading($id)->load(['loadingDocument.supplier', 'loadingDocument.customer', 'loadingDocument.route', 'project']);
        if ($loading->loading_document_id) {
            return redirect()->route('asset.fleet.vendor-loadings.documents.show', $loading->loading_document_id);
        }
        $canManage = $this->canManage();

        return view('assetmanagement::fleet.vendor_loadings.show', compact('loading', 'canManage'));
    }

    public function showDocument($id)
    {
        $this->authorizeView();
        $document = $this->document($id)->load(['supplier', 'customer', 'route', 'lines.project', 'lines.loadingDocument']);
        $canManage = $this->canManage();

        return view('assetmanagement::fleet.vendor_loadings.document_show', array_merge($this->formData(), compact('document', 'canManage')));
    }

    public function updateDocumentHeader(Request $request, $id)
    {
        $this->authorizeManage();
        $request->validate([
            'supplier_id' => 'required|integer',
            'customer_id' => 'required|integer',
            'route_id' => 'nullable|integer',
            'goods_name' => 'required|string|max:191',
            'goods_type' => 'nullable|string|max:191',
            'vessel_name' => 'required|string|max:191',
            'destination' => 'required|string|max:191',
        ]);

        try {
            $document = $this->documentService->updateHeader($this->document($id), $request->all(), $this->businessId());
            return $this->respond($request, true, 'Header dokumen Loading Vendor berhasil diperbarui.', ['id' => $document->id]);
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function createDocumentLine($documentId)
    {
        $this->authorizeManage();
        $loadingDocument = $this->document($documentId)->load(['supplier', 'customer']);

        return view('assetmanagement::fleet.vendor_loadings.form', array_merge($this->formData(), compact('loadingDocument')));
    }

    public function storeDocumentLine(Request $request, $documentId)
    {
        $this->authorizeManage();
        $this->validateInput($request);

        try {
            $loading = $this->documentService->addLine($this->document($documentId), $request->all(), $this->businessId(), (int) auth()->id());
            return $this->respond($request, true, 'Line angkutan berhasil ditambahkan.', [
                'id' => $loading->id,
                'redirect' => route('asset.fleet.vendor-loadings.documents.show', $documentId),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function destroyDocument(Request $request, $id)
    {
        $this->authorizeManage();
        try {
            $this->documentService->delete($this->document($id), $this->businessId());
            return $this->respond($request, true, 'Dokumen dan line Loading Vendor berhasil dihapus.');
        } catch (\Throwable $e) {
            report($e);
            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function edit($id)
    {
        $this->authorizeManage();
        $loading = $this->loading($id)->load(['loadingDocument.supplier', 'loadingDocument.customer', 'loadingDocument.route', 'project']);
        $loadingDocument = null;
        if ($loading->loading_document_id) {
            $loadingDocument = $this->document($loading->loading_document_id)->load(['supplier', 'customer']);
        }

        return view('assetmanagement::fleet.vendor_loadings.form', array_merge($this->formData(), compact('loading', 'loadingDocument')));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeManage();
        $this->validateInput($request);

        try {
            $existing = $this->loading($id);
            $loading = $this->loadingService->update($existing, $request->all(), $this->businessId());

            return $this->respond($request, true, 'Loading Vendor berhasil diperbarui.', [
                'id' => $loading->id,
                'redirect' => $loading->loading_document_id
                    ? route('asset.fleet.vendor-loadings.documents.show', $loading->loading_document_id)
                    : route('asset.fleet.vendor-loadings.show', $loading->id),
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

    public function importForm()
    {
        $this->authorizeImport();

        return view('assetmanagement::fleet.vendor_loadings.grid_import', [
            'canCreateSupplier' => $this->canCreateMinimalSupplier(),
            'canCreateCustomer' => $this->canCreateMinimalCustomer(),
            'canReverseImports' => $this->canManage(),
            'routes' => HeavyEquipmentRoute::where('business_id', $this->businessId())->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'batches' => DB::table('heavy_equipment_vendor_loading_import_batches')
                ->where('business_id', $this->businessId())->where('created_by', auth()->id())
                ->orderByDesc('id')->limit(20)->get(),
            'resumeBatchId' => request()->query('batch_id'),
        ]);
    }

    public function importTemplate()
    {
        $this->authorizeImport();

        return $this->importService->downloadTemplate();
    }

    public function importPreview(Request $request)
    {
        $this->authorizeImport();
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:51200']);

        $batchId = null;
        try {
            $file = $request->file('file');
            $businessId = $this->businessId();
            $userId = (int) auth()->id();
            $extension = strtolower($file->getClientOriginalExtension());
            $batchId = DB::table('heavy_equipment_vendor_loading_import_batches')->insertGetId([
                'business_id' => $businessId,
                'created_by' => $userId,
                'file_name' => $file->getClientOriginalName(),
                'status' => 'queued_preview',
                'stage' => 'queued',
                'last_message' => 'File diterima. Menunggu analisis workbook di queue imports.',
                'last_heartbeat_at' => now(),
                'expires_at' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $relativeDirectory = 'vendor-loading-imports/'.$businessId.'/'.$userId;
            $storedPath = \Illuminate\Support\Facades\Storage::disk('local')->putFileAs($relativeDirectory, $file, $batchId.'.'.$extension);
            if (! $storedPath) {
                DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->delete();
                throw new \RuntimeException('File gagal disimpan di penyimpanan privat.');
            }
            DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->update([
                'file_path' => $storedPath,
                'updated_at' => now(),
            ]);
            \Modules\AssetManagement\Jobs\AnalyzeHeavyEquipmentVendorLoadingImportJob::dispatch($batchId);

            return response()->json([
                'success' => true,
                'msg' => 'Upload diterima. Analisis workbook sedang berjalan di latar belakang.',
                'batch_id' => $batchId,
                'status_url' => route('asset.fleet.vendor-loadings.import.batches.status', $batchId),
            ], 202);
        } catch (\Throwable $e) {
            report($e);
            if ($batchId) {
                DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batchId)->whereIn('status', ['queued_preview', 'analyzing'])->update([
                    'status' => 'failed', 'stage' => 'failed', 'last_message' => 'Batch tidak dapat dimasukkan ke antrean analisis.',
                    'error_message' => $e->getMessage(), 'finished_at' => now(), 'last_heartbeat_at' => now(), 'updated_at' => now(),
                ]);
            }
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    public function importBatchStatus(int $id)
    {
        $this->authorizeImport();
        $batch = $this->ownedImportBatch($id);
        $status = (string) $batch->status;
        $liveProgress = $status === 'importing'
            ? (\Illuminate\Support\Facades\Cache::get('vendor_loading_import_batch:'.$batch->id.':progress') ?: [])
            : [];
        $liveHeartbeat = (int) ($liveProgress['heartbeat_at'] ?? 0);
        $stale = in_array($status, ['queued_preview', 'analyzing', 'queued_import', 'importing'], true)
            && $batch->updated_at && now()->diffInSeconds(\Carbon\Carbon::parse($batch->updated_at)) > 120
            && ! ($liveHeartbeat && now()->timestamp - $liveHeartbeat <= 120);
        $message = $batch->last_message;
        if (! empty($liveProgress['last_message'])) {
            $message = $liveProgress['last_message'];
        }
        if ($stale && in_array($status, ['queued_preview', 'queued_import'], true)) {
            $message = 'Masih menunggu worker queue imports. Proses akan berjalan saat worker aktif.';
        } elseif ($stale && in_array($status, ['analyzing', 'importing'], true)) {
            $message = 'Proses belum mengirim pembaruan terbaru. Periksa worker queue imports atau hubungi administrator.';
        }
        $total = (int) ($batch->total_rows ?: $batch->total_sheets);
        $processed = $status === 'importing'
            ? (int) ($liveProgress['processed_rows'] ?? $batch->processed_rows)
            : (int) ($batch->processed_rows ?: $batch->processed_sheets);
        $percent = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : (in_array($status, ['preview_ready', 'imported', 'partial', 'failed', 'expired', 'reversed'], true) ? 100 : 0);

        return response()->json([
            'success' => true,
            'batch' => [
                'id' => (int) $batch->id,
                'file_name' => $batch->file_name,
                'status' => $status,
                'stage' => $liveProgress['stage'] ?? $batch->stage,
                'message' => $message,
                'stale' => $stale,
                'progress' => $percent,
                'total_sheets' => (int) $batch->total_sheets,
                'processed_sheets' => (int) ($liveProgress['processed_sheets'] ?? $batch->processed_sheets),
                'total_rows' => (int) $batch->total_rows,
                'processed_rows' => (int) ($liveProgress['processed_rows'] ?? $batch->processed_rows),
                'ready_rows' => (int) $batch->ready_rows,
                'issue_rows' => (int) $batch->issue_rows,
                'missing_date_rows' => (int) $batch->missing_date_rows,
                'imported_documents' => (int) $batch->imported_documents,
                'imported_lines' => (int) $batch->imported_lines,
                'skipped_rows' => (int) $batch->skipped_rows,
                'reversed_documents' => (int) ($batch->reversed_documents ?? 0),
                'reversed_lines' => (int) ($batch->reversed_lines ?? 0),
                'reversed_at' => $batch->reversed_at ?? null,
                'reversed_by' => $batch->reversed_by ?? null,
                'error' => $batch->error_message,
                'errors' => json_decode($batch->errors_json ?: '[]', true) ?: [],
                'results' => json_decode($batch->results_json ?: '{}', true) ?: [],
                'updated_at' => $batch->updated_at,
            ],
        ]);
    }

    public function reverseImportBatch(int $id)
    {
        $this->authorizeManage();
        $batch = $this->ownedImportBatch($id);

        try {
            $result = $this->importReverseService->reverse($id, $this->businessId(), (int) auth()->id());

            return response()->json([
                'success' => true,
                'msg' => $result['msg'],
                'batch' => array_merge($result, [
                    'file_name' => $batch->file_name,
                    'progress' => 100,
                    'processed_sheets' => (int) $batch->processed_sheets,
                    'total_sheets' => (int) $batch->total_sheets,
                    'processed_rows' => (int) $batch->processed_rows,
                    'total_rows' => (int) $batch->total_rows,
                    'imported_documents' => (int) $batch->imported_documents,
                    'imported_lines' => (int) $batch->imported_lines,
                    'skipped_rows' => (int) $batch->skipped_rows,
                    'updated_at' => now()->toDateTimeString(),
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'msg' => 'Reverse import gagal diproses. Periksa log aplikasi sebelum mencoba lagi.'], 500);
        }
    }

    public function importBatchPreview(int $id)
    {
        $this->authorizeImport();
        $batch = $this->ownedImportBatch($id);
        if (! $batch->preview_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($batch->preview_path)) {
            return response()->json(['success' => false, 'msg' => 'Preview sudah kedaluwarsa atau belum selesai dianalisis.'], 404);
        }
        if (! in_array($batch->status, ['preview_ready', 'queued_import', 'importing'], true)) {
            return response()->json(['success' => false, 'msg' => 'Preview belum siap untuk ditampilkan.'], 409);
        }

        return response()->json([
            'success' => true,
            'batch_id' => (int) $batch->id,
            'preview' => json_decode(\Illuminate\Support\Facades\Storage::disk('local')->get($batch->preview_path), true),
        ]);
    }

    public function importConfirm(Request $request)
    {
        $this->authorizeImport();
        $request->validate([
            'batch_id' => 'required|integer|min:1',
            'sheet_overrides' => 'nullable|json|max:5000000',
            'row_overrides' => 'nullable|json|max:10000000',
            'sheet_missing_dates' => 'nullable|json|max:1000000',
        ]);
        $batch = $this->ownedImportBatch((int) $request->input('batch_id'));
        if ($batch->status !== 'preview_ready' || ! $batch->preview_path) {
            return response()->json(['success' => false, 'msg' => 'Batch tidak lagi siap dikonfirmasi. Muat ulang status import.'], 409);
        }
        $preview = json_decode(\Illuminate\Support\Facades\Storage::disk('local')->get($batch->preview_path), true);
        $sheets = $preview['sheets'] ?? [];
        if ($sheets === []) {
            return response()->json(['success' => false, 'msg' => 'Preview kosong atau sudah tidak kompatibel. Upload ulang workbook.'], 422);
        }
        $sheetOverrides = $this->decodeMap($request->input('sheet_overrides'));
        $rowOverrides = $this->decodeMap($request->input('row_overrides'));
        $sheetMissingDates = $this->decodeMap($request->input('sheet_missing_dates'));
        $validSheetKeys = collect($sheets)->pluck('sheet_key')->filter()->all();
        foreach (array_keys($sheetOverrides + $sheetMissingDates) as $key) {
            if (! in_array((string) $key, $validSheetKeys, true)) {
                return response()->json(['success' => false, 'msg' => 'Perubahan preview memuat sheet yang tidak dikenal. Upload ulang workbook.'], 422);
            }
        }
        foreach ($sheetOverrides as $values) {
            if (! is_array($values) || array_diff(array_keys($values), ['supplier_id', 'create_supplier', 'customer_id', 'route_id']) !== []) {
                return response()->json(['success' => false, 'msg' => 'Perubahan header sheet tidak valid. Upload ulang workbook.'], 422);
            }
            foreach (['supplier_id', 'customer_id'] as $field) {
                if (isset($values[$field]) && $values[$field] !== '' && ! ctype_digit((string) $values[$field])) {
                    return response()->json(['success' => false, 'msg' => 'Pilihan supplier/customer tidak valid.'], 422);
                }
            }
            if (! empty($values['supplier_id']) && ! Contact::where('business_id', $this->businessId())->whereIn('type', ['supplier', 'both'])->where('contact_status', 'active')->whereKey($values['supplier_id'])->exists()) {
                return response()->json(['success' => false, 'msg' => 'Supplier bukan bagian dari business aktif atau tidak aktif.'], 422);
            }
            if (! empty($values['customer_id']) && ! Contact::where('business_id', $this->businessId())->whereIn('type', ['customer', 'both'])->where('contact_status', 'active')->whereKey($values['customer_id'])->exists()) {
                return response()->json(['success' => false, 'msg' => 'Customer bukan bagian dari business aktif atau tidak aktif.'], 422);
            }
            if (array_key_exists('route_id', $values) && $values['route_id'] !== 'none' && ! ctype_digit((string) $values['route_id'])) {
                return response()->json(['success' => false, 'msg' => 'Pilihan rute dokumen tidak valid.'], 422);
            }
            if (isset($values['route_id']) && $values['route_id'] !== '' && $values['route_id'] !== 'none' && ! HeavyEquipmentRoute::where('business_id', $this->businessId())->where('is_active', true)->whereKey($values['route_id'])->exists()) {
                return response()->json(['success' => false, 'msg' => 'Rute dokumen bukan bagian dari business aktif atau tidak aktif.'], 422);
            }
            if (! empty($values['create_supplier']) && ! $this->canCreateMinimalSupplier()) {
                return response()->json(['success' => false, 'msg' => 'Izin supplier.create diperlukan untuk membuat supplier dari import.'], 403);
            }
            if (isset($values['create_supplier']) && ! in_array($values['create_supplier'], [true, false, 0, 1, '0', '1'], true)) {
                return response()->json(['success' => false, 'msg' => 'Pilihan pembuatan supplier tidak valid.'], 422);
            }
        }
        foreach ($sheets as $sheet) {
            $sheetKey = (string) ($sheet['sheet_key'] ?? '');
            $metadata = $sheet['metadata'] ?? [];
            $values = $sheetOverrides[$sheetKey] ?? [];
            $supplierId = $values['supplier_id'] ?? ($metadata['supplier_id'] ?? null);
            if (empty($values['create_supplier']) && $supplierId && ! Contact::where('business_id', $this->businessId())->whereIn('type', ['supplier', 'both'])->where('contact_status', 'active')->whereKey($supplierId)->exists()) {
                return response()->json(['success' => false, 'msg' => 'Supplier/Bendera pada sheet '.$sheet['title'].' sudah tidak aktif atau bukan milik business ini.'], 422);
            }
            $customerId = $values['customer_id'] ?? ($metadata['customer_id'] ?? null);
            if ($customerId && ! Contact::where('business_id', $this->businessId())->whereIn('type', ['customer', 'both'])->where('contact_status', 'active')->whereKey($customerId)->exists()) {
                return response()->json(['success' => false, 'msg' => 'Customer pada sheet '.$sheet['title'].' sudah tidak aktif atau bukan milik business ini.'], 422);
            }
            $routeId = array_key_exists('route_id', $values) ? $values['route_id'] : ($metadata['route_id'] ?? null);
            if ($routeId && $routeId !== 'none' && ! HeavyEquipmentRoute::where('business_id', $this->businessId())->where('is_active', true)->whereKey($routeId)->exists()) {
                return response()->json(['success' => false, 'msg' => 'Rute dokumen pada sheet '.$sheet['title'].' sudah tidak aktif atau bukan milik business ini.'], 422);
            }
        }
        $validRowKeys = collect($sheets)->flatMap(fn ($sheet) => collect($sheet['rows'] ?? [])->pluck('row_key'))->filter()->all();
        foreach ($rowOverrides as $key => $values) {
            if (! in_array((string) $key, $validRowKeys, true) || ! is_array($values) || array_diff(array_keys($values), ['loading_date']) !== []) {
                return response()->json(['success' => false, 'msg' => 'Perubahan line preview tidak valid. Upload ulang workbook.'], 422);
            }
            if (isset($values['loading_date']) && $values['loading_date'] !== '' && \Illuminate\Support\Facades\Validator::make(['date' => $values['loading_date']], ['date' => 'required|date_format:Y-m-d'])->fails()) {
                return response()->json(['success' => false, 'msg' => 'Tanggal line preview tidak valid.'], 422);
            }
        }
        foreach ($sheetMissingDates as $date) {
            if ($date !== null && $date !== '' && \Illuminate\Support\Facades\Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->fails()) {
                return response()->json(['success' => false, 'msg' => 'Tanggal isian sheet harus berformat YYYY-MM-DD.'], 422);
            }
        }
        $canCreateSupplier = $this->canCreateMinimalSupplier();
        $canCreateCustomer = $this->canCreateMinimalCustomer();
        [$readyRows, $skippedRows] = $this->countImportableRows($sheets, $sheetOverrides, $rowOverrides, $sheetMissingDates, $canCreateSupplier, $canCreateCustomer);
        if ($readyRows < 1) {
            return response()->json(['success' => false, 'msg' => 'Tidak ada tiket siap diimpor. Periksa bendera, customer, rute, tanggal, dan isu baris pada preview.'], 422);
        }

        try {
            DB::transaction(function () use ($batch, $sheetOverrides, $rowOverrides, $sheetMissingDates, $canCreateSupplier, $canCreateCustomer, $readyRows, $skippedRows) {
                $locked = DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'preview_ready') {
                    throw new \RuntimeException('Konfirmasi sudah dikirim atau batch tidak lagi siap.');
                }
                DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->update([
                    'status' => 'queued_import',
                    'stage' => 'queued',
                    'overrides_json' => json_encode(['sheet_overrides' => $sheetOverrides, 'row_overrides' => $rowOverrides, 'sheet_missing_dates' => $sheetMissingDates], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'permissions_json' => json_encode(['can_create_supplier' => $canCreateSupplier, 'can_create_customer' => $canCreateCustomer]),
                    'ready_rows' => $readyRows,
                    'issue_rows' => $skippedRows,
                    'skipped_rows' => 0,
                    'processed_sheets' => 0,
                    'processed_rows' => 0,
                    'last_message' => 'Konfirmasi diterima. Menunggu worker queue imports.',
                    'last_heartbeat_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            \Modules\AssetManagement\Jobs\ProcessHeavyEquipmentVendorLoadingImportJob::dispatch((int) $batch->id);
        } catch (\Throwable $e) {
            DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->where('status', 'queued_import')->update([
                'status' => 'failed', 'stage' => 'failed', 'last_message' => 'Batch tidak dapat dimasukkan ke antrean import.',
                'error_message' => $e->getMessage(), 'finished_at' => now(), 'last_heartbeat_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['success' => false, 'msg' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'queued' => true,
            'batch_id' => (int) $batch->id,
            'ready_rows' => $readyRows,
            'skipped_rows' => $skippedRows,
            'msg' => "Import dijadwalkan: {$readyRows} tiket akan diproses dan {$skippedRows} akan dilewati/dilaporkan.",
        ], 202);
    }

    private function ownedImportBatch(int $id): object
    {
        $batch = DB::table('heavy_equipment_vendor_loading_import_batches')
            ->where('id', $id)
            ->where('business_id', $this->businessId())
            ->where('created_by', auth()->id())
            ->first();
        abort_if(! $batch, 404, 'Batch import tidak ditemukan.');

        return $batch;
    }

    private function countImportableRows(array $sheets, array $sheetOverrides, array $rowOverrides, array $sheetMissingDates, bool $canCreateSupplier, bool $canCreateCustomer): array
    {
        $ready = 0;
        $total = 0;
        foreach ($sheets as $sheet) {
            $key = (string) ($sheet['sheet_key'] ?? '');
            $meta = $sheet['metadata'] ?? [];
            $overrides = $sheetOverrides[$key] ?? [];
            $supplierReady = ! empty($overrides['create_supplier']) && $canCreateSupplier
                || ! empty($overrides['supplier_id'])
                || ! empty($meta['supplier_id']);
            $customerReady = ! empty($overrides['customer_id'])
                || ! empty($meta['customer_id'])
                || (($meta['customer_match_status'] ?? '') === 'will_create' && $canCreateCustomer);
            $routeStatus = $meta['route_match_status'] ?? 'not_set';
            $routeReady = ! in_array($routeStatus, ['conflict', 'unmatched'], true)
                || (array_key_exists('route_id', $overrides) && $overrides['route_id'] !== '');

            foreach ($sheet['rows'] ?? [] as $row) {
                $total++;
                $rowKey = (string) ($row['row_key'] ?? '');
                $date = $rowOverrides[$rowKey]['loading_date'] ?? ($row['loading_date'] ?? null);
                if (empty($date)) $date = $sheetMissingDates[$key] ?? null;
                $dateReady = ! empty($date) && ! \Illuminate\Support\Facades\Validator::make(['date' => $date], ['date' => 'date_format:Y-m-d'])->fails();
                $correctedDate = ! empty($rowOverrides[$rowKey]['loading_date']) || (! empty($sheetMissingDates[$key]) && empty($row['loading_date']));
                $errors = array_filter($row['row_errors'] ?? [], function ($error) use ($supplierReady, $customerReady, $correctedDate) {
                    $text = strtolower((string) $error);
                    if ($supplierReady && (str_contains($text, 'bendera/supplier') || str_starts_with($text, 'sheet memiliki lebih dari satu nama bendera/supplier'))) return false;
                    if ($customerReady && str_contains($text, 'customer ')) return false;
                    if ($correctedDate && str_contains($text, 'tanggal loading tidak terbaca')) return false;
                    return true;
                });
                if ($supplierReady && $customerReady && $routeReady && $dateReady && $errors === []) $ready++;
            }
        }

        return [$ready, max(0, $total - $ready)];
    }

    public function supplierOptions(Request $request)
    {
        $this->authorizeView();

        return response()->json(['results' => $this->loadingService->supplierMatches($this->businessId(), (string) $request->input('term', ''))]);
    }

    public function customerOptions(Request $request)
    {
        $this->authorizeView();

        return response()->json(['results' => $this->loadingService->customerMatches($this->businessId(), (string) $request->input('term', ''))]);
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

    public function createCustomer(Request $request)
    {
        $this->authorizeView();
        if (! $this->canCreateMinimalCustomer()) {
            abort(403, 'Anda tidak memiliki izin membuat customer.');
        }
        $request->validate(['name' => 'required|string|max:191']);

        try {
            $customer = $this->loadingService->createMinimalCustomer($request->input('name'), $this->businessId(), auth()->id());
            return response()->json(['success' => true, 'msg' => 'Customer minimal berhasil dibuat.', 'customer' => ['id' => $customer->id, 'text' => $customer->name]]);
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
            'vendorUnits' => HeavyEquipmentVendorLoading::whereHas('loadingDocument', fn ($query) => $query->where('business_id', $businessId))->whereNotNull('vendor_unit_name')->where('vendor_unit_name', '<>', '')->distinct()->orderBy('vendor_unit_name')->pluck('vendor_unit_name'),
            'vendorDrivers' => HeavyEquipmentVendorLoading::whereHas('loadingDocument', fn ($query) => $query->where('business_id', $businessId))->whereNotNull('vendor_driver_name')->where('vendor_driver_name', '<>', '')->distinct()->orderBy('vendor_driver_name')->pluck('vendor_driver_name'),
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
            'goods_name' => 'nullable|string|max:191',
            'goods_type' => 'nullable|string|max:191',
            'vessel_name' => 'nullable|string|max:191',
            'destination' => 'nullable|string|max:191',
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
        return HeavyEquipmentVendorLoading::whereHas('loadingDocument', fn ($query) => $query->where('business_id', $this->businessId()))->findOrFail($id);
    }

    private function document($id): HeavyEquipmentVendorLoadingDocument
    {
        return HeavyEquipmentVendorLoadingDocument::where('business_id', $this->businessId())->findOrFail($id);
    }

    private function decodeMap(?string $value): array
    {
        if (! $value) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
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

    private function canCreateMinimalSupplier(): bool
    {
        return auth()->user()->can('superadmin') || auth()->user()->can('supplier.create');
    }

    private function canCreateMinimalCustomer(): bool
    {
        return auth()->user()->can('superadmin') || auth()->user()->can('customer.create');
    }

    private function canManage(): bool
    {
        return auth()->user()->can('superadmin') || auth()->user()->can('asset.fleet_vendor_loadings.manage') || auth()->user()->can('asset.fleet_trips.manage') || auth()->user()->can('asset.fleet_trips.create') || auth()->user()->can('asset.create') || auth()->user()->can('asset.update');
    }

    private function authorizeView(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.view') && ! auth()->user()->can('asset.fleet_vendor_loadings.view') && ! auth()->user()->can('asset.fleet_vendor_loadings.manage') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.fleet_trips.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function authorizeImport(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('asset.fleet_vendor_loadings.import') && ! auth()->user()->can('asset.fleet_vendor_loadings.manage') && ! auth()->user()->can('asset.fleet_trips.manage') && ! auth()->user()->can('asset.fleet_trips.create')) {
            abort(403, 'Unauthorized action.');
        }
    }

}
