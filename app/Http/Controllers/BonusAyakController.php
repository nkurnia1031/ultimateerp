<?php

namespace App\Http\Controllers;

use App\BonusAyak;
use App\BonusAyakKasbon;
use App\Exports\BonusAyakExport;
use App\Imports\BonusAyakImportPreview;
use App\Services\BonusAyakImportHistoryService;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Modules\AssetManagement\Entities\Asset;
use Modules\Project\Entities\Project;
use Barryvdh\DomPDF\Facade\Pdf;

class BonusAyakController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAccess();
        $businessId = $this->businessId();
        $from = $request->input('from');
        $to = $request->input('to');
        $nama = $request->input('nama');
        $query = $this->scopedQuery(BonusAyak::query(), $businessId)
            ->with(['driver', 'asset', 'project', 'settlements'])
            ->when($from, fn ($q) => $q->whereDate('tanggal', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('tanggal', '<=', $to))
            ->when($nama, fn ($q) => $q->where(function ($inner) use ($nama) {
                $inner->where('nama_operator', 'like', '%'.$nama.'%')
                    ->orWhereHas('driver', fn ($driver) => $driver->where('first_name', 'like', '%'.$nama.'%')->orWhere('last_name', 'like', '%'.$nama.'%'));
            }));
        if ($request->input('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }
        if (! $from && ! $to) {
            $query->whereMonth('tanggal', now()->month)->whereYear('tanggal', now()->year);
        }

        $data = $query->orderBy('tanggal')->orderBy('id')->get();
        $users = User::where('business_id', $businessId)->where('user_type', 'user')->orderBy('first_name')->get();
        $assets = Asset::where('business_id', $businessId)->orderBy('name')->get();
        $projects = Project::where('business_id', $businessId)->orderBy('name')->pluck('name', 'id');
        if ($request->input('export') === 'excel') {
            return Excel::download(new BonusAyakExport($from, $to, $businessId), 'bonus_ayak.xlsx');
        }

        return view('bonus_ayak.index', [
            'data' => $data,
            'totalBulanIni' => $data->sum('bonus'),
            'total_trip' => $data->sum('trip'),
            'grand_total' => $data->sum('bonus'),
            'from' => $from,
            'to' => $to,
            'nama' => $nama,
            'users' => $users,
            'assets' => $assets,
            'projects' => $projects,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();
        $businessId = $this->businessId();
        $validated = $request->validate([
            'tanggal' => ['required', 'date'],
            'driver_id' => ['required', 'integer'],
            'asset_id' => ['required', 'integer'],
            'trip' => ['required', 'integer', 'min:0'],
            'project_id' => ['nullable', 'integer'],
        ]);
        $driver = User::where('business_id', $businessId)->where('user_type', 'user')->findOrFail($validated['driver_id']);
        $asset = Asset::where('business_id', $businessId)->findOrFail($validated['asset_id']);
        $project = ! empty($validated['project_id'])
            ? Project::where('business_id', $businessId)->findOrFail($validated['project_id'])
            : null;
        BonusAyak::create([
            'business_id' => $businessId,
            'project_id' => $project?->id,
            'tanggal' => $validated['tanggal'],
            'nama_operator' => $driver->user_full_name,
            'driver_id' => $driver->id,
            'unit' => $asset->name,
            'asset_id' => $asset->id,
            'trip' => $validated['trip'],
            'bonus' => $this->hitungBonus($validated['trip']),
            'created_by' => auth()->id(),
        ]);
        return back()->with('success', 'Data Bonus Ayak berhasil disimpan.');
    }

    public function edit($id)
    {
        $this->authorizeAccess();
        $bonusAyak = $this->bonus($id)->load(['driver', 'asset', 'project', 'settlements']);
        $users = User::where('business_id', $this->businessId())->where('user_type', 'user')->orderBy('first_name')->get();
        $assets = Asset::where('business_id', $this->businessId())->orderBy('name')->get();
        $projects = Project::where('business_id', $this->businessId())->orderBy('name')->pluck('name', 'id');
        return view('bonus_ayak.edit', ['bonus' => $bonusAyak, 'users' => $users, 'assets' => $assets, 'projects' => $projects]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAccess();
        $businessId = $this->businessId();
        $bonusAyak = $this->bonus($id);
        $validated = $request->validate([
            'tanggal' => ['required', 'date'],
            'driver_id' => ['required', 'integer'],
            'asset_id' => ['required', 'integer'],
            'trip' => ['required', 'integer', 'min:0'],
            'project_id' => ['nullable', 'integer'],
        ]);
        $driver = User::where('business_id', $businessId)->where('user_type', 'user')->findOrFail($validated['driver_id']);
        $asset = Asset::where('business_id', $businessId)->findOrFail($validated['asset_id']);
        if ($bonusAyak->settlements()->exists()) {
            throw ValidationException::withMessages(['bonus_ayak' => 'Bonus Ayak yang sudah masuk settlement tidak dapat diedit.']);
        }
        $project = ! empty($validated['project_id'])
            ? Project::where('business_id', $businessId)->findOrFail($validated['project_id'])
            : null;
        $bonusAyak->update([
            'business_id' => $businessId,
            'project_id' => $project?->id,
            'tanggal' => $validated['tanggal'],
            'nama_operator' => $driver->user_full_name,
            'driver_id' => $driver->id,
            'unit' => $asset->name,
            'asset_id' => $asset->id,
            'trip' => $validated['trip'],
            'bonus' => $this->hitungBonus($validated['trip']),
        ]);
        return redirect()->route('bonus-ayak.index')->with('success', 'Data Bonus Ayak berhasil diupdate.');
    }

    public function destroy($id)
    {
        $this->authorizeAccess();
        $bonusAyak = $this->bonus($id);
        if ($bonusAyak->settlements()->exists()) {
            throw ValidationException::withMessages(['bonus_ayak' => 'Bonus Ayak yang sudah masuk settlement tidak dapat dihapus.']);
        }
        $bonusAyak->delete();

        return back()->with('success', 'Data Bonus Ayak dihapus.');
    }

    public function exportExcel(Request $request)
    {
        $this->authorizeAccess();
        return Excel::download(new BonusAyakExport($request->input('from'), $request->input('to'), $this->businessId()), 'bonus_ayak.xlsx');
    }

    public function importExcel(Request $request, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,xls|max:51200']);

        $batch = null;
        try {
            $batch = $history->start(
                $this->businessId(),
                (int) auth()->id(),
                $request->file('file')->getClientOriginalName()
            );
            $import = new BonusAyakImportPreview($this->businessId(), (int) auth()->id());
            $import->parseSheets(Excel::toCollection($import, $request->file('file')));

            $entries = collect($import->entries)->values();
            $seen = [];
            $entries = $entries->map(function (array $entry, int $index) use (&$seen) {
                $entry['preview_index'] = $index;
                $fingerprint = (string) ($entry['source_fingerprint'] ?? '');
                $reason = null;

                if ($fingerprint === '' || isset($seen[$fingerprint])) {
                    $reason = 'Duplikat di dalam file upload.';
                } elseif (BonusAyak::where('business_id', $this->businessId())
                    ->where('source_fingerprint', $fingerprint)
                    ->exists()) {
                    $reason = 'Data yang sama sudah ada pada business aktif.';
                }

                if ($reason !== null) {
                    $entry['valid'] = false;
                    $entry['error'] = "Baris {$entry['source_row']}: {$reason}";
                } else {
                    $seen[$fingerprint] = true;
                    $entry['valid'] = true;
                    $entry['error'] = null;
                }

                return $entry;
            })->all();

            $errors = array_values($import->errors);
            if ($entries === [] && $errors === []) {
                $history->fail($batch, 'File tidak berisi baris yang dapat dipreview.');

                return back()->with('error', 'File tidak berisi baris yang dapat dipreview.');
            }

            $payload = [
                'token' => $batch->source_key,
                'user_id' => (int) auth()->id(),
                'business_id' => $this->businessId(),
                'created_at' => now()->toIso8601String(),
                'original_name' => $request->file('file')->getClientOriginalName(),
                'entries' => $entries,
                'errors' => $errors,
            ];
            $history->storePreview($batch, $payload);

            return redirect()->route('bonus-ayak.import-preview', $batch->source_key)
                ->with('success', 'Preview import sudah siap. Periksa baris valid sebelum menyimpan.');
        } catch (\Throwable $e) {
            if ($batch) {
                $history->fail($batch, $e->getMessage());
            }
            report($e);

            return back()->with('error', $e->getMessage());
        }
    }

    public function importPreview(string $token, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        [, $preview] = $history->loadPreview($token, $this->businessId(), (int) auth()->id());

        return view('bonus_ayak.import_preview', compact('preview'));
    }

    public function importConfirm(Request $request, string $token, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*' => ['integer', 'min:0'],
        ]);
        [$batch, $preview] = $history->loadPreview($token, $this->businessId(), (int) auth()->id());
        $selectedIndexes = collect($request->input('entries', []))
            ->map(fn ($index) => (int) $index)
            ->unique()
            ->values();
        $entries = collect($preview['entries'] ?? [])
            ->filter(fn ($entry, $index) => $selectedIndexes->contains((int) $index) && ! empty($entry['valid']))
            ->values();

        if ($entries->isEmpty()) {
            return redirect()->route('bonus-ayak.import-preview', $token)
                ->with('error', 'Pilih minimal satu baris valid untuk diimpor.');
        }

        $history->beginImport($batch, $entries->count());
        try {
            DB::transaction(function () use ($entries, $batch, $history) {
                foreach ($entries as $entry) {
                    $duplicate = BonusAyak::where('business_id', $this->businessId())
                        ->where('source_fingerprint', $entry['source_fingerprint'])
                        ->exists();
                    if ($duplicate) {
                        throw ValidationException::withMessages([
                            'entries' => "Baris {$entry['source_row']} sudah diimpor oleh proses lain atau sudah ada sebelumnya.",
                        ]);
                    }

                    BonusAyak::create([
                        'business_id' => $this->businessId(),
                        'tanggal' => $entry['tanggal'],
                        'nama_operator' => $entry['nama_operator'],
                        'driver_id' => $entry['driver_id'],
                        'unit' => $entry['unit'],
                        'asset_id' => $entry['asset_id'],
                        'trip' => $entry['trip'],
                        'bonus' => $entry['bonus'],
                        'created_by' => auth()->id(),
                        'source_fingerprint' => $entry['source_fingerprint'],
                        'user_import_batch_id' => $batch->id,
                    ]);
                }
                $history->completeImport($batch, $entries->count());
            });
            $history->discardPreview($batch->fresh());

            return redirect()->route('bonus-ayak.index')->with('success', "Import Bonus Ayak berhasil: {$entries->count()} baris disimpan.");
        } catch (ValidationException $e) {
            $history->fail($batch->fresh(), (string) collect($e->errors())->flatten()->first());

            return redirect()->route('bonus-ayak.import-preview', $token)->withErrors($e->errors());
        } catch (\Throwable $e) {
            $history->fail($batch->fresh(), $e->getMessage());
            report($e);

            return redirect()->route('bonus-ayak.import-preview', $token)->with('error', 'Import gagal disimpan. Periksa data dan coba lagi.');
        }
    }

    public function importCancel(string $token, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        [$batch] = $history->loadPreview($token, $this->businessId(), (int) auth()->id());
        $history->cancel($batch);

        return redirect()->route('bonus-ayak.index')->with('success', 'Preview import dibatalkan. Tidak ada data yang disimpan.');
    }

    public function exportPdf(Request $request)
    {
        $this->authorizeAccess();
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->endOfMonth()->toDateString();
        $data = $this->scopedQuery(BonusAyak::query(), $this->businessId())
            ->select('driver_id', 'nama_operator', DB::raw('SUM(trip) as total_trip'), DB::raw('SUM(bonus) as total_bonus'))
            ->whereBetween('tanggal', [$from, $to])
            ->groupBy('driver_id', 'nama_operator')
            ->orderBy('nama_operator')
            ->get();
        $kasbonData = $this->scopedKasbonQuery()->whereBetween('tanggal', [$from, $to])
            ->select('driver_id', 'nama_operator', DB::raw('SUM(jumlah_kasbon) as total_kasbon'))
            ->groupBy('driver_id', 'nama_operator')
            ->get()->mapWithKeys(fn ($row) => [($row->driver_id ?: 'name:'.$row->nama_operator) => $row->total_kasbon])->all();

        $pdf = Pdf::loadView('bonus_ayak.pdf', [
            'data' => $data,
            'displayFrom' => Carbon::parse($from)->format('d/m/Y'),
            'displayTo' => Carbon::parse($to)->format('d/m/Y'),
            'total_trip' => $data->sum('total_trip'),
            'grand_total' => $data->sum('total_bonus'),
            'kasbonData' => $kasbonData,
            'from' => $from,
            'to' => $to,
        ]);

        return $pdf->setPaper('a4', 'portrait')->download('rekap_bonus_ayak.pdf');
    }

    public function kasbonForm()
    {
        $this->authorizeAccess();
        $users = User::where('business_id', $this->businessId())->where('user_type', 'user')->orderBy('first_name')->get();
        $kasbon = $this->scopedKasbonQuery()->with(['driver', 'settlements'])->orderByDesc('tanggal')->get();

        return view('bonus_ayak.kasbon_form', compact('users', 'kasbon') + ['editingKasbon' => null]);
    }

    public function kasbonEdit($id)
    {
        $this->authorizeAccess();
        $kasbonItem = $this->scopedKasbonQuery()->with(['driver', 'settlements'])->findOrFail($id);
        $users = User::where('business_id', $this->businessId())->where('user_type', 'user')->orderBy('first_name')->get();
        $kasbon = $this->scopedKasbonQuery()->with(['driver', 'settlements'])->orderByDesc('tanggal')->get();

        return view('bonus_ayak.kasbon_form', [
            'users' => $users,
            'kasbon' => $kasbon,
            'editingKasbon' => $kasbonItem,
        ]);
    }

    public function kasbonStore(Request $request)
    {
        $this->authorizeAccess();
        $validated = $request->validate([
            'tanggal' => ['required', 'date'],
            'driver_id' => ['required', 'integer'],
            'jumlah_kasbon' => ['required', 'numeric', 'min:0'],
        ]);
        $driver = User::where('business_id', $this->businessId())->where('user_type', 'user')->findOrFail($validated['driver_id']);
        BonusAyakKasbon::create([
            'business_id' => $this->businessId(),
            'tanggal' => $validated['tanggal'],
            'nama_operator' => $driver->user_full_name,
            'driver_id' => $driver->id,
            'jumlah_kasbon' => $validated['jumlah_kasbon'],
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('bonus-ayak.kasbon-form')->with('success', 'Kasbon berhasil disimpan.');
    }

    public function kasbonUpdate(Request $request, $id)
    {
        $this->authorizeAccess();
        $businessId = $this->businessId();
        $kasbon = $this->scopedKasbonQuery()->findOrFail($id);
        if ($kasbon->settlements()->exists()) {
            throw ValidationException::withMessages(['kasbon' => 'Kasbon yang sudah dipotong payroll tidak dapat diedit.']);
        }
        $validated = $request->validate([
            'tanggal' => ['required', 'date'],
            'driver_id' => ['required', 'integer'],
            'jumlah_kasbon' => ['required', 'numeric', 'min:0'],
        ]);
        $driver = User::where('business_id', $businessId)->where('user_type', 'user')->findOrFail($validated['driver_id']);
        $kasbon->update([
            'tanggal' => $validated['tanggal'],
            'nama_operator' => $driver->user_full_name,
            'driver_id' => $driver->id,
            'jumlah_kasbon' => $validated['jumlah_kasbon'],
        ]);

        return redirect()->route('bonus-ayak.kasbon-form')->with('success', 'Kasbon berhasil diupdate.');
    }

    public function kasbonDelete($id)
    {
        $this->authorizeAccess();
        $kasbon = $this->scopedKasbonQuery()->findOrFail($id);
        if ($kasbon->settlements()->exists()) {
            throw ValidationException::withMessages(['kasbon' => 'Kasbon yang sudah dipotong payroll tidak dapat dihapus.']);
        }
        $kasbon->delete();

        return redirect()->route('bonus-ayak.kasbon-form')->with('success', 'Kasbon berhasil dihapus.');
    }

    private function hitungBonus(int $trip): int
    {
        return $trip < 50 ? 0 : 50000 + max(0, $trip - 50) * 2000;
    }

    private function bonus($id): BonusAyak
    {
        return $this->scopedQuery(BonusAyak::query(), $this->businessId())->findOrFail($id);
    }

    private function businessId(): int
    {
        return (int) request()->session()->get('user.business_id');
    }

    private function authorizeAccess(): void
    {
        if (! auth()->user()->can('superadmin') && ! auth()->user()->can('bonus_ayak.access') && ! auth()->user()->can('bonus_ayak.manage')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function scopedQuery($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    private function scopedKasbonQuery()
    {
        return BonusAyakKasbon::query()->where('business_id', $this->businessId());
    }

}
