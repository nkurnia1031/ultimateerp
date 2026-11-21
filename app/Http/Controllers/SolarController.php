<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Jobs\ProcessSolarImport;
use App\Jobs\ProcessSolarPreview;
use App\Services\SolarImportProcessService;
use App\Services\SolarImportHistoryService;
use App\Services\SolarImportService;
use App\Services\SolarLedgerService;
use App\Services\SolarImportPreviewService;
use App\Services\SolarWorkbookService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class SolarController extends Controller
{
    private SolarLedgerService $ledgerService;

    private SolarWorkbookService $workbookService;

    private SolarImportPreviewService $previewService;

    private SolarImportProcessService $processService;

    private SolarImportService $importService;

    public function __construct(
        SolarLedgerService $ledgerService,
        SolarWorkbookService $workbookService,
        SolarImportPreviewService $previewService,
        SolarImportProcessService $processService,
        SolarImportService $importService
    )
    {
        $this->ledgerService = $ledgerService;
        $this->workbookService = $workbookService;
        $this->previewService = $previewService;
        $this->processService = $processService;
        $this->importService = $importService;
    }

    public function index(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $reportDate = $request->validate(['report_date' => ['nullable', 'date']])['report_date'] ?? now()->format('Y-m-d');
        $usageMonth = $request->validate(['usage_month' => ['nullable', 'date_format:Y-m']])['usage_month'] ?? now()->format('Y-m');
        $monthStart = Carbon::createFromFormat('!Y-m', $usageMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $rows = [];

        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $table = $this->ledgerService->table($ledger);
            $query = DB::table("{$table} as solar_row")
                ->leftJoin('assets as solar_asset', function ($join) use ($businessId) {
                    $join->on('solar_row.asset_id', '=', 'solar_asset.id')
                        ->where('solar_asset.business_id', $businessId);
                })
                ->leftJoin('users as solar_driver', function ($join) use ($businessId) {
                    $join->on('solar_row.driver_id', '=', 'solar_driver.id')
                        ->where('solar_driver.business_id', $businessId);
                })
                ->where('solar_row.business_id', $businessId)
                ->select(
                    'solar_row.*',
                    'solar_asset.name as linked_asset_name',
                    DB::raw("TRIM(CONCAT(COALESCE(solar_driver.surname, ''), ' ', COALESCE(solar_driver.first_name, ''), ' ', COALESCE(solar_driver.last_name, ''))) as linked_driver_name")
                );

            if ($ledger !== 'stock') {
                $query->leftJoin('contacts', function ($join) use ($businessId) {
                    $join->on('solar_row.customer_id', '=', 'contacts.id')
                        ->where('contacts.business_id', $businessId);
                })->addSelect(DB::raw("COALESCE(NULLIF(TRIM(contacts.supplier_business_name), ''), NULLIF(TRIM(contacts.name), ''), NULLIF(TRIM(contacts.contact_id), ''), NULLIF(TRIM(contacts.mobile), ''), NULLIF(TRIM(contacts.email), ''), CONCAT('Customer #', contacts.id)) as customer_name"));
            }

            $query->whereBetween('solar_row.tanggal', [$dateFrom, $dateTo])
                ->orderBy('solar_row.tanggal')
                ->orderBy('solar_row.id');

            $rows[$ledger] = $query->get();
        }

        $customers = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->get(['id', 'contact_id', 'name', 'supplier_business_name', 'mobile', 'email'])
            ->map(function ($customer) {
                $customer->display_name = Contact::customerDisplayName($customer, true);

                return $customer;
            })
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $assets = DB::table('assets')
            ->where('business_id', $businessId)
            ->select('id', 'asset_code', 'name')
            ->orderBy('name')
            ->get();
        $drivers = DB::table('users')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->select('id', DB::raw("TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name"))
            ->orderBy('full_name')
            ->get();
        $usageRows = DB::table('solar_equipment_usages')
            ->leftJoin('assets', function ($join) use ($businessId) {
                $join->on('solar_equipment_usages.asset_id', '=', 'assets.id')
                    ->where('assets.business_id', $businessId);
            })
            ->where('solar_equipment_usages.business_id', $businessId)
            ->whereBetween('solar_equipment_usages.usage_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->orderBy('solar_equipment_usages.equipment_name')
            ->orderBy('solar_equipment_usages.usage_date')
            ->select('solar_equipment_usages.*', 'assets.name as asset_current_name')
            ->get();
        $usageMatrix = [];
        foreach ($usageRows as $usage) {
            $key = (string) $usage->equipment_name;
            if (! isset($usageMatrix[$key])) {
                $usageMatrix[$key] = [
                    'equipment_name' => $key,
                    'asset_id' => $usage->asset_id,
                    'asset_current_name' => $usage->asset_current_name,
                    'days' => [],
                    'total_gln' => 0.0,
                ];
            }
            $day = (int) Carbon::parse($usage->usage_date)->format('j');
            $quantity = (float) $usage->quantity_gln;
            $usageMatrix[$key]['days'][$day] = ($usageMatrix[$key]['days'][$day] ?? 0) + $quantity;
            $usageMatrix[$key]['total_gln'] += $quantity;
        }

        return view('solar.index', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'reportDate' => $reportDate,
            'usageMonth' => $usageMonth,
            'usageDays' => (int) $monthEnd->format('j'),
            'usageMatrix' => array_values($usageMatrix),
            'usageRows' => $usageRows,
            'rows' => $rows,
            'balances' => $this->ledgerService->balancesAtEndOfDate($businessId, $reportDate),
            'customers' => $customers,
            'assets' => $assets,
            'drivers' => $drivers,
            'importBatches' => DB::table('solar_import_batches')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function importHistory(Request $request)
    {
        $this->authorizeSolar($request);

        return view('solar.import-history');
    }

    public function importHistoryData(Request $request)
    {
        $businessId = $this->authorizeSolar($request);

        return response()->json(app(SolarImportHistoryService::class)->dataTable($request, $businessId));
    }

    public function restoreImportWorkbook(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $shared = app(SolarImportHistoryService::class)->sharedBatchForDomainBatch($batchId, $businessId);
        abort_unless(app(SolarImportHistoryService::class)->hasSourceSnapshot($batchId, $businessId), 404, 'Snapshot workbook tidak tersedia untuk batch ini.');

        return app(\App\Services\UserImportBatchService::class)->workbookDownload($shared);
    }

    public function importDetail(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $batch, 404);

        $rows = [];
        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $rows[$ledger] = DB::table($this->ledgerService->table($ledger))
                ->where('business_id', $businessId)
                ->where('import_batch_id', $batchId)
                ->orderBy('tanggal')
                ->orderBy('id')
                ->get();
        }
        $rows['usage'] = DB::table('solar_equipment_usages')
            ->where('business_id', $businessId)
            ->where('import_batch_id', $batchId)
            ->orderBy('usage_date')
            ->orderBy('equipment_name')
            ->get();
        $events = DB::table('solar_import_batch_events')
            ->where('business_id', $businessId)
            ->where('batch_id', $batchId)
            ->orderBy('sequence')
            ->limit(300)
            ->get();
        $mappings = $this->previewService->referenceMappings($businessId, $batchId);
        $hasWorkbookSnapshot = app(SolarImportHistoryService::class)->hasSourceSnapshot($batchId, $businessId);

        return view('solar.import-detail', compact('batch', 'rows', 'events', 'mappings', 'hasWorkbookSnapshot'));
    }

    public function reconcilePreview(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $batch, 404);
        abort_unless(
            ($batch->operation ?: 'preview') === 'preview'
            && in_array($batch->process_state ?: 'completed', ['queued', 'processing', 'completed', 'failed', 'cancelled'], true),
            409,
            'Batch preview tidak dapat dibuka pada status ini.'
        );

        $assets = DB::table('assets')->where('business_id', $businessId)->orderBy('name')->get(['id', 'asset_code', 'name']);
        $customers = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->get(['id', 'contact_id', 'name', 'supplier_business_name', 'mobile', 'email'])
            ->map(function ($customer) {
                $customer->display_name = Contact::customerDisplayName($customer, true);

                return $customer;
            })
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $drivers = DB::table('users')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->select('id', DB::raw("TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name"))
            ->orderBy('full_name')
            ->get();
        $viewData = [
            'token' => $batchId,
            'summary' => $this->previewService->summary($batchId, $businessId),
            'assets' => $assets,
            'customers' => $customers,
            'drivers' => $drivers,
        ];
        if ($request->boolean('fragment')) {
            return view('solar._preview-workspace', $viewData);
        }

        return view('solar.reconcile', $viewData);
    }

    public function previewRows(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $base = DB::table('solar_import_preview_rows')
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId);
        abort_if(! DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->exists(), 404);

        $total = (clone $base)->count();
        $query = clone $base;
        if ($request->filled('ledger') && in_array($request->input('ledger'), SolarLedgerService::LEDGERS, true)) {
            $query->where('ledger', $request->input('ledger'));
        }
        if ($request->filled('sheet')) {
            $query->where('source_sheet', mb_substr(trim((string) $request->input('sheet')), 0, 120));
        }
        if ($request->filled('ledger') && $request->input('ledger') === 'usage') {
            $query->where('ledger', 'usage');
        }
        if ($request->filled('status') && in_array($request->input('status'), ['match', 'only_excel', 'only_app', 'different', 'error', 'ambiguous'], true)) {
            if ($request->input('status') === 'ambiguous') {
                $query->where('status', 'error')->where('matched_by', 'ambiguous');
            } else {
                $query->where('status', $request->input('status'));
            }
        }
        $search = trim((string) $request->input('search.value', $request->input('search', '')));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('ledger', 'like', "%{$search}%")
                    ->orWhere('raw_date', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('matched_by', 'like', "%{$search}%")
                    ->orWhere('source_row', 'like', "%{$search}%")
                    ->orWhere('normalized_date', 'like', "%{$search}%")
                    ->orWhere('differences', 'like', "%{$search}%");
            });
        }
        $filtered = (clone $query)->count();
        $columns = ['source_sheet', 'source_row', 'normalized_date', 'status', 'matched_by', 'id'];
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($columns[$orderIndex] ?? 'id', $orderDirection);
        $length = min(5000, max(1, (int) $request->input('length', 25)));
        $start = max(0, (int) $request->input('start', 0));

        $rows = $query->offset($start)->limit($length)->get();
        $data = $rows->map(function ($row) {
            $differences = json_decode((string) $row->differences, true) ?: [];
            $excel = json_decode((string) $row->excel_payload, true) ?: [];
            $label = $row->matched_by === 'ambiguous' ? 'Ambigu' : match ($row->status) {
                'match' => 'Cocok',
                'only_excel' => 'Hanya di Excel',
                'only_app' => 'Hanya di Aplikasi',
                'different' => $row->balance_only ? 'Baris cocok, saldo berbeda' : 'Berbeda',
                'error' => 'Error',
                default => ucfirst($row->status),
            };
            return [
                'id' => $row->id,
                'ledger' => strtoupper($row->ledger),
                'sheet' => $row->source_sheet ?: strtoupper($row->ledger),
                'source_row' => $row->source_row ?: '—',
                'raw_date' => $row->raw_date ?: '—',
                'date' => $row->normalized_date ? Carbon::parse($row->normalized_date)->format('d/m/Y') : '—',
                'date_iso' => $row->normalized_date ? Carbon::parse($row->normalized_date)->format('Y-m-d') : null,
                'source_sheet' => $row->source_sheet,
                'status' => $row->status,
                'status_label' => $label,
                'matched_by' => $row->matched_by ?: '—',
                'detail' => implode('; ', $differences),
                'can_edit_date' => $row->status === 'error' && str_contains(implode(' ', $differences), 'Tanggal'),
                'excel' => $excel,
                'app' => json_decode((string) $row->app_payload, true),
                'importable' => (bool) $row->is_importable,
            ];
        })->values();

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
            'summary' => $this->previewService->summary($batchId, $businessId),
        ]);
    }

    public function previewMappings(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->first();
        abort_if(! $batch || ($batch->operation ?: 'preview') !== 'preview', 404, 'Batch preview tidak ditemukan.');

        $sheet = $request->filled('sheet') ? mb_substr(trim((string) $request->input('sheet')), 0, 120) : null;

        return response()->json([
            'mappings' => $this->previewService->referenceMappings($businessId, $batchId, $sheet),
        ]);
    }

    public function previewReferenceOptions(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batchExists = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->where(function ($query) {
                $query->whereNull('operation')->orWhere('operation', 'preview');
            })
            ->exists();
        abort_unless($batchExists, 404, 'Batch preview tidak ditemukan.');

        $validated = $request->validate([
            'type' => ['required', Rule::in(['asset', 'driver', 'customer'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));

        if ($validated['type'] === 'asset') {
            $query = DB::table('assets')->where('business_id', $businessId);
            if ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('asset_code', 'like', '%'.$search.'%');
                });
            }
            $results = $query->orderBy('name')->limit(30)->get(['id', 'asset_code', 'name'])
                ->map(function ($asset) {
                    $label = trim(implode(' · ', array_filter([
                        trim((string) ($asset->asset_code ?? '')),
                        trim((string) ($asset->name ?? '')),
                    ])));

                    return [
                        'id' => (string) $asset->id,
                        'text' => $label !== '' ? $label : 'Asset #'.$asset->id,
                    ];
                });
        } elseif ($validated['type'] === 'driver') {
            $fullName = "TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
            $query = DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at');
            if ($search !== '') {
                $query->whereRaw($fullName.' LIKE ?', ['%'.$search.'%']);
            }
            $results = $query->orderBy('first_name')->limit(30)
                ->get(['id', DB::raw($fullName.' as full_name')])
                ->filter(fn ($driver) => trim((string) $driver->full_name) !== '')
                ->map(fn ($driver) => ['id' => (string) $driver->id, 'text' => trim((string) $driver->full_name)]);
        } else {
            $query = DB::table('contacts')
                ->where('business_id', $businessId)
                ->whereIn('type', ['customer', 'both']);
            if ($search !== '') {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('supplier_business_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_id', 'like', '%'.$search.'%')
                        ->orWhere('mobile', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            }
            $results = $query->orderBy('supplier_business_name')->orderBy('name')->limit(30)
                ->get(['id', 'name', 'supplier_business_name', 'contact_id', 'mobile', 'email'])
                ->map(fn ($customer) => [
                    'id' => (string) $customer->id,
                    'text' => Contact::customerDisplayName($customer, true),
                ]);
        }

        return response()->json(['results' => $results->values()]);
    }

    public function updatePreviewMapping(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->where(function ($query) {
                $query->where('process_state', 'completed')->orWhereNull('process_state');
            })
            ->first();
        abort_if(! $batch || ($batch->operation ?: 'preview') !== 'preview', 404, 'Batch preview belum siap atau sudah diproses.');

        $validated = $request->validate([
            'type' => ['required', Rule::in(['asset', 'driver'])],
            'source_value' => ['required', 'string', 'max:255'],
            'target_id' => ['nullable', 'integer'],
            'sheet' => ['nullable', 'string', 'max:120'],
        ]);
        $targetId = isset($validated['target_id']) ? (int) $validated['target_id'] : null;
        if ($targetId !== null && $validated['type'] === 'asset') {
            abort_unless(DB::table('assets')->where('business_id', $businessId)->where('id', $targetId)->exists(), 422, 'Unit/alat tidak ditemukan pada business ini.');
        } elseif ($targetId !== null) {
            abort_unless(DB::table('users')->where('business_id', $businessId)->whereNull('deleted_at')->where('id', $targetId)->exists(), 422, 'Supir tidak ditemukan pada business ini.');
        }

        $this->previewService->saveReferenceMapping(
            $businessId,
            $batchId,
            $validated['type'],
            $validated['source_value'],
            $targetId
        );

        return response()->json([
            'success' => true,
            'summary' => $this->previewService->summary($batchId, $businessId),
            'mappings' => $this->previewService->referenceMappings(
                $businessId,
                $batchId,
                $validated['sheet'] ?? null
            ),
        ]);
    }

    public function previewSummary(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        abort_if(! DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->exists(), 404);

        return response()->json($this->previewService->summary($batchId, $businessId));
    }

    public function previewStatus(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);

        return response()->json($this->processService->status($batchId, $businessId));
    }

    public function previewEvents(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $afterId = max(0, (int) $request->query('after_id', 0));

        return response()->json([
            'events' => $this->processService->events($batchId, $businessId, $afterId),
        ]);
    }

    public function uploadPreview(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        try {
            $token = (string) Str::uuid();
            $directory = "solar-imports/{$businessId}";
            $this->cleanupImports($directory);
            $extension = strtolower($request->file('file')->getClientOriginalExtension());
            $path = $request->file('file')->storeAs($directory, "{$token}.{$extension}", 'local');
            DB::table('solar_import_batches')->insert([
                'id' => $token,
                'business_id' => $businessId,
                'created_by' => optional($request->user())->id,
                'file_name' => $request->file('file')->getClientOriginalName(),
                'file_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
                'file_path' => $path,
                'status' => 'preview',
                'operation' => 'preview',
                'process_state' => 'queued',
                'stage' => 'uploading',
                'progress_percent' => 0,
                'progress_message' => 'File diterima, menunggu worker.',
                'heartbeat_at' => now(),
                'counts' => json_encode([]),
                'opening_snapshot' => json_encode($this->ledgerService->openingSnapshot($businessId)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $request->session()->put("solar_imports.{$token}", $path);
            $this->processService->queued($token, $businessId, 'preview');
            ProcessSolarPreview::dispatch($token, $businessId);

            return response()->json([
                'success' => true,
                'batch_id' => $token,
                'status_url' => route('solar.imports.status', $token),
                'message' => 'Upload diterima dan preview sedang diproses.',
            ], 202);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Workbook tidak dapat diproses. Periksa format file dan coba lagi.',
            ], 422);
        }
    }

    public function confirmImportAjax(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::transaction(function () use ($batchId, $businessId) {
            $batch = DB::table('solar_import_batches')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();
            abort_if(! $batch, 404, 'Batch import tidak ditemukan.');
            abort_unless(
                $batch->status === 'preview'
                && ($batch->operation ?: 'preview') === 'preview'
                && ($batch->process_state ?: 'completed') === 'completed',
                409,
                'Preview belum selesai atau sudah diproses.'
            );

            $warnings = json_decode((string) ($batch->preview_warnings ?? '[]'), true) ?: [];
            $blockingWarning = collect($warnings)->first(fn ($warning) => ! empty($warning['blocking']));
            abort_unless(
                ! $blockingWarning,
                422,
                $blockingWarning['message'] ?? 'Peringatan workbook harus diperbaiki sebelum import.'
            );

            $this->processService->queued($batchId, $businessId, 'import', (int) $this->previewService->summary($batchId, $businessId)['only_excel']);

            return $batch;
        });
        $this->processService->flushPendingEvents($batch->id, $businessId);
        ProcessSolarImport::dispatch($batch->id, $businessId);

        return response()->json([
            'success' => true,
            'batch_id' => $batch->id,
            'status_url' => route('solar.imports.status', $batch->id),
            'message' => 'Import diterima dan sedang diproses.',
        ], 202);
    }

    public function cancelImport(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $cancelled = $this->processService->cancel($batchId, $businessId);
        $stagingRemoved = true;
        if ($cancelled && ! app(SolarImportHistoryService::class)->hasSourceSnapshot($batchId, $businessId)) {
            $stagingRemoved = app(SolarImportHistoryService::class)->deleteStaging($batchId, $businessId);
            $request->session()->forget("solar_imports.{$batchId}");
        }

        return response()->json([
            'success' => $cancelled,
            'message' => $cancelled
                ? ($stagingRemoved ? 'Proses dibatalkan.' : 'Proses dibatalkan, tetapi pembersihan file sementara belum berhasil. Hubungi administrator dengan ID batch.')
                : 'Proses sudah berjalan atau selesai.',
            'status' => $this->processService->status($batchId, $businessId),
        ], $cancelled ? 200 : 409);
    }

    public function retryProcess(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        [$batch, $operation] = DB::transaction(function () use ($batchId, $businessId) {
            $batch = DB::table('solar_import_batches')
                ->where('id', $batchId)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();
            abort_if(! $batch, 404, 'Batch import tidak ditemukan.');
            abort_unless(in_array($batch->process_state, ['failed', 'cancelled'], true), 409, 'Batch belum dapat diulang.');
            abort_unless($batch->status === 'preview', 409, 'Batch import yang sudah selesai tidak dapat diulang.');
            abort_unless(
                app(SolarImportHistoryService::class)->hasSourceSnapshot($batchId, $businessId)
                || ($batch->file_path && Storage::disk('local')->exists($batch->file_path)),
                409,
                'Sumber workbook tidak tersedia. Unggah ulang file untuk memulai batch baru.'
            );

            $operation = $batch->operation ?: 'preview';
            $total = $operation === 'import'
                ? (int) $this->previewService->summary($batchId, $businessId)['only_excel']
                : 0;
            $this->processService->queued($batchId, $businessId, $operation, $total);

            return [$batch, $operation];
        });
        $this->processService->flushPendingEvents($batchId, $businessId);
        if ($operation === 'import') {
            ProcessSolarImport::dispatch($batchId, $businessId);
        } else {
            ProcessSolarPreview::dispatch($batchId, $businessId);
        }

        return response()->json([
            'success' => true,
            'status_url' => route('solar.imports.status', $batchId),
        ], 202);
    }

    public function updatePreviewDate(Request $request, string $batchId, int $rowId)
    {
        $businessId = $this->authorizeSolar($request);
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->first();
        abort_if(! $batch, 404, 'Batch preview tidak ditemukan atau sudah diproses.');
        $row = DB::table('solar_import_preview_rows')
            ->where('id', $rowId)
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $row, 404, 'Baris preview tidak ditemukan.');
        abort_unless($row->status === 'error' && str_contains(implode(' ', json_decode((string) $row->differences, true) ?: []), 'Tanggal'), 422, 'Hanya error tanggal yang dapat diedit.');

        $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
        $overrides[$row->ledger.':'.$row->source_row] = $date;
        [$workbookPath, $temporary] = app(SolarImportHistoryService::class)->sourceWorkbookPath($batchId, $businessId);
        try {
            $this->previewService->rebuild($businessId, $batchId, $workbookPath, $overrides);
        } finally {
            app(SolarImportHistoryService::class)->cleanupTemporaryWorkbook($workbookPath, $temporary);
        }
        DB::table('solar_import_batches')->where('id', $batchId)->update([
            'date_overrides' => json_encode($overrides),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'summary' => $this->previewService->summary($batchId, $businessId),
        ]);
    }

    public function updatePreviewRow(Request $request, string $batchId, int $rowId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->where('process_state', 'completed')
            ->first();
        abort_if(! $batch, 404, 'Batch preview tidak ditemukan atau belum siap.');
        $preview = DB::table('solar_import_preview_rows')
            ->where('id', $rowId)
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $preview, 404, 'Baris preview tidak ditemukan.');
        $row = json_decode((string) $preview->excel_payload, true) ?: [];
        abort_if($row === [], 422, 'Baris ini tidak memiliki data yang dapat diedit.');

        if ($preview->ledger === 'usage') {
            $validated = $request->validate([
                'tanggal' => ['required', 'date'],
                'quantity_gln' => ['required', 'numeric', 'gt:0'],
                'equipment_name' => ['required', 'string', 'max:255'],
                'asset_id' => [
                    'nullable',
                    Rule::exists('assets', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
                ],
            ]);
            $row['tanggal'] = Carbon::parse($validated['tanggal'])->format('Y-m-d');
            $row['equipment_name'] = trim($validated['equipment_name']);
            $row['quantity_gln'] = (float) $validated['quantity_gln'];
            $row['asset_id'] = $validated['asset_id'] ?? null;
            $row['asset_match_status'] = $row['asset_id'] ? 'matched' : 'unmatched';
            $row['source_sheet'] = $preview->source_sheet;
            $row['source_row'] = $preview->source_row;
            $row['source_hash'] = $this->ledgerService->rowHash('usage', $row);
            $decorated = $this->ledgerService->decorateSourceKeys([
                'rows' => ['stock' => [], 'pool' => [], 'mess' => [], 'usage' => [$row]],
                'opening' => [],
                'errors' => [],
            ]);
            $comparison = $this->ledgerService->usageReconciliation($businessId, $decorated['rows']['usage']);
        } else {
            $unitMaxLength = $preview->ledger === 'stock' ? 255 : 50;
            $operatorMaxLength = $preview->ledger === 'stock' ? 255 : 100;
            $validated = $request->validate([
                'tanggal' => ['required', 'date'],
                'stock_in' => ['nullable', 'numeric', 'min:0'],
                'out_actual' => ['nullable', 'numeric', 'min:0'],
                'out_meteran' => ['nullable', 'numeric', 'min:0'],
                'qty_in' => ['nullable', 'numeric', 'min:0'],
                'qty_out' => ['nullable', 'numeric', 'min:0'],
                'keterangan' => ['nullable', 'string', 'max:500'],
                'unit' => ['nullable', 'string', 'max:'.$unitMaxLength],
                'operator' => ['nullable', 'string', 'max:'.$operatorMaxLength],
                'customer_id' => ['nullable', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('business_id', $businessId)->whereIn('type', ['customer', 'both']))],
                'asset_id' => ['nullable', Rule::exists('assets', 'id')->where(fn ($query) => $query->where('business_id', $businessId))],
                'driver_id' => ['nullable', Rule::exists('users', 'id')->where(fn ($query) => $query->where('business_id', $businessId)->whereNull('deleted_at'))],
                'nama_kapal' => ['nullable', 'string', 'max:255'],
                'lokasi' => ['nullable', 'string', 'max:255'],
            ]);
            $row['tanggal'] = Carbon::parse($validated['tanggal'])->format('Y-m-d');
            $unitChanged = array_key_exists('unit', $validated)
                && mb_strtolower(trim((string) ($row['unit'] ?? ''))) !== mb_strtolower(trim((string) ($validated['unit'] ?? '')));
            $driverChanged = array_key_exists('operator', $validated)
                && mb_strtolower(trim((string) ($row['operator'] ?? ''))) !== mb_strtolower(trim((string) ($validated['operator'] ?? '')));
            foreach (['stock_in', 'out_actual', 'out_meteran', 'qty_in', 'qty_out', 'keterangan', 'unit', 'operator', 'customer_id', 'asset_id', 'driver_id', 'nama_kapal', 'lokasi'] as $field) {
                if (array_key_exists($field, $validated)) $row[$field] = $validated[$field];
            }
            if ($unitChanged) {
                $row['asset_id'] = null;
                $row['asset_match_status'] = 'unmatched';
            }
            if ($driverChanged) {
                $row['driver_id'] = null;
                $row['driver_match_status'] = 'unmatched';
            }
            unset($row['customer_ref']);
            $row['source_sheet'] = $preview->source_sheet;
            $row['source_row'] = $preview->source_row;
            $ledger = $preview->ledger;
            $row = $this->previewService->refreshReferenceSuggestions($businessId, $ledger, $row);
            $sourceRows = ['stock' => [], 'pool' => [], 'mess' => []];
            $sourceRows[$ledger][] = $row;
            $decorated = $this->ledgerService->decorateSourceKeys(['rows' => $sourceRows, 'opening' => [], 'errors' => []]);
            $result = $this->ledgerService->reconciliation($businessId, ['rows' => $decorated['rows'], 'opening' => [], 'errors' => []]);
            $comparison = [
                'items' => array_values(array_filter($result['items'], fn ($item) => ! empty($item['excel']))),
            ];
        }

        $item = $comparison['items'][0] ?? null;
        abort_if(! $item, 422, 'Baris tidak dapat dicocokkan ulang.');
        $status = $item['status'];
        $excel = $item['excel'] ?: $row;
        $importable = $status === 'only_excel';
        DB::table('solar_import_preview_rows')
            ->where('id', $rowId)
            ->where('batch_id', $batchId)
            ->where('business_id', $businessId)
            ->update([
                'raw_date' => $row['tanggal'],
                'normalized_date' => $row['tanggal'],
                'status' => $status,
                'matched_by' => $item['matched_by'] ?? 'edited',
                'differences' => json_encode(array_values($item['differences'] ?? []), JSON_UNESCAPED_UNICODE),
                'excel_payload' => json_encode($excel, JSON_UNESCAPED_UNICODE),
                'app_payload' => json_encode($item['app'] ?? null, JSON_UNESCAPED_UNICODE),
                'is_importable' => $importable,
                'updated_at' => now(),
            ]);

        $summary = $this->previewService->summary($batchId, $businessId);
        DB::table('solar_import_batches')->where('id', $batchId)->where('business_id', $businessId)->update([
            'counts' => json_encode($summary),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'row' => [
            'id' => $rowId,
            'status' => $status,
            'importable' => $importable,
            'detail' => implode('; ', $item['differences'] ?? []),
            'excel' => $excel,
        ], 'summary' => $summary]);
    }

    public function rollbackImport(Request $request, string $batchId)
    {
        $businessId = $this->authorizeSolar($request);
        $batch = DB::table('solar_import_batches')
            ->where('id', $batchId)
            ->where('business_id', $businessId)
            ->first();
        abort_if(! $batch, 404);

        if ($batch->status !== 'imported') {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Batch tersebut tidak dapat di-rollback pada status saat ini.'], 409);
            }
            return back()->with('status', ['success' => false, 'msg' => 'Batch tersebut tidak dapat di-rollback pada status saat ini.']);
        }

        $hasNewerImport = false;
        $newerBatchIds = DB::table('solar_import_batches')
            ->where('business_id', $businessId)
            ->where('status', 'imported')
            ->where('created_at', '>', $batch->created_at)
            ->pluck('id');
        foreach (SolarLedgerService::LEDGERS as $ledger) {
            if (DB::table($this->ledgerService->table($ledger))
                ->where('business_id', $businessId)
                ->whereIn('import_batch_id', $newerBatchIds)
                ->exists()) {
                $hasNewerImport = true;
                break;
            }
        }
        if (! $hasNewerImport && DB::table('solar_equipment_usages')
            ->where('business_id', $businessId)
            ->whereIn('import_batch_id', $newerBatchIds)
            ->exists()) {
            $hasNewerImport = true;
        }
        if ($hasNewerImport) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Rollback harus dimulai dari batch import yang paling baru.'], 409);
            }
            return back()->with('status', ['success' => false, 'msg' => 'Rollback harus dimulai dari batch import yang paling baru.']);
        }

        DB::transaction(function () use ($businessId, $batch) {
            foreach (SolarLedgerService::LEDGERS as $ledger) {
                DB::table($this->ledgerService->table($ledger))
                    ->where('business_id', $businessId)
                    ->where('import_batch_id', $batch->id)
                    ->delete();
                $this->ledgerService->recalculate($businessId, $ledger);
            }

            DB::table('solar_equipment_usages')
                ->where('business_id', $businessId)
                ->where('import_batch_id', $batch->id)
                ->delete();

            $snapshot = json_decode((string) ($batch->opening_snapshot ?? '[]'), true) ?: [];
            $this->ledgerService->restoreOpeningSnapshot($businessId, $snapshot);
            foreach (SolarLedgerService::LEDGERS as $ledger) {
                $this->ledgerService->recalculate($businessId, $ledger);
            }

            DB::table('solar_import_batches')->where('id', $batch->id)->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);
        });
        app(SolarImportHistoryService::class)->syncFromDomainBatch($batchId, $businessId);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Import Solar berhasil di-rollback.']);
        }

        return back()->with('status', ['success' => true, 'msg' => 'Import Solar berhasil di-rollback.']);
    }

    public function store(Request $request, string $ledger)
    {
        $businessId = $this->authorizeSolar($request);
        $payload = $this->validatedPayload($request, $ledger, $businessId);

        if ($ledger === 'usage') {
            $this->assertUniqueUsage($businessId, $payload);
            DB::table('solar_equipment_usages')->insert($payload + [
                'business_id' => $businessId,
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->successResponse($request, 'Pemakaian Solar berhasil ditambahkan.');
        }

        DB::transaction(function () use ($businessId, $ledger, $payload) {
            DB::table($this->ledgerService->table($ledger))->insert($payload + [
                'business_id' => $businessId,
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->ledgerService->recalculate($businessId, $ledger);
        });

        return $this->successResponse($request, 'Transaksi Solar berhasil ditambahkan.');
    }

    public function update(Request $request, string $ledger, int $id)
    {
        $businessId = $this->authorizeSolar($request);
        $payload = $this->validatedPayload($request, $ledger, $businessId);

        if ($ledger === 'usage') {
            $query = DB::table('solar_equipment_usages')->where('business_id', $businessId)->where('id', $id);
            abort_if(! $query->exists(), 404);
            $this->assertUniqueUsage($businessId, $payload, $id);
            $query->update($payload + ['updated_at' => now()]);
            return $this->successResponse($request, 'Pemakaian Solar berhasil diperbarui.');
        }

        DB::transaction(function () use ($businessId, $ledger, $id, $payload) {
            $updated = DB::table($this->ledgerService->table($ledger))
                ->where('business_id', $businessId)
                ->where('id', $id)
                ->update($payload + [
                    'source' => 'manual',
                    'source_hash' => null,
                    'source_key' => null,
                    'import_batch_id' => null,
                    'updated_at' => now(),
                ]);

            abort_if($updated === 0, 404);
            $this->ledgerService->recalculate($businessId, $ledger);
        });

        return $this->successResponse($request, 'Transaksi Solar berhasil diperbarui.');
    }

    public function destroy(Request $request, string $ledger, int $id)
    {
        $businessId = $this->authorizeSolar($request);

        if ($ledger === 'usage') {
            $deleted = DB::table('solar_equipment_usages')->where('business_id', $businessId)->where('id', $id)->delete();
            abort_if($deleted === 0, 404);
            return $this->successResponse($request, 'Pemakaian Solar berhasil dihapus.');
        }

        DB::transaction(function () use ($businessId, $ledger, $id) {
            $deleted = DB::table($this->ledgerService->table($ledger))
                ->where('business_id', $businessId)
                ->where('id', $id)
                ->delete();

            abort_if($deleted === 0, 404);
            $this->ledgerService->recalculate($businessId, $ledger);
        });

        return $this->successResponse($request, 'Transaksi Solar berhasil dihapus.');
    }

    public function template(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        $workbook = $this->workbookService->buildTemplate($this->customerReferences($businessId));

        return $this->downloadWorkbook($workbook, 'template-stok-solar.xlsx');
    }

    public function usageTemplate(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $names = DB::table('assets')->where('business_id', $businessId)->orderBy('name')->pluck('name')->all();

        return $this->downloadWorkbook(
            $this->workbookService->buildUsageTemplate($month, $names),
            "template-pemakaian-solar-{$month}.xlsx"
        );
    }

    public function export(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $rows = [];

        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $table = $this->ledgerService->table($ledger);
            $query = DB::table($table)
                ->where("{$table}.business_id", $businessId)
                ->whereBetween("{$table}.tanggal", [$dateFrom, $dateTo])
                ->orderBy("{$table}.tanggal")
                ->orderBy("{$table}.id");

            if ($ledger !== 'stock') {
                $query->leftJoin('contacts', function ($join) use ($table, $businessId) {
                    $join->on("{$table}.customer_id", '=', 'contacts.id')
                        ->where('contacts.business_id', $businessId);
                })->select("{$table}.*", DB::raw("COALESCE(NULLIF(TRIM(contacts.supplier_business_name), ''), NULLIF(TRIM(contacts.name), ''), NULLIF(TRIM(contacts.contact_id), ''), NULLIF(TRIM(contacts.mobile), ''), NULLIF(TRIM(contacts.email), ''), CONCAT('Customer #', contacts.id)) as customer_name"));
            }

            $rows[$ledger] = $query->get()->all();
        }

        $workbook = $this->workbookService->buildExport(
            $rows,
            $this->ledgerService->balancesBefore($businessId, $dateFrom),
            $this->customerReferences($businessId)
        );

        return $this->downloadWorkbook($workbook, "stok-solar-{$dateFrom}-{$dateTo}.xlsx");
    }

    public function usageExport(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth()->format('Y-m-d');
        $end = Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->format('Y-m-d');
        $rows = DB::table('solar_equipment_usages')
            ->where('business_id', $businessId)
            ->whereBetween('usage_date', [$start, $end])
            ->orderBy('equipment_name')
            ->orderBy('usage_date')
            ->get()
            ->all();

        return $this->downloadWorkbook(
            $this->workbookService->buildUsageExport($month, $rows),
            "pemakaian-solar-{$month}.xlsx"
        );
    }

    public function dailyReport(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->format('Y-m-d');

        return view('solar.daily-report', [
            'reportDate' => $date,
            'balances' => $this->ledgerService->balancesAtEndOfDate($businessId, $date),
        ]);
    }

    public function reconcile(Request $request)
    {
        return $this->uploadPreview($request);
    }

    public function confirmImport(Request $request)
    {
        $validated = $request->validate(['token' => ['required', 'uuid']]);
        return $this->confirmImportAjax($request, $validated['token']);
    }

    public function exportReconciliation(Request $request, string $token)
    {
        $businessId = $this->authorizeSolar($request);
        abort_if(! DB::table('solar_import_batches')->where('id', $token)->where('business_id', $businessId)->exists(), 404, 'Batch import tidak ditemukan.');
        $result = $this->previewService->result($token, $businessId);

        return $this->downloadWorkbook(
            $this->workbookService->buildReconciliation($result),
            'hasil-cross-check-stok-solar.xlsx'
        );
    }

    private function validatedPayload(Request $request, string $ledger, int $businessId): array
    {
        if ($ledger === 'usage') {
            return $this->validatedUsagePayload($request, $businessId);
        }
        $this->ledgerService->table($ledger);
        $common = [
            'tanggal' => ['required', 'date'],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'asset_id' => ['nullable', Rule::exists('assets', 'id')->where(fn ($query) => $query->where('business_id', $businessId))],
            'driver_id' => ['nullable', Rule::exists('users', 'id')->where(fn ($query) => $query->where('business_id', $businessId)->whereNull('deleted_at'))],
        ];
        $rules = $ledger === 'stock'
            ? $common + [
                'unit' => ['nullable', 'string', 'max:255'],
                'operator' => ['nullable', 'string', 'max:255'],
                'stock_in' => ['nullable', 'numeric', 'min:0'],
                'out_actual' => ['nullable', 'numeric', 'min:0'],
                'out_meteran' => ['nullable', 'numeric', 'min:0'],
            ]
            : $common + [
                'unit' => ['nullable', 'string', 'max:50'],
                'operator' => ['nullable', 'string', 'max:100'],
                'qty_in' => ['nullable', 'numeric', 'min:0'],
                'qty_out' => ['nullable', 'numeric', 'min:0'],
                'customer_id' => [
                    'nullable',
                    Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
                ],
                'nama_kapal' => ['nullable', 'string', 'max:255'],
                'lokasi' => ['nullable', 'string', 'max:255'],
            ];

        $validated = $request->validate($rules);
        if ($ledger === 'stock') {
            if ((float) ($validated['stock_in'] ?? 0) === 0.0
                && (float) ($validated['out_actual'] ?? 0) === 0.0
                && (float) ($validated['out_meteran'] ?? 0) === 0.0
                && trim((string) ($validated['keterangan'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'stock_in' => 'Isi nilai IN/OUT atau berikan keterangan untuk catatan tanpa pergerakan stok.',
                ]);
            }

            return [
                'tanggal' => $validated['tanggal'],
                'asset_id' => $validated['asset_id'] ?? null,
                'driver_id' => $validated['driver_id'] ?? null,
                'unit' => $this->referenceSnapshot($validated['unit'] ?? null, 'assets', $validated['asset_id'] ?? null),
                'operator' => $this->referenceSnapshot($validated['operator'] ?? null, 'users', $validated['driver_id'] ?? null),
                'stock_in' => (float) ($validated['stock_in'] ?? 0),
                'out_actual' => (float) ($validated['out_actual'] ?? 0),
                'stock_actual' => 0,
                'out_meteran' => (float) ($validated['out_meteran'] ?? 0),
                'stock_meteran' => 0,
                'keterangan' => $validated['keterangan'] ?? null,
            ];
        }

        $hasDescription = collect(['keterangan', 'unit', 'operator', 'asset_id', 'driver_id', 'customer_id', 'nama_kapal', 'lokasi'])
            ->contains(fn ($field) => trim((string) ($validated[$field] ?? '')) !== '');
        if ((float) ($validated['qty_in'] ?? 0) === 0.0
            && (float) ($validated['qty_out'] ?? 0) === 0.0
            && ! $hasDescription) {
            throw ValidationException::withMessages([
                'qty_in' => 'Isi nilai IN/OUT atau atribut/keterangan untuk catatan tanpa pergerakan stok.',
            ]);
        }

        $payload = [
            'tanggal' => $validated['tanggal'],
            'qty_in' => (float) ($validated['qty_in'] ?? 0),
            'qty_out' => (float) ($validated['qty_out'] ?? 0),
            'current_stock' => 0,
            'keterangan' => $validated['keterangan'] ?? null,
            'asset_id' => $validated['asset_id'] ?? null,
            'driver_id' => $validated['driver_id'] ?? null,
            'unit' => $this->referenceSnapshot($validated['unit'] ?? null, 'assets', $validated['asset_id'] ?? null),
            'operator' => $this->referenceSnapshot($validated['operator'] ?? null, 'users', $validated['driver_id'] ?? null),
            'customer_id' => $validated['customer_id'] ?? null,
        ];
        if ($ledger === 'mess') {
            $payload['nama_kapal'] = $validated['nama_kapal'] ?? null;
            $payload['lokasi'] = $validated['lokasi'] ?? null;
        }

        return $payload;
    }

    private function referenceSnapshot(?string $snapshot, string $table, ?int $id): ?string
    {
        $snapshot = trim((string) $snapshot);
        if ($snapshot !== '') {
            return $snapshot;
        }
        if ($id === null) {
            return null;
        }

        if ($table === 'assets') {
            return DB::table('assets')->where('id', $id)->value('name');
        }

        return DB::table('users')
            ->where('id', $id)
            ->selectRaw("TRIM(CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name")
            ->value('full_name');
    }

    private function validatedUsagePayload(Request $request, int $businessId): array
    {
        $validated = $request->validate([
            'tanggal' => ['required', 'date'],
            'equipment_name' => ['nullable', 'string', 'max:255'],
            'asset_id' => [
                'nullable',
                Rule::exists('assets', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'quantity_gln' => ['required', 'numeric', 'gt:0'],
        ]);
        $name = trim((string) ($validated['equipment_name'] ?? ''));
        if ($name === '' && ! empty($validated['asset_id'])) {
            $name = (string) DB::table('assets')->where('business_id', $businessId)->where('id', $validated['asset_id'])->value('name');
        }
        if ($name === '') {
            throw ValidationException::withMessages(['equipment_name' => 'Pilih Asset atau isi nama alat.']);
        }

        return [
            'usage_date' => Carbon::parse($validated['tanggal'])->format('Y-m-d'),
            'asset_id' => $validated['asset_id'] ?? null,
            'equipment_name' => $name,
            'quantity_gln' => (float) $validated['quantity_gln'],
        ];
    }

    private function assertUniqueUsage(int $businessId, array $payload, ?int $ignoreId = null): void
    {
        $query = DB::table('solar_equipment_usages')
            ->where('business_id', $businessId)
            ->where('usage_date', $payload['usage_date']);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        $query->where(function ($builder) use ($payload) {
            if (! empty($payload['asset_id'])) {
                $builder->where('asset_id', $payload['asset_id']);
            }
            $builder->orWhereRaw('LOWER(TRIM(equipment_name)) = ?', [mb_strtolower(trim($payload['equipment_name']))]);
        });
        if ($query->exists()) {
            throw ValidationException::withMessages(['quantity_gln' => 'Pemakaian alat pada tanggal tersebut sudah ada. Edit baris yang sudah ada.']);
        }
    }

    private function importPayload(string $ledger, array $row): array
    {
        if ($ledger === 'stock') {
            return [
                'tanggal' => $row['tanggal'],
                'stock_in' => $row['stock_in'],
                'out_actual' => $row['out_actual'],
                'stock_actual' => 0,
                'out_meteran' => $row['out_meteran'],
                'stock_meteran' => 0,
                'keterangan' => $row['keterangan'],
            ];
        }

        $payload = [
            'tanggal' => $row['tanggal'],
            'qty_in' => $row['qty_in'],
            'qty_out' => $row['qty_out'],
            'current_stock' => 0,
            'keterangan' => $row['keterangan'],
            'unit' => $row['unit'] ?? null,
            'operator' => $row['operator'] ?? null,
            'customer_id' => $row['customer_id'] ?? null,
        ];
        if ($ledger === 'mess') {
            $payload['nama_kapal'] = $row['nama_kapal'] ?? null;
            $payload['lokasi'] = $row['lokasi'] ?? null;
        }

        return $payload;
    }

    private function customerReferences(int $businessId): array
    {
        return DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->get(['id', 'contact_id', 'name', 'supplier_business_name', 'mobile', 'email'])
            ->map(function ($customer) {
                return "C{$customer->id} | ".Contact::customerDisplayName($customer, false);
            })
            ->sortBy(fn ($reference) => $reference, SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'date1' => ['nullable', 'date'],
            'date2' => ['nullable', 'date', 'after_or_equal:date1'],
        ]);

        return [
            $validated['date1'] ?? now()->startOfMonth()->format('Y-m-d'),
            $validated['date2'] ?? now()->format('Y-m-d'),
        ];
    }

    private function authorizeSolar(Request $request): int
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $allowed = $request->user()
            && ($request->user()->can('superadmin') || $request->user()->can('solar.access'));
        abort_unless($businessId > 0 && $allowed, 403, 'Unauthorized action.');

        return $businessId;
    }

    private function successResponse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('status', ['success' => true, 'msg' => $message]);
    }

    private function downloadWorkbook(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook, string $fileName)
    {
        return response()->streamDownload(function () use ($workbook) {
            $writer = new Xlsx($workbook);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $workbook->disconnectWorksheets();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    private function cleanupImports(string $directory): void
    {
        $disk = Storage::disk('local');
        $referenced = DB::table('solar_import_batches')
            ->where('file_path', 'like', rtrim($directory, '/').'/%')
            ->get(['id', 'file_path', 'user_import_batch_id', 'process_state'])
            ->keyBy('file_path');
        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) >= now()->subDay()->timestamp) {
                continue;
            }
            $batch = $referenced->get($file);
            if ($batch && in_array($batch->process_state, ['queued', 'processing'], true)) {
                continue;
            }

            if ($disk->exists($file) && ! $disk->delete($file)) {
                \Illuminate\Support\Facades\Log::warning('Expired Solar staging file could not be deleted.', [
                    'path' => $file,
                    'batch_id' => $batch->id ?? null,
                ]);
                continue;
            }
            if ($batch) {
                DB::table('solar_import_batches')->where('id', $batch->id)->update(['file_path' => null, 'updated_at' => now()]);
                if ($batch->user_import_batch_id) {
                    DB::table('user_import_batches')->where('id', $batch->user_import_batch_id)->update(['staging_path' => null, 'updated_at' => now()]);
                }
            }
        }
    }
}
