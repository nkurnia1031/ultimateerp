<?php

namespace App\Services;

use App\Business;
use App\Notifications\ReminderEmailNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Modules\AssetManagement\Entities\AssetExpiryReminder;
use Modules\Essentials\Entities\ReminderEmailDigest;

class ReminderDigestService
{
    protected const LOW_FREQUENCY_REPEATS = [
        'one_time',
        'every_month',
        'every_quarter',
        'every_year',
    ];

    protected const MAX_DIGEST_WINDOW_DAYS = 30;

    protected const LIST_GROUP_LABELS = [
        'today' => 'Hari ini',
        'tomorrow' => 'Besok',
        'week' => 'Minggu ini',
        'later' => 'Berikutnya',
    ];

    /**
     * Send the daily reminder digest per business.
     */
    public function sendDailyDigest(?Carbon $today = null): int
    {
        $today = $today ?: Carbon::today();
        $groups = $this->collectDueItems($today);

        if ($groups->isEmpty()) {
            return 0;
        }

        $businesses = Business::with('owner')
            ->whereIn('id', $groups->keys()->all())
            ->get()
            ->keyBy('id');

        $sentCount = 0;

        foreach ($groups as $businessId => $items) {
            $business = $businesses->get($businessId);

            if (empty($business)) {
                continue;
            }

            if ($this->sendDigestForBusiness($business, $items->values(), $today)) {
                ++$sentCount;
            }
        }

        return $sentCount;
    }

    /**
     * Send a digest for one business, optionally bypassing same-day duplicate checks.
     */
    public function sendDigestForBusiness(Business $business, Collection $items, Carbon $today, bool $force = false): bool
    {
        if ($items->isEmpty()) {
            return false;
        }

        $recipient = data_get($business->email_settings, 'mail_from_address');
        if (empty($recipient)) {
            return false;
        }

        $payload = $this->buildDigestPayload($business, $items->values(), $today);
        $payload['digest_signature'] = $this->buildDigestSignature($payload, $recipient);
        $sendDate = $today->format('Y-m-d');

        $digestLog = ReminderEmailDigest::firstOrNew([
            'business_id' => $business->id,
            'send_date' => $sendDate,
        ]);

        if (
            ! $force
            && $digestLog->exists
            && $digestLog->status === 'sent'
            && data_get($digestLog->payload, 'digest_signature') === $payload['digest_signature']
        ) {
            return false;
        }

        $digestLog->recipient = $recipient;
        $digestLog->subject = $payload['subject'];
        $digestLog->status = 'processing';
        $digestLog->item_count = $payload['summary']['total_count'];
        $digestLog->payload = $payload;
        $digestLog->save();

        try {
            Notification::route('mail', $recipient)
                ->notify(new ReminderEmailNotification([
                    'email_settings' => $business->email_settings ?? [],
                    'subject' => $payload['subject'],
                    'view' => 'emails.reminder_digest',
                    'view_data' => $payload,
                ]));

            $digestLog->status = 'sent';
            $digestLog->sent_at = Carbon::now();
            $digestLog->save();

            $this->markAssetSchedulesSent($items->values(), $today);

            return true;
        } catch (\Exception $e) {
            $digestLog->status = 'failed';
            $digestLog->error_message = $e->getMessage();
            $digestLog->save();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
        }

        return false;
    }

    /**
     * Collect all digest items grouped by business.
     */
    public function collectDueItems(?Carbon $today = null, ?int $businessId = null): Collection
    {
        $today = $today ?: Carbon::today();

        $items = collect()
            ->merge($this->collectReminderItems($today, $businessId))
            ->merge($this->collectAssetItems($today, $businessId));

        return $items->groupBy('business_id');
    }

    /**
     * Collect due items for a single business.
     */
    public function collectDueItemsForBusiness(int $businessId, ?Carbon $today = null): Collection
    {
        return $this->collectDueItems($today, $businessId)->get($businessId, collect());
    }

