<?php

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LegacyReconciliationFindingSnapshotService
{
    private const VARIANCE_TOLERANCE = 0.01;

    private const LEGACY_SCOPE = 'legacy-annual';

    private const PAGE_SIZE = 50;

    private const TRANSACTION_COMPONENTS = [
        'base' => ['journal_base', 'operational_base'],
        'vat_in' => ['journal_vat_in', 'operational_vat_in'],
        'vat_out' => ['journal_vat_out', 'operational_vat_out'],
        'total' => ['journal_total', 'operational_total'],
    ];

    public function persistReports(int $businessId, array $reports): array
    {
        $results = [];
        foreach ($reports as $year => $reportDescriptor) {
            $path = is_array($reportDescriptor) ? ($reportDescriptor['json'] ?? null) : $reportDescriptor;
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                continue;
            }
            $report = json_decode((string) file_get_contents($path), true);
            if (! is_array($report)) {
                throw new RuntimeException('Laporan audit tidak dapat dibaca: '.$path);
            }
            $results[(int) ($report['year'] ?? $year)] = $this->persistReport($businessId, $report);
        }

        return $results;
    }

    public function persistFromDirectory(int $businessId, string $directory, ?int $year = null): array
    {
        $pattern = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'business-'.$businessId.'-*.json';
        $paths = glob($pattern) ?: [];
        $reports = [];
        foreach ($paths as $path) {
            if (! preg_match('/business-'.preg_quote((string) $businessId, '/').'-(\d{4})\.json$/', basename($path), $matches)) {
                continue;
            }
            if ($year !== null && (int) $matches[1] !== $year) {
                continue;
            }
            $reports[(int) $matches[1]] = $path;
        }
        ksort($reports);

        return $this->persistReports($businessId, $reports);
    }

    public function persistReport(int $businessId, array $report): int
    {
        $year = (int) ($report['year'] ?? 0);
        if ($businessId < 1 || (int) ($report['business_id'] ?? 0) !== $businessId || $year < 1900 || $year > 2200) {
            throw new InvalidArgumentException('Business dan tahun laporan audit tidak valid atau tidak cocok.');
        }

        $generatedAt = $this->timestamp($report['generated_at'] ?? null);
        $scopeKey = self::LEGACY_SCOPE;
        $auditPayload = $report;
        unset($auditPayload['generated_at']);
        $auditKey = hash('sha256', implode('|', [
            $scopeKey,
            $businessId,
            $year,
            json_encode($auditPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]));
        $rows = $this->findingsFromReport($businessId, $year, $report, $auditKey, $generatedAt, $scopeKey);

        $this->persistSnapshot($businessId, $year, $scopeKey, $rows, $auditKey, $generatedAt, $report['source_manifest_sha256'] ?? null);

        return count($rows);
    }

    /**
     * Persist the completed read-only cash/bank audit without changing operational or GL source rows.
     * Movement findings are comparable by transaction within the audited date range. Balance findings
     * are comparable only for the same exact date range and account selection.
     */
    public function persistCashBankAudit(
        int $businessId,
        string $startDate,
        string $endDate,
        ?int $accountId,
        array $summary,
        array $auditRows
    ): int {
        if ($businessId < 1 || ! $this->isValidDate($startDate) || ! $this->isValidDate($endDate) || $endDate < $startDate) {
            throw new InvalidArgumentException('Business atau rentang tanggal audit kas/bank tidak valid.');
        }

        $auditedAt = now()->toDateTimeString();
        $year = (int) substr($startDate, 0, 4);
        $balanceComparable = ! array_key_exists('cash_bank_balance_comparable', $summary)
            || $summary['cash_bank_balance_comparable'] === true;
        $balanceScope = $this->cashBankBalanceScope(
            $startDate,
            $endDate,
            $accountId,
            (string) ($summary['cash_bank_mapping_scope'] ?? 'unscoped')
        );
        $rows = [];

        foreach ($balanceComparable ? [
            'opening' => ['source_opening_balance', 'opening_balance', 'opening_balance_difference'],
            'closing' => ['source_closing_balance', 'closing_balance', 'closing_balance_difference'],
        ] : [] as $component => [$sourceField, $actualField, $varianceField]) {
            $componentDate = $component === 'opening' ? $startDate : $endDate;
            $finding = $this->makeFinding(
                $businessId,
                (int) substr($componentDate, 0, 4),
                'cash_bank',
                $component,
                $this->identityHash(['scope' => $balanceScope, 'component' => $component]),
                [
                    'date' => $componentDate,
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                    'account_id' => $accountId,
                    'source_type' => 'Audit kas/bank',
                    'source_number' => $startDate.' — '.$endDate,
                    'description' => $component === 'opening' ? 'Perbedaan saldo awal kas/bank' : 'Perbedaan saldo akhir kas/bank',
                    'expected_source' => $summary[$sourceField] ?? null,
                    'actual_gl' => $summary[$actualField] ?? null,
                ],
                $summary[$sourceField] ?? null,
                $summary[$actualField] ?? null,
                $summary[$varianceField] ?? null,
                '',
                $auditedAt,
                '',
                $accountId === null ? 'Kas/Bank gabungan' : 'Akun pembayaran #'.$accountId,
                $balanceScope
            );
            if ($finding !== null) {
                $rows[$finding['finding_key']] = $finding;
            }
        }

        $movementKeysByAccount = [];
        foreach ($auditRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sourceDate = $this->dateOrNull($row['date'] ?? null);
            $accountIdForRow = empty($row['account_id']) ? null : (int) $row['account_id'];
            if ($sourceDate === null || $accountIdForRow === null) {
                continue;
            }
            $movementScope = $this->cashBankMovementScope($accountIdForRow);
            $identity = $this->cashBankMovementIdentity($row, $accountIdForRow);
            $movementKey = $this->makeFinding(
                $businessId,
                (int) substr($sourceDate, 0, 4),
                'cash_bank',
                'movement',
                $identity,
                $row,
                $row['operational_amount'] ?? null,
                $row['accounting_amount'] ?? null,
                is_numeric($row['difference'] ?? null) ? -(float) $row['difference'] : null,
                '',
                $auditedAt,
                '',
                (string) ($row['payment_account'] ?? ''),
                $movementScope
            );
            if ($movementKey !== null) {
                $rows[$movementKey['finding_key']] = $movementKey;
                $movementKeysByAccount[$accountIdForRow][] = $movementKey['finding_key'];
            }

            $vatDirection = (string) ($row['tax_direction'] ?? '');
            $vatComponent = $vatDirection === 'input' ? 'vat_in' : ($vatDirection === 'output' ? 'vat_out' : null);
            if ($vatComponent !== null && is_numeric($row['vat_difference'] ?? null)) {
                $vatFinding = $this->makeFinding(
                    $businessId,
                    (int) substr($sourceDate, 0, 4),
                    'cash_bank',
                    $vatComponent,
                    $identity,
                    $row,
                    $row['source_vat'] ?? null,
                    $row['journal_vat'] ?? null,
                    -(float) $row['vat_difference'],
                    '',
                    $auditedAt,
                    '',
                    (string) ($row['payment_account'] ?? ''),
                    $movementScope
                );
                if ($vatFinding !== null) {
                    $rows[$vatFinding['finding_key']] = $vatFinding;
                    $movementKeysByAccount[$accountIdForRow][] = $vatFinding['finding_key'];
                }
            }
        }

        $scopeKey = 'cash-bank-audit:'.$startDate.':'.$endDate.':'.($accountId === null ? 'all' : (string) $accountId).':'.(string) ($summary['cash_bank_mapping_scope'] ?? 'unscoped');
        $stableAuditData = [
            'scope' => $scopeKey,
            'balance_comparable' => $balanceComparable,
            'summary' => array_intersect_key($summary, array_flip([
                'opening_balance', 'source_opening_balance', 'opening_balance_difference',
                'closing_balance', 'source_closing_balance', 'closing_balance_difference',
            ])),
            'findings' => array_values(array_map(function (array $row): array {
                return [$row['finding_key'], $row['expected_amount'], $row['actual_amount'], $row['variance']];
            }, $rows)),
        ];
        $auditKey = hash('sha256', json_encode([$businessId, $stableAuditData], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        DB::transaction(function () use ($businessId, $year, $scopeKey, $rows, $auditKey, $auditedAt, $startDate, $endDate, $accountId, $balanceScope, $movementKeysByAccount): void {
            $this->recordAudit($businessId, $year, $scopeKey, $rows, $auditKey, $auditedAt, null);
            $this->upsertFindings($rows, $auditKey, $auditedAt);

            $balanceKeys = array_values(array_filter(array_keys($rows), function ($key) use ($rows): bool {
                return in_array($rows[$key]['component'], ['opening', 'closing'], true);
            }));
            if ($balanceComparable) {
                $this->resolveMissingFindings(
                    DB::table('accounting_legacy_reconciliation_findings')
                        ->where('business_id', $businessId)->where('type', 'cash_bank')->where('scope_key', $balanceScope)
                        ->where('is_current', true),
                    $balanceKeys,
                    $auditKey,
                    $auditedAt,
                    'Selisih saldo tidak muncul pada audit kas/bank ulang dengan periode, akun, dan mapping yang sama.'
                );
            }

            $accountIds = $accountId === null
                ? DB::table('accounting_legacy_reconciliation_findings')
                    ->where('business_id', $businessId)->where('type', 'cash_bank')->where('scope_key', 'like', 'cash-bank-movement:%')
                    ->whereBetween('source_date', [$startDate, $endDate])->distinct()->pluck('scope_key')->map(function ($scope): ?int {
                        $parts = explode(':', (string) $scope);
                        return isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
                    })->filter()->unique()->values()->all()
                : [$accountId];

            foreach ($accountIds as $movementAccountId) {
                $movementScope = $this->cashBankMovementScope((int) $movementAccountId);
                $this->resolveMissingFindings(
                    DB::table('accounting_legacy_reconciliation_findings')
                        ->where('business_id', $businessId)->where('type', 'cash_bank')->where('scope_key', $movementScope)
                        ->whereBetween('source_date', [$startDate, $endDate])->where('is_current', true),
                    array_values(array_unique($movementKeysByAccount[(int) $movementAccountId] ?? [])),
                    $auditKey,
                    $auditedAt,
                    'Selisih transaksi tidak muncul pada audit kas/bank ulang dalam periode yang sama.'
                );
            }
        });

        return count($rows);
    }

    public function summary(int $businessId, array $filters = []): array
    {
        $query = $this->filteredQuery($businessId, $filters);
        $byYearTypeComponent = (clone $query)
            ->select('year', 'type', 'component')
            ->selectRaw('COUNT(*) as finding_count')
            ->selectRaw('SUM(ABS(variance)) as gross_amount')
            ->selectRaw('SUM(variance) as net_amount')
            ->groupBy('year', 'type', 'component')
            ->orderByDesc('year')
            ->orderBy('type')
            ->orderBy('component')
            ->get()
            ->map(function ($row): array {
                return [
                    'year' => (int) $row->year,
                    'type' => (string) $row->type,
                    'type_label' => $this->typeLabel((string) $row->type),
                    'component' => (string) $row->component,
                    'component_label' => $this->componentLabel((string) $row->component),
                    'finding_count' => (int) $row->finding_count,
                    'gross_amount' => round((float) $row->gross_amount, 2),
                    'net_amount' => round((float) $row->net_amount, 2),
                ];
            })->all();

        return [
            'open_count' => (int) $this->baseQuery($businessId)->where('is_current', true)->where('status', 'open')->count(),
            'filtered_count' => (int) (clone $query)->count(),
            'by_year_type_component' => $byYearTypeComponent,
            'last_audited_at' => DB::table('accounting_legacy_reconciliation_audits')
                ->where('business_id', $businessId)
                ->max('completed_at'),
        ];
    }

    public function paginate(int $businessId, array $filters = [], ?int $page = null): LengthAwarePaginator
    {
        $page = max(1, (int) ($page ?? 1));

        return $this->filteredQuery($businessId, $filters)
            ->orderByDesc('year')
            ->orderByDesc('source_date')
            ->orderBy('type')
            ->orderBy('source_number')
            ->paginate(self::PAGE_SIZE, ['*'], 'page', $page)
            ->withQueryString();
    }

    public function years(int $businessId): array
    {
        return DB::table('accounting_legacy_reconciliation_audits')
            ->where('business_id', $businessId)
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();
    }

    public function openCount(int $businessId): int
    {
        return (int) $this->baseQuery($businessId)->where('is_current', true)->where('status', 'open')->count();
    }

    public function typeLabel(string $type): string
    {
        return [
            'transaction' => 'Journal vs operasional',
            'control' => 'Kontrol buku besar',
            'account_balance' => 'Saldo akun',
            'unbalanced_journal' => 'Journal tidak seimbang',
            'cash_bank' => 'Audit kas/bank',
        ][$type] ?? $type;
    }

    public function componentLabel(string $component): string
    {
        return [
            'base' => 'Basis', 'vat_in' => 'VAT In', 'vat_out' => 'VAT Out', 'total' => 'Total',
            'opening' => 'Saldo awal', 'debit' => 'Debit', 'credit' => 'Kredit',
            'closing' => 'Saldo akhir', 'debit_credit' => 'Debit vs kredit', 'movement' => 'Selisih transaksi kas/bank',
        ][$component] ?? $component;
    }

    private function findingsFromReport(int $businessId, int $year, array $report, string $auditKey, string $auditedAt, string $scopeKey): array
    {
        $findings = [];
        foreach ((array) ($report['transaction_reconciliation'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $identity = $this->transactionIdentity($row);
            foreach (self::TRANSACTION_COMPONENTS as $component => [$journalField, $operationalField]) {
                $variance = $row[$component.'_variance'] ?? null;
                $finding = $this->makeFinding(
                    $businessId,
                    $year,
                    'transaction',
                    $component,
                    $identity,
                    $row,
                    $row[$operationalField] ?? null,
                    $row[$journalField] ?? null,
                    $variance,
                    $auditKey,
                    $auditedAt,
                    '',
                    '',
                    $scopeKey
                );
                if ($finding !== null) {
                    $findings[$finding['finding_key']] = $finding;
                }
            }

            $imbalance = $row['journal_balance_variance'] ?? null;
            $finding = $this->makeFinding(
                $businessId,
                $year,
                'unbalanced_journal',
                'debit_credit',
                $identity,
                $row,
                $row['journal_credit'] ?? null,
                $row['journal_debit'] ?? null,
                $imbalance,
                $auditKey,
                $auditedAt,
                '',
                '',
                $scopeKey
            );
            if ($finding !== null) {
                $findings[$finding['finding_key']] = $finding;
            }
        }

        foreach ((array) ($report['account_balances'] ?? []) as $row) {
            if (! is_array($row) || ! is_numeric($row['opening_variance'] ?? null)) {
                continue;
            }
            $identity = $this->identityHash([
                'year' => $year,
                'account_no' => (string) ($row['account_no'] ?? ''),
                'account_name' => (string) ($row['account_name'] ?? ''),
            ]);
            $finding = $this->makeFinding(
                $businessId,
                $year,
                'account_balance',
                'opening',
                $identity,
                $row,
                $row['opening_balance'] ?? null,
                $row['explicit_journal_opening'] ?? null,
                $row['opening_variance'],
                $auditKey,
                $auditedAt,
                (string) ($row['account_no'] ?? ''),
                (string) ($row['account_name'] ?? ''),
                $scopeKey
            );
            if ($finding !== null) {
                $findings[$finding['finding_key']] = $finding;
            }
        }

        foreach ((array) ($report['control_comparison'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $identity = $this->identityHash([
                'year' => $year,
                'control_type' => (string) ($row['control_type'] ?? ''),
                'source_file' => (string) ($row['source_file'] ?? ''),
                'source_sheet' => (string) ($row['source_sheet'] ?? ''),
                'account_no' => (string) ($row['account_no'] ?? ''),
                'account_name' => (string) ($row['account_name'] ?? ''),
            ]);
            $comparisons = [
                'opening' => ['journal_opening', 'control_opening_signed'],
                'debit' => ['journal_debit', 'control_debit'],
                'credit' => ['journal_credit', 'control_credit'],
                'closing' => ['journal_closing', 'control_closing_signed'],
            ];
            foreach ($comparisons as $component => [$journalField, $controlField]) {
                $finding = $this->makeFinding(
                    $businessId,
                    $year,
                    'control',
                    $component,
                    $identity,
                    $row,
                    $row[$journalField] ?? null,
                    $row[$controlField] ?? null,
                    $row[$component.'_variance'] ?? null,
                    $auditKey,
                    $auditedAt,
                    (string) ($row['account_no'] ?? ''),
                    (string) ($row['account_name'] ?? ''),
                    $scopeKey
                );
                if ($finding !== null) {
                    $findings[$finding['finding_key']] = $finding;
                }
            }
        }

        return array_values($findings);
    }

    private function makeFinding(
        int $businessId,
        int $year,
        string $type,
        string $component,
        string $sourceIdentity,
        array $evidence,
        $expected,
        $actual,
        $variance,
        string $auditKey,
        string $auditedAt,
        string $accountNo = '',
        string $accountName = '',
        string $scopeKey = self::LEGACY_SCOPE
    ): ?array {
        if (! is_numeric($expected) || ! is_numeric($actual) || ! is_numeric($variance)
            || ! is_finite((float) $variance) || abs((float) $variance) <= self::VARIANCE_TOLERANCE) {
            return null;
        }

        $keyParts = [$businessId, $year, $type, $sourceIdentity, $component];
        if ($scopeKey !== self::LEGACY_SCOPE) {
            $keyParts[] = $scopeKey;
        }
        $key = hash('sha256', implode('|', $keyParts));
        $sourceDate = $this->dateOrNull($evidence['date'] ?? null);
        $sourceType = (string) ($evidence['source_type'] ?? ($type === 'control' ? 'Legacy control' : 'Journal'));
        $sourceNumber = (string) ($evidence['source_number'] ?? $evidence['source_file'] ?? '');
        $description = (string) ($evidence['description'] ?? $evidence['account_name'] ?? $sourceNumber);

        return [
            'business_id' => $businessId,
            'year' => $year,
            'scope_key' => $scopeKey,
            'type' => $type,
            'component' => $component,
            'finding_key' => $key,
            'source_identity' => $sourceIdentity,
            'source_type' => $sourceType,
            'source_number' => $sourceNumber,
            'source_date' => $sourceDate,
            'account_no' => $accountNo !== '' ? $accountNo : (string) ($evidence['account_no'] ?? ''),
            'account_name' => $accountName !== '' ? $accountName : (string) ($evidence['account_name'] ?? ''),
            'description' => $description,
            'expected_amount' => round((float) $expected, 4),
            'actual_amount' => round((float) $actual, 4),
            'variance' => round((float) $variance, 4),
            'evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'status' => 'open',
            'is_current' => true,
            'last_audit_key' => $auditKey,
            'last_audited_at' => $auditedAt,
            'resolution_history' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function transactionIdentity(array $row): string
    {
        $identity = trim((string) ($row['operational_identity'] ?? ''));
        if ($identity === '') {
            $identity = implode('|', [
                (string) ($row['source_type'] ?? ''),
                (string) ($row['source_number'] ?? ''),
                (string) ($row['date'] ?? ''),
                (string) ($row['description'] ?? ''),
            ]);
        }

        return $this->identityHash(['transaction' => $identity]);
    }

    private function identityHash(array $parts): string
    {
        ksort($parts);

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function persistSnapshot(int $businessId, int $year, string $scopeKey, array $rows, string $auditKey, string $auditedAt, ?string $manifest): void
    {
        DB::transaction(function () use ($businessId, $year, $scopeKey, $rows, $auditKey, $auditedAt, $manifest): void {
            $this->recordAudit($businessId, $year, $scopeKey, $rows, $auditKey, $auditedAt, $manifest);
            $this->upsertFindings($rows, $auditKey, $auditedAt);
            $keys = array_column($rows, 'finding_key');
            $this->resolveMissingFindings(
                DB::table('accounting_legacy_reconciliation_findings')
                    ->where('business_id', $businessId)->where('year', $year)->where('scope_key', $scopeKey)->where('is_current', true),
                $keys,
                $auditKey,
                $auditedAt,
                'Selisih tidak muncul pada audit legacy ulang untuk tahun ini.'
            );
        });
    }

    private function recordAudit(int $businessId, int $year, string $scopeKey, array $rows, string $auditKey, string $auditedAt, ?string $manifest): void
    {
        $now = now();
        DB::table('accounting_legacy_reconciliation_audits')->upsert([[
            'business_id' => $businessId,
            'year' => $year,
            'scope_key' => $scopeKey,
            'audit_key' => $auditKey,
            'source_manifest_sha256' => $manifest,
            'finding_count' => count($rows),
            'completed_at' => $auditedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['business_id', 'year', 'audit_key'], [
            'scope_key', 'source_manifest_sha256', 'finding_count', 'completed_at', 'updated_at',
        ]);
    }

    private function upsertFindings(array $rows, string $auditKey, string $auditedAt): void
    {
        $now = now();
        $existingByKey = [];
        foreach (array_chunk(array_column($rows, 'finding_key'), 400) as $keys) {
            if ($keys === []) {
                continue;
            }
            $existingByKey += DB::table('accounting_legacy_reconciliation_findings')
                ->where('business_id', $rows[0]['business_id'] ?? 0)
                ->whereIn('finding_key', $keys)
                ->get(['finding_key', 'status', 'is_current', 'resolution_history'])
                ->keyBy('finding_key')->all();
        }
        $reopenedHistory = [];
        foreach ($rows as &$row) {
            $existing = $existingByKey[$row['finding_key']] ?? null;
            if ($existing) {
                $row['resolution_history'] = $existing->resolution_history;
                $row['status'] = $existing->status;
                if (! (bool) $existing->is_current || $existing->status === 'resolved') {
                    $row['status'] = 'open';
                    $row['resolution_history'] = $this->appendResolutionEvent(
                        $existing->resolution_history,
                        'reopened',
                        'Selisih muncul kembali pada audit.',
                        $auditKey,
                        $auditedAt
                    );
                    $reopenedHistory[$row['finding_key']] = $row['resolution_history'];
                }
            }
            $row['is_current'] = true;
            $row['last_audit_key'] = $auditKey;
            $row['last_audited_at'] = $auditedAt;
            $row['updated_at'] = $now;
        }
        unset($row);

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('accounting_legacy_reconciliation_findings')->upsert(
                $chunk,
                ['business_id', 'finding_key'],
                [
                    'year', 'scope_key', 'type', 'component', 'source_identity', 'source_type', 'source_number', 'source_date',
                    'account_no', 'account_name', 'description', 'expected_amount', 'actual_amount', 'variance', 'evidence',
                    'status', 'is_current', 'last_audit_key', 'last_audited_at', 'updated_at',
                ]
            );
        }

        foreach (array_chunk($reopenedHistory, 150, true) as $chunk) {
            $caseSql = 'CASE finding_key ';
            $caseBindings = [];
            foreach ($chunk as $findingKey => $history) {
                $caseSql .= 'WHEN ? THEN ? ';
                $caseBindings[] = $findingKey;
                $caseBindings[] = $history;
            }
            $caseSql .= 'ELSE resolution_history END';
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $businessId = (int) ($rows[0]['business_id'] ?? 0);
            $bindings = array_merge($caseBindings, [$businessId], array_keys($chunk));
            DB::update(
                'UPDATE accounting_legacy_reconciliation_findings SET resolution_history = '.$caseSql.' WHERE business_id = ? AND finding_key IN ('.$placeholders.')',
                $bindings
            );
        }
    }

    private function resolveMissingFindings($query, array $currentKeys, string $auditKey, string $auditedAt, string $reason): void
    {
        if ($currentKeys !== []) {
            $query->whereNotIn('finding_key', $currentKeys);
        }
        $staleRows = $query->get(['id', 'resolution_history']);
        if ($staleRows->isEmpty()) {
            return;
        }

        $now = now()->toDateTimeString();
        foreach ($staleRows->chunk(150) as $chunk) {
            $ids = [];
            $caseSql = 'CASE id ';
            $bindings = [];
            foreach ($chunk as $stale) {
                $ids[] = (int) $stale->id;
                $caseSql .= 'WHEN ? THEN ? ';
                $bindings[] = (int) $stale->id;
                $bindings[] = $this->appendResolutionEvent($stale->resolution_history, 'audit_cleared', $reason, $auditKey, $auditedAt);
            }
            $caseSql .= 'ELSE resolution_history END';
            $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $caseBindings = $bindings;
            $bindings = array_merge([$now, $auditKey, $auditedAt], $caseBindings, $ids);
            DB::update(
                'UPDATE accounting_legacy_reconciliation_findings SET status = \'resolved\', is_current = 0, updated_at = ?, last_audit_key = ?, last_audited_at = ?, resolution_history = '.$caseSql.' WHERE id IN ('.$idPlaceholders.')',
                $bindings
            );
        }
    }

    private function appendResolutionEvent(?string $history, string $action, string $reason, string $auditKey, string $at): string
    {
        $events = json_decode((string) $history, true);
        if (! is_array($events)) {
            $events = [];
        }
        $events[] = ['action' => $action, 'reason' => $reason, 'audit_key' => $auditKey, 'at' => $at];

        return json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function cashBankMovementScope(int $accountId): string
    {
        return 'cash-bank-movement:'.$accountId;
    }

    private function cashBankBalanceScope(string $startDate, string $endDate, ?int $accountId, string $mappingScope): string
    {
        return 'cash-bank-balance:'.$startDate.':'.$endDate.':'.($accountId === null ? 'all' : (string) $accountId).':'.$mappingScope;
    }

    private function cashBankMovementIdentity(array $row, int $accountId): string
    {
        if (! empty($row['source_record_type']) && ! empty($row['source_record_id'])) {
            return $this->identityHash([
                'account_id' => $accountId,
                'source_type' => strtolower((string) $row['source_record_type']),
                'source_id' => (int) $row['source_record_id'],
            ]);
        }

        return $this->identityHash([
            'account_id' => $accountId,
            'date' => (string) ($row['date'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_number' => (string) ($row['source_number'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
        ]);
    }

    private function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function filteredQuery(int $businessId, array $filters): Builder
    {
        $query = $this->baseQuery($businessId);
        if (($filters['year'] ?? '') !== '' && is_numeric($filters['year'])) {
            $query->where('year', (int) $filters['year']);
        }
        if (($filters['type'] ?? '') !== '') {
            $query->where('type', (string) $filters['type']);
        }
        if (($filters['status'] ?? 'open') !== 'all') {
            $query->where('status', (string) ($filters['status'] ?? 'open'));
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function (Builder $searchQuery) use ($needle): void {
                $searchQuery->where('source_number', 'like', $needle)
                    ->orWhere('source_type', 'like', $needle)
                    ->orWhere('account_no', 'like', $needle)
                    ->orWhere('account_name', 'like', $needle)
                    ->orWhere('description', 'like', $needle)
                    ->orWhere('source_date', 'like', $needle);
            });
        }

        return $query;
    }

    private function baseQuery(int $businessId): Builder
    {
        return DB::table('accounting_legacy_reconciliation_findings')
            ->where('business_id', $businessId);
    }

    private function timestamp($value): string
    {
        try {
            return Carbon::parse($value ?: now())->toDateTimeString();
        } catch (\Throwable $exception) {
            return now()->toDateTimeString();
        }
    }

    private function dateOrNull($value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
