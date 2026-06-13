<?php

namespace Modules\AssetManagement\Services;

use App\Business;
use App\Notifications\ReminderEmailNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Modules\AssetManagement\Entities\Asset;
use Modules\AssetManagement\Entities\AssetExpiryReminder;
use Modules\AssetManagement\Entities\AssetExpiryReminderItem;
use Modules\Essentials\Entities\Reminder;

class AssetExpiryReminderService
{
    /**
     * Sync reminder schedules for all assets.
     */
    public function syncAllAssets(int $chunkSize = 100): void
    {
        $today = Carbon::today();

        Asset::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $assets) use ($today) {
                $this->syncAssetChunk($assets, $today);
            });
    }

    /**
     * Sync reminder schedules for one business only.
     */
    public function syncBusinessAssets(int $businessId, int $chunkSize = 100): void
    {
        $today = Carbon::today();

        Asset::query()
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $assets) use ($today) {
                $this->syncAssetChunk($assets, $today);
            });
    }

    /**
     * Sync reminder schedules for a single asset.
     */
    public function syncAsset(Asset $asset, ?Business $business = null, ?Carbon $today = null): void
    {
        $business = $business ?: Business::find($asset->business_id);
        $today = $today ?: Carbon::today();

        if (empty($business) || empty($business->owner_id)) {
            $this->deactivateReminderType($asset->id, 'stnk');
            $this->deactivateReminderType($asset->id, 'tbpkp');

            return;
        }

        DB::transaction(function () use ($asset, $business, $today) {
            $this->syncReminderType($asset, $business, 'stnk', $asset->stnk_expired_at, $today);
            $this->syncReminderType($asset, $business, 'tbpkp', $asset->tbpkp_expired_at, $today);
        });
    }

    /**
     * Remove reminder schedules for a deleted asset.
     */
    public function purgeAssetReminders(int $assetId): void
    {
        DB::transaction(function () use ($assetId) {
            $schedules = AssetExpiryReminder::where('asset_id', $assetId)->get();

            foreach ($schedules as $schedule) {
                $this->deleteLinkedReminderRows($schedule);
                $schedule->delete();
            }
        });
    }

    /**
     * Send due asset reminder emails for today.
     */
    public function sendDueEmails(?Carbon $today = null): int
    {
        $today = $today ?: Carbon::today();
        $sentCount = 0;

        $schedules = AssetExpiryReminder::with(['asset', 'business'])
            ->where('is_active', true)
            ->whereDate('expiry_date', '>=', $today->format('Y-m-d'))
            ->get();

        foreach ($schedules as $schedule) {
            if (empty($schedule->asset) || empty($schedule->business)) {
                continue;
            }

            if (! $this->shouldSendEmailOnDate($schedule, $today)) {
                continue;
            }

            $recipient = data_get($schedule->business->email_settings, 'mail_from_address');
            if (empty($recipient)) {
                continue;
            }

            $message = $this->buildReminderName(
                $schedule->asset,
                $schedule->reminder_type,
                $schedule->expiry_date->copy()
            );

            $data = [
                'email_settings' => $schedule->business->email_settings ?? [],
                'subject' => $this->buildEmailSubject(
                    $schedule->asset,
                    $schedule->reminder_type,
                    $schedule->expiry_date->copy()
                ),
                'line' => $message,
            ];

            try {
                Notification::route('mail', $recipient)
                    ->notify(new ReminderEmailNotification($data));

                $schedule->last_email_sent_on = $today->format('Y-m-d');
                $schedule->save();
                ++$sentCount;
            } catch (\Exception $e) {
                \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            }
        }

        return $sentCount;
    }

    /**
     * Remove expired or orphaned reminder rows.
     */
    public function cleanupExpiredSchedules(?Carbon $today = null): void
    {
        $today = $today ?: Carbon::today();

        AssetExpiryReminder::with('asset')
            ->orderBy('id')
            ->chunkById(100, function ($schedules) use ($today) {
                foreach ($schedules as $schedule) {
                    if (empty($schedule->asset)) {
                        DB::transaction(function () use ($schedule) {
                            $this->deleteLinkedReminderRows($schedule);
                            $schedule->delete();
                        });

                        continue;
                    }

                    if (! $schedule->is_active || $schedule->daily_end_date->lt($today)) {
                        DB::transaction(function () use ($schedule) {
                            $this->deleteLinkedReminderRows($schedule);
                            $schedule->is_active = false;
                            $schedule->save();
                        });
                    }
                }
            });
    }

    /**
     * Sync a chunk of assets.
     */
    protected function syncAssetChunk(Collection $assets, Carbon $today): void
    {
        $businesses = Business::whereIn('id', $assets->pluck('business_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($assets as $asset) {
            $this->syncAsset($asset, $businesses->get($asset->business_id), $today);
        }
    }

    /**
     * Sync a reminder type for an asset.
     */
    protected function syncReminderType(Asset $asset, Business $business, string $type, $expiryValue, Carbon $today): void
    {
        if (empty($expiryValue)) {
            $this->deactivateReminderType($asset->id, $type);

            return;
        }

        $expiryDate = Carbon::parse($expiryValue)->startOfDay();

        if ($expiryDate->lt($today)) {
            $this->deactivateReminderType($asset->id, $type);

            return;
        }

        $emailStartDate = $expiryDate->copy()->subDays(30);
        $dailyStartDate = $expiryDate->copy()->subDays(7);
        $desiredDates = $this->buildReminderDates($today, $dailyStartDate, $expiryDate);
        $reminderName = $this->buildReminderName($asset, $type, $expiryDate->copy());

        $schedule = AssetExpiryReminder::with(['items.reminder'])
            ->firstOrNew([
                'asset_id' => $asset->id,
                'reminder_type' => $type,
            ]);

        $scheduleChanged = $schedule->exists
            ? $schedule->expiry_date?->format('Y-m-d') !== $expiryDate->format('Y-m-d')
                || $schedule->email_start_date?->format('Y-m-d') !== $emailStartDate->format('Y-m-d')
                || $schedule->daily_start_date?->format('Y-m-d') !== $dailyStartDate->format('Y-m-d')
                || $schedule->daily_end_date?->format('Y-m-d') !== $expiryDate->format('Y-m-d')
                || (int) $schedule->calendar_owner_id !== (int) $business->owner_id
                || ! $schedule->is_active
            : true;

        $schedule->business_id = $business->id;
        $schedule->expiry_date = $expiryDate->format('Y-m-d');
        $schedule->email_start_date = $emailStartDate->format('Y-m-d');
        $schedule->daily_start_date = $dailyStartDate->format('Y-m-d');
        $schedule->daily_end_date = $expiryDate->format('Y-m-d');
        $schedule->calendar_owner_id = $business->owner_id;
        $schedule->is_active = true;

        if ($scheduleChanged) {
            $schedule->last_email_sent_on = null;
        }

        $schedule->save();
        $schedule->loadMissing(['items.reminder']);

        if ($scheduleChanged || $this->linkedRemindersNeedRefresh($schedule, $desiredDates, $reminderName, $business->owner_id)) {
            $this->deleteLinkedReminderRows($schedule);
            $this->createLinkedReminderRows($schedule, $desiredDates, $reminderName, $business->owner_id);
        }
    }

    /**
     * Deactivate a reminder type without deleting the schedule row.
     */
    protected function deactivateReminderType(int $assetId, string $type): void
    {
        $schedule = AssetExpiryReminder::with('items')
            ->where('asset_id', $assetId)
            ->where('reminder_type', $type)
            ->first();

        if (empty($schedule)) {
            return;
        }

        $this->deleteLinkedReminderRows($schedule);
        $schedule->is_active = false;
        $schedule->save();
    }

    /**
     * Determine if linked reminder rows need to be rebuilt.
     */
    protected function linkedRemindersNeedRefresh(
        AssetExpiryReminder $schedule,
        array $desiredDates,
        string $reminderName,
        int $ownerId
    ): bool {
        $items = $schedule->items;

        if (count($desiredDates) !== $items->count()) {
            return true;
        }

        $expectedDates = collect($desiredDates)->values();
        $actualDates = $items->pluck('reminder_date')
            ->map(function ($date) {
                return $date instanceof Carbon ? $date->format('Y-m-d') : Carbon::parse($date)->format('Y-m-d');
            })
            ->sort()
            ->values();

        if ($expectedDates->sort()->values()->all() !== $actualDates->all()) {
            return true;
        }

        foreach ($items as $item) {
            $reminder = $item->reminder;

            if (
                empty($reminder)
                || $reminder->name !== $reminderName
                || $reminder->repeat !== 'one_time'
                || (int) $reminder->user_id !== $ownerId
                || $reminder->time !== '09:00:00'
                || (int) $reminder->business_id !== (int) $schedule->business_id
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create linked reminder rows for the schedule.
     */
    protected function createLinkedReminderRows(
        AssetExpiryReminder $schedule,
        array $desiredDates,
        string $reminderName,
        int $ownerId
    ): void {
        foreach ($desiredDates as $reminderDate) {
            $reminder = Reminder::create([
                'business_id' => $schedule->business_id,
                'user_id' => $ownerId,
                'name' => $reminderName,
                'date' => $reminderDate,
                'time' => '09:00:00',
                'end_time' => null,
                'repeat' => 'one_time',
            ]);

            AssetExpiryReminderItem::create([
                'asset_expiry_reminder_id' => $schedule->id,
                'reminder_id' => $reminder->id,
                'reminder_date' => $reminderDate,
            ]);
        }
    }

    /**
     * Delete linked reminder rows for a schedule.
     */
    protected function deleteLinkedReminderRows(AssetExpiryReminder $schedule): void
    {
        $reminderIds = $schedule->items()
            ->pluck('reminder_id')
            ->filter()
            ->all();

        if (! empty($reminderIds)) {
            Reminder::whereIn('id', $reminderIds)->delete();
        }

        $schedule->items()->delete();
    }

    /**
     * Build the one-time reminder dates for the calendar.
     */
    protected function buildReminderDates(Carbon $today, Carbon $dailyStartDate, Carbon $expiryDate): array
    {
        $reminderDates = [];
        $cursor = $dailyStartDate->copy();

        if ($cursor->lt($today)) {
            $cursor = $today->copy();
        }

        while ($cursor->lte($expiryDate)) {
            $reminderDates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $reminderDates;
    }

    /**
     * Determine whether an email should be sent for the schedule on the given date.
     */
    protected function shouldSendEmailOnDate(AssetExpiryReminder $schedule, Carbon $today): bool
    {
        if (! $schedule->is_active || empty($schedule->asset) || empty($schedule->business)) {
            return false;
        }

        if (! empty($schedule->last_email_sent_on) && $schedule->last_email_sent_on->isSameDay($today)) {
            return false;
        }

        if ($today->lt($schedule->email_start_date) || $today->gt($schedule->daily_end_date)) {
            return false;
        }

        if ($today->lt($schedule->daily_start_date)) {
            return $schedule->email_start_date->diffInDays($today) % 7 === 0;
        }

        return true;
    }

    /**
     * Build the reminder text shown in the calendar and email body.
     */
    protected function buildReminderName(Asset $asset, string $type, Carbon $expiryDate): string
    {
        $typeLabel = strtoupper($type);
        $model = ! empty($asset->model) ? " ({$asset->model})" : '';

        return "Reminder {$typeLabel} Asset {$asset->asset_code} - {$asset->name}{$model} akan expired pada {$expiryDate->format('d-m-Y')}. Mohon dipastikan dan dilakukan perpanjangan setelah dilakukan pengecekan.";
    }

    /**
     * Build the subject for asset expiry reminder emails.
     */
    protected function buildEmailSubject(Asset $asset, string $type, Carbon $expiryDate): string
    {
        return 'Email Reminder '.strtoupper($type).' Asset '.$asset->asset_code.' untuk tanggal '.$expiryDate->format('d M Y');
    }
}
