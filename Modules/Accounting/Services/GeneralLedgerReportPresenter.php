<?php

namespace Modules\Accounting\Services;

class GeneralLedgerReportPresenter
{
    public function presentAccountEntries(string $accountPrimaryType, array $entries, float $openingBalance, int $currencyPrecision = 2): array
    {
        usort($entries, function ($left, $right) {
            $dateOrder = strcmp((string) ($left['operation_date'] ?? ''), (string) ($right['operation_date'] ?? ''));

            return $dateOrder !== 0
                ? $dateOrder
                : ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
        });

        $balance = round($openingBalance, 4);
        $rows = [];

        foreach ($entries as $entry) {
            $type = strtolower((string) ($entry['type'] ?? ''));
            $amount = round(abs((float) ($entry['amount'] ?? 0)), 4);
            $products = is_array($entry['products'] ?? null) ? $entry['products'] : [];
            $weightsKey = ! empty($entry['is_tax_account']) ? 'tax_amount' : 'net_amount';
            $weights = array_map(function ($product) use ($weightsKey) {
                return abs((float) ($product[$weightsKey] ?? 0));
            }, $products);
            $weightTotal = array_sum($weights);

            if ($products !== [] && $weightTotal > 0) {
                $allocated = 0.0;
                $lastIndex = count($products) - 1;

                foreach ($products as $index => $product) {
                    $productAmount = $index === $lastIndex
                        ? round($amount - $allocated, 4)
                        : round($amount * ($weights[$index] / $weightTotal), $currencyPrecision);
                    $productAmount = min($productAmount, max(0, round($amount - $allocated, 4)));
                    $allocated = round($allocated + $productAmount, 4);
                    $rows[] = $this->makeRow($entry, $productAmount, $product, $balance, $accountPrimaryType, $type, $currencyPrecision);
                    $balance = $rows[count($rows) - 1]['balance'];
                }

                continue;
            }

            $product = null;
            if ($products !== []) {
                $productDescriptions = array_values(array_unique(array_filter(array_map(function ($item) {
                    return trim((string) ($item['description'] ?? ''));
                }, $products))));
                $detailLabels = array_values(array_unique(array_filter(array_map(function ($item) {
                    return trim((string) ($item['detail_label'] ?? ''));
                }, $products))));
                $product = [
                    'name' => implode('; ', array_values(array_filter(array_map(function ($item) {
                        return trim((string) ($item['name'] ?? ''));
                    }, $products)))),
                    'quantity' => array_sum(array_map(function ($item) {
                        return (float) ($item['quantity'] ?? 0);
                    }, $products)),
                    'description' => count($productDescriptions) === 1 ? $productDescriptions[0] : ($entry['description'] ?? ''),
                    'detail_label' => count($detailLabels) === 1 ? $detailLabels[0] : '',
                    'needs_classification_review' => ! empty($entry['needs_classification_review']) || count($productDescriptions) > 1,
                ];
            }

            $rows[] = $this->makeRow($entry, $amount, $product, $balance, $accountPrimaryType, $type, $currencyPrecision);
            $balance = $rows[count($rows) - 1]['balance'];
        }

        return $rows;
    }

    private function makeRow(array $entry, float $amount, ?array $product, float $balance, string $accountPrimaryType, string $type, int $currencyPrecision): array
    {
        $debitNormal = in_array(strtolower($accountPrimaryType), ['asset', 'expense', 'expenses'], true);
        $isDebit = $type === 'debit';
        $signedAmount = ($isDebit === $debitNormal) ? $amount : -$amount;

        $row = $entry;
        unset($row['products'], $row['is_tax_account']);
        $row['product'] = trim((string) ($product['name'] ?? ''));
        $row['quantity'] = isset($product['quantity']) ? (float) $product['quantity'] : null;
        $row['unit'] = trim((string) ($product['unit'] ?? ''));
        if ($product && array_key_exists('description', $product)) {
            $row['description'] = $product['description'];
            $row['detail_label'] = $product['detail_label'] ?? '';
            $row['needs_classification_review'] = ! empty($product['needs_classification_review']);
        }
        $row['amount'] = $amount;
        $row['debit'] = $isDebit ? $amount : 0.0;
        $row['credit'] = $isDebit ? 0.0 : $amount;
        $row['balance'] = round($balance + $signedAmount, 4);

        return $row;
    }
}
