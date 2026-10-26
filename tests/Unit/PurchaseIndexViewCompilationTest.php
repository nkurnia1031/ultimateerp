<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use ParseError;
use PHPUnit\Framework\TestCase;

class PurchaseIndexViewCompilationTest extends TestCase
{
    public function test_purchase_index_partials_compile_to_valid_php(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views/purchase/partials/';
        $compiler = $this->compiler();

        foreach (['purchase_table.blade.php', 'products.blade.php'] as $view) {
            $compiledView = $compiler->compileString(file_get_contents($root.$view));

            try {
                token_get_all($compiledView, TOKEN_PARSE);
            } catch (ParseError $error) {
                $this->fail($view.' compiles to invalid PHP: '.$error->getMessage());
            }

            $this->assertNotSame('', $compiledView);

            if ($view === 'products.blade.php') {
                $this->assertStringContainsString('<?php echo e(number_format(', $compiledView);
            }
        }
    }

    public function test_purchase_product_partial_displays_every_product(): void
    {
        $compiledView = $this->compiler()->compileString(file_get_contents(
            dirname(__DIR__, 2).'/resources/views/purchase/partials/products.blade.php'
        ));
        $products = [
            ['name' => 'Pompa Solar Tangan', 'quantity' => 1, 'unit' => 'Pcs'],
            ['name' => 'Filter Solar', 'quantity' => 2, 'unit' => 'Pcs'],
            ['name' => 'Selang Solar', 'quantity' => 3, 'unit' => 'Meter'],
            ['name' => 'Klem Selang', 'quantity' => 4, 'unit' => 'Pcs'],
        ];
        $__env = new class
        {
            public function addLoop($items): void {}

            public function incrementLoopIndices(): void {}

            public function getLastLoop()
            {
                return null;
            }

            public function popLoop(): void {}
        };

        ob_start();
        eval('?>'.$compiledView);
        $html = ob_get_clean();

        $this->assertStringContainsString('Klem Selang', $html);
        $this->assertStringContainsString('× 4.00 Pcs', $html);
        $this->assertStringNotContainsString('barang lainnya', $html);
    }

    private function compiler(): BladeCompiler
    {
        $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
        $compiler->directive('format_quantity', function ($expression) {
            return "number_format($expression, 2, '.', ',')";
        });

        return $compiler;
    }
}
