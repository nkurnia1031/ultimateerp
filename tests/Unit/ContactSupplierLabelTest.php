<?php

namespace Tests\Unit;

use App\Contact;
use PHPUnit\Framework\TestCase;

class ContactSupplierLabelTest extends TestCase
{
    /**
     * @dataProvider supplierLabelCases
     */
    public function test_supplier_label_never_becomes_empty(array $attributes, bool $appendId, string $expected): void
    {
        $this->assertSame($expected, Contact::supplierDisplayName($attributes, $appendId));
    }

    public static function supplierLabelCases(): array
    {
        return [
            'normal label without id' => [
                ['id' => 1, 'name' => 'PT Alpha', 'supplier_business_name' => 'Alpha Trading'],
                false,
                'PT Alpha (Alpha Trading)',
            ],
            'normal label with id' => [
                ['id' => 1, 'name' => 'PT Alpha', 'supplier_business_name' => 'Alpha Trading', 'contact_id' => 'SUP-01'],
                true,
                'PT Alpha - Alpha Trading (SUP-01)',
            ],
            'business name only' => [
                ['id' => 2, 'name' => '', 'supplier_business_name' => 'Alpha Trading'],
                false,
                'Alpha Trading',
            ],
            'contact id only' => [
                ['id' => 3, 'name' => null, 'supplier_business_name' => null, 'contact_id' => 'SUP-03'],
                false,
                'SUP-03',
            ],
            'mobile fallback' => [
                ['id' => 4, 'name' => ' ', 'supplier_business_name' => ' ', 'contact_id' => ' ', 'mobile' => '08123456789'],
                true,
                '08123456789',
            ],
            'email fallback' => [
                ['id' => 5, 'name' => null, 'supplier_business_name' => null, 'contact_id' => null, 'mobile' => null, 'email' => 'supplier@example.test'],
                true,
                'supplier@example.test',
            ],
            'id fallback' => [
                ['id' => 6, 'name' => null, 'supplier_business_name' => null, 'contact_id' => null, 'mobile' => null, 'email' => null],
                false,
                'Supplier #6',
            ],
            'business name alias fallback' => [
                ['id' => 7, 'name' => null, 'business_name' => 'Alias Supplier'],
                true,
                'Alias Supplier',
            ],
        ];
    }
}
