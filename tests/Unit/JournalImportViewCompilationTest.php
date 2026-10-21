<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use ParseError;
use PHPUnit\Framework\TestCase;

class JournalImportViewCompilationTest extends TestCase
{
    public function test_journal_import_view_compiles_to_valid_php(): void
    {
        $viewPath = dirname(__DIR__, 2).'/Modules/Accounting/Resources/views/journal_entry/import.blade.php';
        $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
        $compiledView = $compiler->compileString(file_get_contents($viewPath));

        try {
            token_get_all($compiledView, TOKEN_PARSE);
        } catch (ParseError $error) {
            $this->fail('Journal import Blade compiles to invalid PHP: '.$error->getMessage());
        }

        $this->assertNotSame('', $compiledView);
    }
}
