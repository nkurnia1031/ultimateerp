<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Services\JournalImportDraftService;
use Modules\Accounting\Services\LegacyImportRunService;
use Modules\Accounting\Services\LegacyImportSourceCatalog;
use Modules\Accounting\Services\LegacyJournalEntryMerger;
use Modules\Accounting\Services\LegacyJournalImportAuditReader;
use Modules\Accounting\Services\LegacyOperationalDraftEnricher;
use Modules\Accounting\Services\LegacyOperationalEvidenceReader;
use Modules\Accounting\Services\LegacyOperationalOnlyProcessor;
use Modules\Accounting\Services\LegacyAttachmentImporter;
use Modules\Accounting\Services\JournalImportInvoiceService;
use Modules\Accounting\Services\JournalImportExpenseService;
use Modules\Accounting\Services\LegacyAnnualAuditService;
use Modules\Accounting\Services\LegacyJournalImportSourceLinkAuditReader;
use Modules\Accounting\Services\LegacyReconciliationFindingSnapshotService;

class ImportLegacySources extends Command
{
    protected $signature = 'accounting:import-legacy
        {--business-id= : Business ID that owns the legacy data}
        {--user-id= : Existing user ID recorded as the import author}
        {--year= : Limit Journal entries and dated source files to one year}
        {--source-dir= : Optional legacy source folder (default: import-legacy)}
        {--apply : Persist the run and process its Journal batch; without this option the command is read-only}
        {--resume-run= : Resume a saved legacy import run for the same source manifest}
        {--chunk-delay-ms=2000 : Delay between Journal Import database chunks (0-30000 ms)}
        {--no-create-missing-main-accounts : Keep missing operational accounts in review instead of creating exact workbook values}';

    protected $description = 'Inventory and coordinate legacy sources through the existing Journal Import pipeline.';

