<?php

namespace App\Http\Controllers;

use App\BonusAyak;
use App\BonusAyakKasbon;
use App\Exports\BonusAyakExport;
use App\Jobs\AnalyzeBonusAyakImportBatch;
use App\Jobs\ProcessBonusAyakImportBatch;
use App\Services\BonusAyakImportHistoryService;
use App\Services\UserImportBatchService;
use App\User;
use App\UserImportBatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $stagingPath = null;
        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension()) ?: 'xlsx';
            $stagingPath = $file->storeAs('user-import-staging/bonus-ayak', (string) Str::uuid().'.'.$extension, 'local');
            if (! $stagingPath) {
                throw new \RuntimeException('Workbook Bonus Ayak tidak dapat disimpan ke storage privat.');
            }
            $batch = $history->start(
                $this->businessId(),
                (int) auth()->id(),
                $file->getClientOriginalName(),
                $stagingPath
            );
            AnalyzeBonusAyakImportBatch::dispatch((int) $batch->id, $this->businessId());
        } catch (\Throwable $e) {
            if (! $batch && $stagingPath) {
                Storage::disk('local')->delete($stagingPath);
            }
            if ($batch) {
                $history->fail($batch, $e->getMessage());
            }
            report($e);

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Workbook gagal dimasukkan ke antrean.'], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'batch_id' => $batch->id,
                'token' => $batch->source_key,
                'status' => $batch->status,
                'status_url' => route('bonus-ayak.import-status', $batch->source_key),
                'preview_url' => route('bonus-ayak.import-preview', $batch->source_key),
            ], 202);
        }

        return redirect()->route('bonus-ayak.import-preview', $batch->source_key)
            ->with('success', 'Workbook masuk antrean analisis. Halaman akan menampilkan preview setelah selesai.');
    }

    public function importPreview(string $token, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        $batch = UserImportBatch::where('business_id', $this->businessId())
            ->where('created_by', auth()->id())
            ->where('importer_key', 'bonus_ayak')
            ->where('source_key', $token)
            ->firstOrFail();
        if (in_array($batch->status, ['imported', 'partial', 'reverted', 'cancelled'], true)) {
            return redirect()->route('bonus-ayak.index');
        }
        if (in_array($batch->status, ['queued_preview', 'analyzing', 'queued_import', 'importing'], true)
            || ($batch->status === 'failed' && blank($batch->preview_path))) {
            return view('bonus_ayak.import_pending', [
                'statusUrl' => route('bonus-ayak.import-status', $token),
                'fileName' => $batch->file_name,
            ]);
        }
        [, $preview] = $history->loadPreview($token, $this->businessId(), (int) auth()->id());

        return view('bonus_ayak.import_preview', compact('preview'));
    }

    public function importStatus(string $token)
    {
        $this->authorizeAccess();
        $batch = UserImportBatch::where('business_id', $this->businessId())
            ->where('created_by', auth()->id())
            ->where('importer_key', 'bonus_ayak')
            ->where('source_key', $token)
            ->firstOrFail();

        return response()->json([
            'status' => $batch->status,
            'progress_percent' => (int) $batch->progress_percent,
            'last_message' => $batch->last_message,
            'error_message' => $batch->error_message,
            'preview_url' => in_array($batch->status, ['preview_ready', 'failed'], true) && filled($batch->preview_path)
                ? route('bonus-ayak.import-preview', $token) : null,
        ]);
    }

    public function importConfirm(Request $request, string $token, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*' => ['integer', 'min:0'],
        ]);
        [$batch] = $history->loadPreview($token, $this->businessId(), (int) auth()->id());
        try {
            $history->queueImport($batch, $request->input('entries', []));
            ProcessBonusAyakImportBatch::dispatch((int) $batch->id, $this->businessId());
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->route('bonus-ayak.import-preview', $token)->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'status' => 'queued_import'], 202);
        }

        return redirect()->route('bonus-ayak.import-preview', $token)
            ->with('success', 'Baris Bonus Ayak masuk antrean untuk disimpan.');
    }

    public function importCancel(string $token, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        $batch = UserImportBatch::where('business_id', $this->businessId())
            ->where('created_by', auth()->id())
            ->where('importer_key', 'bonus_ayak')
            ->where('source_key', $token)->firstOrFail();
        abort_unless(in_array($batch->status, ['queued_preview', 'analyzing', 'preview_ready'], true), 422, 'Batch Bonus Ayak tidak dapat dibatalkan pada status ini.');
        $history->cancel($batch);
        if (filled($batch->staging_path)) {
            Storage::disk('local')->delete($batch->staging_path);
            $batch->forceFill(['staging_path' => null])->save();
        }

        return redirect()->route('bonus-ayak.index')->with('success', 'Preview import dibatalkan. Tidak ada data yang disimpan.');
    }

    public function importHistory(Request $request, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();

        return response()->json($history->history($request, $this->businessId()));
    }

    public function importHistoryDetail(int $batchId, BonusAyakImportHistoryService $history)
    {
        $this->authorizeAccess();
        $detail = $history->detail($batchId, $this->businessId());

        return response()->json(['batch' => $detail['batch'], 'results' => $detail['results'], 'preview' => $detail['preview']]);
    }

    public function importHistorySource(int $batchId, UserImportBatchService $batches)
    {
        $this->authorizeAccess();
        $batch = $batches->findForBusiness($batchId, $this->businessId(), 'bonus_ayak');
        abort_unless($batch->hasSourceSnapshot(), 404);

        return $batches->workbookDownload($batch);
    }

    public function importHistoryRetry(int $batchId, UserImportBatchService $batches)
    {
        $this->authorizeAccess();
        $batch = $batches->findForBusiness($batchId, $this->businessId(), 'bonus_ayak');
        abort_unless($batch->status === 'failed', 422, 'Hanya batch gagal yang dapat dicoba ulang.');

        if (filled($batch->preview_path)) {
            $selection = $batch->results_path ? $batches->readPayload($batch, 'results') : [];
            abort_if(empty($selection['selected_preview_indexes']), 422, 'Pilihan baris untuk retry tidak tersedia.');
            $batch->forceFill(['status' => 'queued_import', 'stage' => 'queued_import', 'failed_at' => null, 'completed_at' => null, 'error_message' => null, 'heartbeat_at' => now()])->save();
            ProcessBonusAyakImportBatch::dispatch((int) $batch->id, $this->businessId());
        } else {
            abort_unless($batch->hasSourceSnapshot() || filled($batch->staging_path), 422, 'Sumber batch tidak tersedia untuk retry.');
            $batch->forceFill(['status' => 'queued_preview', 'stage' => 'queued', 'failed_at' => null, 'completed_at' => null, 'error_message' => null, 'heartbeat_at' => null])->save();
            AnalyzeBonusAyakImportBatch::dispatch((int) $batch->id, $this->businessId());
        }

        return response()->json(['success' => true, 'status' => $batch->fresh()->status], 202);
    }

    public function importHistoryRevert(int $batchId, BonusAyakImportHistoryService $history, UserImportBatchService $batches)
    {
        $this->authorizeAccess();
        $batch = $batches->findForBusiness($batchId, $this->businessId(), 'bonus_ayak');
        $history->revert($batch);

        return response()->json(['success' => true, 'status' => 'reverted']);
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