    /**
     * Build the payload used by the digest email view.
     */
    public function buildDigestPayload(Business $business, Collection $items, Carbon $today): array
    {
        $sortedItems = $items
            ->sortBy(function ($item) {
                return sprintf(
                    '%03d-%s-%s',
                    $item['days_left'],
                    $item['due_date'],
                    mb_strtolower($item['title'])
                );
            })
            ->values();

        return [
            'business_name' => $business->name,
            'owner_name' => trim(($business->owner?->user_full_name ?? '')),
            'display_date' => $this->formatShortDate($today),
            'subject' => 'Reminder Digest - '.$this->formatShortDate($today),
            'hero_summary' => $this->buildHeroSummary($sortedItems->first()),
            'summary' => [
                'total_count' => $sortedItems->count(),
                'asset_count' => $sortedItems->where('source', 'asset')->count(),
                'reminder_count' => $sortedItems->where('source', 'reminder')->count(),
            ],
            'summary_counts' => $this->buildSummaryCounts($sortedItems),
            'list_groups' => $this->buildListGroups($sortedItems),
        ];
    }

    /**
     * Collect low-frequency reminders that should be emailed today.
     */
    protected function collectReminderItems(Carbon $today, ?int $businessId = null): Collection
    {
        $rows = \DB::table('essentials_reminders as er')
            ->leftJoin('asset_expiry_reminder_items as aeri', 'aeri.reminder_id', '=', 'er.id')
            ->join('users as u', 'u.id', '=', 'er.user_id')
            ->whereNull('aeri.reminder_id')
            ->whereIn('er.repeat', self::LOW_FREQUENCY_REPEATS)
            ->when($businessId, function ($query) use ($businessId) {
                $query->where('er.business_id', $businessId);
            })
            ->select(
                'er.id',
                'er.business_id',
                'er.user_id',
                'er.name',
                'er.date',
                'er.repeat',
                'er.time',
                'u.surname',
                'u.first_name',
                'u.last_name'
            )
            ->get();

        $items = collect();

        foreach ($rows as $row) {
            $occurrenceDate = $this->nextOccurrenceDate(Carbon::parse($row->date)->startOfDay(), $row->repeat, $today);

            if (empty($occurrenceDate)) {
                continue;
            }

            $daysLeft = $today->diffInDays($occurrenceDate, false);
            if ($daysLeft < 0 || $daysLeft > self::MAX_DIGEST_WINDOW_DAYS) {
                continue;
            }

            if (! $this->shouldSendOnSchedule($occurrenceDate, $today)) {
                continue;
            }

            $ownerName = $this->buildUserName($row);
            $repeatLabel = $this->repeatLabel($row->repeat);
            $meta = [
                ['label' => 'Sumber', 'value' => 'Reminder Umum'],
                ['label' => 'Pemilik', 'value' => $ownerName],
                ['label' => 'Repeat', 'value' => $repeatLabel],
            ];

            if (! empty($row->time)) {
                $meta[] = ['label' => 'Waktu', 'value' => Carbon::parse($row->time)->format('H:i')];
            }

            $items->push($this->buildDigestItem([
                'business_id' => (int) $row->business_id,
                'source' => 'reminder',
                'source_name' => 'Reminder',
                'source_detail' => $repeatLabel,
                'owner_label' => $ownerName,
                'title' => $row->name,
                'meta' => $meta,
                'source_ref' => (int) $row->id,
            ], $occurrenceDate, $daysLeft));
        }

        return $items;
    }

    /**
     * Collect asset expiry reminders that should be emailed today.
     */
    protected function collectAssetItems(Carbon $today, ?int $businessId = null): Collection
    {
        $schedules = AssetExpiryReminder::with(['asset', 'business.owner'])
            ->where('is_active', true)
            ->whereDate('expiry_date', '>=', $today->format('Y-m-d'))
            ->when($businessId, function ($query) use ($businessId) {
                $query->where('business_id', $businessId);
            })
            ->get();

        $items = collect();

        foreach ($schedules as $schedule) {
            if (empty($schedule->asset) || empty($schedule->business)) {
                continue;
            }

            $daysLeft = $today->diffInDays($schedule->expiry_date, false);
            if ($daysLeft < 0 || $daysLeft > self::MAX_DIGEST_WINDOW_DAYS) {
                continue;
            }

            if (! $this->shouldSendOnSchedule($schedule->expiry_date, $today)) {
                continue;
            }

            $asset = $schedule->asset;
            $ownerName = trim(($schedule->business->owner?->user_full_name ?? ''));
            $model = ! empty($asset->model) ? " ({$asset->model})" : '';
            $assetType = strtoupper($schedule->reminder_type);
            $assetTitle = trim($assetType.' Asset '.$asset->asset_code.' - '.$asset->name.$model);

            $items->push($this->buildDigestItem([
                'business_id' => (int) $schedule->business_id,
                'source' => 'asset',
                'source_name' => 'Asset',
                'source_detail' => $assetType,
                'owner_label' => $ownerName ?: '-',
                'title' => $assetTitle,
                'meta' => [
                    ['label' => 'Sumber', 'value' => 'Asset'],
                    ['label' => 'Pemilik', 'value' => $ownerName ?: '-'],
                    ['label' => 'Tipe asset', 'value' => $assetType],
                ],
                'source_ref' => (int) $schedule->id,
            ], Carbon::parse($schedule->expiry_date)->startOfDay(), $daysLeft));
        }

        return $items;
    }

