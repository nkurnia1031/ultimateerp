<?php

namespace Tests\Unit;

use App\Services\UserBusinessImportService;
use PHPUnit\Framework\TestCase;

class UserBusinessImportServiceTest extends TestCase
{
    public function test_import_username_replaces_only_the_source_business_suffix(): void
    {
        $service = new UserBusinessImportService();

        $this->assertSame('andi-02', $service->destinationUsername('andi-01', 1, 2));
        $this->assertSame('andi-01-02', $service->destinationUsername('andi-01', 3, 2));
        $this->assertSame('andi-02', $service->destinationUsername('andi', 1, 2));
    }

    public function test_username_suffix_uses_the_existing_two_digit_business_convention(): void
    {
        $service = new UserBusinessImportService();

        $this->assertSame('-02', $service->businessSuffix(2));
        $this->assertSame('-100', $service->businessSuffix(100));
        $this->assertNull($service->destinationUsername(null, 1, 2));
    }
}
