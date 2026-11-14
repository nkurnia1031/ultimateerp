<?php

namespace App\Exports;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon;
use Generator;

class TransactionLineExport implements FromGenerator, WithHeadings, WithCustomStartCell, WithColumnWidths, WithColumnFormatting, WithStyles, WithTitle
{
    private const TRANSACTION_CHUNK_SIZE = 200;

    private $type;

    private $businessId;

    private $filteredQuery;

    public function __construct(string $type, int $businessId, $filteredQuery)
    {
        $this->type = $type;
        $this->businessId = $businessId;
        $this->filteredQuery = $filteredQuery;
    }

    public function headings(): array
    {
        return [
            'Tanggal',
            'Jenis transaksi',
            'Nomor dokumen',
            'Supplier / pelanggan',
            'Lokasi',
            'Produk / deskripsi',
            'Kategori',
            'Subkategori',
            'Kuantitas',
            'Satuan',
            'Harga satuan sebelum diskon (sebelum pajak)',
            'Harga satuan setelah diskon (sebelum pajak)',
            'Harga satuan setelah pajak',
            'Jenis diskon',
            'Nilai diskon per satuan',
            'Total diskon line',
            'Pajak line',
            'Pajak transaksi',
            'Subtotal line sebelum pajak',
            'Nilai line setelah pajak',
            'Total transaksi',
        ];
    }

    public function startCell(): string
    {
        return 'A4';
    }