    /**
     * Determine the next occurrence for a repeating reminder.
     */
    protected function nextOccurrenceDate(Carbon $baseDate, string $repeat, Carbon $today): ?Carbon
    {
        if ($repeat === 'one_time') {
            return $baseDate->copy();
        }

        $occurrence = $baseDate->copy();
        $advanceCount = 0;

        while ($occurrence->lt($today)) {
            $occurrence = $this->advanceRepeatDate($occurrence, $repeat);
            ++$advanceCount;

            if ($advanceCount > 240) {
                return null;
            }
        }

        return $occurrence;
    }

    /**
     * Advance a reminder date based on the repeat pattern.
     */
    protected function advanceRepeatDate(Carbon $date, string $repeat): Carbon
    {
        if ($repeat === 'every_quarter') {
            return $date->copy()->addMonthsNoOverflow(3);
        }

        if ($repeat === 'every_year') {
            return $date->copy()->addYearNoOverflow();
        }

        return $date->copy()->addMonthNoOverflow();
    }

    /**
     * Determine if the schedule should email today.
     */
    protected function shouldSendOnSchedule(Carbon $dueDate, Carbon $today): bool
    {
        $emailStartDate = $dueDate->copy()->subDays(30);
        $dailyStartDate = $dueDate->copy()->subDays(7);

        if ($today->lt($emailStartDate) || $today->gt($dueDate)) {
            return false;
        }

        if ($today->lt($dailyStartDate)) {
            return $emailStartDate->diffInDays($today) % 7 === 0;
        }

        return true;
    }

    /**
     * Mark asset schedules as emailed for today.
     */
    protected function markAssetSchedulesSent(Collection $items, Carbon $today): void
    {
        $scheduleIds = $items
            ->where('source', 'asset')
            ->pluck('source_ref')
            ->unique()
            ->all();

        if (empty($scheduleIds)) {
            return;
        }

        AssetExpiryReminder::whereIn('id', $scheduleIds)
            ->update(['last_email_sent_on' => $today->format('Y-m-d')]);
    }

    /**
     * Build a normalized digest item payload.
     */
    protected function buildDigestItem(array $payload, Carbon $dueDate, int $daysLeft): array
    {
        return array_merge($payload, [
            'days_left' => (int) $daysLeft,
            'due_date' => $dueDate->format('Y-m-d'),
            'relative_due_label' => $this->relativeDueLabel($daysLeft),
            'due_date_full_label' => $this->formatFullDate($dueDate),
            'group_key' => $this->groupKeyForDaysLeft($daysLeft),
            'group_label' => $this->groupLabelForDaysLeft($daysLeft),
            'status_tone' => $this->statusToneForDaysLeft($daysLeft),
        ]);
    }

    /**
     * Build the high-level summary cards keyed by time horizon.
     */
    protected function buildSummaryCounts(Collection $items): array
    {
        return [
            'today' => $this->buildSummaryCard($items, fn ($item) => $item['days_left'] === 0, 'Hari ini'),
            'tomorrow' => $this->buildSummaryCard($items, fn ($item) => $item['days_left'] === 1, 'Besok'),
            'within_7_days' => $this->buildSummaryCard($items, fn ($item) => $item['days_left'] >= 0 && $item['days_left'] <= 7, '7 hari'),
            'within_30_days' => $this->buildSummaryCard($items, fn ($item) => $item['days_left'] >= 0 && $item['days_left'] <= self::MAX_DIGEST_WINDOW_DAYS, '30 hari'),
        ];
    }

