<?php

namespace Modules\Accounting\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Modules\Accounting\Entities\AccountingAccount;

class AccountingAccountsExport implements FromQuery, WithHeadings
{
    public function __construct(private int $businessId)
    {
    }

    public function query()
    {
        return AccountingAccount::query()
            ->where('business_id', $this->businessId)
            ->select('name', 'gl_code', 'account_primary_type', 'status', 'description');
    }

    public function headings(): array
    {
        return [
            'Account Name',
            'GL Code',
            'Account Type',
            'Status',
            'Description',
        ];
    }
}
