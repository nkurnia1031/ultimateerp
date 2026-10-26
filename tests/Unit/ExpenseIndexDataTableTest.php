<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExpenseIndexDataTableTest extends TestCase
{
    private string $viewPath;

    private string $sharedScriptPath;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->viewPath = $root.'/resources/views/expense/index.blade.php';
        $this->sharedScriptPath = $root.'/public/js/app.js';
    }

    public function test_shared_expense_table_column_count_matches_the_view_header_and_footer(): void
    {
        $view = file_get_contents($this->viewPath);
        $sharedScript = file_get_contents($this->sharedScriptPath);

        preg_match('/<table(?=[^>]*\bid="expense_table")[^>]*>.*?<thead>\s*<tr>(.*?)<\/tr>/s', $view, $headerMatch);
        preg_match('/expense_table\s*=\s*\$\([\'"]#expense_table[\'"]\)\.DataTable\(\{.*?columns:\s*\[(.*?)\]\s*,\s*fnDrawCallback/s', $sharedScript, $columnsMatch);
        preg_match('/<tfoot>\s*<tr[^>]*>(.*?)<\/tr>\s*<\/tfoot>/s', $view, $footerMatch);

        $this->assertNotEmpty($headerMatch, 'Expense index table header was not found.');
        $this->assertNotEmpty($columnsMatch, 'Shared Expense DataTables configuration was not found.');
        $this->assertNotEmpty($footerMatch, 'Expense index footer was not found.');

        $headerCount = preg_match_all('/<th\b/', $headerMatch[1]);
        $columnCount = preg_match_all('/\bdata\s*:/', $columnsMatch[1]);
        preg_match_all('/<(?:td|th)\b([^>]*)>/', $footerMatch[1], $footerCells);
        $footerCount = 0;
        foreach ($footerCells[1] as $attributes) {
            preg_match('/\bcolspan\s*=\s*["\']?(\d+)/', $attributes, $spanMatch);
            $footerCount += isset($spanMatch[1]) ? (int) $spanMatch[1] : 1;
        }

        $this->assertSame($headerCount, $columnCount);
        $this->assertSame($headerCount, $footerCount);
        $this->assertStringContainsString("data: 'total_before_tax'", $columnsMatch[1]);
    }

    public function test_expense_table_has_a_single_initialization_source(): void
    {
        $view = file_get_contents($this->viewPath);

        $this->assertStringNotContainsString('expense_table.destroy()', $view);
    }
}
