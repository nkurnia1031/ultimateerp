<?php

namespace Modules\Accounting\Services;

class LegacyJournalEntryMerger
{
    private JournalImportIdentityService $identityService;

    public function __construct(?JournalImportIdentityService $identityService = null)
    {
        $this->identityService = $identityService ?: new JournalImportIdentityService();
    }

    /**
 * Merge entries from overlapping Journal workbooks. Identical entries are kept once;
 * conflicting versions resolve to the newest source export and retain the losing
 * versions as audit provenance.
     *
     * @param array<int, array{path:string,sha256:string,entries:array}> $workbooks
     */
    public function merge(array $workbooks, ?int $year = null): array
    {
        $groups = [];
        $excludedByYear = 0;

        foreach ($workbooks as $workbook) {
            $path = (string) ($workbook['path'] ?? '');
            $fileHash = (string) ($workbook['sha256'] ?? '');
            foreach ((array) ($workbook['entries'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if ($year !== null && (int) substr((string) ($entry['date'] ?? ''), 0, 4) !== $year) {
                    $excludedByYear++;
                    continue;
                }

                $fingerprint = $this->identityService->fingerprint($entry);
                $sourceKey = trim((string) ($entry['source_key'] ?? ''));
                $groupKey = $this->isStableSourceKey($entry, $sourceKey)
                    ? $sourceKey
                    : 'unkeyed|'.$fileHash.'|'.(int) ($entry['header_row'] ?? 0).'|'.$fingerprint;

                $entry['legacy_sources'] = array_values(array_merge(
                    (array) ($entry['legacy_sources'] ?? []),
                    [[
                        'path' => $path,
                        'sha256' => $fileHash,
                        'row' => (int) ($entry['header_row'] ?? 0),
                        'source_key' => $sourceKey,
                        'source_identity' => hash('sha256', implode('|', [$sourceKey, $fingerprint])),
                        'fingerprint' => $fingerprint,
                    ]]
                ));

                $groups[$groupKey][] = [
                    'entry' => $entry,
                    'fingerprint' => $fingerprint,
                ];
            }
        }

        $merged = [];
        $duplicateCount = 0;
        $conflictCount = 0;

        foreach ($groups as $sourceKey => $group) {
            $fingerprints = array_values(array_unique(array_column($group, 'fingerprint')));
            if (count($fingerprints) === 1) {
                $entry = $group[0]['entry'];
                $entry['legacy_sources'] = [];
                foreach ($group as $candidate) {
                    $entry['legacy_sources'] = array_merge(
                        $entry['legacy_sources'],
                        (array) ($candidate['entry']['legacy_sources'] ?? [])
                    );
                }
                $entry['legacy_sources'] = $this->uniqueSources($entry['legacy_sources']);
                $duplicateCount += count($group) - 1;
                $merged[] = $entry;

                continue;
            }

            $conflictCount++;
            usort($group, fn (array $left, array $right): int => $this->compareSourcePriority($right, $left));
            $winner = $group[0];
            $entry = $winner['entry'];
            $entry['legacy_sources'] = $this->uniqueSources(array_merge(...array_map(
                fn (array $candidate): array => (array) ($candidate['entry']['legacy_sources'] ?? []),
                $group
            )));
            $entry['legacy_conflict_resolution'] = [
                'policy' => 'newest_source_export',
                'selected_fingerprint' => $winner['fingerprint'],
                'selected_source' => $this->sourceDescription($winner['entry']),
                'alternatives' => array_values(array_map(function (array $candidate): array {
                    return [
                        'fingerprint' => $candidate['fingerprint'],
                        'source' => $this->sourceDescription($candidate['entry']),
                    ];
                }, array_slice($group, 1))),
                'message' => 'Isi berbeda ditemukan untuk '.$sourceKey.'; dipilih versi dari file Journal dengan periode akhir paling baru. Semua versi disimpan pada provenance audit.',
            ];
            $entry['duplicate_matches'] = array_values(array_unique(array_merge(
                (array) ($entry['duplicate_matches'] ?? []),
                ['Konflik lintas workbook untuk kunci sumber '.$sourceKey.' diselesaikan dengan file Journal terbaru.']
            )));
            $merged[] = $entry;
        }

        return [
            'entries' => $merged,
            'duplicate_count' => $duplicateCount,
            'conflict_count' => $conflictCount,
            'excluded_by_year' => $excludedByYear,
        ];
    }

    private function isStableSourceKey(array $entry, string $sourceKey): bool
    {
        return $sourceKey !== ''
            && strtolower((string) ($entry['source_type'] ?? '')) !== 'unknown'
            && trim((string) ($entry['source_number'] ?? '')) !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($entry['date'] ?? '')) === 1;
    }

    private function uniqueSources(array $sources): array
    {
        $unique = [];
        $seen = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $identity = implode('|', [
                (string) ($source['path'] ?? ''),
                (string) ($source['row'] ?? ''),
                (string) ($source['fingerprint'] ?? ''),
            ]);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $unique[] = $source;
        }

        return $unique;
    }

    private function compareSourcePriority(array $left, array $right): int
    {
        $leftSource = $this->sourceDescription($left['entry'] ?? []);
        $rightSource = $this->sourceDescription($right['entry'] ?? []);
        $leftDate = $leftSource['period_end'] ?? '';
        $rightDate = $rightSource['period_end'] ?? '';
        $dateComparison = strcmp($leftDate, $rightDate);
        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        $rowComparison = ((int) ($leftSource['row'] ?? 0)) <=> ((int) ($rightSource['row'] ?? 0));
        if ($rowComparison !== 0) {
            return $rowComparison;
        }

        return strcmp((string) ($leftSource['path'] ?? ''), (string) ($rightSource['path'] ?? ''));
    }

    private function sourceDescription(array $entry): array
    {
        $source = (array) (($entry['legacy_sources'] ?? [])[0] ?? []);
        $path = (string) ($source['path'] ?? '');
        $periodEnd = '';
        if (preg_match_all('/(?:\d{2}-\d{2}-\d{4}|\d{4}-\d{2}-\d{2})/', $path, $matches) && ! empty($matches[0])) {
            $dateText = end($matches[0]);
            foreach (['!d-m-Y', '!Y-m-d'] as $format) {
                $date = \DateTimeImmutable::createFromFormat($format, $dateText);
                if ($date !== false) {
                    $periodEnd = $date->format('Y-m-d');
                    break;
                }
            }
        }
        if ($periodEnd === '' && preg_match_all('/(?:19|20)\d{2}/', $path, $years) && ! empty($years[0])) {
            $periodEnd = max($years[0]).'-12-31';
        }

        return [
            'path' => $path,
            'row' => (int) ($source['row'] ?? 0),
            'period_end' => $periodEnd,
        ];
    }
}
