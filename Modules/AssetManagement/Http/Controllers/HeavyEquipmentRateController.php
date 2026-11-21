<?php

namespace Modules\AssetManagement\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\HeavyEquipmentRateBatch;
use Modules\AssetManagement\Entities\HeavyEquipmentRateUnit;
use Modules\AssetManagement\Entities\HeavyEquipmentRoute;
use Modules\AssetManagement\Entities\HeavyEquipmentRouteRate;
use Modules\AssetManagement\Jobs\AnalyzeHeavyEquipmentRateImportJob;
use Modules\AssetManagement\Jobs\ProcessHeavyEquipmentRateImportJob;
use Modules\AssetManagement\Services\HeavyEquipmentRateImportHistoryService;
use Modules\AssetManagement\Services\HeavyEquipmentRateService;
use App\Services\UserImportBatchService;
use App\UserImportBatch;

class HeavyEquipmentRateController extends Controller
{
    public function __construct(
        private HeavyEquipmentRateService $rateService,
        private HeavyEquipmentRateImportHistoryService $importHistoryService
    )
    {
    }

    public function index()
    {
        $this->authorizeView();
        $businessId = $this->businessId();
        $hasBatchIssues = HeavyEquipmentRateBatch::where('business_id', $businessId)
            ->where('status', 'draft')->whereNotNull('error_message')->get(['error_message'])
            ->contains(fn (HeavyEquipmentRateBatch $batch) => $this->parseBatchIssues($batch->error_message) !== []);
        $hasPreviewIssues = $hasBatchIssues || HeavyEquipmentRateBatch::where('business_id', $businessId)
            ->where('status', 'draft')
            ->whereHas('rates.units', fn ($units) => $units->whereNull('asset_id'))
            ->exists();

        return view('assetmanagement::fleet.rates.index', compact('hasPreviewIssues'));
    }

