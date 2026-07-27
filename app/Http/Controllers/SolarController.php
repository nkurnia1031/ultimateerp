<?php

namespace App\Http\Controllers;

use App\Services\SolarLedgerService;
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

    public function __construct(SolarLedgerService $ledgerService, SolarWorkbookService $workbookService)
    {
        $this->ledgerService = $ledgerService;
        $this->workbookService = $workbookService;
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
        ]);
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
            $parsed = $this->prepareParsedRows(
                $businessId,
                $this->workbookService->parse(Storage::disk('local')->path($path))
            );
            $result = $this->ledgerService->reconciliation($businessId, $parsed);
            $request->session()->put("solar_imports.{$token}", $path);

            return view('solar.reconcile', [
                'token' => $token,
                'result' => $result,
                'visibleItems' => array_slice($result['items'], 0, 500),
                'totalItems' => count($result['items']),
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
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404, 'File preview sudah tidak tersedia.');

        try {
            $parsed = $this->prepareParsedRows(
                $businessId,
                $this->workbookService->parse(Storage::disk('local')->path($path))
            );
            $result = $this->ledgerService->reconciliation($businessId, $parsed);
            $inserted = DB::transaction(function () use ($businessId, $result) {
                $inserted = ['stock' => 0, 'pool' => 0, 'mess' => 0];

                foreach (SolarLedgerService::LEDGERS as $ledger) {
                    $table = $this->ledgerService->table($ledger);
                    if (DB::table($table)->where('business_id', $businessId)->doesntExist() && isset($result['opening'][$ledger])) {
                        $opening = $result['opening'][$ledger];
                        $this->ledgerService->setOpeningBalance(
                            $businessId,
                            $ledger,
                            $opening,
                            $opening['date']
                        );
                    }

                    $payloads = [];
                    foreach ($result['onlyExcel'][$ledger] ?? [] as $row) {
                        $hash = $this->ledgerService->rowHash($ledger, $row);
                        $payloads[] = $this->importPayload($ledger, $row) + [
                            'business_id' => $businessId,
                            'source' => 'excel',
                            'source_sheet' => $row['source_sheet'],
                            'source_row' => $row['source_row'],
                            'source_hash' => $hash,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    foreach (array_chunk($payloads, 500) as $chunk) {
                        $inserted[$ledger] += DB::table($table)->insertOrIgnore($chunk);
                    }
                    $this->ledgerService->recalculate($businessId, $ledger);
                }

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
        $path = $request->session()->get("solar_imports.{$token}");
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404, 'File preview sudah tidak tersedia.');

        $parsed = $this->prepareParsedRows(
            $businessId,
            $this->workbookService->parse(Storage::disk('local')->path($path))
        );
        $result = $this->ledgerService->reconciliation($businessId, $parsed);

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

    private function prepareParsedRows(int $businessId, array $parsed): array
    {
        $customers = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->select('id', 'name', 'supplier_business_name')
            ->get();
        $byId = $customers->keyBy('id');
        $byName = [];
        foreach ($customers as $customer) {
            foreach ([$customer->name, $customer->supplier_business_name] as $name) {
                if ($name) {
                    $byName[mb_strtolower(trim($name))] = (int) $customer->id;
                }
            }
        }

        foreach (['pool', 'mess'] as $ledger) {
            foreach ($parsed['rows'][$ledger] as $index => $row) {
                $reference = trim((string) ($row['customer_ref'] ?? ''));
                $customerId = null;
                if ($reference !== '') {
                    if (preg_match('/^C(\d+)\b/i', $reference, $matches) && $byId->has((int) $matches[1])) {
                        $customerId = (int) $matches[1];
                    } elseif (isset($byName[mb_strtolower($reference)])) {
                        $customerId = $byName[mb_strtolower($reference)];
                    } else {
                        $parsed['errors'][] = [
                            'ledger' => $ledger,
                            'row' => $row['source_row'],
                            'message' => "Customer \"{$reference}\" tidak ditemukan pada business ini.",
                        ];
                        unset($parsed['rows'][$ledger][$index]);
                        continue;
                    }
                }
                $parsed['rows'][$ledger][$index]['customer_id'] = $customerId;
                unset($parsed['rows'][$ledger][$index]['customer_ref']);
            }
            $parsed['rows'][$ledger] = array_values($parsed['rows'][$ledger]);
        }

        return $parsed;
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
