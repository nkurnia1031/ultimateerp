<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

class JournalImportSourceGroupService
{
    public function __construct(
        private JournalImportSourceGroupMatcher $matcher,
        private JournalImportSourceLinkService $sourceLinkService
    ) {
    }

    public function suggestions(int $rowId, string $sourceType, array $entry, iterable $candidates, int $businessId, array $options = []): array
    {
        $result = $this->matcher->findGroups($sourceType, $entry, $candidates, $businessId, $options);
        foreach ($result['groups'] as &$group) {
            $group['fingerprint'] = $this->matcher->groupFingerprint($rowId, $sourceType, $group);
        }
        unset($group);

        return $result;
    }

    public function validateSelectedGroup(int $rowId, string $sourceType, array $entry, iterable $candidates, array $memberIds, int $businessId, array $options = []): ?array
    {
        $group = $this->matcher->validateSelectedGroup($sourceType, $entry, $candidates, $memberIds, $businessId, $options);
        if ($group !== null) {
            $group['fingerprint'] = $this->matcher->groupFingerprint($rowId, $sourceType, $group);
        }

        return $group;
    }

    /** Re-read and lock candidates before a bulk action commits its preview. */
    public function revalidateUniqueGroups(array $items, int $businessId, array $options = []): array
    {
        $currentCandidates = $this->lockCurrentCandidates($items, $businessId);
        $validated = [];
        foreach ($items as $item) {
            $row = $item['row'];
            $entry = $item['entry'];
            $candidates = $this->currentCandidatesForItem($item, $currentCandidates);
            $result = $this->matcher->findGroups((string) $row->source_type, $entry, $candidates, $businessId, $options);
            if ($result['status'] !== 'unique') {
                throw new \RuntimeException('Kandidat grup sumber berubah sejak preview; muat ulang saran sebelum konfirmasi.');
            }
            $group = $result['groups'][0];
            $group['fingerprint'] = $this->matcher->groupFingerprint((int) $row->id, (string) $row->source_type, $group);
            if (! hash_equals((string) $item['group']['fingerprint'], $group['fingerprint'])) {
                throw new \RuntimeException('Nominal atau detail kandidat grup berubah sejak preview; muat ulang saran sebelum konfirmasi.');
            }
            $item['group'] = $group;
            $validated[] = $item;
        }

        return $validated;
    }

    /** Re-read and lock candidates before committing a group selected manually. */
    public function revalidateSelectedGroup(array $item, array $memberIds, int $businessId, array $options = []): ?array
    {
        $currentCandidates = $this->lockCurrentCandidates([$item], $businessId);
        $candidates = $this->currentCandidatesForItem($item, $currentCandidates);
        $group = $this->matcher->validateSelectedGroup(
            (string) $item['row']->source_type,
            $item['entry'],
            $candidates,
            $memberIds,
            $businessId,
            $options
        );
        if ($group !== null) {
            $group['fingerprint'] = $this->matcher->groupFingerprint(
                (int) $item['row']->id,
                (string) $item['row']->source_type,
                $group
            );
        }

        return $group;
    }

    public function bulkFingerprint(int $businessId, int $importId, array $groups): string
    {
        $fingerprints = array_values(array_map(fn (array $item): string => (string) $item['group']['fingerprint'], $groups));
        sort($fingerprints, SORT_STRING);

        return hash('sha256', json_encode([
            'business_id' => $businessId,
            'import_id' => $importId,
            'groups' => $fingerprints,
        ], JSON_UNESCAPED_SLASHES));
    }

