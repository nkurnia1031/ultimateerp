<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalImportBulkMappingService
{
    public function __construct(
        private JournalImportAccountComponentService $componentService,
        private JournalImportDraftService $draftService
    ) {
    }

    public function previewExpenseCategories(object $batch, int $businessId): array
    {
        $groups = $this->expenseCategoryGroups($batch, $businessId);
        $categories = DB::table('expense_categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($category) => ['id' => (int) $category->id, 'name' => (string) $category->name])
            ->values()
            ->all();

        return ['groups' => $groups, 'categories' => $categories];
    }

    public function applyExpenseCategoryMappings(object $batch, int $businessId, array $mappings): int
    {
        if ($mappings === [] || count($mappings) > 50) {
            throw ValidationException::withMessages([
                'mappings' => 'Pilih 1 sampai 50 kelompok mapping Expense.',
            ]);
        }

        return DB::transaction(function () use ($batch, $businessId, $mappings) {
            $lockedBatch = DB::table('accounting_journal_imports')
                ->where('id', $batch->id)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();
            if (empty($lockedBatch) || ! in_array($lockedBatch->status, ['analyzed', 'partially_imported', 'failed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'batch' => 'Batch sedang diproses atau tidak dapat diubah.',
                ]);
            }

            $availableGroups = collect($this->expenseCategoryGroups($lockedBatch, $businessId))->keyBy('cohort_key');
            $selectedKeys = [];
            $selected = [];
            foreach ($mappings as $mapping) {
                $cohortKey = (string) ($mapping['cohort_key'] ?? '');
                $fingerprint = (string) ($mapping['fingerprint'] ?? '');
                $categoryId = (int) ($mapping['expense_category_id'] ?? 0);
                if (isset($selectedKeys[$cohortKey])) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Kelompok mapping yang sama dipilih lebih dari sekali.',
                    ]);
                }
                $selectedKeys[$cohortKey] = true;

                $group = $availableGroups->get($cohortKey);
                if (! $group || ! hash_equals((string) $group['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Isi batch berubah sejak preview. Muat ulang kelompok mapping sebelum menerapkan.',
                    ]);
                }

                $categoryExists = DB::table('expense_categories')
                    ->where('id', $categoryId)
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at')
                    ->whereNull('parent_id')
                    ->exists();
                if (! $categoryExists) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Kategori harus berasal dari kategori utama business aktif.',
                    ]);
                }

                $selected[] = ['group' => $group, 'category_id' => $categoryId];
            }

            $updatedRows = 0;
            foreach ($selected as $item) {
                $group = $item['group'];
                $rowIds = array_values(array_map('intval', $group['row_ids']));
                $rows = DB::table('accounting_journal_import_rows')
                    ->where('journal_import_id', $lockedBatch->id)
                    ->where('business_id', $businessId)
                    ->whereIn('id', $rowIds)
                    ->where('source_type', 'Expense')
                    ->where('source_action', 'create')
                    ->whereIn('processing_status', ['pending', 'failed'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($rows->count() !== count($rowIds)) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Sebagian baris kelompok sudah berubah atau tidak dapat diproses. Muat ulang preview.',
                    ]);
                }

                foreach ($rows as $row) {
                    $draftData = json_decode((string) ($row->draft_data ?? '{}'), true);
                    $draftData = is_array($draftData) ? $draftData : [];
                    $draftData['expense_category_mappings'] = is_array($draftData['expense_category_mappings'] ?? null)
                        ? $draftData['expense_category_mappings']
                        : [];
                    $draftData['expense_category_mappings'][$group['account_key']] = (int) $item['category_id'];
                    if (empty($draftData['location_id']) && ! empty($group['location_id'])) {
                        $draftData['location_id'] = (int) $group['location_id'];
                    }

                    DB::table('accounting_journal_import_rows')
                        ->where('id', $row->id)
                        ->where('journal_import_id', $lockedBatch->id)
                        ->where('business_id', $businessId)
                        ->update([
                            'draft_data' => json_encode($draftData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                    $updatedRows++;
                }
            }

            return $updatedRows;
        });
    }

    private function expenseCategoryGroups(object $batch, int $businessId): array
    {
        $defaultLocationId = $this->draftService->defaultActiveLocationId($businessId);
        $options = ! empty($batch->import_options) ? json_decode($batch->import_options, true) : [];
        $options = is_array($options) ? $options : [];
        $activeCategoryIds = DB::table('expense_categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
        $groups = [];

        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $businessId)
            ->where('source_type', 'Expense')
            ->where('source_action', 'create')
            ->whereIn('processing_status', ['pending', 'failed'])
            ->orderBy('id')
            ->chunkById(250, function ($rows) use (&$groups, $batch, $businessId, $defaultLocationId, $options, $activeCategoryIds) {
                foreach ($rows as $row) {
                    $entry = json_decode((string) $row->entry_data, true) ?: [];
                    $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
                    if (! empty($entry['is_unbalanced']) || ! empty($entry['is_duplicate'])
                        || ! empty($entry['exists_in_system']) || ! empty($entry['source_identity_conflict'])
                        || empty($entry['source_key']) || ! $this->isValidDate($date)
                        || ! is_numeric($entry['debit_total'] ?? null) || (float) $entry['debit_total'] <= 0
                        || ! empty($row->source_record_id) || ! empty($row->source_record_type)) {
                        continue;
                    }

                    $draftData = json_decode((string) ($row->draft_data ?? '{}'), true);
                    $draftData = is_array($draftData) ? $draftData : [];
                    $components = array_values(array_filter(
                        $this->componentService->groupJournalLines($entry, $businessId, $options),
                        fn (array $component) => empty($component['is_tax']) && (float) ($component['debit'] ?? 0) > 0
                    ));
                    if ($components === []) {
                        continue;
                    }

                    $categoryMappings = is_array($draftData['expense_category_mappings'] ?? null)
                        ? $draftData['expense_category_mappings']
                        : [];
                    $locationId = empty($draftData['location_id'])
                        ? $defaultLocationId
                        : (int) $draftData['location_id'];

                    foreach ($components as $component) {
                        $accountKey = (string) ($component['account_key'] ?? '');
                        $mappedCategoryId = (int) ($categoryMappings[$accountKey] ?? 0);
                        $defaultCategoryId = (int) ($draftData['expense_category_id'] ?? 0);
                        if ($accountKey === ''
                            || ($mappedCategoryId > 0 && isset($activeCategoryIds[$mappedCategoryId]))
                            || (count($components) === 1 && $defaultCategoryId > 0 && isset($activeCategoryIds[$defaultCategoryId]))) {
                            continue;
                        }

                        $cohortKey = hash('sha256', json_encode([
                            'business_id' => $businessId,
                            'import_id' => (int) $batch->id,
                            'source_type' => 'Expense',
                            'source_action' => 'create',
                            'field' => 'expense_category_mappings',
                            'account_key' => $accountKey,
                            'location_id' => $locationId,
                        ], JSON_UNESCAPED_SLASHES));

                        if (! isset($groups[$cohortKey])) {
                            $groups[$cohortKey] = [
                                'cohort_key' => $cohortKey,
                                'account_key' => $accountKey,
                                'account_no' => (string) ($component['account_no'] ?? ''),
                                'account_name' => (string) ($component['account_name'] ?? ''),
                                'location_id' => $locationId === null ? null : (int) $locationId,
                                'row_ids' => [],
                                'members' => [],
                                'component_total' => 0.0,
                                'journal_total' => 0.0,
                                'dates' => [],
                                'suggested_category_ids' => [],
                            ];
                        }

                        $groups[$cohortKey]['row_ids'][(int) $row->id] = (int) $row->id;
                        $groups[$cohortKey]['members'][(int) $row->id] = [
                            'id' => (int) $row->id,
                            'entry_hash' => hash('sha256', (string) $row->entry_data),
                            'draft_hash' => hash('sha256', (string) ($row->draft_data ?? '{}')),
                            'status' => (string) $row->processing_status,
                            'component_amount' => round((float) $component['debit'], 4),
                        ];
                        $groups[$cohortKey]['component_total'] += (float) $component['debit'];
                        $groups[$cohortKey]['journal_total'] += (float) $entry['debit_total'];
                        $groups[$cohortKey]['dates'][] = $date;
                    }
                }
            }, 'id');

        $categories = DB::table('expense_categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->get(['id', 'name']);
        $result = [];
        foreach ($groups as $group) {
            if ($group['row_ids'] === []) {
                continue;
            }
            ksort($group['members']);
            sort($group['dates']);
            $members = array_values($group['members']);
            $rowIds = array_values($group['row_ids']);
            sort($rowIds);
            $fingerprint = hash('sha256', json_encode([
                'cohort_key' => $group['cohort_key'],
                'members' => $members,
            ], JSON_UNESCAPED_SLASHES));
            $suggestions = $categories->filter(fn ($category) => strcasecmp(trim((string) $category->name), trim($group['account_name'])) === 0);
            $group['fingerprint'] = $fingerprint;
            $group['row_ids'] = $rowIds;
            $group['row_count'] = count($rowIds);
            $group['component_total'] = round((float) $group['component_total'], 4);
            $group['journal_total'] = round((float) $group['journal_total'], 4);
            $group['date_from'] = $group['dates'][0] ?? null;
            $group['date_to'] = $group['dates'] === [] ? null : end($group['dates']);
            $group['suggested_category_id'] = $suggestions->count() === 1 ? (int) $suggestions->first()->id : null;
            unset($group['members'], $group['dates'], $group['suggested_category_ids']);
            $result[] = $group;
        }

        usort($result, fn (array $left, array $right): int => [$left['account_name'], $left['account_no'], $left['location_id']] <=> [$right['account_name'], $right['account_no'], $right['location_id']]);

        return $result;
    }

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
