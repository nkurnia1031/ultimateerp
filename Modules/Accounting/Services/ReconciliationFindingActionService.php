<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReconciliationFindingActionService
{
    private const AMOUNT_TOLERANCE = 0.01;
    private const RATE_TOLERANCE = 0.01;

    public function previewOperationalTaxCorrection(int $businessId, int $findingId): array
    {
        $finding = $this->finding($businessId, $findingId);
        $sourceReview = $this->sourceReview($businessId, $finding);
        $proposal = $this->buildTaxProposal($finding);

        return $proposal === null
            ? [
                'supported' => false,
                'reason' => $this->lastUnsupportedReason,
                'editor_url' => $this->sourceEditorUrl($businessId, $finding),
                'source_review' => $sourceReview,
            ]
            : ['supported' => true, 'proposal' => $proposal, 'source_review' => $sourceReview];
    }

    private string $lastUnsupportedReason = 'Temuan ini tidak memiliki bukti yang cukup untuk koreksi pajak otomatis.';

    public function confirmOperationalTaxCorrection(int $businessId, int $findingId, int $actorId, string $fingerprint): array
    {
        return DB::transaction(function () use ($businessId, $findingId, $actorId, $fingerprint): array {
            $finding = $this->finding($businessId, $findingId, true);
            if ($finding->status === 'resolved' && ! (bool) $finding->is_current) {
                return ['status' => 'resolved', 'idempotent' => true];
            }

            $existingHistory = json_decode((string) $finding->resolution_history, true);
            foreach (is_array($existingHistory) ? $existingHistory : [] as $event) {
                if (($event['action'] ?? null) === 'operational_tax_corrected'
                    && hash_equals((string) ($event['fingerprint'] ?? ''), $fingerprint)) {
                    return ['status' => (string) $finding->status, 'idempotent' => true];
                }
            }

            $proposal = $this->buildTaxProposal($finding, true);
            if ($proposal === null) {
                $this->reject($this->lastUnsupportedReason);
            }
            if (! hash_equals((string) $proposal['fingerprint'], $fingerprint)) {
                $this->reject('Data sumber atau nilai temuan berubah setelah preview. Muat ulang preview sebelum mengonfirmasi.');
            }

            $transactionUpdate = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('id', $proposal['source']['id'])
                ->where('status', 'final');
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $transactionUpdate->whereNull('deleted_at');
            }
            $updated = $transactionUpdate->update([
                'tax_id' => $proposal['after']['tax_id'],
                'tax_amount' => $proposal['after']['tax_amount'],
                'total_before_tax' => $proposal['after']['total_before_tax'],
                'final_total' => $proposal['after']['final_total'],
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                $this->reject('Transaksi sumber berubah atau tidak lagi aktif. Muat ulang preview.');
            }

            $sourceAfter = DB::table('transactions')->where('business_id', $businessId)
                ->where('id', $proposal['source']['id'])->first();
            $verifiedVariance = round((float) $proposal['journal_vat'] - (float) $sourceAfter->tax_amount, 4);
            $isCleared = abs($verifiedVariance) <= self::AMOUNT_TOLERANCE
                && abs((float) $sourceAfter->final_total - ((float) $sourceAfter->total_before_tax + (float) $sourceAfter->tax_amount)) <= self::AMOUNT_TOLERANCE;
            $status = $isCleared ? 'resolved' : 'open';

            $history = $this->appendHistory($finding->resolution_history, [
                'action' => 'operational_tax_corrected',
                'actor_id' => $actorId,
                'at' => now()->toDateTimeString(),
                'source_transaction_id' => (int) $proposal['source']['id'],
                'fingerprint' => $fingerprint,
                'before' => $proposal['before'],
                'after' => $proposal['after'],
                'journal_vat' => (float) $proposal['journal_vat'],
                'verified_variance' => $verifiedVariance,
            ]);

            DB::table('accounting_legacy_reconciliation_findings')
                ->where('business_id', $businessId)
                ->where('id', $findingId)
                ->update([
                    'expected_amount' => round((float) $sourceAfter->tax_amount, 4),
                    'actual_amount' => round((float) $proposal['journal_vat'], 4),
                    'variance' => $verifiedVariance,
                    'status' => $status,
                    'is_current' => $isCleared ? false : (bool) $finding->is_current,
                    'resolution_history' => $history,
                    'updated_at' => now(),
                ]);

            return [
                'status' => $status,
                'idempotent' => false,
                'verified_variance' => $verifiedVariance,
                'message' => $isCleared
                    ? 'Pajak transaksi diperbarui dan selisih VAT terverifikasi sudah hilang.'
                    : 'Pajak diperbarui, tetapi selisih masih ada dan tetap terbuka.',
            ];
        });
    }

    public function accept(int $businessId, int $findingId, int $actorId, string $reason): array
    {
        return DB::transaction(function () use ($businessId, $findingId, $actorId, $reason): array {
            $finding = $this->finding($businessId, $findingId, true);
            if ($finding->status === 'accepted' && (bool) $finding->is_current) {
                return ['status' => 'accepted', 'idempotent' => true];
            }
            if ($finding->status !== 'open' || ! (bool) $finding->is_current) {
                $this->reject('Hanya temuan terbuka yang dapat diterima.');
            }

            $history = $this->appendHistory($finding->resolution_history, [
                'action' => 'accepted',
                'actor_id' => $actorId,
                'at' => now()->toDateTimeString(),
                'reason' => trim($reason),
                'audit_key' => (string) $finding->last_audit_key,
            ]);
            DB::table('accounting_legacy_reconciliation_findings')
                ->where('business_id', $businessId)
                ->where('id', $findingId)
                ->update(['status' => 'accepted', 'resolution_history' => $history, 'updated_at' => now()]);

            return ['status' => 'accepted', 'idempotent' => false];
        });
    }

    private function buildTaxProposal(object $finding, bool $lockSource = false): ?array
    {
        $evidence = json_decode((string) $finding->evidence, true);
        if (! is_array($evidence)) {
            return $this->unsupported('Bukti temuan tidak dapat dibaca.');
        }
        if ($finding->status !== 'open' || ! (bool) $finding->is_current) {
            return $this->unsupported('Koreksi hanya tersedia untuk temuan yang masih terbuka.');
        }
        if ($finding->type !== 'transaction' || $finding->component !== 'vat_in'
            || (string) $finding->source_type !== 'Expense') {
            return $this->unsupported('Koreksi otomatis saat ini hanya mendukung komponen VAT In pada Expense.');
        }
        if (($evidence['match_status'] ?? null) !== 'matched'
            || (int) ($evidence['operational_source_count'] ?? 0) !== 1
            || empty($evidence['has_operational_source'])
            || ! empty($evidence['source_identity_conflict'])
            || ! is_numeric($evidence['operational_base'] ?? null)
            || ! is_numeric($evidence['operational_vat_in'] ?? null)
            || ! is_numeric($evidence['journal_base'] ?? null)
            || ! is_numeric($evidence['journal_vat_in'] ?? null)
            || ! is_numeric($evidence['journal_total'] ?? null)
            || ! is_numeric($finding->expected_amount)
            || ! is_numeric($finding->actual_amount)) {
            return $this->unsupported('Audit tidak membuktikan satu sumber operasional dan komponen pajak yang lengkap.');
        }

        $sourceNumber = trim((string) $finding->source_number);
        $sourceDate = (string) $finding->source_date;
        if ($sourceNumber === '' || $sourceDate === '') {
            return $this->unsupported('Nomor dan tanggal sumber tidak lengkap untuk pencocokan transaksi.');
        }

        $sourceQuery = DB::table('transactions')
            ->where('business_id', (int) $finding->business_id)
            ->where('type', 'expense')
            ->where('status', 'final')
            ->whereDate('transaction_date', $sourceDate)
            ->where(function ($query) use ($sourceNumber): void {
                $query->where('ref_no', $sourceNumber)->orWhere('invoice_no', $sourceNumber);
            });
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $sourceQuery->whereNull('deleted_at');
        }
        if ($lockSource) {
            $sourceQuery->lockForUpdate();
        }
        $sources = $sourceQuery->get([
            'id', 'business_id', 'type', 'status', 'ref_no', 'invoice_no', 'transaction_date',
            'total_before_tax', 'tax_id', 'tax_amount', 'final_total', 'updated_at',
        ]);
        if ($sources->count() !== 1) {
            return $this->unsupported($sources->isEmpty()
                ? 'Transaksi aktif dengan nomor dan tanggal yang sama tidak ditemukan.'
                : 'Pencocokan sumber menghasilkan lebih dari satu transaksi; koreksi otomatis memerlukan sumber yang unik.');
        }
        $source = $sources->first();

        $base = round((float) $evidence['operational_base'], 4);
        $expectedVat = round((float) $evidence['operational_vat_in'], 4);
        $journalBase = round((float) $evidence['journal_base'], 4);
        $journalVat = round((float) $evidence['journal_vat_in'], 4);
        if ($base <= 0 || $journalVat <= 0
            || abs($journalBase - $base) > self::AMOUNT_TOLERANCE
            || abs((float) $finding->expected_amount - $expectedVat) > self::AMOUNT_TOLERANCE
            || abs((float) $finding->actual_amount - $journalVat) > self::AMOUNT_TOLERANCE
            || abs((float) ($evidence['journal_vat_out'] ?? 0)) > self::AMOUNT_TOLERANCE
            || abs((float) ($evidence['journal_other_tax'] ?? 0)) > self::AMOUNT_TOLERANCE) {
            return $this->unsupported('Nilai Journal tidak menunjukkan satu komponen VAT In positif yang dapat dikoreksi sendiri.');
        }

        $currentVat = round((float) $source->tax_amount, 4);
        $currentBase = round((float) $source->total_before_tax, 4);
        $currentTotal = round((float) $source->final_total, 4);
        if (abs($currentBase - $base) > self::AMOUNT_TOLERANCE
            || abs($currentVat - $expectedVat) > self::AMOUNT_TOLERANCE
            || abs($currentTotal - ($currentBase + $currentVat)) > self::AMOUNT_TOLERANCE) {
            return $this->unsupported('Nilai dasar, VAT, atau total transaksi berubah dari snapshot audit; sumber tidak diubah.');
        }
        if (abs(round($base + $journalVat, 4) - round((float) $evidence['journal_total'], 4)) > self::AMOUNT_TOLERANCE) {
            return $this->unsupported('Total Journal memuat komponen selain basis dan VAT yang belum dapat dibuktikan untuk koreksi ini.');
        }

        $rate = round($journalVat / $base * 100, 4);
        $taxRatesQuery = DB::table('tax_rates')->where('business_id', (int) $finding->business_id);
        if (Schema::hasColumn('tax_rates', 'deleted_at')) {
            $taxRatesQuery->whereNull('deleted_at');
        }
        if (Schema::hasColumn('tax_rates', 'is_tax_group')) {
            $taxRatesQuery->where('is_tax_group', false);
        }
        $taxRates = $taxRatesQuery->get(['id', 'name', 'amount'])->filter(function ($taxRate) use ($rate, $base, $journalVat): bool {
            return abs(round((float) $taxRate->amount, 4) - $rate) <= self::RATE_TOLERANCE
                && abs(round($base * (float) $taxRate->amount / 100, 2) - $journalVat) <= self::AMOUNT_TOLERANCE;
        })->values();
        if ($taxRates->count() !== 1) {
            return $this->unsupported($taxRates->isEmpty()
                ? 'Tarif hasil hitung VAT Journal tidak cocok dengan TaxRate aktif pada business ini.'
                : 'Tarif hasil hitung cocok dengan beberapa TaxRate; pilihannya perlu ditinjau manual.');
        }
        $taxRate = $taxRates->first();

        $before = [
            'tax_id' => $source->tax_id === null ? null : (int) $source->tax_id,
            'tax_amount' => $currentVat,
            'total_before_tax' => $currentBase,
            'final_total' => $currentTotal,
        ];
        $after = [
            'tax_id' => (int) $taxRate->id,
            'tax_rate_name' => (string) $taxRate->name,
            'tax_rate' => (float) $taxRate->amount,
            'tax_amount' => $journalVat,
            'total_before_tax' => $currentBase,
            'final_total' => round($currentBase + $journalVat, 4),
        ];
        $fingerprint = hash('sha256', json_encode([
            'finding_key' => (string) $finding->finding_key,
            'audit_key' => (string) $finding->last_audit_key,
            'source_id' => (int) $source->id,
            'source_updated_at' => (string) $source->updated_at,
            'before' => $before,
            'after' => $after,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return [
            'source' => [
                'id' => (int) $source->id,
                'type' => 'Expense',
                'number' => $sourceNumber,
                'date' => $sourceDate,
            ],
            'before' => $before,
            'after' => $after,
            'journal_vat' => $journalVat,
            'journal_total' => (float) $evidence['journal_total'],
            'fingerprint' => $fingerprint,
        ];
    }

    private function sourceEditorUrl(int $businessId, object $finding): ?string
    {
        $type = strtolower(trim((string) $finding->source_type));
        $routeNames = [
            'expense' => ['expenses.edit', 'expenses.index'],
            'sales invoice' => ['sells.edit', 'sells.index'],
            'purchase invoice' => ['purchases.edit', 'purchases.index'],
        ];
        $routes = $routeNames[$type] ?? null;
        if ($routes === null || trim((string) $finding->source_number) === '' || empty($finding->source_date)) {
            return null;
        }

        $typeValue = [
            'expense' => 'expense', 'sales invoice' => 'sell', 'purchase invoice' => 'purchase',
        ][$type];
        $routeParameter = [
            'expense' => 'expense', 'sales invoice' => 'sell', 'purchase invoice' => 'purchase',
        ][$type];
        $query = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', $typeValue)
            ->whereDate('transaction_date', (string) $finding->source_date)
            ->where(function ($builder) use ($finding): void {
                $builder->where('ref_no', trim((string) $finding->source_number))
                    ->orWhere('invoice_no', trim((string) $finding->source_number));
            });
        if (Schema::hasColumn('transactions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $sources = $query->get(['id']);
        if ($sources->count() === 1 && \Illuminate\Support\Facades\Route::has($routes[0])) {
            return route($routes[0], [$routeParameter => (int) $sources->first()->id]);
        }
        if (\Illuminate\Support\Facades\Route::has($routes[1])) {
            return route($routes[1], ['search' => trim((string) $finding->source_number)]);
        }

        return null;
    }

    private function sourceReview(int $businessId, object $finding): array
    {
        $evidence = json_decode((string) ($finding->evidence ?? '{}'), true);
        $evidence = is_array($evidence) ? $evidence : [];
        $type = strtolower(trim((string) $finding->source_type));
        $sourceTypes = [
            'expense' => 'expense',
            'purchase' => 'purchase',
            'sales invoice' => 'sell',
            'sell' => 'sell',
            'purchase invoice' => 'purchase',
        ];
        $sourceType = $sourceTypes[$type] ?? null;
        $operational = [
            'found' => false,
            'match_status' => 'unavailable',
            'message' => 'Jenis sumber operasional ini tidak memiliki editor transaksi yang didukung.',
            'transaction' => null,
            'lines' => [],
            'edit_url' => null,
        ];

        $evidenceTransactionId = (int) ($evidence['transaction_id'] ?? 0);
        if ($sourceType !== null && ($evidenceTransactionId > 0
            || (trim((string) $finding->source_number) !== '' && ! empty($finding->source_date)))) {
            $sourceQuery = DB::table('transactions as t')
                ->leftJoin('contacts as contact', function ($join) use ($businessId): void {
                    $join->on('contact.id', '=', 't.contact_id')->where('contact.business_id', '=', $businessId);
                })
                ->leftJoin('business_locations as location', function ($join) use ($businessId): void {
                    $join->on('location.id', '=', 't.location_id')->where('location.business_id', '=', $businessId);
                })
                ->where('t.business_id', $businessId)
                ->where('t.type', $sourceType);
            if ($evidenceTransactionId > 0) {
                $sourceQuery->where('t.id', $evidenceTransactionId);
            } else {
                $sourceQuery->whereDate('t.transaction_date', (string) $finding->source_date)
                    ->where(function ($query) use ($finding): void {
                        $query->where('t.ref_no', trim((string) $finding->source_number))
                            ->orWhere('t.invoice_no', trim((string) $finding->source_number));
                    });
            }
            if (Schema::hasColumn('transactions', 'deleted_at')) {
                $sourceQuery->whereNull('t.deleted_at');
            }

            $sources = $sourceQuery->limit(2)->get([
                't.id', 't.type', 't.status', 't.ref_no', 't.invoice_no', 't.transaction_date',
                't.total_before_tax', 't.tax_amount', 't.final_total', 't.additional_notes',
                't.staff_note', 'contact.name as contact_name', 'location.name as location_name',
            ]);

            if ($sources->count() === 1) {
                $source = $sources->first();
                $operationalLines = $this->operationalLines($businessId, $sourceType, (int) $source->id);
                if ((string) $finding->type === 'cash_bank') {
                    array_unshift($operationalLines, $this->cashBankSnapshotLine($evidence));
                }
                $operational = [
                    'found' => true,
                    'match_status' => 'matched',
                    'message' => null,
                    'transaction' => [
                        'id' => (int) $source->id,
                        'type' => (string) $source->type,
                        'status' => (string) $source->status,
                        'number' => trim((string) ($source->ref_no ?: $source->invoice_no)),
                        'invoice_number' => (string) ($source->invoice_no ?? ''),
                        'date' => (string) $source->transaction_date,
                        'contact' => (string) ($source->contact_name ?? ''),
                        'location' => (string) ($source->location_name ?? ''),
                        'total_before_tax' => (float) $source->total_before_tax,
                        'tax_amount' => (float) $source->tax_amount,
                        'final_total' => (float) $source->final_total,
                        'note' => trim(implode("\n", array_filter([
                            trim((string) ($source->additional_notes ?? '')),
                            trim((string) ($source->staff_note ?? '')),
                        ]))),
                    ],
                    'lines' => $operationalLines,
                    'edit_url' => $this->sourceEditUrlForId($type, (int) $source->id),
                ];
            } elseif ($sources->isEmpty()) {
                $operational['match_status'] = 'missing';
                $operational['message'] = 'Transaksi aktif yang cocok dengan nomor dan tanggal sumber tidak ditemukan.';
            } else {
                $operational['match_status'] = 'ambiguous';
                $operational['message'] = 'Lebih dari satu transaksi cocok; tombol edit langsung disembunyikan untuk mencegah perubahan pada sumber yang salah.';
            }
        }

        if ((string) $finding->type === 'cash_bank' && ! $operational['found']) {
            $operational = [
                'found' => true,
                'match_status' => 'audit_snapshot',
                'message' => 'Detail operasional kas/bank diambil dari snapshot audit yang tersimpan.',
                'transaction' => [
                    'id' => (int) ($evidence['source_record_id'] ?? 0) ?: null,
                    'type' => (string) ($evidence['source_type'] ?? 'Kas/Bank'),
                    'status' => (string) ($evidence['status'] ?? ''),
                    'number' => (string) ($evidence['source_number'] ?? $finding->source_number),
                    'date' => (string) ($evidence['date'] ?? $finding->source_date),
                    'contact' => (string) ($evidence['contact'] ?? ''),
                    'location' => (string) ($evidence['payment_account'] ?? ''),
                    'total_before_tax' => null,
                    'tax_amount' => is_numeric($evidence['source_vat'] ?? null) ? (float) $evidence['source_vat'] : null,
                    'final_total' => is_numeric($evidence['operational_amount'] ?? null) ? (float) $evidence['operational_amount'] : null,
                    'note' => trim(implode(" · ", array_filter([
                        (string) ($evidence['description'] ?? ''),
                        (string) ($evidence['category'] ?? ''),
                        (string) ($evidence['detail_category'] ?? ''),
                        (string) ($evidence['journal_detail'] ?? ''),
                    ]))),
                    'is_snapshot' => true,
                ],
                'lines' => [$this->cashBankSnapshotLine($evidence)],
                'edit_url' => null,
            ];
        }

        $journals = $this->journalSourceDetails($businessId, $finding, $evidence);

        return [
            'source_type' => (string) $finding->source_type,
            'source_number' => (string) $finding->source_number,
            'source_date' => (string) $finding->source_date,
            'description' => (string) $finding->description,
            'operational' => $operational,
            'journals' => $journals,
            'journal_totals' => [
                'debit' => is_numeric($evidence['journal_debit'] ?? null) ? (float) $evidence['journal_debit'] : null,
                'credit' => is_numeric($evidence['journal_credit'] ?? null) ? (float) $evidence['journal_credit'] : null,
                'total' => is_numeric($evidence['journal_total'] ?? null) ? (float) $evidence['journal_total'] : null,
            ],
        ];
    }

    private function operationalLines(int $businessId, string $type, int $transactionId): array
    {
        if ($type === 'expense') {
            $columns = [
                'line.id', 'line.description', 'line.amount',
                'category.name as category_name', 'subcategory.name as subcategory_name',
            ];
            if (Schema::hasColumn('expense_lines', 'note')) {
                $columns[] = 'line.note';
            }
            if (Schema::hasColumn('expense_lines', 'document')) {
                $columns[] = 'line.document';
            }

            return DB::table('expense_lines as line')
                ->leftJoin('expense_categories as category', function ($join) use ($businessId): void {
                    $join->on('category.id', '=', 'line.category_id')->where('category.business_id', '=', $businessId);
                })
                ->leftJoin('expense_categories as subcategory', function ($join) use ($businessId): void {
                    $join->on('subcategory.id', '=', 'line.sub_category_id')->where('subcategory.business_id', '=', $businessId);
                })
                ->where('line.transaction_id', $transactionId)
                ->orderBy('line.id')
                ->get($columns)
                ->map(fn ($line): array => [
                    'id' => (int) $line->id,
                    'category' => trim(implode(' / ', array_filter([
                        (string) ($line->category_name ?? ''),
                        (string) ($line->subcategory_name ?? ''),
                    ]))),
                    'description' => (string) ($line->description ?? ''),
                    'note' => (string) ($line->note ?? ''),
                    'document' => ! empty($line->document) ? basename(str_replace('\\', '/', (string) $line->document)) : '',
                    'amount' => (float) $line->amount,
                ])->all();
        }

        $table = $type === 'purchase' ? 'purchase_lines' : 'transaction_sell_lines';
        $priceColumn = $type === 'purchase' ? 'purchase_price' : 'unit_price';
        $priceWithTaxColumn = $type === 'purchase' ? 'purchase_price_inc_tax' : 'unit_price_inc_tax';

        return DB::table($table.' as line')
            ->leftJoin('products as product', function ($join) use ($businessId): void {
                $join->on('product.id', '=', 'line.product_id')->where('product.business_id', '=', $businessId);
            })
            ->leftJoin('variations as variation', function ($join): void {
                $join->on('variation.id', '=', 'line.variation_id')->on('variation.product_id', '=', 'line.product_id');
            })
            ->leftJoin('tax_rates as tax', function ($join) use ($businessId): void {
                $join->on('tax.id', '=', 'line.tax_id')->where('tax.business_id', '=', $businessId);
            })
            ->where('line.transaction_id', $transactionId)
            ->orderBy('line.id')
            ->get([
                'line.id', 'line.quantity', 'line.'.$priceColumn.' as unit_price',
                'line.'.$priceWithTaxColumn.' as unit_price_inc_tax', 'line.item_tax',
                'product.name as product_name', 'product.sku as product_code',
                'variation.name as variation_name', 'variation.sub_sku as variation_code',
                'tax.name as tax_name',
            ])
            ->map(function ($line): array {
                $quantity = (float) $line->quantity;
                $unitPrice = (float) $line->unit_price;
                $unitTax = (float) $line->item_tax;

                return [
                    'id' => (int) $line->id,
                    'product' => trim(implode(' - ', array_filter([
                        trim((string) ($line->product_name ?? '')),
                        trim((string) ($line->variation_name ?? '')),
                    ]))),
                    'code' => trim((string) ($line->variation_code ?: $line->product_code)),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_price_inc_tax' => is_numeric($line->unit_price_inc_tax) ? (float) $line->unit_price_inc_tax : null,
                    'unit_tax' => $unitTax,
                    'tax_name' => (string) ($line->tax_name ?? ''),
                    'line_total' => round($quantity * ($unitPrice + $unitTax), 4),
                ];
            })->all();
    }

    private function sourceEditUrlForId(string $sourceType, int $transactionId): ?string
    {
        $route = [
            'expense' => ['expenses.edit', 'expense'],
            'sell' => ['sells.edit', 'sell'],
            'sales invoice' => ['sells.edit', 'sell'],
            'purchase' => ['purchases.edit', 'purchase'],
            'purchase invoice' => ['purchases.edit', 'purchase'],
        ][strtolower(trim($sourceType))] ?? null;

        return $route !== null && \Illuminate\Support\Facades\Route::has($route[0])
            ? route($route[0], [$route[1] => $transactionId])
            : null;
    }

    private function cashBankSnapshotLine(array $evidence): array
    {
        return [
            'snapshot' => true,
            'category' => trim(implode(' · ', array_filter([
                (string) ($evidence['category'] ?? ''),
                (string) ($evidence['payment_account'] ?? ''),
            ]))),
            'description' => trim(implode(' · ', array_filter([
                (string) ($evidence['description'] ?? ''),
                (string) ($evidence['detail_category'] ?? ''),
                (string) ($evidence['journal_detail'] ?? ''),
            ]))),
            'amount' => is_numeric($evidence['operational_amount'] ?? null) ? (float) $evidence['operational_amount'] : null,
            'journal_amount' => is_numeric($evidence['accounting_amount'] ?? null) ? (float) $evidence['accounting_amount'] : null,
            'source_vat' => is_numeric($evidence['source_vat'] ?? null) ? (float) $evidence['source_vat'] : null,
        ];
    }

    private function journalSourceDetails(int $businessId, object $finding, array $evidence): array
    {
        $snapshotLines = array_values((array) ($evidence['journal_components'] ?? []));
        if (! Schema::hasTable('accounting_journal_import_rows')) {
            return $this->snapshotJournalDetails($finding, $snapshotLines, $evidence);
        }

        $rowIds = array_values(array_unique(array_filter(array_map('intval', (array) ($evidence['journal_import_row_ids'] ?? [])))));
        $rowIds = array_merge($rowIds, $this->cashBankJournalImportRowIds($businessId, $evidence));
        $rowIds = array_values(array_unique(array_filter($rowIds)));
        $sourceKey = trim((string) ($evidence['source_key'] ?? ''));
        $journalNumber = trim((string) ($evidence['journal_source_number'] ?? ''));
        if ($rowIds === [] && $sourceKey === '' && $journalNumber === '') {
            return $this->snapshotJournalDetails($finding, $snapshotLines, $evidence);
        }

        $hasMappingId = Schema::hasColumn('accounting_journal_import_rows', 'journal_mapping_id');
        $select = ['id', 'entry_data', 'source_number', 'source_type'];
        if ($hasMappingId) {
            $select[] = 'journal_mapping_id';
        }
        $rows = DB::table('accounting_journal_import_rows')
            ->where('business_id', $businessId)
            ->where(function ($query) use ($rowIds, $sourceKey, $journalNumber): void {
                if ($rowIds !== []) {
                    $query->whereIn('id', array_slice($rowIds, 0, 100));
                }
                if ($sourceKey !== '') {
                    $rowIds !== []
                        ? $query->orWhere('source_key', $sourceKey)
                        : $query->where('source_key', $sourceKey);
                }
                if ($journalNumber !== '') {
                    if ($rowIds !== [] || $sourceKey !== '') {
                        $query->orWhere('source_number', $journalNumber);
                    } else {
                        $query->where('source_number', $journalNumber);
                    }
                }
            })
            ->orderBy('id')
            ->limit(100)
            ->get($select);
        $journalDate = substr(trim((string) ($evidence['date'] ?? $finding->source_date)), 0, 10);
        if ($journalDate !== '') {
            $rows = $rows->filter(function ($row) use ($journalDate): bool {
                $entry = json_decode((string) ($row->entry_data ?? ''), true);
                $entryDate = is_array($entry) ? substr(trim((string) ($entry['date'] ?? '')), 0, 10) : '';

                return $entryDate === '' || $entryDate === $journalDate;
            })->values();
        }

        $mappingIds = $hasMappingId
            ? $rows->pluck('journal_mapping_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all()
            : [];
        $journals = [];
        foreach ($mappingIds as $mappingId) {
            $mapping = DB::table('accounting_acc_trans_mappings')
                ->where('business_id', $businessId)
                ->where('type', 'journal_entry')
                ->where('id', $mappingId)
                ->first(['id', 'ref_no', 'operation_date', 'note']);
            if (! $mapping) {
                continue;
            }

            $lines = DB::table('accounting_accounts_transactions as line')
                ->leftJoin('accounting_accounts as account', function ($join) use ($businessId): void {
                    $join->on('account.id', '=', 'line.accounting_account_id')->where('account.business_id', '=', $businessId);
                })
                ->where('line.acc_trans_mapping_id', $mappingId)
                ->where('line.sub_type', 'journal_entry')
                ->orderBy('line.id')
                ->get([
                    'line.id', 'line.amount', 'line.type', 'line.note', 'account.name as account_name',
                    'account.gl_code as account_code',
                ])
                ->map(fn ($line): array => [
                    'account' => (string) ($line->account_name ?? ''),
                    'account_code' => (string) ($line->account_code ?? ''),
                    'debit' => (string) $line->type === 'debit' ? (float) $line->amount : 0.0,
                    'credit' => (string) $line->type === 'credit' ? (float) $line->amount : 0.0,
                    'note' => (string) ($line->note ?? ''),
                ])->all();

            $journals[] = [
                'id' => (int) $mapping->id,
                'number' => (string) $mapping->ref_no,
                'date' => (string) $mapping->operation_date,
                'note' => (string) ($mapping->note ?? ''),
                'lines' => $lines,
                'edit_url' => \Illuminate\Support\Facades\Route::has('journal-entry.edit')
                    ? route('journal-entry.edit', (int) $mapping->id)
                    : null,
            ];
        }

        if ($journals !== []) {
            return $journals;
        }

        return $this->snapshotJournalDetails($finding, $snapshotLines, $evidence);
    }

    private function cashBankJournalImportRowIds(int $businessId, array $evidence): array
    {
        if ((string) ($evidence['source_record_type'] ?? '') === ''
            || ! Schema::hasTable('account_transactions')
            || ! Schema::hasColumn('account_transactions', 'journal_import_row_id')
            || ! Schema::hasTable('accounts')) {
            return [];
        }

        $query = DB::table('account_transactions as movement')
            ->join('accounts as payment_account', function ($join) use ($businessId): void {
                $join->on('payment_account.id', '=', 'movement.account_id')
                    ->where('payment_account.business_id', '=', $businessId);
            })
            ->whereNotNull('movement.journal_import_row_id');
        $recordType = strtolower((string) $evidence['source_record_type']);
        $recordId = (int) ($evidence['source_record_id'] ?? 0);
        if (str_contains($recordType, 'accounttransaction') && $recordId > 0) {
            $query->where('movement.id', $recordId);
        } elseif (str_contains($recordType, 'transactionpayment') && $recordId > 0) {
            $query->where('movement.transaction_payment_id', $recordId);
        } elseif ((int) ($evidence['transaction_id'] ?? 0) > 0) {
            $query->where('movement.transaction_id', (int) $evidence['transaction_id']);
        } else {
            return [];
        }

        return $query->distinct()->pluck('movement.journal_import_row_id')
            ->filter()->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function snapshotJournalDetails(object $finding, array $snapshotLines, array $evidence): array
    {
        if ($snapshotLines === []) {
            if ((string) $finding->type === 'cash_bank'
                && (is_numeric($evidence['accounting_amount'] ?? null)
                    || is_numeric($evidence['journal_vat'] ?? null)
                    || trim((string) ($evidence['journal_detail'] ?? '')) !== '')) {
                return [[
                    'id' => null,
                    'number' => (string) ($evidence['journal_source_number'] ?? $finding->source_number),
                    'date' => (string) ($evidence['date'] ?? $finding->source_date),
                    'note' => (string) ($evidence['journal_detail'] ?? ''),
                    'snapshot_summary' => true,
                    'amount' => is_numeric($evidence['accounting_amount'] ?? null) ? (float) $evidence['accounting_amount'] : null,
                    'vat' => is_numeric($evidence['journal_vat'] ?? null) ? (float) $evidence['journal_vat'] : null,
                    'lines' => [],
                    'edit_url' => null,
                ]];
            }

            return [];
        }

        return [[
            'id' => null,
            'number' => (string) $finding->source_number,
            'date' => (string) $finding->source_date,
            'note' => (string) $finding->description,
            'lines' => array_map(fn ($line): array => [
                'account' => (string) ($line['account_name'] ?? ''),
                'account_code' => (string) ($line['account_no'] ?? ''),
                'debit' => is_numeric($line['debit'] ?? null) ? (float) $line['debit'] : 0.0,
                'credit' => is_numeric($line['credit'] ?? null) ? (float) $line['credit'] : 0.0,
                'note' => '',
            ], array_filter($snapshotLines, 'is_array')),
            'edit_url' => null,
        ]];
    }

    private function finding(int $businessId, int $findingId, bool $lock = false): object
    {
        $query = DB::table('accounting_legacy_reconciliation_findings')
            ->where('business_id', $businessId)
            ->where('id', $findingId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $finding = $query->first();
        if (! $finding) {
            abort(404);
        }

        return $finding;
    }

    private function unsupported(string $reason): ?array
    {
        $this->lastUnsupportedReason = $reason;

        return null;
    }

    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['finding' => $message]);
    }

    private function appendHistory(?string $history, array $event): string
    {
        $events = json_decode((string) $history, true);
        if (! is_array($events)) {
            $events = [];
        }
        $events[] = $event;

        return json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
