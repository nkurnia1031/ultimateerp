<?php

namespace Modules\Accounting\Services;

final class GeneralLedgerBackfillCheckpoint
{
    private ?string $phase;

    private ?int $cursor;

    private int $processed;

    private array $counts;

    private ?int $removedDuplicateAppPostings;

    private function __construct(
        ?string $phase,
        ?int $cursor,
        int $processed,
        array $counts,
        ?int $removedDuplicateAppPostings
    ) {
        $this->phase = $phase;
        $this->cursor = $cursor;
        $this->processed = max(0, $processed);
        $this->counts = $counts;
        $this->removedDuplicateAppPostings = $removedDuplicateAppPostings;
    }

    public static function fromArray(array $checkpoint): self
    {
        $counts = is_array($checkpoint['counts'] ?? null) ? $checkpoint['counts'] : [];
        $removedDuplicateAppPostings = null;
        if (array_key_exists('removed_duplicate_app_postings', $checkpoint)) {
            $removedDuplicateAppPostings = max(0, (int) $checkpoint['removed_duplicate_app_postings']);
        } elseif (array_key_exists('removed_duplicate_app_postings', $counts)) {
            $removedDuplicateAppPostings = max(0, (int) $counts['removed_duplicate_app_postings']);
        }

        return new self(
            isset($checkpoint['phase']) ? (string) $checkpoint['phase'] : null,
            isset($checkpoint['cursor']) ? max(0, (int) $checkpoint['cursor']) : null,
            max(0, (int) ($checkpoint['processed'] ?? 0)),
            $counts,
            $removedDuplicateAppPostings
        );
    }

    public function phase(): ?string
    {
        return $this->phase;
    }

    public function cursor(): ?int
    {
        return $this->cursor;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    public function hasCount(string $key): bool
    {
        return array_key_exists($key, $this->counts);
    }

    public function count(string $key): int
    {
        return max(0, (int) ($this->counts[$key] ?? 0));
    }

    public function removedDuplicateAppPostings(): ?int
    {
        return $this->removedDuplicateAppPostings;
    }
}
