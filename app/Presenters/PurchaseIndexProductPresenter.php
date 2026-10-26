<?php

namespace App\Presenters;

class PurchaseIndexProductPresenter
{
    public function summarize(iterable $purchaseLines): array
    {
        $products = [];

        foreach ($purchaseLines as $line) {
            $product = $line->product ?? null;
            if (! $product) {
                continue;
            }

            $name = trim((string) $product->name);
            if ($product->type === 'variable') {
                $variation = $line->variation ?? null;
                $variationParts = [
                    data_get($variation, 'product_variation.name'),
                    data_get($variation, 'name'),
                ];
                foreach ($variationParts as $variationPart) {
                    $variationPart = trim((string) $variationPart);
                    if ($variationPart !== '') {
                        $name .= ' - '.$variationPart;
                    }
                }
            }

            $quantity = (float) ($line->quantity ?? 0);
            if (! is_finite($quantity)) {
                continue;
            }

            $unit = $line->sub_unit ?? data_get($product, 'unit');
            $unitName = trim((string) data_get($unit, 'short_name', ''));
            $groupKey = implode('|', [
                (string) ($line->product_id ?? $product->id ?? ''),
                (string) ($line->variation_id ?? data_get($line, 'variation.id', '')),
                (string) ($line->sub_unit_id ?? 'base'),
            ]);

            if (! isset($products[$groupKey])) {
                $products[$groupKey] = [
                    'name' => $name,
                    'quantity' => 0.0,
                    'unit' => $unitName,
                ];
            }

            $products[$groupKey]['quantity'] += $quantity;
        }

        $summary = array_values($products);
        usort($summary, fn ($left, $right) => strcasecmp($left['name'], $right['name']));

        return $summary;
    }
}
