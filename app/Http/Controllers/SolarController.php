<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSolarImport;
use App\Jobs\ProcessSolarPreview;
use App\Services\SolarImportProcessService;
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
        $rows = [];

        foreach (SolarLedgerService::LEDGERS as $ledger) {
            $table = $this->ledgerService->table($ledger);
            $query = DB::table($table)
                ->leftJoin('contacts', function ($join) use ($table, $businessId) {
                    $join->on("{$table}.customer_id", '=', 'contacts.id')
                        ->where('contacts.business_id', $businessId);
                })
                ->where("{$table}.business_id", $businessId)
                ->whereBetween("{$table}.tanggal", [$dateFrom, $dateTo])
                ->orderBy("{$table}.tanggal")
                ->orderBy("{$table}.id")
                ->select("{$table}.*", DB::raw('COALESCE(contacts.supplier_business_name, contacts.name) as customer_name'));

            if ($ledger === 'stock') {
                $query = DB::table($table)
                    ->where('business_id', $businessId)
                    ->whereBetween('tanggal', [$dateFrom, $dateTo])
                    ->orderBy('tanggal')
                    ->orderBy('id');
            }

            $rows[$ledger] = $query->get();
        }

        $customers = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->select('id', DB::raw('COALESCE(supplier_business_name, name) as display_name'))
            ->orderBy('display_name')
            ->get();

        return view('solar.index', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'rows' => $rows,
            'balances' => $this->ledgerService->currentBalances($businessId),
            'customers' => $customers,
            'importBatches' => DB::table('solar_import_batches')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function importHistory(Request $request)
    {
        $businessId = $this->authorizeSolar($request);

        return view('solar.import-history', [
            'batches' => DB::table('solar_import_batches')
                ->where('business_id', $businessId)
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
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

        return view('solar.import-detail', compact('batch', 'rows'));
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
            && in_array($batch->process_state ?: 'completed', ['completed', 'failed'], true),
            409,
            'Preview belum selesai.'
        );

        return view('solar.reconcile', [
            'token' => $batchId,
            'summary' => $this->previewService->summary($batchId, $businessId),
        ]);
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
        $columns = ['ledger', 'source_row', 'normalized_date', 'status', 'matched_by', 'id'];
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($columns[$orderIndex] ?? 'id', $orderDirection);
        $length = min(100, max(1, (int) $request->input('length', 25)));
        $start = max(0, (int) $request->input('start', 0));

        $rows = $query->offset($start)->limit($length)->get();
        $data = $rows->map(function ($row) {
            $differences = json_decode((string) $row->differences, true) ?: [];
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
                'source_row' => $row->source_row ?: '—',
                'raw_date' => $row->raw_date ?: '—',
                'date' => $row->normalized_date ? Carbon::parse($row->normalized_date)->format('d/m/Y') : '—',
                'status' => $row->status,
                'status_label' => $label,
                'matched_by' => $row->matched_by ?: '—',
                'detail' => implode('; ', $differences),
                'can_edit_date' => $row->status === 'error' && str_contains(implode(' ', $differences), 'Tanggal'),
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

            $this->processService->queued($batchId, $businessId, 'import', (int) $this->previewService->summary($batchId, $businessId)['only_excel']);

            return $batch;
        });
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

        return response()->json([
            'success' => $cancelled,
            'message' => $cancelled ? 'Proses dibatalkan.' : 'Proses sudah berjalan atau selesai.',
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

            $operation = $batch->operation ?: 'preview';
            $total = $operation === 'import'
                ? (int) $this->previewService->summary($batchId, $businessId)['only_excel']
                : 0;
            $this->processService->queued($batchId, $businessId, $operation, $total);

            return [$batch, $operation];
        });
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
        $path = $batch->file_path ?: $request->session()->get("solar_imports.{$batchId}");
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404, 'File preview sudah tidak tersedia.');

        $this->previewService->rebuild($businessId, $batchId, Storage::disk('local')->path($path), $overrides);
        DB::table('solar_import_batches')->where('id', $batchId)->update([
            'date_overrides' => json_encode($overrides),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'summary' => $this->previewService->summary($batchId, $businessId),
        ]);
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
        if ($hasNewerImport) {
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

        return back()->with('status', ['success' => true, 'msg' => 'Import Solar berhasil di-rollback.']);
    }

    public function store(Request $request, string $ledger)
    {
        $businessId = $this->authorizeSolar($request);
        $payload = $this->validatedPayload($request, $ledger, $businessId);

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
                })->select("{$table}.*", DB::raw('COALESCE(contacts.supplier_business_name, contacts.name) as customer_name'));
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

    public function reconcile(Request $request)
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
                'counts' => json_encode([]),
                'opening_snapshot' => json_encode($this->ledgerService->openingSnapshot($businessId)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $result = $this->previewService->rebuild(
                $businessId,
                $token,
                Storage::disk('local')->path($path)
            );
            DB::table('solar_import_batches')->where('id', $token)->update([
                'counts' => json_encode($this->previewService->summary($token, $businessId)),
                'updated_at' => now(),
            ]);
            $request->session()->put("solar_imports.{$token}", $path);

            return view('solar.reconcile', [
                'token' => $token,
                'summary' => $this->previewService->summary($token, $businessId),
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('status', ['success' => false, 'msg' => 'Workbook tidak dapat diproses. Periksa format file dan coba lagi.']);
        }
    }

    public function confirmImport(Request $request)
    {
        $businessId = $this->authorizeSolar($request);
        $validated = $request->validate(['token' => ['required', 'uuid']]);
        $path = $request->session()->get("solar_imports.{$validated['token']}");
        $batch = DB::table('solar_import_batches')
            ->where('id', $validated['token'])
            ->where('business_id', $businessId)
            ->where('status', 'preview')
            ->first();
        abort_if(! $batch, 404, 'Batch import tidak ditemukan atau sudah diproses.');
        $path = $batch->file_path ?: $path;
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404, 'File preview sudah tidak tersedia.');

        try {
            $overrides = json_decode((string) ($batch->date_overrides ?? '[]'), true) ?: [];
            $result = $this->previewService->rebuild(
                $businessId,
                $batch->id,
                Storage::disk('local')->path($path),
                $overrides
            );
            $rowsForImport = ['stock' => [], 'pool' => [], 'mess' => []];
            foreach ($this->previewService->importableRows($batch->id, $businessId) as $row) {
                $rowsForImport[$row['ledger'] ?? 'stock'][] = $row;
            }
            $inserted = DB::transaction(function () use ($businessId, $result, $batch, $rowsForImport) {
                $inserted = ['stock' => 0, 'pool' => 0, 'mess' => 0];
                $snapshot = $this->ledgerService->openingSnapshot($businessId);
                DB::table('solar_import_batches')->where('id', $batch->id)->update([
                    'opening_snapshot' => json_encode($snapshot),
                    'updated_at' => now(),
                ]);

                foreach (SolarLedgerService::LEDGERS as $ledger) {
                    $table = $this->ledgerService->table($ledger);
                    if (DB::table($table)->where('business_id', $businessId)->doesntExist() && isset($result['opening'][$ledger])) {
                        $opening = $result['opening'][$ledger];
                        $this->ledgerService->setOpeningBalance(
                            $businessId,
                            $ledger,
                            $opening,
                            $opening['date'],
                            $batch->id
                        );
                    }

                    $payloads = [];
                    foreach ($rowsForImport[$ledger] ?? [] as $row) {
                        $hash = $this->ledgerService->rowHash($ledger, $row);
                        $payloads[] = $this->importPayload($ledger, $row) + [
                            'business_id' => $businessId,
                            'source' => 'excel',
                            'source_sheet' => $row['source_sheet'],
                            'source_row' => $row['source_row'],
                            'source_hash' => $hash,
                            'source_key' => $row['source_key'] ?? null,
                            'import_batch_id' => $batch->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    foreach (array_chunk($payloads, 500) as $chunk) {
                        $inserted[$ledger] += DB::table($table)->insertOrIgnore($chunk);
                    }
                    $this->ledgerService->recalculate($businessId, $ledger);
                }

                DB::table('solar_import_batches')->where('id', $batch->id)->update([
                    'status' => 'imported',
                    'confirmed_at' => now(),
                    'counts' => json_encode($inserted),
                    'updated_at' => now(),
                ]);

                return $inserted;
            });

            Storage::disk('local')->delete($path);
            $request->session()->forget("solar_imports.{$validated['token']}");
            $message = sprintf(
                'Import selesai: %d Stock, %d Pool, dan %d Mess ditambahkan.',
                $inserted['stock'],
                $inserted['pool'],
                $inserted['mess']
            );

            return redirect()->route('solar.index')->with('status', ['success' => true, 'msg' => $message]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('status', ['success' => false, 'msg' => 'Import gagal dan seluruh perubahan dibatalkan.']);
        }
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
        $this->ledgerService->table($ledger);
        $common = [
            'tanggal' => ['required', 'date'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ];
        $rules = $ledger === 'stock'
            ? $common + [
                'stock_in' => ['nullable', 'numeric', 'min:0'],
                'out_actual' => ['nullable', 'numeric', 'min:0'],
                'out_meteran' => ['nullable', 'numeric', 'min:0'],
            ]
            : $common + [
                'qty_in' => ['nullable', 'numeric', 'min:0'],
                'qty_out' => ['nullable', 'numeric', 'min:0'],
                'unit' => ['nullable', 'string', 'max:50'],
                'operator' => ['nullable', 'string', 'max:100'],
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
                'stock_in' => (float) ($validated['stock_in'] ?? 0),
                'out_actual' => (float) ($validated['out_actual'] ?? 0),
                'stock_actual' => 0,
                'out_meteran' => (float) ($validated['out_meteran'] ?? 0),
                'stock_meteran' => 0,
                'keterangan' => $validated['keterangan'] ?? null,
            ];
        }

        $hasDescription = collect(['keterangan', 'unit', 'operator', 'customer_id', 'nama_kapal', 'lokasi'])
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
            'unit' => $validated['unit'] ?? null,
            'operator' => $validated['operator'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
        ];
        if ($ledger === 'mess') {
            $payload['nama_kapal'] = $validated['nama_kapal'] ?? null;
            $payload['lokasi'] = $validated['lokasi'] ?? null;
        }

        return $payload;
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
            ->orderBy('name')
            ->get()
            ->map(function ($customer) {
                $name = $customer->supplier_business_name ?: $customer->name;

                return "C{$customer->id} | {$name}";
            })
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
        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->timestamp) {
                $disk->delete($file);
            }
        }
    }
}
