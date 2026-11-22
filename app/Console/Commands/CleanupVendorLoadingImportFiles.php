<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupVendorLoadingImportFiles extends Command
{
    protected $signature = 'vendor-loadings:cleanup-import-files';
    protected $description = 'Hapus file sumber dan preview import Loading Vendor yang sudah melewati masa retensi.';

    public function handle(): int
    {
        $disk = Storage::disk('user_imports');
        DB::table('heavy_equipment_vendor_loading_import_batches')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['preview_ready', 'imported', 'partial', 'failed'])
            ->orderBy('id')
            ->chunkById(100, function ($batches) use ($disk) {
                foreach ($batches as $batch) {
                    foreach ([$batch->file_path, $batch->preview_path] as $path) {
                        if ($path && $disk->exists($path)) {
                            $disk->delete($path);
                        }
                    }
                    $update = [
                        'file_path' => null,
                        'preview_path' => null,
                        'expires_at' => null,
                        'updated_at' => now(),
                    ];
                    if ($batch->status === 'preview_ready') {
                        $update['status'] = 'expired';
                        $update['stage'] = 'expired';
                        $update['last_message'] = 'Masa penyimpanan preview berakhir. Upload ulang workbook untuk mengimpor.';
                        $update['finished_at'] = now();
                    }
                    DB::table('heavy_equipment_vendor_loading_import_batches')->where('id', $batch->id)->update($update);
                }
            });

        $this->info('File import Loading Vendor kedaluwarsa telah dibersihkan.');
        return self::SUCCESS;
    }
}
