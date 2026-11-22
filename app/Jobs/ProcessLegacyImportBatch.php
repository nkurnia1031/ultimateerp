<?php

namespace App\Jobs;

use App\Http\Controllers\ImportOpeningStockController;
use App\Http\Controllers\ImportProductsController;
use App\Services\LegacyImportBatchService;
use App\Services\UserImportBatchService;
use App\User;
use App\UserImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessLegacyImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private int $batchId, private int $businessId, private string $importerKey)
    {
        $this->onQueue('imports');
    }

    public function handle(LegacyImportBatchService $service, UserImportBatchService $batches): void
    {
        $query = UserImportBatch::where('business_id', $this->businessId)->where('importer_key', $this->importerKey)
            ->where('id', $this->batchId)->where('status', 'queued_import');
        $batch = $query->first();
        if (! $batch || ! $query->update([
            'status' => 'importing', 'stage' => 'importing', 'progress_percent' => 1,
            'last_message' => 'Import sedang diproses oleh worker.', 'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
        ])) {
            return;
        }

        $temporaryPath = null;
        $originalRequest = app('request');
        $originalSession = app('session')->driver();
        try {
            $batch = $batch->fresh();
            $user = User::where('business_id', $this->businessId)->findOrFail((int) $batch->created_by);
            Auth::setUser($user);
            if (filled($batch->staging_path) && Storage::disk('user_imports')->exists($batch->staging_path)) {
                $workbookPath = Storage::disk('user_imports')->path($batch->staging_path);
            } else {
                abort_unless($batch->hasSourceSnapshot(), 422, 'Workbook sumber tidak tersedia untuk import.');
                $temporaryPath = $batches->restoredWorkbookPath($batch);
                $workbookPath = $temporaryPath;
            }
            if (! $batch->hasSourceSnapshot()) {
                $batches->captureWorkbook($batch, $workbookPath);
            }
            $beforeState = $service->prepare($batch->fresh(), $workbookPath);
            $batch->forceFill(['progress_current' => 0, 'progress_total' => (int) $beforeState['source_rows'], 'progress_percent' => 1])->save();
            Cache::put($service->progressKey((int) $batch->id), [
                'progress_current' => 0, 'progress_total' => (int) $beforeState['source_rows'], 'progress_percent' => 1,
            ], 3600);
            DB::beginTransaction();
            $createdProductIds = $this->runExistingImporter($batch, $workbookPath, $user);
            $service->complete($batch->fresh(), $beforeState, $createdProductIds);
            DB::commit();
        } catch (\Throwable $exception) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $current = UserImportBatch::where('business_id', $this->businessId)->where('importer_key', $this->importerKey)->find($this->batchId);
            if ($current && in_array($current->status, ['queued_import', 'importing'], true)) {
                $service->fail($current, $exception);
            }
            report($exception);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($batch && filled($batch->staging_path) && $batch->fresh()->hasSourceSnapshot()) {
                Storage::disk('user_imports')->delete($batch->staging_path);
                UserImportBatch::whereKey($batch->id)->update(['staging_path' => null]);
            }
            if ($temporaryPath && is_file($temporaryPath)) {
                @unlink($temporaryPath);
                @rmdir(dirname($temporaryPath));
            }
            app()->instance('request', $originalRequest);
            app()->instance('session.store', $originalSession);
            app('redirect')->setSession($originalSession);
            Auth::logout();
        }
    }

    private function runExistingImporter(UserImportBatch $batch, string $path, User $user): array
    {
        $options = json_decode((string) $batch->options_json, true) ?: [];
        $context = $options['session_context'] ?? [];
        $session = new Store('legacy-import-'.$batch->id, new ArraySessionHandler(60), (string) \Illuminate\Support\Str::uuid());
        $session->start();
        $session->put('user', ['id' => (int) $user->id, 'business_id' => (int) $this->businessId]);
        foreach (['business', 'financial_year'] as $key) {
            if (isset($context[$key]) && is_array($context[$key])) {
                $session->put($key, $context[$key]);
            }
        }
        $uploadedFile = new UploadedFile($path, (string) $batch->file_name, null, null, true);
        $uri = $this->importerKey === 'products' ? '/import-products/store' : '/import-opening-stock/store';
        $request = Request::create($uri, 'POST', [], [], ['products_csv' => $uploadedFile], [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $request->setLaravelSession($session);
        $request->attributes->set('process_import_batch', true);
        $request->attributes->set('import_batch_id', (int) $batch->id);
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);
        app()->instance('session.store', $session);
        app('redirect')->setSession($session);

        $controller = $this->importerKey === 'products' ? app(ImportProductsController::class) : app(ImportOpeningStockController::class);
        $response = $controller->store($request);
        $flash = $response->getSession()->get('status') ?? $response->getSession()->get('notification');
        if (! is_array($flash) || (int) ($flash['success'] ?? 0) !== 1) {
            throw new \RuntimeException((string) ($flash['msg'] ?? 'Importer domain menolak workbook.'));
        }

        return array_values(array_unique(array_map('intval', (array) $request->attributes->get('batch_created_product_ids', []))));
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)->where('importer_key', $this->importerKey)
            ->where('id', $this->batchId)->whereIn('status', ['queued_import', 'importing'])->first();
        if ($batch) {
            app(LegacyImportBatchService::class)->fail($batch, $exception);
        }
    }
}
