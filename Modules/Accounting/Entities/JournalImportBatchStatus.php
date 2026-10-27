<?php

namespace Modules\Accounting\Entities;

final class JournalImportBatchStatus
{
    private const FLAGS_BY_STATUS = [
        'queued' => ['active', 'waiting', 'queued'],
        'analyzing' => ['active', 'waiting'],
        'preparing_import' => ['active', 'waiting'],
        'queued_import' => ['active', 'waiting', 'queued'],
        'importing' => ['active'],
        'reconciling' => ['active'],
        'processing' => ['active'],
    ];

    public static function activeStatuses(): array
    {
        return self::statusesWithFlag('active');
    }

    public static function waitingStatuses(): array
    {
        return self::statusesWithFlag('waiting');
    }

    public static function queuedStatuses(): array
    {
        return self::statusesWithFlag('queued');
    }

    public static function isActive(string $status): bool
    {
        return self::hasFlag($status, 'active');
    }

    public static function isWaiting(string $status): bool
    {
        return self::hasFlag($status, 'waiting');
    }

    public static function isQueued(string $status): bool
    {
        return self::hasFlag($status, 'queued');
    }

    private static function hasFlag(string $status, string $flag): bool
    {
        return in_array($flag, self::FLAGS_BY_STATUS[$status] ?? [], true);
    }

    private static function statusesWithFlag(string $flag): array
    {
        $statuses = [];

        foreach (self::FLAGS_BY_STATUS as $status => $flags) {
            if (in_array($flag, $flags, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }
}
