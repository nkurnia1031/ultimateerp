<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class LegacyAnnualAuditService
{
    /**
     * Journal Entry is a GL-only posting. Every other supported workbook type
     * represents an operational source that the audit must try to identify;
     * absent evidence is a coverage finding, not "not applicable".
     */
    private const OPERATIONAL_SOURCE_TYPES = [
        'Sales Invoice',
        'Purchase Invoice',
        'Expense',
        'Receive Payment',
        'Purchase Payment',
        'Bank Deposit',
        'Bank Transfer',
        'Bank Withdrawal',
        'Stock Adjustment',
        'Stock In/Out',
        'Opening Balance',
        'Reversal',
    ];

    private LegacyAnnualAuditControlReader $controlReader;

    private JournalImportAccountComponentService $componentService;

    public function __construct(
        LegacyAnnualAuditControlReader $controlReader,
        JournalImportAccountComponentService $componentService
    ) {
        $this->controlReader = $controlReader;
        $this->componentService = $componentService;
    }

    /**
     * Generate private annual reports from legacy source evidence. This method
     * intentionally has no database dependency and never posts or edits records.
     */
    public function generate(
        array $journalEntries,
        array $operationalEvidence,
        array $inventory,
        int $businessId,
        ?int $year = null,
        ?string $outputDirectory = null,
        array $journalImportRows = []
    ): array {
        $root = (string) ($inventory['root'] ?? '');
        $controls = $this->controlReader->read((array) ($inventory['files'] ?? []), $root);
        $years = $this->coveredYears($journalEntries, $operationalEvidence, $inventory, $controls);
        if ($year !== null) {
            $years = array_values(array_unique(array_merge($years, [$year])));
            sort($years);
        }
        if ($years === []) {
            return [
                'output_directory' => $outputDirectory ?? storage_path('app/legacy-import-reports/business-'.$businessId),
                'reports' => [],
                'message' => 'Tidak ada tahun transaksi atau periode sumber yang dapat diaudit.',
                'control_errors' => $controls['errors'],
            ];
        }

        $ledger = $this->buildJournalLedger($journalEntries, $years);
        $importRowsBySourceKey = [];
        foreach ($journalImportRows as $importRow) {
            if (! is_array($importRow)) {
                continue;
            }
            $sourceKey = trim((string) ($importRow['source_key'] ?? ''));
            if ($sourceKey !== '') {
                $importRowsBySourceKey[$sourceKey][] = $importRow;
            }
        }
        $controlComparisons = $this->compareControls(
            $controls,
            $ledger['account_balances'],
            $ledger['cash_bank_totals'],
            $years
        );
        $outputDirectory = $outputDirectory
            ?? storage_path('app/legacy-import-reports/business-'.$businessId);
        File::ensureDirectoryExists($outputDirectory);

        $requestedYears = $year === null ? $years : [$year];
        $reports = [];
        $unassignedImportRows = array_values(array_filter($journalImportRows, fn (array $row): bool =>
            (int) ($row['year'] ?? 0) < 1
        ));
        foreach ($requestedYears as $reportYear) {
            $annualTransactions = $this->buildTransactionsForYear(
                $journalEntries,
                $operationalEvidence,
                (int) $reportYear,
                $importRowsBySourceKey
            );
            $annualImportRows = array_values(array_filter($journalImportRows, fn (array $row): bool =>
                (int) ($row['year'] ?? 0) === (int) $reportYear
            ));
            $annualAccounts = array_values($ledger['account_balances'][$reportYear] ?? []);
            $annualControls = array_values(array_filter(
                $controlComparisons,
                fn (array $row): bool => (int) $row['year'] === (int) $reportYear
            ));
            $annualCoverage = $this->coverageForYear($inventory, $reportYear);
            $summary = $this->annualSummary(
                $annualTransactions,
                $annualAccounts,
                $annualControls,
                $annualCoverage,
                $controls['errors'],
                $annualImportRows,
                count($unassignedImportRows)
            );
            $report = [
                'schema_version' => 2,
                'business_id' => $businessId,
                'year' => (int) $reportYear,
                'generated_at' => now()->toIso8601String(),
                'source_manifest_sha256' => $this->manifestHash($inventory),
                'balance_convention' => 'Debit dikurangi kredit; saldo negatif menunjukkan posisi kredit.',
                'summary' => $summary,
                'account_balances' => $annualAccounts,
                'transaction_reconciliation' => $annualTransactions,
                'journal_import_rows' => $annualImportRows,
                'journal_import_unassigned_rows' => $unassignedImportRows,
                'control_comparison' => $annualControls,
                'coverage' => $annualCoverage,
                'control_errors' => $controls['errors'],
            ];
            $fileStem = 'business-'.$businessId.'-'.$reportYear;
            $jsonPath = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileStem.'.json';
            $xlsxPath = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileStem.'.xlsx';
            $this->writeJson($jsonPath, $report);
            $this->writeWorkbook($xlsxPath, $report);
            $reports[(int) $reportYear] = [
                'json' => $jsonPath,
                'xlsx' => $xlsxPath,
                'journal_entries' => $summary['journal_entries'],
                'operational_matches' => $summary['operational_matches'],
                'control_variances' => $summary['control_variances'],
            ];
        }

        return [
            'output_directory' => $outputDirectory,
            'reports' => $reports,
            'control_errors' => $controls['errors'],
        ];
    }

    private function buildJournalLedger(array $entries, array $years): array
    {
        $accounts = [];
        $movement = [];
        $explicitOpening = [];
        $openingRows = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $date = $this->validDate($entry['date'] ?? null);
            if ($date === null) {
                continue;
            }
            $entryYear = (int) substr($date, 0, 4);
            $isOpening = strtolower(trim((string) ($entry['source_type'] ?? ''))) === 'opening balance';
            $lines = (array) ($entry['lines'] ?? []);
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $debit = $this->amount($line['debit'] ?? 0);
                $credit = $this->amount($line['credit'] ?? 0);
                if (abs($debit) < 0.00001 && abs($credit) < 0.00001) {
                    continue;
                }
                $account = $this->accountFromLine($line);
                $key = $account['key'];
                $accounts[$key] = $account;
                if ($isOpening) {
                    $explicitOpening[$entryYear][$key]['debit'] = ($explicitOpening[$entryYear][$key]['debit'] ?? 0) + $debit;
                    $explicitOpening[$entryYear][$key]['credit'] = ($explicitOpening[$entryYear][$key]['credit'] ?? 0) + $credit;
                    $openingRows[$entryYear][$key][] = [
                        'source_type' => $entry['source_type'] ?? '',
                        'source_number' => $entry['source_number'] ?? '',
                        'date' => $date,
                        'description' => $entry['description'] ?? '',
                        'debit' => $debit,
                        'credit' => $credit,
                        'sources' => $this->provenance((array) ($entry['legacy_sources'] ?? [])),
                    ];
                } else {
                    $movement[$entryYear][$key]['debit'] = ($movement[$entryYear][$key]['debit'] ?? 0) + $debit;
                    $movement[$entryYear][$key]['credit'] = ($movement[$entryYear][$key]['credit'] ?? 0) + $credit;
                }
            }
        }

        $firstYear = min($years);
        $lastYear = max($years);
        $calculationYears = range($firstYear, $lastYear);
        $accountBalances = [];
        foreach ($accounts as $accountKey => $account) {
            $previousClosing = null;
            $previousKnown = false;
            foreach ($calculationYears as $calculationYear) {
                $openingData = $explicitOpening[$calculationYear][$accountKey] ?? null;
                $explicit = $openingData === null
                    ? null
                    : round((float) $openingData['debit'] - (float) $openingData['credit'], 2);
                $movementData = $movement[$calculationYear][$accountKey] ?? ['debit' => 0.0, 'credit' => 0.0];
                $debit = round((float) ($movementData['debit'] ?? 0), 2);
                $credit = round((float) ($movementData['credit'] ?? 0), 2);

                if ($previousKnown) {
                    $opening = (float) $previousClosing;
                    $openingBasis = 'saldo_akhir_tahun_sebelumnya';
                    $openingVariance = $explicit === null ? null : round($explicit - $opening, 2);
                } elseif ($explicit !== null) {
                    $opening = $explicit;
                    $openingBasis = 'opening_balance_journal';
                    $openingVariance = null;
                } elseif ($previousClosing !== null) {
                    $opening = (float) $previousClosing;
                    $openingBasis = 'carry_forward_dengan_saldo_awal_tidak_lengkap';
                    $openingVariance = null;
                } else {
                    $opening = 0.0;
                    $openingBasis = 'awal_tanpa_saldo_sumber';
                    $openingVariance = null;
                }

                $closing = round($opening + $debit - $credit, 2);
                $known = $previousKnown || $explicit !== null;
                $row = [
                    'year' => $calculationYear,
                    'account_no' => $account['account_no'],
                    'account_name' => $account['account_name'],
                    'opening_balance' => $opening,
                    'opening_basis' => $openingBasis,
                    'explicit_journal_opening' => $explicit,
                    'opening_variance' => $openingVariance,
                    'debit' => $debit,
                    'credit' => $credit,
                    'closing_balance' => $closing,
                    'balance_is_complete' => $known,
                    'opening_entries' => array_values($openingRows[$calculationYear][$accountKey] ?? []),
                ];
                $accountHasHistory = $previousClosing !== null;
                if ($debit !== 0.0 || $credit !== 0.0 || $explicit !== null || $accountHasHistory) {
                    $accountBalances[$calculationYear][$accountKey] = $row;
                }
                $previousClosing = ($debit !== 0.0 || $credit !== 0.0 || $explicit !== null || $accountHasHistory)
                    ? $closing
                    : null;
                $previousKnown = $known;
            }
        }

        $cashBankTotals = [];
        foreach ($accountBalances as $balanceYear => $yearAccounts) {
            foreach ($yearAccounts as $row) {
                if (! $this->looksLikeCashBank($row)) {
                    continue;
                }
                $cashBankTotals[$balanceYear]['opening_balance'] = ($cashBankTotals[$balanceYear]['opening_balance'] ?? 0) + (float) $row['opening_balance'];
                $cashBankTotals[$balanceYear]['debit'] = ($cashBankTotals[$balanceYear]['debit'] ?? 0) + (float) $row['debit'];
                $cashBankTotals[$balanceYear]['credit'] = ($cashBankTotals[$balanceYear]['credit'] ?? 0) + (float) $row['credit'];
                $cashBankTotals[$balanceYear]['closing_balance'] = ($cashBankTotals[$balanceYear]['closing_balance'] ?? 0) + (float) $row['closing_balance'];
                $cashBankTotals[$balanceYear]['accounts'][] = trim($row['account_no'].' '.$row['account_name']);
            }
        }

        return [
            'account_balances' => $accountBalances,
            'cash_bank_totals' => $cashBankTotals,
        ];
    }

    private function buildTransactionsForYear(array $entries, array $operationalEvidence, int $year, array $importRowsBySourceKey = []): array
    {
        $documents = (array) ($operationalEvidence['documents'] ?? []);
        $journalIdentities = [];
        $transactions = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $date = $this->validDate($entry['date'] ?? null);
            if ($date === null || (int) substr($date, 0, 4) !== $year) {
                continue;
            }
            $identity = $this->operationalIdentity($entry);
            if ($identity !== null) {
                $journalIdentities[$identity] = true;
            }
            $document = $identity !== null ? ($documents[$identity] ?? null) : null;
            $sourceKey = trim((string) ($entry['source_key'] ?? ''));
            $transactions[] = $this->transactionRow(
                $entry,
                $document,
                $identity,
                $year,
                $importRowsBySourceKey[$sourceKey] ?? []
            );
        }

        foreach ($documents as $identity => $document) {
            if (! is_array($document) || (int) substr((string) ($document['date'] ?? ''), 0, 4) !== $year
                || isset($journalIdentities[(string) $identity])) {
                continue;
            }
            $transactions[] = $this->operationalOnlyRow((string) $identity, $document, $year);
        }

        usort($transactions, fn (array $left, array $right): int => [
            (string) ($left['date'] ?? ''),
            (string) ($left['source_type'] ?? ''),
            (string) ($left['source_number'] ?? ''),
        ] <=> [
            (string) ($right['date'] ?? ''),
            (string) ($right['source_type'] ?? ''),
            (string) ($right['source_number'] ?? ''),
        ]);

        return $transactions;
    }

    private function transactionRow(array $entry, ?array $document, ?string $identity, int $year, array $journalImportRows = []): array
    {
        $sourceType = trim((string) ($entry['source_type'] ?? ''));
        $sourceNumber = trim((string) ($entry['source_number'] ?? ''));
        $journal = $this->journalComponents($entry);
        $journalDebit = $this->journalSideTotal($entry, 'debit');
        $journalCredit = $this->journalSideTotal($entry, 'credit');
        $operational = $document === null ? null : $this->operationalAmounts($document, $sourceType);
        $documentStatus = (string) ($document['status'] ?? '');
        if ($sourceType === 'Journal Entry') {
            $status = 'not_applicable';
        } elseif (in_array($sourceType, self::OPERATIONAL_SOURCE_TYPES, true)) {
            $status = $document === null
                ? 'missing_operational_source'
                : ($documentStatus === 'matched' ? 'matched' : 'conflict');
        } else {
            $status = 'unsupported_source_type';
        }
        if (! empty($entry['legacy_conflict_resolution'])) {
            $status = 'journal_conflict_resolved';
        } elseif (! empty($entry['source_identity_conflict'])) {
            $status = 'journal_conflict';
        } elseif ($status === 'matched' && $document !== null) {
            // Equal gross totals must not hide a basis/VAT difference that cancels out.
            $componentPairs = [
                [$journal['base'] ?? null, $operational['base'] ?? null],
                [$journal['vat_in'] ?? null, $operational['vat_in'] ?? null],
                [$journal['vat_out'] ?? null, $operational['vat_out'] ?? null],
                [$journal['total'] ?? null, $operational['total'] ?? null],
            ];
            foreach ($componentPairs as [$journalAmount, $operationalAmount]) {
                if (is_numeric($journalAmount) && is_numeric($operationalAmount)
                    && abs((float) $journalAmount - (float) $operationalAmount) > 0.01) {
                    $status = 'amount_mismatch';
                    break;
                }
            }
        }

        $label = $this->transactionLabel($entry, $document, $status);
        return [
            'year' => $year,
            'date' => $entry['date'] ?? null,
            'source_type' => $sourceType,
            'source_number' => $sourceNumber,
            'description' => trim((string) ($entry['description'] ?? '')),
            'label' => $label['label'],
            'label_source' => $label['source'],
            'journal_base' => $journal['base'],
            'journal_vat_in' => $journal['vat_in'],
            'journal_vat_out' => $journal['vat_out'],
            'journal_other_tax' => $journal['other_tax'],
            'journal_total' => $journal['total'],
            'journal_debit' => $journalDebit,
            'journal_credit' => $journalCredit,
            'journal_balance_variance' => round($journalDebit - $journalCredit, 2),
            'operational_base' => $operational['base'] ?? null,
            'operational_vat_in' => $operational['vat_in'] ?? null,
            'operational_vat_out' => $operational['vat_out'] ?? null,
            'operational_total' => $operational['total'] ?? null,
            'base_variance' => $this->difference($journal['base'], $operational['base'] ?? null),
            'vat_in_variance' => $this->difference($journal['vat_in'], $operational['vat_in'] ?? null),
            'vat_out_variance' => $this->difference($journal['vat_out'], $operational['vat_out'] ?? null),
            'total_variance' => $this->difference($journal['total'], $operational['total'] ?? null),
            'match_status' => $status,
            'match_reason' => $document['review_reason']
                ?? $entry['legacy_evidence_reason']
                ?? ($entry['legacy_conflict_resolution']['message'] ?? null)
                ?? ($entry['source_identity_conflict_message'] ?? null),
            'journal_conflict_resolution' => $entry['legacy_conflict_resolution'] ?? null,
            'journal_components' => $journal['components'],
            'journal_sources' => $this->provenance((array) ($entry['legacy_sources'] ?? [])),
            'operational_sources' => $this->provenance((array) ($document['provenance'] ?? [])),
            'operational_source_count' => (int) ($document['source_count'] ?? 0),
            'linked_operational_source_count' => (int) ($document['linked_source_count'] ?? 0),
            'has_operational_source' => $document !== null,
            'operational_identity' => $identity,
            'source_key' => (string) ($entry['source_key'] ?? ''),
            'journal_import_status' => $this->journalImportStatus($journalImportRows),
            'journal_import_errors' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => trim((string) ($row['processing_error'] ?? $row['processing_message'] ?? '')),
                $journalImportRows
            )))),
            'journal_import_row_ids' => array_values(array_filter(array_map(
                fn (array $row): int => (int) ($row['id'] ?? 0),
                $journalImportRows
            ))),
            'unmapped_journal_accounts' => (array) ($entry['missing_account_lines'] ?? []),
            'has_unmapped_journal_accounts' => ! empty($entry['missing_account_lines']),
        ];
    }

    private function operationalOnlyRow(string $identity, array $document, int $year): array
    {
        $amounts = $this->operationalAmounts($document, (string) ($document['source_type'] ?? ''));
        $sourceType = (string) ($document['source_type'] ?? '');
        $label = $this->transactionLabel(['description' => $document['description'] ?? '', 'source_type' => $sourceType], $document, 'operational_only');

        return [
            'year' => $year,
            'date' => $document['date'] ?? null,
            'source_type' => $sourceType,
            'source_number' => $document['source_number'] ?? '',
            'description' => $document['description'] ?? '',
            'label' => $label['label'],
            'label_source' => $label['source'],
            'journal_base' => null,
            'journal_vat_in' => null,
            'journal_vat_out' => null,
            'journal_other_tax' => null,
            'journal_total' => null,
            'journal_debit' => null,
            'journal_credit' => null,
            'journal_balance_variance' => null,
            'operational_base' => $amounts['base'],
            'operational_vat_in' => $amounts['vat_in'],
            'operational_vat_out' => $amounts['vat_out'],
            'operational_total' => $amounts['total'],
            'base_variance' => null,
            'vat_in_variance' => null,
            'vat_out_variance' => null,
            'total_variance' => null,
            'match_status' => ($document['status'] ?? '') === 'conflict' ? 'operational_conflict' : 'operational_only',
            'match_reason' => $document['review_reason'] ?? null,
            'journal_components' => [],
            'journal_sources' => [],
            'operational_sources' => $this->provenance((array) ($document['provenance'] ?? [])),
            'operational_source_count' => (int) ($document['source_count'] ?? 1),
            'linked_operational_source_count' => (int) ($document['linked_source_count'] ?? 0),
            'has_operational_source' => true,
            'operational_identity' => $identity,
        ];
    }

    private function journalSideTotal(array $entry, string $side): float
    {
        $totalField = $side.'_total';
        if (is_numeric($entry[$totalField] ?? null)) {
            return round((float) $entry[$totalField], 2);
        }

        $total = 0.0;
        foreach ((array) ($entry['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $total += $this->amount($line[$side] ?? 0);
            }
        }

        return round($total, 2);
    }

    private function journalComponents(array $entry): array
    {
        $components = $this->componentService->groupJournalLines($entry, 0);
        $sourceType = (string) ($entry['source_type'] ?? '');
        $side = $sourceType === 'Sales Invoice' ? 'credit' : 'debit';
        if (! in_array($sourceType, ['Sales Invoice', 'Purchase Invoice', 'Expense'], true)) {
            $debits = array_sum(array_column($components, 'debit'));
            $credits = array_sum(array_column($components, 'credit'));
            $side = $credits > $debits ? 'credit' : 'debit';
        }
        $base = 0.0;
        $vatIn = 0.0;
        $vatOut = 0.0;
        $otherTax = 0.0;
        $total = 0.0;
        $taxConflict = false;
        $componentRows = [];
        foreach ((array) ($entry['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $key = $this->componentService->accountKey($line);
            if (isset($line['row'])) {
                $componentRows[$key][] = (int) $line['row'];
            }
        }
        $rows = [];
        foreach ($components as $component) {
            $amount = (float) ($component[$side] ?? 0);
            $total += $amount;
            if (empty($component['is_tax'])) {
                $base += $amount;
            } elseif (! empty($component['tax_direction_conflict'])) {
                $otherTax += $amount;
                $taxConflict = true;
            } elseif (($component['tax_direction'] ?? null) === 'input') {
                $vatIn += $amount;
            } elseif (($component['tax_direction'] ?? null) === 'output') {
                $vatOut += $amount;
            } else {
                $otherTax += $amount;
            }
            $rows[] = [
                'account_no' => (string) ($component['account_no'] ?? ''),
                'account_name' => (string) ($component['account_name'] ?? ''),
                'debit' => round((float) ($component['debit'] ?? 0), 2),
                'credit' => round((float) ($component['credit'] ?? 0), 2),
                'is_tax' => (bool) ($component['is_tax'] ?? false),
                'tax_direction' => $component['tax_direction'] ?? null,
                'tax_direction_conflict' => (bool) ($component['tax_direction_conflict'] ?? false),
                'source_rows' => array_values(array_unique($componentRows[$this->componentService->accountKey($component)] ?? [])),
            ];
        }

        return [
            'base' => round($base, 2),
            'vat_in' => round($vatIn, 2),
            'vat_out' => round($vatOut, 2),
            'other_tax' => round($otherTax, 2),
            'total' => round($total, 2),
            'tax_direction_conflict' => $taxConflict,
            'components' => $rows,
        ];
    }

    private function operationalAmounts(array $document, string $sourceType): array
    {
        $base = is_numeric($document['base'] ?? null) ? (float) $document['base'] : null;
        $tax = is_numeric($document['tax'] ?? null) ? (float) $document['tax'] : null;
        if ($base === null) {
            $lineAmounts = [];
            foreach ((array) ($document['lines'] ?? []) as $line) {
                if (is_numeric($line['line_base'] ?? null)) {
                    $lineAmounts[] = (float) $line['line_base'];
                }
            }
            foreach ((array) ($document['expense_lines'] ?? []) as $line) {
                if (is_numeric($line['amount'] ?? null)) {
                    $lineAmounts[] = (float) $line['amount'];
                }
            }
            if ($lineAmounts !== []) {
                $base = array_sum($lineAmounts);
            }
        }
        if ($tax === null) {
            $lineTaxes = [];
            foreach ((array) ($document['lines'] ?? []) as $line) {
                if (is_numeric($line['tax'] ?? null)) {
                    $lineTaxes[] = (float) $line['tax'];
                }
            }
            if ($lineTaxes !== []) {
                $tax = array_sum($lineTaxes);
            }
        }
        $total = is_numeric($document['total'] ?? null)
            ? (float) $document['total']
            : ($base !== null && $tax !== null ? $base + $tax : null);

        return [
            'base' => $base === null ? null : round($base, 2),
            'tax' => $tax === null ? null : round($tax, 2),
            'vat_in' => $tax === null ? null : (in_array($sourceType, ['Purchase Invoice', 'Expense'], true) ? round($tax, 2) : 0.0),
            'vat_out' => $tax === null ? null : ($sourceType === 'Sales Invoice' ? round($tax, 2) : 0.0),
            'total' => $total === null ? null : round($total, 2),
        ];
    }

    private function transactionLabel(array $entry, ?array $document, string $status): array
    {
        $description = trim((string) ($entry['description'] ?? ''));
        if ($document !== null && in_array($status, ['matched', 'amount_mismatch', 'operational_only'], true) && ($document['status'] ?? '') === 'matched') {
            $categoryNames = [];
            $category = trim((string) ($document['category_name'] ?? ''));
            if ($category !== '') {
                $categoryNames[] = $category;
            }
            foreach ((array) ($document['expense_lines'] ?? []) as $line) {
                $name = trim((string) ($line['category_name'] ?? ''));
                if ($name !== '') {
                    $categoryNames[$name] = $name;
                }
            }
            $categoryNames = array_values(array_unique(array_filter($categoryNames)));
            if (count($categoryNames) === 1) {
                return ['label' => $this->standardLabel($categoryNames[0]), 'source' => 'operational_category'];
            }
            if (count($categoryNames) > 1) {
                return ['label' => $description, 'source' => 'journal_description_ambiguous_category'];
            }
            if (($entry['source_type'] ?? '') === 'Sales Invoice') {
                return ['label' => 'Pendapatan Usaha', 'source' => 'matched_operational_source_type'];
            }
            if (! empty($document['source_linked'])) {
                $linkedTypeLabels = [
                    'Purchase Invoice' => 'Pembelian',
                    'Receive Payment' => 'Penerimaan Pembayaran',
                    'Purchase Payment' => 'Pembayaran Pembelian',
                    'Bank Deposit' => 'Setoran Bank',
                    'Bank Transfer' => 'Transfer Bank',
                    'Bank Withdrawal' => 'Penarikan Bank',
                    'Stock Adjustment' => 'Penyesuaian Stok',
                    'Stock In/Out' => 'Mutasi Stok',
                    'Opening Balance' => 'Saldo Awal',
                    'Reversal' => 'Pembalikan',
                ];
                $linkedType = (string) ($document['linked_source_type'] ?? $entry['source_type'] ?? '');
                if (isset($linkedTypeLabels[$linkedType])) {
                    return ['label' => $linkedTypeLabels[$linkedType], 'source' => 'linked_operational_source_type'];
                }
            }
        }

        return ['label' => $description, 'source' => 'journal_description'];
    }

    private function standardLabel(string $category): string
    {
        $normalized = $this->normalize($category);
        foreach ([
            'hpp' => '/\b(hpp|cost of sales|harga pokok)\b/u',
            'Operasional' => '/\b(operasional|operational)\b/u',
            'Asset Lancar' => '/\b(asset lancar|aset lancar|current asset)\b/u',
            'Pembelian Perlengkapan' => '/\b(perlengkapan|supplies)\b/u',
            'Pembelian Peralatan' => '/\b(peralatan|equipment)\b/u',
        ] as $label => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $label === 'hpp' ? 'HPP' : $label;
            }
        }

        return $category;
    }

    private function compareControls(array $controls, array $accountBalances, array $cashBankTotals, array $years): array
    {
        $comparisons = [];
        $journalByCode = [];
        $journalByName = [];
        foreach ($accountBalances as $year => $accounts) {
            foreach ($accounts as $row) {
                $code = $this->normalizeAccountNumber((string) ($row['account_no'] ?? ''));
                $name = $this->normalize((string) ($row['account_name'] ?? ''));
                if ($code !== '') {
                    $journalByCode[$year][$code] = $row;
                }
                if ($name !== '') {
                    $journalByName[$year][$name] = $row;
                }
            }
        }

        foreach ((array) ($controls['general_ledger'] ?? []) as $control) {
            $year = (int) ($control['year'] ?? 0);
            if (! in_array($year, $years, true)) {
                continue;
            }
            $code = $this->normalizeAccountNumber((string) ($control['account_no'] ?? ''));
            $name = $this->normalize((string) ($control['account_name'] ?? ''));
            $journal = $code !== ''
                ? ($journalByCode[$year][$code] ?? null)
                : ($journalByName[$year][$name] ?? null);
            if ($journal === null && $name !== '') {
                $journal = $journalByName[$year][$name] ?? null;
            }
            $balanceSign = $this->accountBalanceSign((string) ($journal['account_no'] ?? $control['account_no'] ?? ''));
            $signedOpening = $this->signedControlBalance($control['opening_balance'], $balanceSign);
            $signedClosing = $this->signedControlBalance($control['closing_balance'], $balanceSign);
            $comparisons[] = [
                'year' => $year,
                'control_type' => 'general_ledger_account',
                'source_file' => $control['source_file'],
                'source_sheet' => $control['source_sheet'],
                'account_no' => $control['account_no'],
                'account_name' => $control['account_name'],
                'control_opening' => $control['opening_balance'],
                'control_opening_signed' => $signedOpening,
                'control_debit' => round((float) $control['debit'], 2),
                'control_credit' => round((float) $control['credit'], 2),
                'control_closing' => $control['closing_balance'],
                'control_closing_signed' => $signedClosing,
                'journal_opening' => $journal['opening_balance'] ?? null,
                'journal_debit' => $journal['debit'] ?? null,
                'journal_credit' => $journal['credit'] ?? null,
                'journal_closing' => $journal['closing_balance'] ?? null,
                'opening_variance' => $this->difference($signedOpening, $journal['opening_balance'] ?? null),
                'debit_variance' => $this->difference($control['debit'], $journal['debit'] ?? null),
                'credit_variance' => $this->difference($control['credit'], $journal['credit'] ?? null),
                'closing_variance' => $this->difference($signedClosing, $journal['closing_balance'] ?? null),
                'match_status' => $journal === null ? 'account_not_matched' : 'matched_for_comparison',
                'source_rows' => $control['source_rows'],
            ];
        }

        foreach ((array) ($controls['book_references'] ?? []) as $control) {
            $year = (int) ($control['year'] ?? 0);
            if (! in_array($year, $years, true)) {
                continue;
            }
            $journal = $cashBankTotals[$year] ?? null;
            $comparisons[] = [
                'year' => $year,
                'control_type' => 'consolidated_book_reference',
                'source_file' => $control['source_file'],
                'source_sheet' => $control['source_sheet'],
                'account_no' => '',
                'account_name' => 'Kas/bank gabungan dari akun Journal yang teridentifikasi',
                'control_opening' => $control['opening_balance'],
                'control_opening_signed' => $control['opening_balance'],
                'control_debit' => round((float) $control['debit'], 2),
                'control_credit' => round((float) $control['credit'], 2),
                'control_closing' => $control['closing_balance'],
                'control_closing_signed' => $control['closing_balance'],
                'journal_opening' => $journal['opening_balance'] ?? null,
                'journal_debit' => $journal === null ? null : round((float) $journal['debit'], 2),
                'journal_credit' => $journal === null ? null : round((float) $journal['credit'], 2),
                'journal_closing' => $journal === null ? null : round((float) $journal['closing_balance'], 2),
                'opening_variance' => $this->difference($control['opening_balance'], $journal['opening_balance'] ?? null),
                'debit_variance' => $this->difference($control['debit'], $journal['debit'] ?? null),
                'credit_variance' => $this->difference($control['credit'], $journal['credit'] ?? null),
                'closing_variance' => $this->difference($control['closing_balance'], $journal['closing_balance'] ?? null),
                'match_status' => $journal === null ? 'cash_bank_accounts_not_identified' : 'consolidated_comparison',
                'journal_accounts' => $journal['accounts'] ?? [],
                'source_rows' => $control['source_rows'],
            ];
        }

        return $comparisons;
    }

    /**
     * The legacy control workbook displays credit-normal accounts as positive
     * balances, while this audit consistently uses debit minus credit. Convert
     * liabilities, equity, and income to that signed convention before comparing.
     */
    private function accountBalanceSign(string $accountNo): int
    {
        $accountClass = substr(trim($accountNo), 0, 1);

        return in_array($accountClass, ['2', '3', '4'], true) ? -1 : 1;
    }

    private function signedControlBalance($balance, int $sign): ?float
    {
        if (! is_numeric($balance)) {
            return null;
        }

        return round((float) $balance * $sign, 2);
    }

    private function annualSummary(
        array $transactions,
        array $accounts,
        array $controls,
        array $coverage,
        array $controlErrors,
        array $journalImportRows = [],
        int $unassignedImportRowCount = 0
    ): array
    {
        $count = function (array $statuses, array $allowed): int {
            return count(array_filter($statuses, fn ($status) => in_array($status, $allowed, true)));
        };
        $statuses = array_column($transactions, 'match_status');
        $operationalRows = array_filter($transactions, fn (array $row): bool => ! empty($row['has_operational_source']));
        $openingVariances = count(array_filter($accounts, fn (array $row): bool => is_numeric($row['opening_variance'] ?? null) && abs((float) $row['opening_variance']) > 0.01));
        $controlVariances = count(array_filter($controls, function (array $row): bool {
            foreach (['opening_variance', 'debit_variance', 'credit_variance', 'closing_variance'] as $field) {
                if (is_numeric($row[$field] ?? null) && abs((float) $row[$field]) > 0.01) {
                    return true;
                }
            }

            return $row['match_status'] === 'account_not_matched';
        }));
        $journalEntries = count(array_filter($transactions, fn (array $row): bool => $row['match_status'] !== 'operational_only'
            && $row['match_status'] !== 'operational_conflict'));
        $unmappedAccountEntries = count(array_filter(
            $transactions,
            fn (array $row): bool => ! empty($row['has_unmapped_journal_accounts'])
        ));
        $importStatusCounts = $journalImportRows === []
            ? []
            : array_count_values(array_column($journalImportRows, 'processing_status'));

        return [
            'journal_entries' => $journalEntries,
            'operational_documents' => count($operationalRows),
            'operational_matches' => $count($statuses, ['matched']),
            'missing_operational_source' => $count($statuses, ['missing_operational_source']),
            'unsupported_source_types' => $count($statuses, ['unsupported_source_type']),
            'source_conflicts' => $count($statuses, ['conflict', 'journal_conflict', 'operational_conflict']),
            'journal_conflicts_resolved' => $count($statuses, ['journal_conflict_resolved']),
            'amount_mismatches' => $count($statuses, ['amount_mismatch']),
            'operational_only' => $count($statuses, ['operational_only', 'operational_conflict']),
            'journal_duplicate_sources' => array_sum(array_map(
                fn (array $row): int => max(0, count((array) ($row['journal_sources'] ?? [])) - 1),
                $transactions
            )),
            'operational_duplicate_sources' => array_sum(array_map(
                fn (array $row): int => max(0, (int) ($row['operational_source_count'] ?? 0) - 1),
                $transactions
            )),
            'linked_operational_source_rows' => array_sum(array_column($transactions, 'linked_operational_source_count')),
            'accounts' => count($accounts),
            'opening_variances' => $openingVariances,
            'control_rows' => count($controls),
            'control_variances' => $controlVariances,
            'coverage_files' => count((array) ($coverage['files'] ?? [])),
            'control_read_errors' => count($controlErrors),
            'balance_incomplete_accounts' => count(array_filter($accounts, fn (array $row): bool => empty($row['balance_is_complete']))),
            'source_type_counts' => array_count_values(array_column($transactions, 'source_type')),
            'missing_source_classes' => $coverage['missing_source_classes'] ?? [],
            'journal_import_rows' => count($journalImportRows),
            'journal_import_unassigned_rows' => $unassignedImportRowCount,
            'journal_import_status_counts' => $importStatusCounts,
            'journal_import_failed_rows' => (int) ($importStatusCounts['failed'] ?? 0),
            'journal_import_unfinished_rows' => array_sum(array_intersect_key(
                $importStatusCounts,
                array_flip(['pending', 'queued', 'processing'])
            )),
            'journal_import_unmapped_account_rows' => count(array_filter(
                $journalImportRows,
                fn (array $row): bool => ! empty($row['missing_account_lines'])
            )),
            'unmapped_journal_account_entries' => $unmappedAccountEntries,
        ];
    }

    private function journalImportStatus(array $journalImportRows): ?string
    {
        if ($journalImportRows === []) {
            return null;
        }

        $statuses = array_values(array_unique(array_filter(array_map(
            fn (array $row): string => trim((string) ($row['processing_status'] ?? '')),
            $journalImportRows
        ))));

        return count($statuses) === 1 ? $statuses[0] : ($statuses === [] ? null : 'multiple');
    }

    private function coveredYears(array $entries, array $evidence, array $inventory, array $controls): array
    {
        $years = [];
        foreach ($entries as $entry) {
            $date = $this->validDate($entry['date'] ?? null);
            if ($date !== null) {
                $years[(int) substr($date, 0, 4)] = true;
            }
        }
        foreach ((array) ($evidence['documents'] ?? []) as $document) {
            $date = $this->validDate($document['date'] ?? null);
            if ($date !== null) {
                $years[(int) substr($date, 0, 4)] = true;
            }
        }
        foreach ((array) ($inventory['year_coverage'] ?? []) as $year => $_count) {
            $years[(int) $year] = true;
        }
        foreach (array_merge((array) ($controls['general_ledger'] ?? []), (array) ($controls['book_references'] ?? [])) as $control) {
            $controlYear = (int) ($control['year'] ?? 0);
            if ($controlYear > 0) {
                $years[$controlYear] = true;
            }
        }
        $years = array_keys($years);
        sort($years, SORT_NUMERIC);
        if ($years === []) {
            return [];
        }

        return range(min($years), max($years));
    }

    private function coverageForYear(array $inventory, int $year): array
    {
        $rows = [];
        $classes = [];
        $expectedClasses = [
            'journal_posting_source',
            'sales_operational_source',
            'purchase_operational_source',
            'expense_operational_source',
            'expense_detail_source',
            'general_ledger_control',
            'book_reference',
        ];
        foreach ((array) ($inventory['files'] ?? []) as $file) {
            $start = isset($file['period_start']) ? (int) substr((string) $file['period_start'], 0, 4) : null;
            $end = isset($file['period_end']) ? (int) substr((string) $file['period_end'], 0, 4) : null;
            if ($start !== null && $end !== null && ($year < $start || $year > $end)) {
                continue;
            }
            $rows[] = [
                'path' => $file['path'] ?? '',
                'classification' => $file['classification'] ?? '',
                'period_start' => $file['period_start'] ?? null,
                'period_end' => $file['period_end'] ?? null,
                'sha256' => $file['sha256'] ?? null,
                'duplicate_of' => $file['duplicate_of'] ?? null,
            ];
            $classification = (string) ($file['classification'] ?? '');
            $classes[$classification] = ($classes[$classification] ?? 0) + 1;
        }

        return [
            'files' => $rows,
            'source_classes' => $classes,
            'missing_source_classes' => array_values(array_filter(
                $expectedClasses,
                fn (string $classification): bool => empty($classes[$classification])
            )),
        ];
    }

    private function accountFromLine(array $line): array
    {
        $accountNo = trim((string) ($line['account_no'] ?? ''));
        $accountName = trim((string) ($line['account_name'] ?? ''));
        if ($accountNo === '' && $accountName === '') {
            $accountName = 'Akun tidak bernama';
        }
        $key = $this->normalizeAccountNumber($accountNo).'|'.$this->normalize($accountName);

        return [
            'key' => $key,
            'account_no' => $accountNo,
            'account_name' => $accountName,
        ];
    }

    private function looksLikeCashBank(array $account): bool
    {
        $label = strtolower(trim((string) ($account['account_no'] ?? '').' '.(string) ($account['account_name'] ?? '')));

        return preg_match('/\b(cash|kas|bank|giro|mandiri|bca|bri|bni|bsi|rekening|petty)\b/u', $label) === 1
            || preg_match('/^1-100/u', trim((string) ($account['account_no'] ?? ''))) === 1;
    }

    private function operationalIdentity(array $entry): ?string
    {
        $type = trim((string) ($entry['source_type'] ?? ''));
        $number = $this->normalizeIdentityText($entry['source_number'] ?? '');
        $date = $this->validDate($entry['date'] ?? null);
        if ($type === '' || $number === '' || $date === null) {
            return null;
        }

        return implode('|', [$type, $number, $date]);
    }

    private function normalizeAccountNumber(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)));
    }

    private function normalizeIdentityText($value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value) ?: (string) $value;

        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function normalize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $value)));
    }

    private function provenance(array $sources): array
    {
        $result = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $result[] = [
                'path' => (string) ($source['path'] ?? ''),
                'row' => isset($source['row']) ? (int) $source['row'] : null,
                'source_key' => (string) ($source['source_key'] ?? ''),
            ];
        }

        return $result;
    }

    private function validDate($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function amount($value): float
    {
        $amount = is_numeric($value) ? (float) $value : 0.0;

        return is_finite($amount) ? $amount : 0.0;
    }

    private function difference($left, $right): ?float
    {
        return is_numeric($left) && is_numeric($right)
            ? round((float) $left - (float) $right, 2)
            : null;
    }

    private function manifestHash(array $inventory): string
    {
        $files = [];
        foreach ((array) ($inventory['files'] ?? []) as $file) {
            $files[] = [
                'path' => (string) ($file['path'] ?? ''),
                'sha256' => (string) ($file['sha256'] ?? ''),
                'classification' => (string) ($file['classification'] ?? ''),
            ];
        }
        usort($files, fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return hash('sha256', json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function writeJson(string $path, array $report): void
    {
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false || file_put_contents($path, $json.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('JSON audit tahunan tidak dapat ditulis: '.$path);
        }
    }

    private function writeWorkbook(string $path, array $report): void
    {
        $workbook = new Spreadsheet();
        $summarySheet = $workbook->getActiveSheet();
        $summarySheet->setTitle('Ringkasan');
        $summaryRows = [
            ['Audit Buku Besar Tahunan', ''],
            ['Business ID', $report['business_id']],
            ['Tahun', $report['year']],
            ['Konvensi saldo', $report['balance_convention']],
            ['Dibuat', $report['generated_at']],
        ];
        foreach ((array) $report['summary'] as $key => $value) {
            $summaryRows[] = [$key, $value];
        }
        $this->writeTable($summarySheet, ['Metrik', 'Nilai'], $summaryRows, false);

        $this->writeTable($workbook->createSheet()->setTitle('Saldo per Akun'), [
            'Tahun', 'Nomor Akun', 'Nama Akun', 'Saldo Awal (Debit - Kredit)', 'Dasar Saldo Awal',
            'Opening Journal', 'Selisih Opening', 'Debit', 'Kredit', 'Saldo Akhir (Debit - Kredit)',
            'Saldo Lengkap', 'Detail Opening Balance',
        ], array_map(fn (array $row) => [
            $row['year'], $row['account_no'], $row['account_name'], $row['opening_balance'],
            $row['opening_basis'], $row['explicit_journal_opening'], $row['opening_variance'],
            $row['debit'], $row['credit'], $row['closing_balance'], $row['balance_is_complete'] ? 'Ya' : 'Belum',
            $this->jsonCell($row['opening_entries'] ?? []),
        ], (array) $report['account_balances']));

        $this->writeTable($workbook->createSheet()->setTitle('Rekonsiliasi Transaksi'), [
            'Tahun', 'Tanggal', 'Jenis', 'Nomor', 'Keterangan Journal', 'Label', 'Asal Label',
            'Basis Journal', 'VAT In Journal', 'VAT Out Journal', 'Pajak Lain Journal', 'Total Journal',
            'Basis Operasional', 'VAT In Operasional', 'VAT Out Operasional', 'Total Operasional',
            'Selisih Basis', 'Selisih VAT In', 'Selisih VAT Out', 'Selisih Total', 'Status Match',
            'Alasan', 'Resolusi Konflik Journal', 'Komponen Akun Journal', 'File/Baris Journal', 'File/Baris Operasional',
            'Debit Journal', 'Kredit Journal', 'Selisih Debit/Kredit', 'Jumlah Sumber Operasional Tertaut',
            'Source Key', 'Status Import Journal', 'Error Import Journal', 'Akun Journal Belum Terpetakan',
        ], array_map(fn (array $row) => [
            $row['year'], $row['date'], $row['source_type'], $row['source_number'], $row['description'],
            $row['label'], $row['label_source'], $row['journal_base'], $row['journal_vat_in'],
            $row['journal_vat_out'], $row['journal_other_tax'], $row['journal_total'],
            $row['operational_base'], $row['operational_vat_in'], $row['operational_vat_out'],
            $row['operational_total'], $row['base_variance'], $row['vat_in_variance'],
            $row['vat_out_variance'], $row['total_variance'], $row['match_status'], $row['match_reason'],
            $this->jsonCell($row['journal_conflict_resolution'] ?? []),
            $this->jsonCell($row['journal_components'] ?? []), $this->jsonCell($row['journal_sources'] ?? []),
            $this->jsonCell($row['operational_sources'] ?? []), $row['journal_debit'] ?? null,
            $row['journal_credit'] ?? null, $row['journal_balance_variance'] ?? null,
            $row['linked_operational_source_count'] ?? 0,
            $row['source_key'] ?? '',
            $row['journal_import_status'] ?? '',
            $this->jsonCell($row['journal_import_errors'] ?? []),
            $this->jsonCell($row['unmapped_journal_accounts'] ?? []),
        ], (array) $report['transaction_reconciliation']));

        if ((array) ($report['journal_import_rows'] ?? []) !== []) {
            $this->writeTable($workbook->createSheet()->setTitle('Status Import Journal'), [
                'Tahun', 'Tanggal', 'Jenis', 'Nomor', 'Source Key', 'Status Proses', 'Aksi Sumber',
                'Journal Mapping ID', 'Error', 'Akun Belum Terpetakan', 'Status Rekonsiliasi',
                'Total Journal', 'Total Operasional', 'Selisih',
            ], array_map(fn (array $row) => [
                $row['year'], $row['date'], $row['source_type'], $row['source_number'], $row['source_key'],
                $row['processing_status'], $row['source_action'], $row['journal_mapping_id'],
                $row['processing_error'] ?? $row['processing_message'] ?? '',
                $this->jsonCell($row['missing_account_lines'] ?? []), $row['reconciliation_status'],
                $row['journal_total'], $row['business_total'], $row['difference_amount'],
            ], (array) $report['journal_import_rows']));
        }

        if ((array) ($report['journal_import_unassigned_rows'] ?? []) !== []) {
            $this->writeTable($workbook->createSheet()->setTitle('Import Journal Tanpa Tahun'), [
                'Tanggal Sumber', 'Jenis', 'Nomor', 'Source Key', 'Status Proses', 'Error',
                'Akun Belum Terpetakan', 'Aksi Sumber', 'Journal Mapping ID',
            ], array_map(fn (array $row) => [
                $row['date'], $row['source_type'], $row['source_number'], $row['source_key'],
                $row['processing_status'], $row['processing_error'] ?? $row['processing_message'] ?? '',
                $this->jsonCell($row['missing_account_lines'] ?? []), $row['source_action'],
                $row['journal_mapping_id'],
            ], (array) $report['journal_import_unassigned_rows']));
        }

        $this->writeTable($workbook->createSheet()->setTitle('Kontrol Saldo'), [
            'Tahun', 'Jenis Kontrol', 'File', 'Sheet', 'Nomor Akun', 'Nama Akun',
            'Saldo Awal Kontrol sumber', 'Saldo Awal Kontrol D-K', 'Debit Kontrol', 'Kredit Kontrol',
            'Saldo Akhir Kontrol sumber', 'Saldo Akhir Kontrol D-K',
            'Saldo Awal Journal', 'Debit Journal', 'Kredit Journal', 'Saldo Akhir Journal',
            'Selisih Opening', 'Selisih Debit', 'Selisih Kredit', 'Selisih Closing', 'Status',
            'Akun Kas/Bank Journal', 'Baris Sumber',
        ], array_map(fn (array $row) => [
            $row['year'], $row['control_type'], $row['source_file'], $row['source_sheet'],
            $row['account_no'], $row['account_name'], $row['control_opening'], $row['control_opening_signed'],
            $row['control_debit'], $row['control_credit'], $row['control_closing'], $row['control_closing_signed'],
            $row['journal_opening'], $row['journal_debit'],
            $row['journal_credit'], $row['journal_closing'], $row['opening_variance'], $row['debit_variance'],
            $row['credit_variance'], $row['closing_variance'], $row['match_status'],
            $this->jsonCell($row['journal_accounts'] ?? []), $this->jsonCell($row['source_rows'] ?? []),
        ], (array) $report['control_comparison']));

        $coverageRows = array_map(fn (array $row) => [
            $report['year'], $row['path'], $row['classification'], $row['period_start'],
            $row['period_end'], $row['sha256'], $row['duplicate_of'],
        ], (array) ($report['coverage']['files'] ?? []));
        foreach ((array) ($report['coverage']['missing_source_classes'] ?? []) as $missingClass) {
            $coverageRows[] = [$report['year'], '', $missingClass, '', '', '', 'Tidak ada file dalam periode tahun'];
        }
        $this->writeTable($workbook->createSheet()->setTitle('Cakupan Sumber'), [
            'Tahun Laporan', 'File', 'Klasifikasi', 'Periode Mulai', 'Periode Akhir', 'SHA-256', 'Duplikat Dari',
        ], $coverageRows);

        if ((array) $report['control_errors'] !== []) {
            $this->writeTable($workbook->createSheet()->setTitle('Error Kontrol'), [
                'File', 'Klasifikasi', 'Error',
            ], array_map(fn (array $row) => [
                $row['path'], $row['classification'], $row['message'],
            ], (array) $report['control_errors']));
        }

        $writer = new Xlsx($workbook);
        try {
            $writer->save($path);
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function writeTable($sheet, array $headers, array $rows, bool $withHeader = true): void
    {
        $startRow = 1;
        if ($withHeader) {
            $this->writeRow($sheet, $startRow, $headers);
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '17365D']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->freezePane('A2');
            $startRow++;
        }
        foreach ($rows as $row) {
            $this->writeRow($sheet, $startRow++, array_values($row));
        }
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $lastRow = max(1, $startRow - 1);
        if ($withHeader && $lastRow > 1) {
            $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);
        }
        foreach (range(1, max(1, count($headers))) as $columnIndex) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($column)->setWidth($columnIndex <= 5 ? 22 : 18);
        }
        $sheet->getStyle('A1:'.$lastColumn.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
    }

    private function writeRow($sheet, int $rowNumber, array $values): void
    {
        foreach ($values as $index => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
            if (is_array($value)) {
                $value = $this->jsonCell($value);
            }
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
            } elseif (is_bool($value)) {
                $sheet->setCellValueExplicit($cell, $value ? 'Ya' : 'Tidak', DataType::TYPE_STRING);
            } else {
                $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }
    }

    private function jsonCell(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