    public function handle(
        LegacyImportSourceCatalog $catalog,
        LegacyJournalEntryMerger $merger,
        LegacyOperationalEvidenceReader $evidenceReader,
        LegacyOperationalDraftEnricher $enricher,
        JournalEntryController $journalController,
        JournalImportDraftService $draftService,
        LegacyImportRunService $runService,
        LegacyOperationalOnlyProcessor $operationalOnlyProcessor,
        LegacyAttachmentImporter $attachmentImporter,
        JournalImportInvoiceService $invoiceService,
        JournalImportExpenseService $expenseService,
        LegacyAnnualAuditService $annualAuditService,
        LegacyJournalImportSourceLinkAuditReader $sourceLinkAuditReader,
        LegacyJournalImportAuditReader $journalImportAuditReader,
        LegacyReconciliationFindingSnapshotService $findingSnapshots
    ): int {
        $businessId = $this->positiveOption('business-id');
        $userId = $this->positiveOption('user-id');
        if ($businessId === null || $userId === null) {
            $this->error('Opsi --business-id dan --user-id wajib berupa bilangan bulat positif.');

            return self::FAILURE;
        }

        $year = $this->yearOption();
        if ($this->option('year') !== null && $year === null) {
            $this->error('Opsi --year harus berupa tahun empat digit yang valid.');

            return self::FAILURE;
        }
        $delay = $this->positiveOption('chunk-delay-ms', true);
        if ($delay === null || $delay > 30000) {
            $this->error('Opsi --chunk-delay-ms harus berupa bilangan bulat antara 0 dan 30000.');

            return self::FAILURE;
        }

        $business = DB::table('business')->where('id', $businessId)->first();
        if (! $business) {
            $this->error('Business #'.$businessId.' tidak ditemukan.');

            return self::FAILURE;
        }
        if (! DB::table('users')->where('id', $userId)->exists()) {
            $this->error('User #'.$userId.' tidak ditemukan.');

            return self::FAILURE;
        }

        $isApply = (bool) $this->option('apply');
        $resumeRunId = $this->positiveOption('resume-run');
        if ($this->option('resume-run') !== null && $resumeRunId === null) {
            $this->error('Opsi --resume-run harus berupa bilangan bulat positif.');

            return self::FAILURE;
        }
        if ($resumeRunId !== null && ! $isApply) {
            $this->error('Resume mengubah status batch; sertakan --apply.');

            return self::FAILURE;
        }

        $sourceDirectoryOption = trim((string) $this->option('source-dir'));
        $sourceDirectory = realpath($sourceDirectoryOption !== '' ? $sourceDirectoryOption : base_path('import-legacy'));
        if ($sourceDirectory === false) {
            $this->error('Folder sumber legacy tidak ditemukan atau tidak dapat dibaca.');

            return self::FAILURE;
        }

        try {
            $inventory = $catalog->scan($sourceDirectory, $year);
            $auditInventory = $year === null ? $inventory : $catalog->scan($sourceDirectory);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifestHash = $this->manifestHash($inventory, $year);
        $this->printInventory($business->name ?? 'Business', $businessId, $inventory, $year, $isApply);

        if (! $isApply) {
            try {
                $analysis = $this->analyzeJournalSources($auditInventory, $businessId, $year, $journalController, $merger, $draftService, $evidenceReader, $enricher);
                $this->printAnalysis($analysis);
                $annualAudit = $annualAuditService->generate(
                    $analysis['_annual_audit_data']['journal_entries'] ?? [],
                    $analysis['_annual_audit_data']['operational_evidence'] ?? [],
                    $auditInventory,
                    $businessId,
                    $year
                );
                $this->printAnnualAudit($annualAudit);
                $this->comment('Dry-run tidak mengubah database atau menyalin file sumber. Laporan audit disimpan lokal; tambahkan --apply untuk menyimpan batch.');
            } catch (\Throwable $exception) {
                $this->error('Analisis dry-run gagal: '.$exception->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if (! $this->legacyTablesAreReady()) {
            $this->error('Tabel legacy import belum tersedia. Jalankan migrasi Accounting terlebih dahulu.');

            return self::FAILURE;
        }

        $runId = $resumeRunId;
        if ($runId !== null) {
            $run = $runService->findRun($runId, $businessId);
            if (! $run) {
                $this->error('Run #'.$runId.' tidak ditemukan untuk business #'.$businessId.'.');

                return self::FAILURE;
            }
            if (! hash_equals((string) $run->manifest_sha256, $manifestHash)) {
                $this->error('Isi atau daftar file import-legacy berubah sejak run dibuat. Gunakan manifest yang sama atau mulai run baru.');

                return self::FAILURE;
            }
            if (($run->year_filter === null ? null : (int) $run->year_filter) !== $year) {
                $this->error('Filter tahun harus sama dengan run yang akan dilanjutkan.');

                return self::FAILURE;
            }
        } else {
            $existingRun = $runService->findByManifest($businessId, $year ?? 0, $manifestHash);
            if ($existingRun) {
                $this->error('Sumber yang sama sudah tersimpan pada run #'.$existingRun->id.' ('.$existingRun->status.'). Gunakan --resume-run='.$existingRun->id.' agar tidak membuat posting duplikat.');

                return self::FAILURE;
            }

            try {
                $runId = $runService->createRun(
                    $businessId,
                    $userId,
                    $year,
                    $sourceDirectory,
                    $manifestHash,
                    $inventory['files']
                );
            } catch (\Throwable $exception) {
                $existingRun = $runService->findByManifest($businessId, $year ?? 0, $manifestHash);
                if ($existingRun) {
                    $this->error('Sumber yang sama sudah dimulai sebagai run #'.$existingRun->id.'. Gunakan --resume-run='.$existingRun->id.'.');

                    return self::FAILURE;
                }
                $this->error('Run legacy tidak dapat dibuat: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->line('Legacy run ID: '.$runId);
        $annualAudit = null;
        $auditAnalysis = null;

        try {
            $run = $runService->findRun($runId, $businessId);
            $importId = empty($run->journal_import_id) ? null : (int) $run->journal_import_id;
            $batch = $importId === null ? null : DB::table('accounting_journal_imports')
                ->where('id', $importId)
                ->where('business_id', $businessId)
                ->first();

            $journalAlreadyImported = $batch !== null && (string) $batch->status === 'imported';

            if ($batch === null || $this->batchNeedsPreparation($batch, $businessId)) {
                $analysis = $this->analyzeJournalSources($auditInventory, $businessId, $year, $journalController, $merger, $draftService, $evidenceReader, $enricher);
                $auditAnalysis = $analysis;
                $this->printAnalysis($analysis);

                $matchedEvidenceIdentities = [];
                foreach ($analysis['entries'] as $entry) {
                    $identity = $entry['legacy_evidence_identity'] ?? null;
                    if (! is_string($identity) || $identity === '') {
                        continue;
                    }
                    $status = (string) ($entry['legacy_evidence_status'] ?? 'matched');
                    $matchedEvidenceIdentities[$identity] = [
                        'status' => in_array($status, ['matched'], true) ? 'matched' : ($status === 'conflict' ? 'conflict' : 'review'),
                        'reason' => $entry['legacy_evidence_reason'] ?? $entry['source_identity_conflict_message'] ?? null,
                    ];
                }
                $runService->persistOperationalEvidence(
                    $runId,
                    $businessId,
                    $analysis['_operational_evidence_data'],
                    $matchedEvidenceIdentities
                );

                if ($analysis['entries'] === []) {
                    $auditedEvidence = $sourceLinkAuditReader->mergeIntoEvidence(
                        (array) ($analysis['_annual_audit_data']['operational_evidence'] ?? []),
                        $businessId,
                        $importId === null ? null : (int) $importId
                    );
                    $annualAudit = $annualAuditService->generate(
                        (array) ($analysis['_annual_audit_data']['journal_entries'] ?? []),
                        $auditedEvidence,
                        $auditInventory,
                        $businessId,
                        $year
                    );
                    $operationalOnly = $operationalOnlyProcessor->process(
                        $runId,
                        $businessId,
                        $userId,
                        $analysis['_operational_evidence_data'],
                        [],
                        $analysis['_standalone_draft_data'],
                        $runService,
                        $invoiceService,
                        $expenseService,
                        fn (int $processed, int $total, array $counts) => $this->line(
                            'Sumber operasional tanpa Journal: '.$processed.' / '.$total
                            .' (' .$counts['created'].' dibuat, '.$counts['linked_existing'].' ditautkan, '
                            .$counts['review'].' review).'
                        )
                    );
                    $runService->syncLegacyRecordIds($runId, $businessId, $analysis['_operational_evidence_data']);
                    $attachments = $attachmentImporter->import($runId, $businessId, $sourceDirectory);
                    $summary = $this->summaryForStorage($analysis);
                    $summary['operational_only_processing'] = $operationalOnly;
                    $summary['legacy_attachments'] = $attachments;
                    $runService->updateRun($runId, $businessId, 'review', 'Tidak ada entri Journal; transaksi operasional lengkap dibuat atau ditautkan dan sisanya masuk review tanpa posting GL.', $summary, true);
                    $this->persistAnnualFindings($findingSnapshots, $businessId, $year, $annualAudit);
                    $this->comment('Tidak ada Journal entry. Transaksi operasional lengkap diproses tanpa GL; item yang belum aman tetap tersimpan untuk review.');
                    $this->printAnnualAudit($annualAudit);
                    $this->printAttachmentSummary($attachments);

                    return self::SUCCESS;
                }

                if ($batch === null) {
                    $batchId = $runService->createJournalBatch(
                        $runId,
                        $businessId,
                        $userId,
                        'Legacy import '.$manifestHash.'.xlsx',
                        $this->summaryForStorage($analysis)
                    );
                    $importId = $batchId;
                    $batch = DB::table('accounting_journal_imports')->where('id', $importId)->first();
                }

                $entriesForRows = array_map(function (array $entry) {
                    unset($entry['legacy_draft_data']);

                    return $entry;
                }, $analysis['entries']);
                $draftService->persistEntries($importId, $businessId, $entriesForRows);
                $enricher->persistDrafts($importId, $businessId, $analysis['entries']);
                $this->syncBatchAnalysis($importId, $businessId, $analysis);
                $runService->saveJournalBatch($runId, $businessId, $importId, $this->summaryForStorage($analysis));
                $runService->syncJournalCrosswalks($runId, $businessId, $importId);
            }

            if ($auditAnalysis === null) {
                $auditAnalysis = $this->analyzeJournalSources(
                    $auditInventory,
                    $businessId,
                    $year,
                    $journalController,
                    $merger,
                    $draftService,
                    $evidenceReader,
                    $enricher
                );
            }

            if ($journalAlreadyImported) {
                $exitCode = self::SUCCESS;
                $this->line('Batch Journal #'.$importId.' sudah imported; lanjut sinkronisasi sumber operasional dan lampiran tanpa mengulang posting.');
            } else {
                $runService->updateRun($runId, $businessId, 'importing', 'Menjalankan batch melalui CLI Journal Import yang sudah ada.');
                $runService->enableLegacyAuthoritativeJournalMode((int) $importId, $businessId);
                $this->line('Mode legacy-authoritative: posting Journal tetap diproses; link operasional dipakai jika datanya cukup dan unik.');
                $arguments = [
                    '--business-id' => $businessId,
                    '--user-id' => $userId,
                    '--resume-batch' => $importId,
                    '--chunk-delay-ms' => $delay,
                ];
                if ((bool) $this->option('no-create-missing-main-accounts')) {
                    $arguments['--no-create-missing-main-accounts'] = true;
                }
                $exitCode = $this->call('accounting:import-journal', $arguments);
            }

            $batch = DB::table('accounting_journal_imports')
                ->where('id', $importId)
                ->where('business_id', $businessId)
                ->first();
            $crosswalkCount = $runService->syncJournalCrosswalks($runId, $businessId, $importId);
            $summary = isset($analysis)
                ? $this->summaryForStorage($analysis)
                : (json_decode((string) ($run->summary ?? ''), true) ?: []);
            $operationalEvidence = $analysis['_operational_evidence_data']
                ?? $evidenceReader->read($inventory['files'], (string) $inventory['root'], $year);
            $runService->syncLegacyRecordIds($runId, $businessId, $operationalEvidence);
            $journalEvidenceIdentities = $this->journalEvidenceIdentities($importId, $businessId);
            $journalIdentitySet = array_fill_keys($journalEvidenceIdentities, true);
            $operationalOnlyDocuments = array_diff_key((array) ($operationalEvidence['documents'] ?? []), $journalIdentitySet);
            $standaloneDrafts = $analysis['_standalone_draft_data']
                ?? $enricher->prepareStandaloneDrafts($operationalOnlyDocuments, $businessId, $draftService);
            $operationalOnly = $operationalOnlyProcessor->process(
                $runId,
                $businessId,
                $userId,
                $operationalEvidence,
                $journalEvidenceIdentities,
                $standaloneDrafts,
                $runService,
                $invoiceService,
                $expenseService,
                fn (int $processed, int $total, array $counts) => $this->line(
                    'Sumber operasional tanpa Journal: '.$processed.' / '.$total
                    .' (' .$counts['created'].' dibuat, '.$counts['linked_existing'].' ditautkan, '
                    .$counts['review'].' review).'
                )
            );
            $attachments = $attachmentImporter->import($runId, $businessId, $sourceDirectory);
            $summary['journal_import_id'] = $importId;
            $summary['journal_status'] = $batch->status ?? 'unknown';
            $summary['journal_crosswalks'] = $crosswalkCount;
            $summary['operational_only_processing'] = $operationalOnly;
            $summary['legacy_attachments'] = $attachments;

            [$runStatus, $message, $finished] = $this->runStatusForBatch((string) ($batch->status ?? 'failed'));
            if ($operationalOnly['review'] > 0 || $operationalOnly['created'] > 0 || $operationalOnly['linked_existing'] > 0) {
                $runStatus = 'review';
                $message .= ' Transaksi tanpa Journal ditautkan/dibuat bila aman; posting GL tidak dibuat dari sumber operasional saja.';
            }
            if (($attachments['actionable'] ?? 0) > 0) {
                $runStatus = 'review';
                $message .= ' Sebagian lampiran belum dapat ditautkan atau diunduh; rincian tersimpan pada laporan lampiran.';
            }
            $runService->updateRun($runId, $businessId, $runStatus, $message, $summary, $finished);

            $auditedEvidence = $sourceLinkAuditReader->mergeIntoEvidence(
                (array) ($auditAnalysis['_annual_audit_data']['operational_evidence'] ?? []),
                $businessId,
                (int) $importId
            );
            $journalEntriesForAudit = (array) ($auditAnalysis['_annual_audit_data']['journal_entries'] ?? []);
            $journalImportRows = $journalImportAuditReader->read(
                $businessId,
                (int) $importId,
                $this->missingAccountLinesBySourceKey($journalEntriesForAudit)
            );
            $annualAudit = $annualAuditService->generate(
                (array) ($auditAnalysis['_annual_audit_data']['journal_entries'] ?? []),
                $auditedEvidence,
                $auditInventory,
                $businessId,
                $year,
                null,
                $journalImportRows
            );
            $this->persistAnnualFindings($findingSnapshots, $businessId, $year, $annualAudit);
            $loanFindingCount = $findingSnapshots->persistLoanClassificationFromJournalImport(
                $businessId,
                (int) $importId,
                $manifestHash
            );
            if ($loanFindingCount > 0) {
                $this->line('Snapshot klasifikasi pinjaman belum terkonfirmasi: '.$loanFindingCount.' temuan tersimpan untuk ditinjau.');
            }
            $this->line('Legacy run #'.$runId.' — '.$message);
            $this->line('Crosswalk sumber yang tersimpan: '.$crosswalkCount);
            $this->printAnnualAudit($annualAudit);
            $this->printAttachmentSummary($attachments);

            return $exitCode === self::FAILURE ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $runService->updateRun($runId, $businessId, 'recoverable', 'Proses terhenti; jalankan ulang dengan --resume-run='.$runId.'.');
            $this->error('Legacy run terhenti: '.$exception->getMessage());
            $this->line('Data yang sudah tersimpan dipertahankan. Lanjutkan dengan --resume-run='.$runId.' --apply.');

            return self::FAILURE;
        }
    }

    private function analyzeJournalSources(
        array $inventory,
        int $businessId,
        ?int $year,
        JournalEntryController $controller,
        LegacyJournalEntryMerger $merger,
        JournalImportDraftService $draftService,
        LegacyOperationalEvidenceReader $evidenceReader,
        LegacyOperationalDraftEnricher $enricher
    ): array {
        $workbooks = [];
        $analyses = [];
        $root = (string) $inventory['root'];
        $files = array_values(array_filter($inventory['files'], function (array $file): bool {
            return $file['classification'] === 'journal_posting_source' && empty($file['duplicate_of']);
        }));

        foreach ($files as $fileIndex => $file) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
            $this->line('Menganalisis Journal source '.($fileIndex + 1).' / '.count($files).': '.$file['path']);
            $lastReportedRow = -5000;
            $analysis = $controller->analyzeJournalWorkbook($path, $businessId, function (array $progress) use (&$lastReportedRow, $file) {
                $processed = (int) ($progress['processed_rows'] ?? $progress['processed'] ?? 0);
                $total = (int) ($progress['total_rows'] ?? $progress['total'] ?? 0);
                if ($processed >= $lastReportedRow + 5000 || ($total > 0 && $processed >= $total)) {
                    $this->line('  '.$file['path'].': '.$processed.' / '.$total.' baris dibaca.');
                    $lastReportedRow = $processed;
                }
            });
            $workbooks[] = [
                'path' => $file['path'],
                'sha256' => $file['sha256'],
                'entries' => $analysis['entries'] ?? [],
            ];
            $analyses[] = $analysis;
        }

        $allMerged = $merger->merge($workbooks);
        $merged = $year === null ? $allMerged : $merger->merge($workbooks, $year);
        $allOperationalEvidence = $evidenceReader->read($inventory['files'], $root);
        $operationalEvidence = $year === null
            ? $allOperationalEvidence
            : $this->filterOperationalEvidenceByYear($allOperationalEvidence, $year);
        $entries = $enricher->enrich($merged['entries'], $operationalEvidence, $businessId, $draftService);
        $journalEvidenceIdentities = array_values(array_unique(array_filter(array_map(
            fn (array $entry) => $entry['legacy_evidence_identity'] ?? null,
            $entries
        ))));
        $operationalOnlyDocuments = array_diff_key(
            (array) ($operationalEvidence['documents'] ?? []),
            array_fill_keys($journalEvidenceIdentities, true)
        );
        $standaloneDraftData = $enricher->prepareStandaloneDrafts($operationalOnlyDocuments, $businessId, $draftService);
        $standaloneReady = count(array_filter($standaloneDraftData, fn (array $draft) => ! empty($draft['ready'])));
        $missingAccounts = [];
        foreach ($analyses as $analysis) {
            foreach ((array) ($analysis['missing_accounts'] ?? []) as $account) {
                $missingAccounts[(string) ($account['key'] ?? json_encode($account))] = $account;
            }
        }

        $types = [];
        $dates = [];
        $matched = 0;
        $unmatched = 0;
        $ambiguous = 0;
        $suggested = 0;
        $unbalanced = 0;
        $unsupported = 0;
        $evidenceStatuses = [];
        foreach ($entries as $entry) {
            $type = (string) ($entry['source_type'] ?? 'Unknown');
            $types[$type] = ($types[$type] ?? 0) + 1;
            if (! empty($entry['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $entry['date'])) {
                $dates[] = (string) $entry['date'];
            }
            $unbalanced += ! empty($entry['is_unbalanced']) ? 1 : 0;
            if (! in_array($type, $draftService->sourceTypes(), true)) {
                $unsupported++;
            }
            switch ((string) ($entry['source_link_status'] ?? 'unmatched')) {
                case 'matched': $matched++; break;
                case 'ambiguous': $ambiguous++; break;
                case 'suggested': $suggested++; break;
                default: $unmatched++;
            }
            $evidenceStatus = (string) ($entry['legacy_evidence_status'] ?? 'missing');
            $evidenceStatuses[$evidenceStatus] = ($evidenceStatuses[$evidenceStatus] ?? 0) + 1;
        }

        sort($dates);
        ksort($types);
        $auditEntries = $this->attachMissingAccountDetails($allMerged['entries'], array_values($missingAccounts));
        $summary = [
            'entries' => $entries,
            'total_entries' => count($entries),
            'journal_entry_count' => (int) ($types['Journal Entry'] ?? 0),
            'non_journal_count' => count($entries) - (int) ($types['Journal Entry'] ?? 0),
            'unbalanced_count' => $unbalanced,
            'duplicate_count' => $merged['duplicate_count'],
            'conflict_count' => $merged['conflict_count'],
            'excluded_by_year' => $merged['excluded_by_year'],
            'missing_accounts' => array_values($missingAccounts),
            'missing_account_count' => count($missingAccounts),
            'source_link_matched_count' => $matched,
            'source_link_unmatched_count' => $unmatched,
            'source_link_ambiguous_count' => $ambiguous,
            'source_link_suggested_count' => $suggested,
            'source_link_review_count' => $ambiguous + $suggested + $unmatched,
            'unsupported_count' => $unsupported,
            'types' => $types,
            'source_date_range' => $dates === [] ? null : $dates[0].' — '.$dates[count($dates) - 1],
            'workbook_count' => count($files),
            'operational_document_count' => (int) ($operationalEvidence['document_count'] ?? 0),
            'operational_source_record_count' => (int) ($operationalEvidence['source_record_count'] ?? 0),
            'operational_duplicate_count' => (int) ($operationalEvidence['duplicate_source_count'] ?? 0),
            'operational_conflict_count' => (int) ($operationalEvidence['conflict_count'] ?? 0),
            'operational_evidence_statuses' => $evidenceStatuses,
            'operational_only_count' => count($operationalOnlyDocuments),
            'operational_only_ready_count' => $standaloneReady,
            'operational_only_review_count' => count($standaloneDraftData) - $standaloneReady,
            'operational_file_summaries' => $operationalEvidence['file_summaries'] ?? [],
            '_operational_evidence_data' => $operationalEvidence,
            '_standalone_draft_data' => $standaloneDraftData,
            '_annual_audit_data' => [
                'journal_entries' => $auditEntries,
                'journal_duplicate_count' => $allMerged['duplicate_count'],
                'journal_conflict_count' => $allMerged['conflict_count'],
                'operational_evidence' => $allOperationalEvidence,
            ],
        ];

        return $summary;
    }

    private function attachMissingAccountDetails(array $entries, array $missingAccounts): array
    {
        $missingKeys = array_fill_keys(array_filter(array_map(
            fn (array $account): string => trim((string) ($account['key'] ?? '')),
            $missingAccounts
        )), true);

        foreach ($entries as &$entry) {
            $unmapped = [];
            foreach ((array) ($entry['lines'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $key = md5(strtolower(trim((string) ($line['account_no'] ?? '')).'|'.trim((string) ($line['account_name'] ?? ''))));
                if (! isset($missingKeys[$key])) {
                    continue;
                }
                $unmapped[$key] = [
                    'account_no' => $line['account_no'] ?? '',
                    'account_name' => $line['account_name'] ?? '',
                    'description' => $line['description'] ?? '',
                ];
            }
            $entry['missing_account_lines'] = array_values($unmapped);
            $entry['has_missing_accounts'] = $unmapped !== [];
        }
        unset($entry);

        return $entries;
    }

    private function missingAccountLinesBySourceKey(array $journalEntries): array
    {
        $unmappedBySourceKey = [];
        foreach ($journalEntries as $entry) {
            if (! is_array($entry) || empty($entry['source_key']) || empty($entry['missing_account_lines'])) {
                continue;
            }
            $unmappedBySourceKey[(string) $entry['source_key']] = $entry['missing_account_lines'];
        }

        return $unmappedBySourceKey;
    }

    private function syncBatchAnalysis(int $importId, int $businessId, array $analysis): void
    {
        DB::table('accounting_journal_imports')
            ->where('id', $importId)
            ->where('business_id', $businessId)
            ->update([
                'source_date_range' => $analysis['source_date_range'],
                'total_entries' => $analysis['total_entries'],
                'unbalanced_entries' => $analysis['unbalanced_count'],
                'duplicate_entries' => $analysis['duplicate_count'] + $analysis['conflict_count'],
                'missing_accounts' => $analysis['missing_account_count'],
                'unsupported_entries' => $analysis['unsupported_count'],
                'summary' => json_encode($this->summaryForStorage($analysis), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_message' => 'Analisis lintas workbook selesai; overlap identik digabung dan versi konflik dipilih dari export dengan periode terbaru.',
                'status' => 'analyzed',
                'updated_at' => now(),
            ]);
    }

    private function batchNeedsPreparation(object $batch, int $businessId): bool
    {
        if (! in_array((string) $batch->status, ['analyzed'], true)) {
            return false;
        }

        $entryCount = (int) DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $batch->id)
            ->where('business_id', $businessId)
            ->count();

        return $entryCount < (int) ($batch->total_entries ?? 0);
    }

    private function printInventory(string $businessName, int $businessId, array $inventory, ?int $year, bool $apply): void
    {
        $this->info('Business: '.$businessName.' (#'.$businessId.')');
        $this->line('Mode: '.($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Folder: '.$inventory['root']);
        $this->line('Filter tahun: '.($year === null ? 'semua tahun' : $year));
        $this->line('File terinventarisasi: '.(int) $inventory['file_count']);
        $this->line('File identik: '.(int) $inventory['duplicate_file_count']);
        foreach ($inventory['counts'] as $classification => $count) {
            $this->line('  '.$classification.': '.$count);
        }
    }

    private function printAnalysis(array $analysis): void
    {
        $this->line('Journal workbooks: '.(int) ($analysis['workbook_count'] ?? 0));
        $this->line('Journal groups after overlap merge: '.(int) ($analysis['total_entries'] ?? 0));
        $this->line('Identical groups deduplicated: '.(int) ($analysis['duplicate_count'] ?? 0));
        $this->line('Conflicting groups retained for review: '.(int) ($analysis['conflict_count'] ?? 0));
        $this->line('Excluded by year: '.(int) ($analysis['excluded_by_year'] ?? 0));
        $this->line('Unbalanced groups: '.(int) ($analysis['unbalanced_count'] ?? 0));
        $this->line('Source links — matched: '.(int) ($analysis['source_link_matched_count'] ?? 0)
            .', unmatched: '.(int) ($analysis['source_link_unmatched_count'] ?? 0)
            .', suggested: '.(int) ($analysis['source_link_suggested_count'] ?? 0)
            .', ambiguous: '.(int) ($analysis['source_link_ambiguous_count'] ?? 0));
        $this->line('Missing account keys: '.(int) ($analysis['missing_account_count'] ?? 0));
        $this->line('Operational docs: '.(int) ($analysis['operational_document_count'] ?? 0)
            .' ('.$analysis['operational_source_record_count'].' records, '
            .$analysis['operational_duplicate_count'].' duplicates, '
            .$analysis['operational_conflict_count'].' conflicts)');
        $this->line('Operational-only docs: '.(int) ($analysis['operational_only_count'] ?? 0)
            .' ('.(int) ($analysis['operational_only_ready_count'] ?? 0).' siap dibuat/ditautkan; '
            .(int) ($analysis['operational_only_review_count'] ?? 0).' perlu review)');
        foreach ((array) ($analysis['operational_evidence_statuses'] ?? []) as $status => $count) {
            $this->line('  Operational evidence '.$status.': '.$count);
        }
        $fileErrors = array_filter((array) ($analysis['operational_file_summaries'] ?? []), fn (array $file) => ! empty($file['error']));
        foreach ($fileErrors as $file) {
            $this->warn('Operational source gagal dibaca '.$file['path'].': '.$file['error']);
        }
        if (! empty($analysis['source_date_range'])) {
            $this->line('Journal dates: '.$analysis['source_date_range']);
        }
    }

    private function summaryForStorage(array $analysis): array
    {
        unset(
            $analysis['entries'],
            $analysis['missing_accounts'],
            $analysis['_operational_evidence_data'],
            $analysis['_standalone_draft_data'],
            $analysis['_annual_audit_data']
        );

        return $analysis;
    }

    private function printAttachmentSummary(array $summary): void
    {
        $this->line('Lampiran legacy: '.(int) ($summary['linked'] ?? 0).' tertaut, '
            .(int) ($summary['review'] ?? 0).' review, '
            .(int) ($summary['failed'] ?? 0).' gagal, '
            .(int) ($summary['rejected'] ?? 0).' ditolak.');
        $this->line('Nama file bertabrakan: '.(int) ($summary['collision_groups'] ?? 0).' grup / '
            .(int) ($summary['collision_attachments'] ?? 0).' lampiran diberi nama tujuan unik.');
        if (! empty($summary['mapping_report_path'])) {
            $this->line('Laporan nama sumber → tujuan: '.$summary['mapping_report_path']);
        }
    }

    private function printAnnualAudit(array $audit): void
    {
        $this->line('Laporan rekonsiliasi tahunan: '.($audit['output_directory'] ?? ''));
        if (($audit['reports'] ?? []) === []) {
            $this->line((string) ($audit['message'] ?? 'Tidak ada laporan tahunan yang dibuat.'));
        }
        foreach ((array) ($audit['reports'] ?? []) as $year => $report) {
            $this->line(
                '  '.$year.': '.$report['journal_entries'].' Journal; '.$report['operational_matches']
                .' sumber operasional cocok; '.$report['control_variances'].' selisih kontrol.'
            );
            $this->line('    XLSX: '.$report['xlsx']);
            $this->line('    JSON: '.$report['json']);
        }
        foreach ((array) ($audit['control_errors'] ?? []) as $error) {
            $this->warn('  Kontrol tidak terbaca: '.$error['path'].' — '.$error['message']);
        }
    }

    private function filterOperationalEvidenceByYear(array $evidence, int $year): array
    {
        $documents = [];
        $fileCounts = [];
        $sourceRecordCount = 0;
        $duplicateCount = 0;
        $conflictCount = 0;
        foreach ((array) ($evidence['documents'] ?? []) as $identity => $document) {
            if (! is_array($document) || (int) substr((string) ($document['date'] ?? ''), 0, 4) !== $year) {
                continue;
            }
            $documents[$identity] = $document;
            $sourceCount = max(1, (int) ($document['source_count'] ?? 1));
            $sourceRecordCount += $sourceCount;
            $duplicateCount += max(0, $sourceCount - 1);
            $conflictCount += ($document['status'] ?? '') === 'conflict' ? 1 : 0;
            foreach ((array) ($document['provenance'] ?? []) as $source) {
                $path = (string) ($source['path'] ?? '');
                if ($path !== '') {
                    $fileCounts[$path] = ($fileCounts[$path] ?? 0) + 1;
                }
            }
        }

        $fileSummaries = [];
        foreach ((array) ($evidence['file_summaries'] ?? []) as $summary) {
            $path = (string) ($summary['path'] ?? '');
            $count = (int) ($fileCounts[$path] ?? 0);
            if ($count > 0 || ! empty($summary['error'])) {
                $summary['documents'] = $count;
                $fileSummaries[] = $summary;
            }
        }

        return [
            'documents' => $documents,
            'document_count' => count($documents),
            'source_record_count' => $sourceRecordCount,
            'duplicate_source_count' => $duplicateCount,
            'conflict_count' => $conflictCount,
            'file_summaries' => $fileSummaries,
        ];
    }

    private function manifestHash(array $inventory, ?int $year): string
    {
        $manifest = [
            'year' => $year,
            'files' => array_map(function (array $file) {
                return [
                    'path' => $file['path'],
                    'sha256' => $file['sha256'],
                    'classification' => $file['classification'],
                ];
            }, $inventory['files']),
        ];

        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function runStatusForBatch(string $status): array
    {
        if ($status === 'imported') {
            return ['completed', 'Batch Journal selesai diproses.', true];
        }
        if (in_array($status, ['partially_imported', 'analyzed', 'cancelled'], true)) {
            return ['review', 'Sebagian sumber masih perlu dilengkapi atau ditinjau.', true];
        }

        return ['failed', 'Batch Journal gagal; data tersimpan untuk pemeriksaan dan resume.', true];
    }

    private function legacyTablesAreReady(): bool
    {
        return Schema::hasTable('accounting_legacy_import_runs')
            && Schema::hasTable('accounting_legacy_import_sources')
            && Schema::hasTable('accounting_legacy_import_crosswalks')
            && Schema::hasColumn('accounting_legacy_import_crosswalks', 'legacy_record_id')
            && Schema::hasTable('accounting_legacy_import_attachments')
            && Schema::hasTable('accounting_legacy_reconciliation_audits')
            && Schema::hasTable('accounting_legacy_reconciliation_findings')
            && Schema::hasColumn('media', 'storage_location')
            && Schema::hasColumn('media', 'legacy_source_attachment_id');
    }

    private function persistAnnualFindings(
        LegacyReconciliationFindingSnapshotService $findingSnapshots,
        int $businessId,
        ?int $year,
        array $annualAudit
    ): void {
        $reports = $findingSnapshots->persistReports($businessId, (array) ($annualAudit['reports'] ?? []));
        if ($reports === []) {
            $reports = $findingSnapshots->persistFromDirectory(
                $businessId,
                (string) ($annualAudit['output_directory'] ?? storage_path('app/legacy-import-reports/business-'.$businessId)),
                $year
            );
        }

        $this->line('Snapshot temuan rekonsiliasi tersimpan: '.array_sum($reports).' temuan pada '.count($reports).' tahun.');
    }

    private function journalEvidenceIdentities(int $importId, int $businessId): array
    {
        $identities = [];
        DB::table('accounting_journal_import_rows')
            ->where('journal_import_id', $importId)
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$identities) {
                foreach ($rows as $row) {
                    $entry = json_decode((string) $row->entry_data, true);
                    $identity = is_array($entry) ? ($entry['legacy_evidence_identity'] ?? null) : null;
                    if (is_string($identity) && $identity !== '') {
                        $identities[$identity] = true;
                    }
                }
            }, 'id');

        return array_keys($identities);
    }

    private function positiveOption(string $name, bool $allowZero = false): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (! preg_match('/^\d+$/', (string) $value)) {
            return null;
        }

        $number = (int) $value;

        return $allowZero ? ($number >= 0 ? $number : null) : ($number > 0 ? $number : null);
    }

    private function yearOption(): ?int
    {
        $value = $this->option('year');
        if ($value === null || $value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}$/', (string) $value)) {
            return null;
        }
        $year = (int) $value;

        return $year > 0 ? $year : null;
    }
}
