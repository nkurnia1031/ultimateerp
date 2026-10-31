<?php

namespace Tests\Unit;

use App\Transaction;
use Mockery;
use Modules\Accounting\Services\JournalImportAccountComponentService;
use Modules\Accounting\Services\JournalImportSourceMatchPolicy;
use Modules\Accounting\Services\JournalImportSourceMatcher;
use Modules\Accounting\Services\JournalImportTaxResolver;
use PHPUnit\Framework\TestCase;

class JournalImportSourceMatchPolicyTest extends TestCase
{
    public function test_unique_exact_source_is_linked_without_running_vat_correction(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldNotReceive('resolveForEntry');
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 100, 100, 0);

        $decision = $policy->resolve([$candidate], 'Expense', [
            'date' => '2026-10-01',
            'debit_total' => 100,
            'lines' => [
                ['account_name' => 'Office expense', 'type' => 'debit', 'debit' => 100],
                ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 100],
            ],
        ], 3);

        $this->assertSame('matched', $decision['status']);
        $this->assertSame('link', $decision['default_action']);
        $this->assertSame(12, $decision['selected_candidate']['id']);
    }

    public function test_nearest_amount_is_only_a_suggestion_and_is_not_auto_selected(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldNotReceive('resolveForEntry');
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 95, 95, 0);

        $decision = $policy->resolve([$candidate], 'Expense', [
            'date' => '2026-10-01',
            'debit_total' => 100,
        ], 3);

        $this->assertSame('suggested', $decision['status']);
        $this->assertSame('review', $decision['default_action']);
        $this->assertNull($decision['selected_candidate']);
    }

    public function test_same_date_and_total_with_a_different_basis_tax_split_stays_for_review(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldNotReceive('resolveForEntry');
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 110, 110, 0);

        $decision = $policy->resolve([$candidate], 'Expense', [
            'date' => '2026-10-01',
            'debit_total' => 110,
            'credit_total' => 110,
            'lines' => [
                ['account_name' => 'Office expense', 'type' => 'debit', 'debit' => 100],
                ['account_name' => 'VAT In', 'type' => 'debit', 'debit' => 10],
                ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 110],
            ],
        ], 3);

        $this->assertSame('suggested', $decision['status']);
        $this->assertSame('review', $decision['default_action']);
        $this->assertNull($decision['selected_candidate']);
        $this->assertSame(20.0, $decision['candidates'][0]['component_difference']);
    }

    public function test_transaction_with_saved_components_is_not_auto_linked_when_journal_components_are_unavailable(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldNotReceive('resolveForEntry');
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 110, 100, 10);

        $decision = $policy->resolve([$candidate], 'Expense', [
            'date' => '2026-10-01',
            'debit_total' => 110,
        ], 3);

        $this->assertSame('suggested', $decision['status']);
        $this->assertSame('review', $decision['default_action']);
        $this->assertNull($decision['selected_candidate']);
    }

    public function test_expense_with_wrong_vat_direction_stays_for_review_even_when_components_match(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldNotReceive('resolveForEntry');
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 110, 100, 10);

        $decision = $policy->resolve([$candidate], 'Expense', [
            'date' => '2026-10-01',
            'debit_total' => 110,
            'credit_total' => 110,
            'lines' => [
                ['account_name' => 'Office expense', 'type' => 'debit', 'debit' => 100],
                ['account_name' => 'VAT Out', 'type' => 'debit', 'debit' => 10],
                ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 110],
            ],
        ], 3);

        $this->assertSame('suggested', $decision['status']);
        $this->assertSame('review', $decision['default_action']);
        $this->assertFalse($decision['candidates'][0]['component_match']);
        $this->assertFalse($decision['candidates'][0]['tax_direction_match']);
    }

    public function test_unique_missing_vat_difference_is_safe_to_update_when_taxrate_resolves(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')
            ->once()
            ->with(Mockery::type('array'), 3, 100.0)
            ->andReturn(['id' => 7, 'rate' => 10, 'tax_amount' => 10, 'direction' => 'input']);
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 100, 100, 0);
        $entry = [
            'date' => '2026-10-01',
            'debit_total' => 110,
            'credit_total' => 110,
            'lines' => [
                ['account_name' => 'Office expense', 'type' => 'debit', 'debit' => 100],
                ['account_name' => 'VAT In', 'type' => 'debit', 'debit' => 10],
                ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 110],
            ],
        ];

        $decision = $policy->resolve([$candidate], 'Expense', $entry, 3);

        $this->assertSame('matched', $decision['status']);
        $this->assertSame('update', $decision['default_action']);
        $this->assertSame(12, $decision['selected_candidate']['id']);
    }

    public function test_sales_vat_difference_is_updated_only_when_output_tax_is_correctly_resolved(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')
            ->once()
            ->with(Mockery::type('array'), 3, 100.0)
            ->andReturn(['id' => 8, 'rate' => 10, 'tax_amount' => 10, 'direction' => 'output']);
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(22, 100, 100, 0, 'sell');
        $entry = [
            'date' => '2026-10-01',
            'debit_total' => 110,
            'credit_total' => 110,
            'lines' => [
                ['account_name' => 'Sales', 'type' => 'credit', 'credit' => 100],
                ['account_name' => 'VAT Out', 'type' => 'credit', 'credit' => 10],
                ['account_name' => 'Accounts Receivable', 'type' => 'debit', 'debit' => 110],
            ],
        ];

        $decision = $policy->resolve([$candidate], 'Sales Invoice', $entry, 3);

        $this->assertSame('matched', $decision['status']);
        $this->assertSame('update', $decision['default_action']);
        $this->assertSame(22, $decision['selected_candidate']['id']);
    }

    public function test_unexplained_or_ambiguous_vat_difference_remains_for_review(): void
    {
        $taxResolver = Mockery::mock(JournalImportTaxResolver::class);
        $taxResolver->shouldReceive('resolveForEntry')
            ->once()
            ->andReturn(['id' => 7, 'rate' => 10, 'tax_amount' => 10, 'direction' => 'output']);
        $policy = $this->makePolicy($taxResolver);
        $candidate = $this->candidate(12, 100, 100, 0);
        $entry = [
            'date' => '2026-10-01',
            'debit_total' => 110,
            'credit_total' => 110,
            'lines' => [
                ['account_name' => 'Office expense', 'type' => 'debit', 'debit' => 100],
                ['account_name' => 'VAT In', 'type' => 'debit', 'debit' => 10],
                ['account_name' => 'Cash', 'type' => 'credit', 'credit' => 110],
            ],
        ];

        $wrongDirection = $policy->resolve([$candidate], 'Expense', $entry, 3);
        $ambiguous = $policy->resolve([
            $candidate,
            $this->candidate(13, 100, 100, 0),
        ], 'Expense', $entry, 3);

        $this->assertSame('suggested', $wrongDirection['status']);
        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertNull($ambiguous['selected_candidate']);
    }

    private function makePolicy($taxResolver): JournalImportSourceMatchPolicy
    {
        return new JournalImportSourceMatchPolicy(
            new JournalImportSourceMatcher(),
            new JournalImportAccountComponentService(),
            $taxResolver
        );
    }

    private function candidate(int $id, float $amount, float $baseAmount, float $taxAmount, string $transactionType = 'expense'): array
    {
        return [
            'id' => $id,
            'record_type' => Transaction::class,
            'transaction_type' => $transactionType,
            'date' => '2026-10-01',
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'tax_amount' => $taxAmount,
            'references' => ['EXP-2026-01'],
            'label' => 'Expense #'.$id,
        ];
    }
}