    public function title(): string
    {
        return [
            'expense' => 'Detail Expense',
            'purchase' => 'Detail Pembelian',
            'sell' => 'Detail Penjualan',
        ][$this->type] ?? 'Detail Transaksi';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 17, 'B' => 19, 'C' => 22, 'D' => 30, 'E' => 19,
            'F' => 36, 'G' => 22, 'H' => 22, 'I' => 15, 'J' => 12,
            'K' => 25, 'L' => 25, 'M' => 22, 'N' => 16, 'O' => 22,
            'P' => 20, 'Q' => 19, 'R' => 20, 'S' => 24, 'T' => 24,
            'U' => 22,
        ];
    }

    public function columnFormats(): array
    {
        $currency = '"Rp" #,##0.00;[Red]("Rp" #,##0.00);-';

        return [
            'A' => 'dd mmm yyyy hh:mm',
            'I' => '#,##0.####;[Red](#,##0.####);-',
            'K' => $currency,
            'L' => $currency,
            'M' => $currency,
            'O' => '#,##0.00;[Red](#,##0.00);-',
            'P' => $currency,
            'Q' => $currency,
            'R' => $currency,
            'S' => $currency,
            'T' => $currency,
            'U' => $currency,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = 'U';
        $lastRow = max(4, $sheet->getHighestRow());
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->setCellValue('A1', $this->title());
        $sheet->setCellValue('A2', 'Diekspor pada '.now()->format('d M Y H:i:s'));
        $sheet->setCellValue('A3', 'Satu baris untuk tiap rincian transaksi. Filter dan pencarian aktif pada index diterapkan.');
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(22);
        $sheet->getRowDimension(4)->setRowHeight(44);
        $sheet->freezePane('F5');
        $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
        $sheet->setShowGridlines(false);
        $sheet->getSheetView()->setZoomScale(85);
        $sheet->getTabColor()->setRGB('16A34A');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        if ($lastRow > 4) {
            $bodyRange = "A5:{$lastColumn}{$lastRow}";
            $bandedRows = new Conditional();
            $bandedRows->setConditionType(Conditional::CONDITION_EXPRESSION);
            $bandedRows->addCondition('MOD(ROW(),2)=0');
            $bandedRows->getStyle()->getFill()->setFillType(Fill::FILL_SOLID);
            $bandedRows->getStyle()->getFill()->getStartColor()->setRGB('F1F5F9');
            $sheet->setConditionalStyles($bodyRange, [$bandedRows]);
        }

        $styles = [
            'A1:U1' => [
                'font' => ['name' => 'Aptos Display', 'size' => 16, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ],
            'A2:U3' => [
                'font' => ['name' => 'Aptos', 'size' => 10, 'color' => ['rgb' => '475569']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF0F6']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ],
            'A4:U4' => [
                'font' => ['name' => 'Aptos', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '334155']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '16A34A']]],
            ],
        ];

        if ($lastRow > 4) {
            $styles["A5:U{$lastRow}"] = [
                'font' => ['name' => 'Aptos', 'size' => 10, 'color' => ['rgb' => '1F2937']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'D9E2EC']]],
            ];
        }

        return $styles;
    }

    public function generator(): Generator
    {
        $idQuery = clone $this->filteredQuery;
        if (method_exists($idQuery, 'setEagerLoads')) {
            $idQuery->setEagerLoads([]);
        }
        $transactionIds = $idQuery->select('transactions.id')->cursor();

        $chunk = [];
        foreach ($transactionIds as $transaction) {
            $chunk[] = (int) $transaction->id;

            if (count($chunk) >= self::TRANSACTION_CHUNK_SIZE) {
                yield from $this->rowsForTransactions($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield from $this->rowsForTransactions($chunk);
        }
    }

    private function rowsForTransactions(array $transactionIds): Generator
    {
        $rows = $this->baseQuery($transactionIds)
            ->orderBy('transactions.id')
            ->orderBy('line_id')
            ->get()
            ->groupBy('transaction_id');

        foreach ($transactionIds as $transactionId) {
            $transactionRows = $rows->get($transactionId, collect());
            if ($transactionRows->isEmpty()) {
                continue;
            }

            foreach ($transactionRows as $row) {
                yield $this->formatRow($row);
            }
        }
    }

    private function baseQuery(array $transactionIds): QueryBuilder
    {
        $query = DB::table('transactions')
            ->leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
            ->leftJoin('business_locations', 'transactions.location_id', '=', 'business_locations.id')
            ->where('transactions.business_id', $this->businessId)
            ->whereIn('transactions.id', $transactionIds)
            ->select([
                'transactions.id as transaction_id',
                'transactions.transaction_date',
                'transactions.type',
                'transactions.ref_no',
                'transactions.invoice_no',
                'transactions.final_total',
                'transactions.tax_amount as transaction_tax',
                'contacts.name as contact_name',
                'contacts.supplier_business_name',
                'business_locations.name as location_name',
            ]);

        if ($this->type === 'expense') {
            return $this->expenseLines($query);
        }

        if ($this->type === 'purchase') {
            return $this->purchaseLines($query);
        }

        return $this->sellLines($query);
    }

    private function expenseLines(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->leftJoin('expense_lines as lines', 'transactions.id', '=', 'lines.transaction_id')
            ->leftJoin('expense_categories as categories', 'lines.category_id', '=', 'categories.id')
            ->leftJoin('expense_categories as sub_categories', 'lines.sub_category_id', '=', 'sub_categories.id')
            ->addSelect([
                'lines.id as line_id',
                DB::raw('NULL as variation_name'),
                DB::raw('NULL as product_variation_name'),
                DB::raw('NULL as product_type'),
                DB::raw('NULL as quantity'),
                DB::raw('NULL as unit_name'),
                'categories.name as category_name',
                'sub_categories.name as sub_category_name',
                'lines.description as product_name',
                DB::raw('NULL as price_before_discount'),
                DB::raw('NULL as price_after_discount'),
                DB::raw('NULL as price_inc_tax'),
                DB::raw('NULL as item_tax'),
                DB::raw('NULL as discount_type'),
                DB::raw('NULL as discount_value'),
                'lines.amount as expense_amount',
            ]);
    }

    private function purchaseLines(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->leftJoin('purchase_lines as lines', 'transactions.id', '=', 'lines.transaction_id')
            ->leftJoin('products', 'lines.product_id', '=', 'products.id')
            ->leftJoin('variations', 'lines.variation_id', '=', 'variations.id')
            ->leftJoin('product_variations', 'variations.product_variation_id', '=', 'product_variations.id')
            ->leftJoin('units as line_units', 'lines.sub_unit_id', '=', 'line_units.id')
            ->leftJoin('units as product_units', 'products.unit_id', '=', 'product_units.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('categories as sub_categories', 'products.sub_category_id', '=', 'sub_categories.id')
            ->addSelect([
                'lines.id as line_id',
                'products.name as product_name',
                'products.type as product_type',
                'variations.name as variation_name',
                'product_variations.name as product_variation_name',
                'lines.quantity',
                DB::raw('COALESCE(line_units.short_name, product_units.short_name) as unit_name'),
                'categories.name as category_name',
                'sub_categories.name as sub_category_name',
                DB::raw('NULL as description'),
                'lines.pp_without_discount as price_before_discount',
                'lines.purchase_price as price_after_discount',
                'lines.purchase_price_inc_tax as price_inc_tax',
                'lines.item_tax',
                DB::raw("IF(lines.discount_percent > 0, 'percentage', NULL) as discount_type"),
                'lines.discount_percent as discount_value',
                DB::raw('NULL as expense_amount'),
            ]);
    }

    private function sellLines(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->leftJoin('transaction_sell_lines as lines', function ($join) {
                $join->on('transactions.id', '=', 'lines.transaction_id')
                    ->whereNull('lines.parent_sell_line_id');
            })
            ->leftJoin('products', 'lines.product_id', '=', 'products.id')
            ->leftJoin('variations', 'lines.variation_id', '=', 'variations.id')
            ->leftJoin('product_variations', 'variations.product_variation_id', '=', 'product_variations.id')
            ->leftJoin('units as line_units', 'lines.sub_unit_id', '=', 'line_units.id')
            ->leftJoin('units as product_units', 'products.unit_id', '=', 'product_units.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('categories as sub_categories', 'products.sub_category_id', '=', 'sub_categories.id')
            ->addSelect([
                'lines.id as line_id',
                'products.name as product_name',
                'products.type as product_type',
                'variations.name as variation_name',
                'product_variations.name as product_variation_name',
                'lines.quantity',
                DB::raw('COALESCE(line_units.short_name, product_units.short_name) as unit_name'),
                'categories.name as category_name',
                'sub_categories.name as sub_category_name',
                DB::raw('NULL as description'),
                'lines.unit_price_before_discount as price_before_discount',
                'lines.unit_price as price_after_discount',
                'lines.unit_price_inc_tax as price_inc_tax',
                'lines.item_tax',
                'lines.line_discount_type as discount_type',
                'lines.line_discount_amount as discount_value',
                DB::raw('NULL as expense_amount'),
            ]);
    }

    private function formatRow(object $row): array
    {
        $isExpense = $this->type === 'expense';
        $isRefund = $isExpense && $row->type === 'expense_refund';
        $sign = $isRefund ? -1 : 1;

        $transactionType = $this->safeText([
            'expense' => 'Expense',
            'expense_refund' => 'Pengembalian Expense',
            'purchase' => 'Pembelian',
            'sell' => 'Penjualan',
        ][$row->type] ?? (string) $row->type);
        $documentNumber = $this->safeText((string) ($row->invoice_no ?: $row->ref_no));
        $contact = trim(implode(' / ', array_filter([
            trim((string) $row->supplier_business_name),
            trim((string) $row->contact_name),
        ], fn ($value) => $value !== '')));

        if ($row->line_id === null) {
            return [
                $this->excelDateValue((string) $row->transaction_date),
                $transactionType,
                $documentNumber,
                $this->safeText($contact),
                $this->safeText((string) $row->location_name),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $sign * (float) $row->transaction_tax,
                null,
                null,
                $sign * (float) $row->final_total,
            ];
        }

        $productName = trim((string) $row->product_name);
        if ($row->product_type === 'variable') {
            $productName = implode(' - ', array_filter([
                $productName,
                trim((string) $row->product_variation_name),
                trim((string) $row->variation_name),
            ], fn ($value) => $value !== ''));
        }

        $quantity = $row->quantity === null ? null : (float) $row->quantity;
        $priceBeforeDiscount = $row->price_before_discount === null ? null : (float) $row->price_before_discount;
        $priceAfterDiscount = $row->price_after_discount === null ? null : (float) $row->price_after_discount;
        $priceIncTax = $row->price_inc_tax === null ? null : (float) $row->price_inc_tax;
        $itemTax = $row->item_tax === null ? null : (float) $row->item_tax;
        $discountType = $row->discount_type;
        $discountValue = $row->discount_value === null ? null : (float) $row->discount_value;

        if ($isExpense) {
            $amount = $sign * (float) $row->expense_amount;
            $priceBeforeDiscount = null;
            $priceAfterDiscount = null;
            $priceIncTax = null;
            $itemTax = null;
            $discountType = null;
            $discountValue = null;
            $lineDiscount = null;
            $subtotal = $amount;
            $lineTotal = $amount;
        } else {
            if ($this->type === 'purchase') {
                $lineDiscount = $quantity !== null && $priceBeforeDiscount !== null && $priceAfterDiscount !== null
                    ? ($priceBeforeDiscount - $priceAfterDiscount) * $quantity
                    : null;
            } else {
                $unitDiscount = 0.0;
                if ($discountType === 'fixed') {
                    $unitDiscount = (float) ($discountValue ?? 0);
                } elseif ($discountType === 'percentage') {
                    $unitDiscount = (float) ($priceBeforeDiscount ?? 0) * (float) ($discountValue ?? 0) / 100;
                }
                $lineDiscount = $quantity === null ? null : $unitDiscount * $quantity;
            }

            $lineDiscount = $lineDiscount === null ? null : $sign * $lineDiscount;
            $itemTax = $itemTax === null || $quantity === null ? null : $sign * $itemTax * $quantity;
            $subtotal = $priceAfterDiscount === null || $quantity === null
                ? null
                : $sign * $priceAfterDiscount * $quantity;
            $lineTotal = $priceIncTax === null || $quantity === null
                ? null
                : $sign * $priceIncTax * $quantity;
        }

        if ($isRefund) {
            $priceBeforeDiscount = $priceBeforeDiscount === null ? null : -$priceBeforeDiscount;
            $priceAfterDiscount = $priceAfterDiscount === null ? null : -$priceAfterDiscount;
            $priceIncTax = $priceIncTax === null ? null : -$priceIncTax;
            $discountValue = $discountValue === null ? null : -$discountValue;
        }

        return [
            $this->excelDateValue((string) $row->transaction_date),
            $transactionType,
            $documentNumber,
            $this->safeText($contact),
            $this->safeText((string) $row->location_name),
            $this->safeText($productName),
            $this->safeText((string) $row->category_name),
            $this->safeText((string) $row->sub_category_name),
            $quantity === null ? null : $sign * $quantity,
            $this->safeText((string) $row->unit_name),
            $priceBeforeDiscount,
            $priceAfterDiscount,
            $priceIncTax,
            $this->safeText([
                'percentage' => 'Persentase',
                'fixed' => 'Nominal',
            ][$discountType] ?? (string) $discountType),
            $discountValue,
            $lineDiscount,
            $itemTax,
            $sign * (float) $row->transaction_tax,
            $subtotal,
            $lineTotal,
            $sign * (float) $row->final_total,
        ];
    }

    private function safeText(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[=+\-@\t\r]/u', $value)) {
            return "'".$value;
        }

        return $value;
    }

    private function excelDateValue(string $value): ?float
    {
        if (trim($value) === '') {
            return null;
        }

        return ExcelDate::PHPToExcel(Carbon::parse($value));
    }
}
