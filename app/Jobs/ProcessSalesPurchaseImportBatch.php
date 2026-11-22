<?php

namespace App\Jobs;

use App\Http\Controllers\ImportSalesController;
use App\Services\SalesPurchaseImportBatchService;
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

class ProcessSalesPurchaseImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private int $batchId, private int $businessId, private string $kind, private string $operation)
    {
        $this->onQueue('imports');
    }

    public function handle(SalesPurchaseImportBatchService $service, UserImportBatchService $batches): void
    {
        $expectedStatus = $this->operation === 'preview' ? 'queued_preview' : 'queued_import';
        $activeStatus = $this->operation === 'preview' ? 'analyzing' : 'importing';
        $key = $service->importerKey($this->kind);
        $query = UserImportBatch::where('business_id', $this->businessId)->where('importer_key', $key)
            ->where('id', $this->batchId)->where('status', $expectedStatus);
        $batch = $query->first();
        if (! $batch || ! $query->update([
            'status' => $activeStatus, 'stage' => $activeStatus, 'progress_percent' => 1,
            'last_message' => $this->operation === 'preview' ? 'Workbook sedang dianalisis.' : 'Transaksi sedang di-import.',
            'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now(),
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
                $sourcePath = Storage::disk('user_imports')->path($batch->staging_path);
            } else {
                abort_unless($batch->hasSourceSnapshot(), 422, 'Workbook sumber tidak tersedia.');
                $temporaryPath = $batches->restoredWorkbookPath($batch);
                $sourcePath = $temporaryPath;
            }
            if (! $batch->hasSourceSnapshot()) {
                $batches->captureWorkbook($batch, $sourcePath);
            }
            $options = json_decode((string) $batch->options_json, true) ?: [];
            $session = $this->session($batch, $user, $options);
            $uriBase = $this->kind === 'purchase' ? '/import-pembelian' : '/import-sales';
            if ($this->operation === 'preview') {
                $request = Request::create($uriBase.'/preview', 'POST', [], [], [
                    'sales' => new UploadedFile($sourcePath, (string) $batch->file_name, null, null, true),
                ], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $request->attributes->set('batch_file_name', (string) $batch->file_name);
                $request->attributes->set('import_batch_source_path', $sourcePath);
            } else {
                $request = Request::create($uriBase, 'POST', [
                    'batch_id' => $batch->id,
                    'file_name' => $batch->file_name,
                    'import_fields' => $options['import_fields'] ?? [],
                    'group_by' => $options['group_by'] ?? null,
                    'location_id' => $options['location_id'] ?? null,
                ], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $request->attributes->set('import_batch_source_path', $sourcePath);
            }
            $request->setLaravelSession($session);
            $request->attributes->set('process_import_batch', true);
            $request->setUserResolver(fn () => $user);
            app()->instance('request', $request);
            app()->instance('session.store', $session);
            app('redirect')->setSession($session);

            $controller = app(ImportSalesController::class);
            if ($this->operation === 'preview') {
                $view = $controller->preview($request);
                abort_unless($view instanceof \Illuminate\View\View, 422, 'Preview workbook tidak dapat dibuat.');
                $service->completePreview($batch->fresh(), $view->getData());
            } else {
                $before = $service->prepareImport($batch->fresh(), $sourcePath);
                $batch->forceFill([
                    'progress_current' => 0,
                    'progress_total' => (int) $before['source_rows'],
                    'progress_percent' => 1,
                    'last_message' => 'Transaksi sedang diproses oleh worker.',
                ])->save();
                Cache::put($service->progressKey((int) $batch->id), [
                    'progress_current' => 0, 'progress_total' => (int) $before['source_rows'], 'progress_percent' => 1,
                ], 3600);
                $request->attributes->set('import_batch_id', (int) $batch->id);
                DB::beginTransaction();
                $response = $controller->import($request);
                $flash = $response->getSession()->get('status') ?? $response->getSession()->get('notification');
                if (! is_array($flash) || (int) ($flash['success'] ?? 0) !== 1) {
                    throw new \RuntimeException((string) ($flash['msg'] ?? 'Importer Sales/Purchase menolak workbook.'));
                }
                $service->completeImport($batch->fresh(), $before, [
                    'transaction_ids' => $request->attributes->get('import_batch_transaction_ids', []),
                    'contact_ids' => $request->attributes->get('import_batch_contact_ids', []),
                    'product_ids' => $request->attributes->get('import_batch_product_ids', []),
                    'row_outcomes' => $request->attributes->get('import_batch_row_outcomes', []),
                ]);
                DB::commit();
            }
        } catch (\Throwable $exception) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $current = UserImportBatch::where('business_id', $this->businessId)->where('importer_key', $key)->find($this->batchId);
            if ($current && in_array($current->status, ['queued_preview', 'analyzing', 'queued_import', 'importing'], true)) {
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

    private function session(UserImportBatch $batch, User $user, array $options): Store
    {
        $session = new Store('sales-import-'.$batch->id, new ArraySessionHandler(60), (string) \Illuminate\Support\Str::uuid());
        $session->start();
        $session->put('user', ['id' => (int) $user->id, 'business_id' => (int) $this->businessId]);
        $context = $options['session_context'] ?? [];
        if (isset($context['business'])) {
            $session->put('business', $context['business']);
        }
        if (isset($options['csrf_token'])) {
            $session->put('_token', $options['csrf_token']);
        }

        return $session;
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UserImportBatch::where('business_id', $this->businessId)->where('importer_key', 'sales_'.$this->kind)
            ->where('id', $this->batchId)->whereIn('status', ['queued_preview', 'analyzing', 'queued_import', 'importing'])->first();
        if ($batch) {
            app(SalesPurchaseImportBatchService::class)->fail($batch, $exception);
        }
    }
}
