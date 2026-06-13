<?php

namespace App\Console\Commands;

use App\Services\ReminderDigestService;
use Illuminate\Console\Command;

class ProcessReminderEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:processReminderEmails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send combined reminder digest emails';

    protected $reminderDigestService;

    public function __construct(ReminderDigestService $reminderDigestService)
    {
        parent::__construct();
        $this->reminderDigestService = $reminderDigestService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '512M');

            $sentCount = $this->reminderDigestService->sendDailyDigest();
            $this->info("Processed reminder digest emails. Sent {$sentCount} email(s).");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
