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

    public function previewCohorts(object $batch, int $businessId, ?string $sourceType = null): array
    {
        $groups = [];
        if ($sourceType === null || $sourceType === 'Expense') {
            $groups = array_merge($groups, $this->expenseCategoryGroups($batch, $businessId));
        }
        if ($sourceType === null || in_array($sourceType, ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal'], true)) {
            $groups = array_merge($groups, $this->bankAccountGroups($batch, $businessId, $sourceType));
        }
        if ($sourceType === null || in_array($sourceType, ['Receive Payment', 'Purchase Payment'], true)) {
            $groups = array_merge($groups, $this->paymentInvoiceGroups($batch, $businessId, $sourceType));
        }

        usort($groups, fn (array $left, array $right): int =>
            [$left['source_type'], $left['field'], $left['context_label']] <=> [$right['source_type'], $right['field'], $right['context_label']]
        );

        $totalGroupCount = count($groups);
        $groups = array_slice($groups, 0, 50);
        $previewRowIds = [];
        foreach ($groups as $group) {
            foreach ($group['row_ids'] as $rowId) {
                $previewRowIds[(int) $rowId] = true;
            }
        }
        $categories = DB::table('expense_categories')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($category) => ['id' => (int) $category->id, 'name' => (string) $category->name])
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'categories' => $categories,
            'total_group_count' => $totalGroupCount,
            'total_row_count' => count($previewRowIds),
            'has_more' => $totalGroupCount > count($groups),
        ];
    }

    public function applyCohortMappings(object $batch, int $businessId, array $mappings, ?string $sourceType = null): int
    {
        if ($mappings === [] || count($mappings) > 50) {
            throw ValidationException::withMessages([
                'mappings' => 'Pilih 1 sampai 50 kelompok mapping.',
            ]);
        }

        return DB::transaction(function () use ($batch, $businessId, $mappings, $sourceType) {
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

            $availableGroups = collect($this->previewCohorts($lockedBatch, $businessId, $sourceType)['groups'])->keyBy('cohort_key');
            $selectedKeys = [];
            $selected = [];
            foreach ($mappings as $mapping) {
                $cohortKey = (string) ($mapping['cohort_key'] ?? '');
                $fingerprint = (string) ($mapping['fingerprint'] ?? '');
                $field = (string) ($mapping['field'] ?? '');
                $value = trim((string) ($mapping['value'] ?? ''));
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

                if ($field !== (string) $group['field'] || $value === ''
                    || ! collect($group['options'])->contains(fn (array $option) => (string) $option['id'] === $value)) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Pilihan mapping tidak tersedia untuk kelompok ini. Muat ulang preview.',
                    ]);
                }

                $selected[] = ['group' => $group, 'value' => $value];
            }

            $updatedRowIds = [];
            $currentDraftHashes = [];
            foreach ($selected as $item) {
                $group = $item['group'];
                $rowIds = array_values(array_map('intval', $group['row_ids']));
                $rows = DB::table('accounting_journal_import_rows')
                    ->where('journal_import_id', $lockedBatch->id)
                    ->where('business_id', $businessId)
                    ->whereIn('id', $rowIds)
                    ->where('source_type', $group['source_type'])
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

                $expectedMembers = collect($group['_members'] ?? [])->keyBy('id');
                foreach ($rows as $row) {
                    $expected = $expectedMembers->get((int) $row->id);
                    $expectedDraftHash = $currentDraftHashes[(int) $row->id] ?? ($expected['draft_hash'] ?? null);
                    if (! is_array($expected)
                        || (int) $expected['id'] !== (int) $row->id
                        || ! hash_equals((string) $expected['entry_hash'], hash('sha256', (string) $row->entry_data))
                        || ! hash_equals((string) $expectedDraftHash, hash('sha256', (string) ($row->draft_data ?? '{}')))
                        || (string) $expected['status'] !== (string) $row->processing_status) {
                        throw ValidationException::withMessages([
                            'mappings' => 'Isi baris kelompok berubah saat mapping diterapkan. Muat ulang preview.',
                        ]);
                    }
                }

                foreach ($rows as $row) {
                    $draftData = json_decode((string) ($row->draft_data ?? '{}'), true);
                    $draftData = is_array($draftData) ? $draftData : [];

                    if ($group['field'] === 'expense_category_mappings') {
                        $draftData['expense_category_mappings'] = is_array($draftData['expense_category_mappings'] ?? null)
                            ? $draftData['expense_category_mappings']
                            : [];
                        $draftData['expense_category_mappings'][$group['account_key']] = (int) $item['value'];
                        if (empty($draftData['location_id']) && ! empty($group['location_id'])) {
                            $draftData['location_id'] = (int) $group['location_id'];
                        }
                    } elseif (in_array($group['field'], ['payment_account_id', 'transfer_account_id'], true)) {
                        $draftData[$group['field']] = (int) $item['value'];
                    } elseif ($group['field'] === 'invoice_reference') {
                        $draftData['invoice_reference'] = (string) $item['value'];
                    }

                    if (in_array($group['source_type'], ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal'], true)
                        && ! empty($draftData['payment_account_id']) && ! empty($draftData['transfer_account_id'])
                        && (int) $draftData['payment_account_id'] === (int) $draftData['transfer_account_id']) {
                        throw ValidationException::withMessages([
                            'mappings' => 'Akun kas/bank asal dan tujuan transfer harus berbeda.',
                        ]);
                    }

                    $encodedDraftData = json_encode($draftData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    DB::table('accounting_journal_import_rows')
                        ->where('id', $row->id)
                        ->where('journal_import_id', $lockedBatch->id)
                        ->where('business_id', $businessId)
                        ->update([
                            'draft_data' => $encodedDraftData,
                            'updated_at' => now(),
                        ]);
                    $currentDraftHashes[(int) $row->id] = hash('sha256', (string) $encodedDraftData);
                    $updatedRowIds[(int) $row->id] = true;
                }
            }

            return count($updatedRowIds);
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
                    if (! $this->isEligibleSourceRow($row, $entry)) {
                        continue;
                    }
                    $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);

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
                                'source_type' => 'Expense',
                                'field' => 'expense_category_mappings',
                                'field_label' => 'Kategori Expense',
                                'context_label' => trim((string) ($component['account_no'] ?? '').' '.(string) ($component['account_name'] ?? '')).' · lokasi #'.$locationId,
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
            ->orderBy('name')
            ->orderBy('id')
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
                'options' => array_column($categories->all(), 'id'),
            ], JSON_UNESCAPED_SLASHES));
            $suggestions = $categories->filter(fn ($category) => strcasecmp(trim((string) $category->name), trim($group['account_name'])) === 0);
            $group['fingerprint'] = $fingerprint;
            $group['row_ids'] = $rowIds;
            $group['row_count'] = count($rowIds);
            $group['component_total'] = round((float) $group['component_total'], 4);
            $group['journal_total'] = round((float) $group['journal_total'], 4);
            $group['date_from'] = $group['dates'][0] ?? null;
            $group['date_to'] = $group['dates'] === [] ? null : end($group['dates']);
            $group['_members'] = $members;
            $group['suggested_category_id'] = $suggestions->count() === 1 ? (int) $suggestions->first()->id : null;
            $group['suggested_value'] = $group['suggested_category_id'] === null ? null : (string) $group['suggested_category_id'];
            $group['options'] = $categories->map(fn ($category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ])->values()->all();
            $group['component_label'] = trim((string) $group['account_no'].' '.(string) $group['account_name']);
            unset($group['members'], $group['dates'], $group['suggested_category_ids']);
            $result[] = $group;
        }

        usort($result, fn (array $left, array $right): int => [$left['account_name'], $left['account_no'], $left['location_id']] <=> [$right['account_name'], $right['account_no'], $right['location_id']]);

        return $result;
    }

    private function bankAccountGroups(object $batch, int $businessId, ?string $sourceType): array
    {
        $options = DB::table('accounts')
            ->where('business_id', $businessId)
            ->where('is_closed', 0)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'account_number'])
            ->map(fn ($account) => [
                'id' => (int) $account->id,
                'name' => trim((string) $account->name.' · '.(string) $account->account_number),
            ])->values()->all();
        $groups = [];
        $query = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $businessId)
            ->whereIn('source_type', ['Bank Deposit', 'Bank Transfer', 'Bank Withdrawal'])
            ->where('source_action', 'create')
            ->whereIn('processing_status', ['pending', 'failed'])
            ->orderBy('id');
        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        $query->chunkById(250, function ($rows) use (&$groups, $batch, $businessId, $options) {
            $importOptions = ! empty($batch->import_options) ? json_decode($batch->import_options, true) : [];
            $importOptions = is_array($importOptions) ? $importOptions : [];
            foreach ($rows as $row) {
                $entry = json_decode((string) $row->entry_data, true) ?: [];
                if (! $this->isEligibleSourceRow($row, $entry)) {
                    continue;
                }
                $draftData = json_decode((string) ($row->draft_data ?? '{}'), true) ?: [];
                $components = $this->componentService->groupJournalLines($entry, $businessId, $importOptions);
                $context = $this->journalAccountContext($components);
                if ($context === null) {
                    continue;
                }

                $fields = [];
                if (empty($draftData['payment_account_id'])) {
                    $fields[] = 'payment_account_id';
                }
                if ($row->source_type === 'Bank Transfer' && empty($draftData['transfer_account_id'])) {
                    $fields[] = 'transfer_account_id';
                }
                foreach ($fields as $field) {
                    $cohortKey = hash('sha256', json_encode([
                        'business_id' => $businessId,
                        'import_id' => (int) $batch->id,
                        'source_type' => (string) $row->source_type,
                        'field' => $field,
                        'journal_context' => $context['key'],
                    ], JSON_UNESCAPED_SLASHES));
                    if (! isset($groups[$cohortKey])) {
                        $groups[$cohortKey] = [
                            'cohort_key' => $cohortKey,
                            'source_type' => (string) $row->source_type,
                            'field' => $field,
                            'field_label' => $field === 'payment_account_id' ? 'Akun kas/bank' : 'Akun tujuan transfer',
                            'context_label' => $context['label'],
                            'account_key' => null,
                            'location_id' => null,
                            'row_ids' => [],
                            'members' => [],
                            'component_total' => 0.0,
                            'journal_total' => 0.0,
                            'dates' => [],
                            'suggested_value' => null,
                            'options' => $options,
                        ];
                    }
                    $groups[$cohortKey]['row_ids'][(int) $row->id] = (int) $row->id;
                    $groups[$cohortKey]['members'][(int) $row->id] = [
                        'id' => (int) $row->id,
                        'entry_hash' => hash('sha256', (string) $row->entry_data),
                        'draft_hash' => hash('sha256', (string) ($row->draft_data ?? '{}')),
                        'status' => (string) $row->processing_status,
                    ];
                    $groups[$cohortKey]['component_total'] += (float) $entry['debit_total'];
                    $groups[$cohortKey]['journal_total'] += (float) $entry['debit_total'];
                    $groups[$cohortKey]['dates'][] = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
                }
            }
        }, 'id');

        return $this->finalizeGroups($groups);
    }

    private function paymentInvoiceGroups(object $batch, int $businessId, ?string $sourceType): array
    {
        $rowsByTypeAndReference = [];
        $references = [];
        $query = DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $businessId)
            ->whereIn('source_type', ['Receive Payment', 'Purchase Payment'])
            ->where('source_action', 'create')
            ->whereIn('processing_status', ['pending', 'failed'])
            ->orderBy('id');
        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        $query->chunkById(250, function ($rows) use (&$rowsByTypeAndReference, &$references) {
            foreach ($rows as $row) {
                $entry = json_decode((string) $row->entry_data, true) ?: [];
                if (! $this->isEligibleSourceRow($row, $entry)) {
                    continue;
                }
                $draftData = json_decode((string) ($row->draft_data ?? '{}'), true) ?: [];
                if (! empty($draftData['invoice_reference'])) {
                    continue;
                }
                $reference = trim((string) ($entry['source_number'] ?? ''));
                if ($reference === '') {
                    $reference = trim((string) ($row->source_number ?? ''));
                }
                $normalizedReference = $this->normalizeReference($reference);
                if ($normalizedReference === '') {
                    continue;
                }
                $type = (string) $row->source_type;
                $rowsByTypeAndReference[$type][$normalizedReference]['reference'] = $reference;
                $rowsByTypeAndReference[$type][$normalizedReference]['rows'][(int) $row->id] = $row;
                $references[$type][$normalizedReference] = true;
            }
        }, 'id');

        $groups = [];
        foreach ($rowsByTypeAndReference as $type => $referenceGroups) {
            $targets = $this->exactInvoiceTargets($batch, $businessId, $type, array_keys($references[$type] ?? []));
            foreach ($referenceGroups as $normalizedReference => $referenceGroup) {
                $candidates = array_values($targets[$normalizedReference] ?? []);
                if (count($candidates) !== 1) {
                    continue;
                }
                $rowIds = array_keys($referenceGroup['rows']);
                sort($rowIds);
                $members = [];
                $journalTotal = 0.0;
                $dates = [];
                foreach ($referenceGroup['rows'] as $row) {
                    $entry = json_decode((string) $row->entry_data, true) ?: [];
                    $members[(int) $row->id] = [
                        'id' => (int) $row->id,
                        'entry_hash' => hash('sha256', (string) $row->entry_data),
                        'draft_hash' => hash('sha256', (string) ($row->draft_data ?? '{}')),
                        'status' => (string) $row->processing_status,
                    ];
                    $journalTotal += (float) $entry['debit_total'];
                    $dates[] = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
                }
                ksort($members);
                sort($dates);
                $cohortKey = hash('sha256', json_encode([
                    'business_id' => $businessId,
                    'import_id' => (int) $batch->id,
                    'source_type' => $type,
                    'field' => 'invoice_reference',
                    'source_reference' => $normalizedReference,
                    'invoice_target' => $candidates[0]['id'],
                ], JSON_UNESCAPED_SLASHES));
                $groups[$cohortKey] = [
                    'cohort_key' => $cohortKey,
                    'source_type' => $type,
                    'field' => 'invoice_reference',
                    'field_label' => 'Invoice induk',
                    'context_label' => 'Referensi Journal: '.$referenceGroup['reference'].' · '.$candidates[0]['name'],
                    'account_key' => null,
                    'location_id' => null,
                    'row_ids' => $rowIds,
                    'members' => array_values($members),
                    'component_total' => 0.0,
                    'journal_total' => round($journalTotal, 4),
                    'dates' => $dates,
                    'suggested_value' => (string) $candidates[0]['id'],
                    'options' => $candidates,
                ];
            }
        }

        return $this->finalizeGroups($groups);
    }

    private function exactInvoiceTargets(object $batch, int $businessId, string $paymentType, array $references): array
    {
        if ($references === []) {
            return [];
        }
        $invoiceType = $paymentType === 'Receive Payment' ? 'sell' : 'purchase';
        $invoiceDraftType = $paymentType === 'Receive Payment' ? 'Sales Invoice' : 'Purchase Invoice';
        $targets = [];
        foreach (array_chunk($references, 3000) as $referenceChunk) {
            $invoiceQuery = DB::table('transactions as invoice')
                ->leftJoin('contacts as contact', 'contact.id', '=', 'invoice.contact_id')
                ->where('invoice.business_id', $businessId)
                ->where('invoice.type', $invoiceType)
                ->where('invoice.status', 'final')
                ->where(function ($query) use ($referenceChunk) {
                    $query->whereIn(DB::raw('LOWER(TRIM(invoice.invoice_no))'), $referenceChunk)
                        ->orWhereIn(DB::raw('LOWER(TRIM(invoice.ref_no))'), $referenceChunk);
                });
            foreach ($invoiceQuery->orderBy('invoice.id')->get([
                'invoice.id', 'invoice.invoice_no', 'invoice.ref_no', 'invoice.transaction_date',
                'invoice.final_total', 'contact.name as contact_name', 'contact.supplier_business_name',
            ]) as $invoice) {
                $referenceValues = array_unique(array_filter([
                    $this->normalizeReference((string) ($invoice->invoice_no ?? '')),
                    $this->normalizeReference((string) ($invoice->ref_no ?? '')),
                ]));
                $contactName = trim((string) ($invoice->contact_name ?: $invoice->supplier_business_name));
                $invoiceLabel = 'Invoice #'.trim((string) ($invoice->invoice_no ?: $invoice->ref_no))
                    .' · '.substr((string) $invoice->transaction_date, 0, 10)
                    .' · '.number_format((float) $invoice->final_total, 2, '.', ',');
                if ($contactName !== '') {
                    $invoiceLabel .= ' · '.$contactName;
                }
                foreach ($referenceValues as $referenceValue) {
                    if (in_array($referenceValue, $referenceChunk, true)) {
                        $targets[$referenceValue]['transaction:'.(int) $invoice->id] = [
                            'id' => 'transaction:'.(int) $invoice->id,
                            'name' => $invoiceLabel,
                        ];
                    }
                }
            }

            $draftRows = DB::table('accounting_journal_import_rows')
                ->where('journal_import_id', $batch->id)
                ->where('business_id', $businessId)
                ->where('source_type', $invoiceDraftType)
                ->whereIn('source_action', ['create', 'update', 'link'])
                ->whereIn(DB::raw('LOWER(TRIM(source_number))'), $referenceChunk)
                ->whereIn('processing_status', ['pending', 'failed', 'queued'])
                ->orderBy('id')
                ->get(['id', 'source_number', 'processing_status']);
            foreach ($draftRows as $draftRow) {
                $referenceValue = $this->normalizeReference((string) $draftRow->source_number);
                $targets[$referenceValue]['draft:'.(int) $draftRow->id] = [
                    'id' => 'draft:'.(int) $draftRow->id,
                    'name' => $invoiceDraftType.' #'.trim((string) $draftRow->source_number)
                        .' · baris batch #'.(int) $draftRow->id.' · '.$draftRow->processing_status,
                ];
            }
        }

        return $targets;
    }

    private function journalAccountContext(array $components): ?array
    {
        if ($components === []) {
            return null;
        }
        $signature = [];
        $labels = [];
        foreach ($components as $component) {
            $debit = (float) ($component['debit'] ?? 0) > 0;
            $credit = (float) ($component['credit'] ?? 0) > 0;
            $signature[] = [
                'account_key' => (string) $component['account_key'],
                'debit' => $debit,
                'credit' => $credit,
            ];
            $labels[] = trim((string) ($component['account_no'] ?? '').' '.(string) ($component['account_name'] ?? ''))
                .' ('.($debit ? 'D' : '').($credit ? 'K' : '').')';
        }
        usort($signature, fn (array $left, array $right): int => $left['account_key'] <=> $right['account_key']);
        sort($labels);

        return [
            'key' => hash('sha256', json_encode($signature, JSON_UNESCAPED_SLASHES)),
            'label' => implode(' · ', $labels),
        ];
    }

    private function isEligibleSourceRow(object $row, array $entry): bool
    {
        $date = substr(trim((string) ($entry['date'] ?? '')), 0, 10);
        $debit = $entry['debit_total'] ?? null;
        $credit = $entry['credit_total'] ?? null;

        return empty($entry['is_unbalanced'])
            && empty($entry['is_duplicate'])
            && empty($entry['exists_in_system'])
            && empty($entry['source_identity_conflict'])
            && ! empty($entry['source_key'])
            && $this->isValidDate($date)
            && is_numeric($debit) && is_finite((float) $debit) && (float) $debit > 0
            && is_numeric($credit) && is_finite((float) $credit) && abs((float) $debit - (float) $credit) <= 0.01
            && empty($row->source_record_id) && empty($row->source_record_type);
    }

    private function normalizeReference(string $reference): string
    {
        return mb_strtolower(trim($reference));
    }

    private function finalizeGroups(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            if (empty($group['row_ids'])) {
                continue;
            }
            sort($group['row_ids']);
            if (isset($group['members']) && is_array($group['members'])) {
                ksort($group['members']);
                $members = array_values($group['members']);
            } else {
                $members = array_values($group['members'] ?? []);
            }
            sort($group['dates']);
            $group['fingerprint'] = hash('sha256', json_encode([
                'cohort_key' => $group['cohort_key'],
                'members' => $members,
                'options' => array_column($group['options'] ?? [], 'id'),
            ], JSON_UNESCAPED_SLASHES));
            $group['row_count'] = count($group['row_ids']);
            $group['component_total'] = round((float) $group['component_total'], 4);
            $group['journal_total'] = round((float) $group['journal_total'], 4);
            $group['date_from'] = $group['dates'][0] ?? null;
            $group['date_to'] = $group['dates'] === [] ? null : end($group['dates']);
            $group['_members'] = $members;
            unset($group['members'], $group['dates']);
            $result[] = $group;
        }

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