    /**
     * Build one summary card with asset/reminder breakdown.
     */
    protected function buildSummaryCard(Collection $items, callable $filter, string $label): array
    {
        $filtered = $items->filter($filter)->values();
        $assetCount = $filtered->where('source', 'asset')->count();
        $reminderCount = $filtered->where('source', 'reminder')->count();

        return [
            'label' => $label,
            'total' => $filtered->count(),
            'asset_count' => $assetCount,
            'reminder_count' => $reminderCount,
        ];
    }

    /**
     * Build flat list groups used only as visual chunking in the email.
     */
    protected function buildListGroups(Collection $items): array
    {
        $groups = [];

        foreach (self::LIST_GROUP_LABELS as $key => $label) {
            $groupItems = $items->where('group_key', $key)->values();

            if ($groupItems->isEmpty()) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $label,
                'items' => $groupItems->all(),
            ];
        }

        return $groups;
    }

    /**
     * Build the hero summary line shown above the digest cards.
     */
    protected function buildHeroSummary(?array $item): ?string
    {
        if (empty($item)) {
            return null;
        }

        return 'Terdekat: '.$item['relative_due_label'].' - '.$item['title'].' - jatuh tempo '.$item['due_date_full_label'];
    }

    /**
     * Return an exact relative label for the number of days left.
     */
    protected function relativeDueLabel(int $daysLeft): string
    {
        if ($daysLeft === 0) {
            return 'Hari ini';
        }

        if ($daysLeft === 1) {
            return 'Besok';
        }

        return $daysLeft.' hari lagi';
    }

    /**
     * Map a due date to a visual group key.
     */
    protected function groupKeyForDaysLeft(int $daysLeft): string
    {
        if ($daysLeft === 0) {
            return 'today';
        }

        if ($daysLeft === 1) {
            return 'tomorrow';
        }

        if ($daysLeft >= 2 && $daysLeft <= 7) {
            return 'week';
        }

        return 'later';
    }

    /**
     * Map a due date to a visual group label.
     */
    protected function groupLabelForDaysLeft(int $daysLeft): string
    {
        return self::LIST_GROUP_LABELS[$this->groupKeyForDaysLeft($daysLeft)];
    }

    /**
     * Build a simple tone token for urgency styling.
     */
    protected function statusToneForDaysLeft(int $daysLeft): string
    {
        if ($daysLeft === 0) {
            return 'today';
        }

        if ($daysLeft === 1) {
            return 'tomorrow';
        }

        if ($daysLeft <= 7) {
            return 'week';
        }

        return 'later';
    }

    /**
     * Get a readable reminder repeat label.
     */
    protected function repeatLabel(string $repeat): string
    {
        return match ($repeat) {
            'one_time' => 'Satu kali',
            'every_day' => 'Harian',
            'every_week' => 'Mingguan',
            'every_month' => 'Bulanan',
            'every_quarter' => 'Triwulanan',
            'every_year' => 'Tahunan',
            default => ucfirst(str_replace('_', ' ', $repeat)),
        };
    }

    /**
     * Format a date in Indonesian with weekday for due dates.
     */
    protected function formatFullDate(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('l, d F Y');
    }

    /**
     * Format a shorter Indonesian date for digest header/subject.
     */
    protected function formatShortDate(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('d F Y');
    }

    /**
     * Build a stable content signature for the digest payload.
     */
    protected function buildDigestSignature(array $payload, string $recipient): string
    {
        return sha1(json_encode([
            'recipient' => $recipient,
            'business_name' => $payload['business_name'] ?? null,
            'owner_name' => $payload['owner_name'] ?? null,
            'display_date' => $payload['display_date'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'summary_counts' => $payload['summary_counts'] ?? [],
            'hero_summary' => $payload['hero_summary'] ?? null,
            'list_groups' => $payload['list_groups'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Build a display name from reminder user columns.
     */
    protected function buildUserName($row): string
    {
        return trim(implode(' ', array_filter([
            $row->surname ?? '',
            $row->first_name ?? '',
            $row->last_name ?? '',
        ]))) ?: '-';
    }

}
