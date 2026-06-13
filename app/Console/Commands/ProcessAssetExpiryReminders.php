<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\AssetManagement\Services\AssetExpiryReminderService;

class ProcessAssetExpiryReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:processAssetExpiryReminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync and clean up asset expiry reminders';

    /**
     * Create a new command instance.
     */
    public function __construct(protected AssetExpiryReminderService $assetExpiryReminderService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '512M');

            $this->assetExpiryReminderService->syncAllAssets();
            $this->assetExpiryReminderService->cleanupExpiredSchedules();

            $this->info('Processed asset expiry reminders.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
