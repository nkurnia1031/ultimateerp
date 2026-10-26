<?php

namespace Tests\Unit;

use App\Presenters\PurchaseIndexProductPresenter;
use PHPUnit\Framework\TestCase;

class PurchaseIndexProductPresenterTest extends TestCase
{
    public function test_it_sums_quantities_for_the_same_product_and_variation_but_keeps_other_variations_separate(): void
    {
        $product = (object) [
            'id' => 4,
            'name' => 'Coffee',
            'type' => 'variable',
            'unit' => (object) ['short_name' => 'kg'],
        ];
        $redVariation = (object) [
            'id' => 10,
            'name' => 'Small',
            'product_variation' => (object) ['name' => 'Red'],
        ];
        $blueVariation = (object) [
            'id' => 11,
            'name' => 'Large',
            'product_variation' => (object) ['name' => 'Blue'],
        ];
        $lines = [
            $this->line($product, $redVariation, 2),
            $this->line($product, $redVariation, 3),
            $this->line($product, $blueVariation, 4),
        ];

        $summary = (new PurchaseIndexProductPresenter())->summarize($lines);

        $this->assertSame([
            ['name' => 'Coffee - Blue - Large', 'quantity' => 4.0, 'unit' => 'kg'],
            ['name' => 'Coffee - Red - Small', 'quantity' => 5.0, 'unit' => 'kg'],
        ], $summary);
    }

    private function line(object $product, object $variation, float $quantity): object
    {
        return (object) [
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'sub_unit_id' => null,
            'quantity' => $quantity,
            'product' => $product,
            'variation' => $variation,
            'sub_unit' => null,
        ];
    }
}
