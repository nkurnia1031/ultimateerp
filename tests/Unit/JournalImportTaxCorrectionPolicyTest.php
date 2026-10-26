<?php

namespace Tests\Unit;

use Modules\Accounting\Services\JournalImportTaxCorrectionPolicy;
use PHPUnit\Framework\TestCase;

class JournalImportTaxCorrectionPolicyTest extends TestCase
{
    public function test_it_corrects_only_a_final_total_that_is_still_equal_to_the_pre_tax_base(): void
    {
        $policy = new JournalImportTaxCorrectionPolicy();
        $newTotals = ['total_before_tax' => 100, 'tax' => 10, 'final_total' => 110];

        $this->assertTrue($policy->shouldCorrectFinalTotal(0, 100, 100, $newTotals));
        $this->assertTrue($policy->shouldCorrectFinalTotal(null, 100, 100, $newTotals));
        $this->assertFalse($policy->shouldCorrectFinalTotal(10, 100, 100, $newTotals));
        $this->assertFalse($policy->shouldCorrectFinalTotal(0, 100, 105, $newTotals));
        $this->assertFalse($policy->shouldCorrectFinalTotal(0, 100, 100, [
            'total_before_tax' => 100,
            'tax' => 10,
            'final_total' => 100,
        ]));
    }
}