    /**
     * Return the transport-rate table through DataTables server-side AJAX.
     * The initial page must not render every rate and every Asset option.
     */
    public function data(Request $request)
    {
        $this->authorizeView();

        $query = HeavyEquipmentRouteRate::query()
            ->where('business_id', $this->businessId())
            ->whereIn('status', ['active', 'expired', 'draft']);
        $recordsTotal = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder
                    ->where('notes', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('route', function ($routeQuery) use ($like) {
                        $routeQuery->where('name', 'like', $like)
                            ->orWhere('origin', 'like', $like)
                            ->orWhere('destination', 'like', $like);
                    })
                    ->orWhereHas('units', function ($unitQuery) use ($like) {
                        $unitQuery->where('unit_label', 'like', $like)
                            ->orWhereHas('asset', function ($assetQuery) use ($like) {
                                $assetQuery->where('name', 'like', $like)
                                    ->orWhere('asset_code', 'like', $like);
                            });
                    });
            });
        }

        $recordsFiltered = (clone $query)->count();
        $orderColumn = (int) $request->input('order.0.column', 2);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderMap = [
            0 => 'route_id',
            2 => 'effective_from',
            3 => 'driver_rate_per_ton',
            4 => 'travel_allowance',
            5 => 'sale_rate_per_ton',
            7 => 'status',
        ];
        $orderBy = $orderMap[$orderColumn] ?? 'effective_from';
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 25);
        $length = $length < 1 ? 100 : min($length, 100);

        $rates = $query
            ->with(['route', 'units.asset', 'batch'])
            ->withCount('trips')
            ->orderBy($orderBy, $orderDirection)
            ->orderBy('route_id')
            ->skip($start)
            ->take($length)
            ->get();
        $canManageRates = $this->canManageRates();

        $data = $rates->map(function (HeavyEquipmentRouteRate $rate) use ($canManageRates) {
            $rowIssue = $this->rateIssueData($rate);
            $columns = [];
            foreach (['route', 'unit', 'effective', 'driver_rate', 'travel_allowance', 'sale_rate', 'notes', 'status', 'actions'] as $column) {
                $columns[] = view('assetmanagement::fleet.rates.partials.rate-cell', [
                    'rate' => $rate,
                    'column' => $column,
                    'rowIssue' => $rowIssue,
                    'canManageRates' => $canManageRates,
                ])->render();
            }

            return $columns;
        })->values();

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * Search Asset options only when a user opens the multi-select.
     */
    public function assetOptions(Request $request)
    {
        $this->authorizeView();

        $search = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $like = '%' . addcslashes($search, '%_\\') . '%';

        $assets = Asset::query()
            ->where('business_id', $this->businessId())
            ->when($search !== '', function ($query) use ($like) {
                $query->where(function ($builder) use ($like) {
                    $builder->where('name', 'like', $like)->orWhere('asset_code', 'like', $like);
                });
            })
            ->orderBy('name')
            ->paginate($perPage, ['id', 'name', 'asset_code'], 'page', $page);

        return response()->json([
            'results' => $assets->getCollection()->map(function (Asset $asset) {
                return [
                    'id' => $asset->id,
                    'text' => $asset->name . ($asset->asset_code ? ' (' . $asset->asset_code . ')' : ''),
                ];
            })->values(),
            'pagination' => ['more' => $assets->hasMorePages()],
        ]);
    }

    /**
     * Return draft-import errors and unmatched units through DataTables AJAX.
     * Error messages are stored as batch JSON, so filtering/pagination is kept
     * in this endpoint instead of making the initial page render every row.
     */
    public function issuesData(Request $request)
    {
        $this->authorizeView();
        $businessId = $this->businessId();

        $batches = HeavyEquipmentRateBatch::query()
            ->where('business_id', $businessId)
            ->where('status', 'draft')
            ->get(['id', 'file_name', 'error_message']);
        $batchNames = $batches->pluck('file_name', 'id');
        $issues = collect();

        foreach ($batches as $batch) {
            foreach ($this->parseBatchIssues($batch->error_message) as $issue) {
                $issues->push([
                    'batch_id' => $batch->id,
                    'file_name' => $batch->file_name,
                    'row' => $issue['row'] ?? null,
                    'message' => $issue['message'],
                ]);
            }
        }

        $draftRates = HeavyEquipmentRouteRate::query()
            ->where('business_id', $businessId)
            ->where('status', 'draft')
            ->with('units')
            ->get(['id', 'batch_id', 'source_row']);

        foreach ($draftRates as $rate) {
            foreach ($rate->units->whereNull('asset_id') as $unit) {
                $issues->push([
                    'batch_id' => $rate->batch_id,
                    'file_name' => $batchNames->get($rate->batch_id, 'Tarif manual'),
                    'row' => $rate->source_row,
                    'message' => "Unit '{$unit->unit_label}' belum dicocokkan ke master Asset. Pilih Asset pada kolom Unit.",
                ]);
            }
        }

        $issues = $issues->unique(function (array $issue) {
            return ($issue['batch_id'] ?? 'batch') . '|' . ($issue['row'] ?? 'general') . '|' . $issue['message'];
        })->values();
        $recordsTotal = $issues->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $issues = $issues->filter(function (array $issue) use ($needle) {
                return str_contains(mb_strtolower((string) $issue['batch_id']), $needle)
                    || str_contains(mb_strtolower((string) $issue['file_name']), $needle)
                    || str_contains(mb_strtolower((string) ($issue['row'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) $issue['message']), $needle);
            })->values();
        }

        $recordsFiltered = $issues->count();
        $issues = $issues->sortBy(function (array $issue) {
            return [(int) ($issue['row'] ?? PHP_INT_MAX), (int) ($issue['batch_id'] ?? 0), $issue['message']];
        })->values();
        if (strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc') {
            $issues = $issues->reverse()->values();
        }

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $length = $length < 1 ? 100 : min($length, 100);
        $pageIssues = $issues->slice($start, $length);

        $data = $pageIssues->map(function (array $issue) {
            $rowLabel = $issue['row'] ? 'Baris ' . $issue['row'] : 'Umum';

            return [
                '#' . e($issue['batch_id']) . '<br><small>' . e($issue['file_name']) . '</small>',
                e($rowLabel),
                e($issue['message']),
                'Periksa baris tersebut di Excel. Jika masalahnya unit, pilih Asset yang sesuai pada tabel tarif sebelum konfirmasi.',
            ];
        })->values();

        return response()->json([
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
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

        try {
            $validated = $this->validateRate($request);
            $rate = $this->saveRate($validated);

            return $this->respond($request, true, "Tarif manual #{$rate->id} berhasil dibuat dan langsung aktif.", [
                'rate_id' => $rate->id,
            ], 200, 'asset.fleet.rates.index');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'msg' => $e->getMessage() ?: 'Periksa data tarif.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            report($e);

            return $this->respond($request, false, 'Tarif belum disimpan. Periksa data dan coba lagi.', [], 422, 'asset.fleet.rates.index');
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
            return $this->respond($request, false, 'Tarif yang sudah dibatalkan tidak dapat diedit. Buat tarif atau revisi baru.', [], 422);
        }
        if ($rate->trips->isNotEmpty()) {
            return $this->respond($request, false, 'Tarif yang sudah dipakai SPB tidak dapat diedit. Gunakan Buat Revisi Tarif.', [], 422);
        }
        try {
            $validated = $this->validateRate($request);
            $this->saveRate($validated, $rate);

            return $this->respond($request, true, "Tarif #{$rate->id} berhasil diperbarui.", [
                'rate_id' => $rate->id,
            ], 200, 'asset.fleet.rates.index');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'msg' => $e->getMessage() ?: 'Periksa data tarif.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withInput()->withErrors($e->errors());
        } catch (\Throwable $e) {
            report($e);

            return $this->respond($request, false, 'Tarif belum diperbarui. Periksa data dan coba lagi.', [], 422, 'asset.fleet.rates.index');
        }
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeManage();
        $rate = $this->rate($id)->load(['trips', 'batch']);
        if ($rate->status === 'cancelled') {
            return $this->respond($request, false, 'Tarif tersebut sudah dibatalkan.', [], 422);
        }
        if ($rate->trips->isNotEmpty()) {
            return $this->respond($request, false, 'Tarif tidak dapat dihapus karena sudah dipakai SPB. Histori transaksi harus dipertahankan; gunakan Buat Revisi Tarif.', [], 422);
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

            return $this->respond($request, true, 'Tarif hasil import dibatalkan dan tetap tersimpan sebagai histori sumber.');
        }

        DB::transaction(function () use ($rate) {
            $batch = $rate->batch;
            $rate->delete();

            if ($batch && str_starts_with((string) $batch->file_name, 'Manual - ') && ! $batch->rates()->exists()) {
                $batch->delete();
            }
        });

        return $this->respond($request, true, 'Tarif berhasil dihapus.');
    }

    public function upload(Request $request)
    {
        $this->authorizeManage();
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
            'effective_from' => 'required|date',
        ]);

        $batch = null;
        try {
            $batch = $this->importHistoryService->createUploadBatch(
                $request->file('file'),
                Carbon::parse($validated['effective_from'])->toDateString(),
                $this->businessId(),
                (int) auth()->id()
            );
            AnalyzeHeavyEquipmentRateImportJob::dispatch((int) $batch->id);

            return response()->json([
                'success' => true,
                'queued' => true,
                'batch_id' => (int) $batch->id,
                'status' => $batch->status,
                'msg' => 'Upload diterima. Analisis workbook tarif berjalan di latar belakang.',
            ], 202);
        } catch (\Throwable $e) {
            report($e);
            if ($batch) {
                $this->importHistoryService->markPreviewFailed((int) $batch->id, $e->getMessage());
                return response()->json([
                    'success' => false,
                    'batch_id' => (int) $batch->id,
                    'msg' => 'Upload tersimpan, tetapi analisis tidak dapat dimasukkan ke antrean.',
                ], 422);
            }

            return $this->respond($request, false, $this->friendlyRateExceptionMessage($e), [], 422, 'asset.fleet.rates.index');
        }
    }

    public function confirm(Request $request, $id)
    {
        $this->authorizeManage();
        $domain = HeavyEquipmentRateBatch::where('business_id', $this->businessId())->findOrFail($id);
        $shared = $this->importHistoryService->ensureHistoryEnvelope($domain);

        return $this->queueConfirm($shared->id, $request);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeManage();
        $batch = HeavyEquipmentRateBatch::where('business_id', $this->businessId())->findOrFail($id);

        try {
            $this->rateService->cancel($batch);
            $this->importHistoryService->syncFromDomainBatch((int) $batch->id);

            return $this->respond($request, true, 'Batch tarif dibatalkan.');
        } catch (\Throwable $e) {
            report($e);

            return $this->respond($request, false, $e->getMessage(), [], 422);
        }
    }

    public function importBatchHistory(Request $request)
    {
        $this->authorizeView();

        return response()->json($this->importHistoryService->dataTable(
            $request,
            $this->businessId(),
            $this->canManageRates()
        ));
    }

    public function importBatchDetails(int $id)
    {
        $this->authorizeView();

        return response()->json($this->importHistoryService->details($id, $this->businessId()));
    }

    public function downloadImportBatch(int $id)
    {
        $this->authorizeView();
        $batch = app(UserImportBatchService::class)->findForBusiness(
            $id,
            $this->businessId(),
            HeavyEquipmentRateImportHistoryService::IMPORTER_KEY
        );
        abort_if(! $batch->hasSourceSnapshot() || ! Storage::disk('local')->exists($batch->source_snapshot_path), 404, 'Snapshot workbook belum tersedia untuk batch lama ini.');

        return app(UserImportBatchService::class)->workbookDownload($batch);
    }

    public function confirmImportBatch(Request $request, int $id)
    {
        $this->authorizeManage();

        return $this->queueConfirm($id, $request);
    }

    public function retryImportBatch(int $id)
    {
        $this->authorizeManage();
        $batch = app(UserImportBatchService::class)->findForBusiness(
            $id,
            $this->businessId(),
            HeavyEquipmentRateImportHistoryService::IMPORTER_KEY
        );
        $domain = $this->importHistoryService->domainBatchForSharedBatch($id, $this->businessId());
        if ($batch->status !== 'failed' || $domain->status !== 'draft'
            || ! $batch->preview_path || ! Storage::disk('local')->exists($batch->preview_path)
            || ! $batch->hasSourceSnapshot() || ! Storage::disk('local')->exists($batch->source_snapshot_path)) {
            return response()->json(['success' => false, 'msg' => 'Retry hanya tersedia untuk aktivasi batch gagal yang masih berstatus draft dan memiliki preview serta snapshot.'], 409);
        }

        try {
            DB::transaction(function () use ($id) {
                $locked = UserImportBatch::where('business_id', $this->businessId())
                    ->where('importer_key', HeavyEquipmentRateImportHistoryService::IMPORTER_KEY)
                    ->lockForUpdate()->find($id);
                $domain = HeavyEquipmentRateBatch::where('business_id', $this->businessId())
                    ->where('user_import_batch_id', $id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'failed' || ! $domain || $domain->status !== 'draft'
                    || ! $locked->preview_path || ! Storage::disk('local')->exists($locked->preview_path)
                    || ! $locked->source_snapshot_path || ! Storage::disk('local')->exists($locked->source_snapshot_path)) {
                    throw new \InvalidArgumentException('Batch sudah berubah status atau tidak lagi memenuhi syarat retry.');
                }
                $locked->forceFill([
                    'status' => 'queued_import',
                    'stage' => 'queued',
                    'progress_current' => 0,
                    'progress_total' => max(1, (int) $domain->source_row_count),
                    'progress_percent' => 0,
                    'last_message' => 'Retry aktivasi diterima. Menunggu worker queue imports.',
                    'error_message' => null,
                    'failed_at' => null,
                    'completed_at' => null,
                    'heartbeat_at' => now(),
                ])->save();
            });

            ProcessHeavyEquipmentRateImportJob::dispatch($id);

            return response()->json(['success' => true, 'queued' => true, 'batch_id' => $id, 'msg' => 'Retry aktivasi batch tarif diterima.'], 202);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 409);
        } catch (\Throwable $exception) {
            report($exception);
            UserImportBatch::where('business_id', $this->businessId())->whereKey($id)->where('status', 'queued_import')->update([
                'status' => 'failed', 'stage' => 'failed', 'last_message' => 'Retry tidak dapat dimasukkan ke antrean.',
                'error_message' => $exception->getMessage(), 'failed_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['success' => false, 'msg' => 'Retry tidak dapat dimasukkan ke antrean import.'], 409);
        }
    }

    public function cancelImportBatch(int $id)
    {
        $this->authorizeManage();
        $domain = $this->importHistoryService->domainBatchForSharedBatch($id, $this->businessId());
        if ($domain->status !== 'draft') {
            return response()->json(['success' => false, 'msg' => 'Hanya draft tarif yang dapat dibatalkan.'], 409);
        }

        try {
            $batch = $this->importHistoryService->cancelOrRevert($id, $this->businessId());

            return response()->json(['success' => true, 'status' => $batch->status, 'msg' => 'Draft batch tarif dibatalkan.']);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 409);
        }
    }

    public function revertImportBatch(int $id)
    {
        $this->authorizeManage();
        $batch = app(UserImportBatchService::class)->findForBusiness(
            $id,
            $this->businessId(),
            HeavyEquipmentRateImportHistoryService::IMPORTER_KEY
        );
        if ($batch->status !== 'imported') {
            return response()->json(['success' => false, 'msg' => 'Batch belum berstatus imported.'], 409);
        }

        try {
            $updated = $this->importHistoryService->cancelOrRevert($id, $this->businessId());

            return response()->json(['success' => true, 'status' => $updated->status, 'msg' => 'Batch tarif berhasil di-revert.']);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 409);
        }
    }

    private function queueConfirm(int $id, Request $request)
    {
        $batch = app(UserImportBatchService::class)->findForBusiness(
            $id,
            $this->businessId(),
            HeavyEquipmentRateImportHistoryService::IMPORTER_KEY
        );
        $domain = $this->importHistoryService->domainBatchForSharedBatch($id, $this->businessId());
        if ($batch->status !== 'preview_ready') {
            return response()->json(['success' => false, 'msg' => 'Batch tarif tidak lagi siap dikonfirmasi. Muat ulang riwayat import.'], 409);
        }

        try {
            DB::transaction(function () use ($id, $domain) {
                $locked = UserImportBatch::where('business_id', $this->businessId())
                    ->where('importer_key', HeavyEquipmentRateImportHistoryService::IMPORTER_KEY)
                    ->lockForUpdate()->find($id);
                $domain = HeavyEquipmentRateBatch::where('business_id', $this->businessId())
                    ->where('user_import_batch_id', $id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'preview_ready' || ! $domain || $domain->status !== 'draft') {
                    throw new \InvalidArgumentException('Konfirmasi sudah dikirim atau batch tidak lagi berstatus draft.');
                }
                if (! $domain->rates()->where('status', '!=', 'cancelled')->exists()) {
                    throw new \InvalidArgumentException('Batch tarif tidak memiliki baris yang dapat diaktifkan.');
                }
                $locked->forceFill([
                    'status' => 'queued_import',
                    'stage' => 'queued',
                    'progress_current' => 0,
                    'progress_total' => max(1, (int) $domain->source_row_count),
                    'progress_percent' => 0,
                    'last_message' => 'Konfirmasi diterima. Menunggu worker queue imports.',
                    'heartbeat_at' => now(),
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                ])->save();
            });

            ProcessHeavyEquipmentRateImportJob::dispatch($id);

            return response()->json([
                'success' => true,
                'queued' => true,
                'batch_id' => $id,
                'msg' => 'Konfirmasi batch tarif diterima dan berjalan di latar belakang.',
            ], 202);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'msg' => $exception->getMessage()], 409);
        } catch (\Throwable $exception) {
            report($exception);
            UserImportBatch::where('business_id', $this->businessId())->whereKey($id)->where('status', 'queued_import')->update([
                'status' => 'failed', 'stage' => 'failed', 'last_message' => 'Batch tidak dapat dimasukkan ke antrean import.',
                'error_message' => $exception->getMessage(), 'failed_at' => now(), 'updated_at' => now(),
            ]);
            $this->importHistoryService->syncFromDomainBatch((int) $domain->id);

            return response()->json(['success' => false, 'msg' => 'Batch tidak dapat dimasukkan ke antrean import.'], 409);
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

        return $this->respond($request, true, "Unit {$unit->unit_label} berhasil dicocokkan ke Asset.");
    }

    public function mapUnits(Request $request, $id)
    {
        $this->authorizeManage();
        $validated = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'asset_ids.required' => 'Pilih minimal satu Asset.',
            'asset_ids.min' => 'Pilih minimal satu Asset.',
            'asset_ids.*.distinct' => 'Asset yang sama tidak boleh dipilih dua kali.',
        ]);

        $assetIds = array_values(array_map('intval', $validated['asset_ids']));
        $businessId = $this->businessId();
        $assets = Asset::where('business_id', $businessId)
            ->whereIn('id', $assetIds)
            ->get(['id', 'name', 'asset_code'])
            ->keyBy('id');

        if ($assets->count() !== count($assetIds)) {
            return $this->respond($request, false, 'Sebagian Asset tidak berasal dari business aktif. Pilih ulang Asset yang tersedia.', [], 422);
        }

        try {
            $mappingLabels = [];
            DB::transaction(function () use ($id, $businessId, $assetIds, $assets, &$mappingLabels) {
                $rate = HeavyEquipmentRouteRate::where('business_id', $businessId)
                    ->where('status', 'draft')
                    ->findOrFail($id);
                $units = HeavyEquipmentRateUnit::where('route_rate_id', $rate->id)
                    ->whereNull('asset_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($units->isEmpty()) {
                    throw new \RuntimeException('Semua unit pada baris ini sudah dicocokkan atau baris tidak lagi berstatus draft.');
                }
                if (count($assetIds) > $units->count()) {
                    throw new \RuntimeException('Pilih maksimal ' . $units->count() . ' Asset, sesuai jumlah unit yang belum cocok.');
                }

                $usedAssetIds = HeavyEquipmentRateUnit::where('route_rate_id', $rate->id)
                    ->whereNotNull('asset_id')
                    ->whereIn('asset_id', $assetIds)
                    ->pluck('asset_id')
                    ->all();
                if ($usedAssetIds !== []) {
                    throw new \RuntimeException('Salah satu Asset yang dipilih sudah dipakai oleh unit lain pada baris tarif ini.');
                }

                foreach ($assetIds as $index => $assetId) {
                    $unit = $units[$index];
                    $asset = $assets->get($assetId);
                    $unit->update(['asset_id' => $assetId]);
                    $mappingLabels[] = $unit->unit_label . ' → ' . $asset->name;
                }
            });

            return $this->respond($request, true, count($mappingLabels) . ' unit berhasil dicocokkan ke Asset: ' . implode(', ', $mappingLabels));
        } catch (\Throwable $e) {
            report($e);

            return $this->respond($request, false, $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Pemetaan Asset belum berhasil. Muat ulang preview lalu coba lagi.', [], 422);
        }
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

    private function rateIssueData(HeavyEquipmentRouteRate $rate): array
    {
        $issues = collect($this->parseBatchIssues(optional($rate->batch)->error_message))
            ->filter(fn (array $issue) => (int) ($issue['row'] ?? 0) === (int) $rate->source_row)
            ->pluck('message')
            ->all();

        foreach ($rate->units as $unit) {
            if (! $unit->asset_id) {
                $issues[] = "Unit '{$unit->unit_label}' belum dicocokkan ke master Asset. Pilih Asset pada kolom Unit.";
            }
        }

        return [
            'errors' => array_values(array_unique($issues)),
            'warnings' => $rate->sale_rate_per_ton === null
                ? ['Harga/Ton belum diisi; tarif ini belum siap dipakai untuk tagihan pelanggan.']
                : [],
        ];
    }

    private function canManageRates(): bool
    {
        return auth()->user()->can('superadmin')
            || auth()->user()->can('asset.fleet_rates.manage')
            || auth()->user()->can('asset.create')
            || auth()->user()->can('asset.update');
    }

    private function parseBatchIssues(?string $errorMessage): array
    {
        if (! $errorMessage) {
            return [];
        }

        $errors = json_decode($errorMessage, true);
        $errors = is_array($errors) ? $errors : [$errorMessage];

        return collect($errors)
            ->filter(fn ($error) => is_string($error) && trim($error) !== '')
            ->map(function (string $error) {
                $row = null;
                $detail = trim($error);
                if (preg_match('/^Baris\s+(\d+):\s*(.+)$/u', $detail, $matches)) {
                    $row = (int) $matches[1];
                    $detail = $matches[2];
                }

                return [
                    'row' => $row,
                    'message' => $this->friendlyRateIssue($detail),
                    'raw' => $error,
                ];
            })
            ->unique(fn (array $issue) => ($issue['row'] ?? 'general') . '|' . $issue['message'])
            ->values()
            ->all();
    }

    private function friendlyRateIssue(string $message): string
    {
        $message = trim($message);
        $message = preg_replace(
            "/unit '([^']+)' belum cocok dengan Assets\./u",
            "Unit '$1' belum dicocokkan ke master Asset. Pilih Asset pada kolom Unit.",
            $message
        );

        return str_replace([
            'Rute dan Unit wajib diisi.',
            'Unit tidak valid.',
            'Borongan Supir/Ton dan Uang Jalan harus berupa angka.',
            'tarif tidak boleh negatif.',
            'tidak memiliki unit.',
        ], [
            'Lengkapi kolom Rute dan Unit pada file Excel.',
            'Isi Unit dengan nama unit yang valid.',
            'Isi Borongan Supir/Ton dan Uang Jalan dengan angka.',
            'Nilai tarif tidak boleh negatif.',
            'Baris ini belum memiliki unit.',
        ], $message);
    }

    private function friendlyRateExceptionMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return 'File tarif belum dapat diproses. Periksa format file dan coba lagi.';
        }

        $parts = preg_split('/\s+(?=Baris\s+\d+:|Duplikasi pada workbook:|Tarif sudah ada untuk rute)/u', $message);
        if (count($parts) > 1) {
            return 'File tarif belum dapat diproses. Periksa baris yang ditandai pada tabel preview.';
        }

        return mb_strlen($message) > 240 ? mb_substr($message, 0, 237) . '...' : $message;
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

    private function respond(Request $request, bool $success, string $message, array $extra = [], int $status = 200, ?string $redirectRoute = null)
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge([
                'success' => $success,
                'msg' => $message,
            ], $extra), $status);
        }

        $flash = array_merge([
            'success' => $success,
            'msg' => $message,
        ], $extra);

        return $redirectRoute
            ? redirect()->route($redirectRoute)->with('status', $flash)
            : back()->withInput()->with('status', $flash);
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