    public function persistConfirmedGroup(object $row, array $entry, array $group, int $userId, string $method, ?string $bulkFingerprint = null): object
    {
        $members = array_values($group['members'] ?? []);
        if (count($members) < 2) {
            throw new \InvalidArgumentException('Grup sumber harus memuat minimal dua transaksi.');
        }
        $primary = $members[0];
        $entry['source_link_status'] = 'matched';
        $entry['source_link_label'] = 'Grup split ('.count($members).' transaksi)';
        $entry['source_link_note'] = 'Satu Journal ditautkan ke '.count($members).' transaksi sumber yang tetap terpisah.';
        $entry['source_link_group'] = [
            'status' => 'confirmed',
            'method' => $method,
            'fingerprint' => (string) ($group['fingerprint'] ?? ''),
            'bulk_confirmation_fingerprint' => $bulkFingerprint,
            'confirmed_by' => $userId,
            'confirmed_at' => now()->toDateTimeString(),
            'member_ids' => array_values(array_map('intval', $group['member_ids'] ?? [])),
            'members' => $members,
            'base_amount' => (float) $group['base_amount'],
            'tax_amount' => (float) $group['tax_amount'],
            'total_amount' => (float) $group['total_amount'],
            'journal_base_amount' => (float) $group['journal_base_amount'],
            'journal_tax_amount' => (float) $group['journal_tax_amount'],
            'journal_total_amount' => (float) $group['journal_total_amount'],
        ];

        DB::table('accounting_journal_import_rows')
            ->where('id', $row->id)
            ->where('business_id', $row->business_id)
            ->update([
                'source_action' => 'link',
                'source_record_type' => (string) $primary['record_type'],
                'source_record_id' => (int) $primary['id'],
                'entry_data' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_reviewed' => 1,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->sourceLinkService->sync(
            (int) $row->id,
            (int) $row->journal_import_id,
            (int) $row->business_id,
            (string) $row->source_type,
            $entry,
            (string) $primary['record_type'],
            (int) $primary['id']
        );

        return DB::table('accounting_journal_import_rows')->where('id', $row->id)->first();
    }

    public function removeForImport(int $importId, int $businessId): int
    {
        return $this->sourceLinkService->removeForImport($importId, $businessId);
    }

    private function lockCurrentCandidates(array $items, int $businessId): array
    {
        $candidateIds = [];
        foreach ($items as $item) {
            foreach (($item['candidates'] ?? []) as $candidate) {
                if (($candidate['record_type'] ?? null) === \App\Transaction::class && (int) ($candidate['id'] ?? 0) > 0) {
                    $candidateIds[(int) $candidate['id']] = (int) $candidate['id'];
                }
            }
        }

        $records = [];
        foreach (array_chunk(array_values($candidateIds), 500) as $ids) {
            $rows = DB::table('transactions')
                ->where('business_id', $businessId)
                ->whereIn('type', ['expense', 'purchase'])
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get([
                    'id', 'type', 'invoice_no', 'ref_no', 'transaction_date',
                    'total_before_tax', 'tax_amount', 'final_total',
                ]);
            foreach ($rows as $row) {
                $records[(int) $row->id] = $row;
            }
        }

        return $records;
    }

    private function currentCandidatesForItem(array $item, array $records): array
    {
        $current = [];
        foreach (($item['candidates'] ?? []) as $candidate) {
            $id = (int) ($candidate['id'] ?? 0);
            $record = $records[$id] ?? null;
            if ($record === null || ($candidate['record_type'] ?? null) !== \App\Transaction::class) {
                continue;
            }
            $candidate['transaction_type'] = (string) $record->type;
            $candidate['date'] = substr((string) $record->transaction_date, 0, 10);
            $candidate['amount'] = $record->final_total === null ? null : (float) $record->final_total;
            $candidate['base_amount'] = $record->total_before_tax === null ? null : (float) $record->total_before_tax;
            $candidate['tax_amount'] = $record->tax_amount === null ? null : (float) $record->tax_amount;
            $candidate['references'] = array_values(array_filter(array_map('trim', [
                (string) ($record->invoice_no ?? ''),
                (string) ($record->ref_no ?? ''),
            ]), fn (string $reference): bool => $reference !== ''));
            $current[] = $candidate;
        }

        return $current;
    }
}
