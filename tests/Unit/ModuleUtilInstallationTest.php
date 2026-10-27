<?php

namespace Tests\Unit;

use App\Utils\ModuleUtil;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleUtilInstallationTest extends TestCase
{
    public function test_crm_module_detection_is_case_insensitive_before_the_system_table_exists(): void
    {
        Schema::shouldReceive('hasTable')->never();

        $this->assertTrue((new ModuleUtil())->isModuleInstalled('Crm'));
    }

    public function test_other_modules_are_not_installed_before_the_system_table_exists(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('system')
            ->andReturnFalse();

        $this->assertFalse((new ModuleUtil())->isModuleInstalled('Accounting'));
    }
}
