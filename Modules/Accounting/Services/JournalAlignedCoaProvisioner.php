<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class JournalAlignedCoaProvisioner
{
    const BM_BUSINESS_ID = 2;

    private $tableExistsCache = [];

    private $columnCache = [];

    /**
     * Provision the shared Journal-aligned chart for one business.
     *
     * Business-specific cash/bank codes and the legacy Bank Loan expense are
     * deliberately limited to CV BM TRANSPORT (business #2).
     *
     * @param int $businessId
     * @return array
     */
    public function provisionBusiness($businessId)
    {
        $businessId = (int) $businessId;
        $result = [
            'business_id' => $businessId,
            'created' => 0,
            'reused' => 0,
            'crosswalks_created' => 0,
            'mapping_skips' => [],
            'conflicts' => [],
            'skipped' => null,
        ];

        if (! $this->hasRequiredSchema()) {
            $result['skipped'] = 'accounting_schema_unavailable';

            return $result;
        }

        $result = DB::transaction(function () use ($businessId, $result) {
            $business = DB::table('business')
                ->where('id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($business)) {
                $result['skipped'] = 'business_not_found';

                return $result;
            }

            $accountRows = DB::table('accounting_accounts')
                ->where('business_id', $businessId)
                ->get()
                ->keyBy('id');

            foreach ($this->catalog() as $entry) {
                if (! empty($entry['business_2_only']) && $businessId !== self::BM_BUSINESS_ID) {
                    continue;
                }

                $accountName = $entry['name'];
                if ($businessId !== self::BM_BUSINESS_ID && $entry['code'] === '1-10001') {
                    $accountName = 'Cash and cash equivalents';
                }

                $operationalAccountId = null;
                if ($businessId === self::BM_BUSINESS_ID && ! empty($entry['operational_name'])) {
                    $operationalAccountId = $this->findOperationalAccountId(
                        $businessId,
                        $entry['operational_name'],
                        $entry['code'],
                        $result
                    );
                }

                $account = $this->findOrCreateAccount(
                    $business,
                    $entry,
                    $accountName,
                    $operationalAccountId,
                    $accountRows,
                    $result
                );

                if (empty($account)) {
                    continue;
                }

                if ($this->attachOperationalAccount(
                    $businessId,
                    $account,
                    $operationalAccountId,
                    $entry['code'],
                    $result
                )) {
                    $result['crosswalks_created']++;
                }
            }

            return $result;
        });

        foreach ($result['conflicts'] as $conflict) {
            Log::warning('Journal-aligned CoA provisioning skipped a conflicting account.', $conflict + [
                'business_id' => $businessId,
            ]);
        }

        return $result;
    }

    /**
     * The journal-derived account catalog contains 70 distinct codes.
     *
     * @return array
     */
    public function catalog()
    {
        return [
            $this->entry('1-10001', 'Cash', false, 'Cash'),
            $this->entry('1-10004', 'MANDIRI - BMT', true, 'Mandiri BMT'),
            $this->entry('1-10005', 'Petty Cash Pool', true, 'Petty Cash Pool'),
            $this->entry('1-10006', 'MANDIRI - 9641', true, 'Mandiri - 9641'),
            $this->entry('1-10007', 'Mandiri PT', true, 'Mandiri PT'),
            $this->entry('1-10100', 'Account Receivable', false, 'Account Receivable'),
            $this->entry('1-10103', 'Employee Receivables', false, 'Employee Receivables'),
            $this->entry('1-10200', 'Inventory'),
            $this->entry('1-10500', 'VAT In'),
            $this->entry('1-10502', 'Prepaid Income Tax - PPh 23'),
            $this->entry('1-10703', 'Fixed Assets - Vehicles'),
            $this->entry('1-10704', 'Fixed Assets - Machinery & Equipment'),
            $this->entry('1-10753', 'Accumulated Depreciation - Vehicles', false, 'Accumulated Depreciation - Vehicles'),
            $this->entry('1-10754', 'Accumulated Depreciation - Machinery & Equipment', false, 'Accumulated Depreciation - Machinery & Equipment'),
            $this->entry('2-20100', 'Trade Payable', false, 'Trade Payable'),
            $this->entry('2-20400', 'Bank Loans'),
            $this->entry('2-20500', 'VAT Out'),
            $this->entry('3-30999', 'Opening Balance Equity'),
            $this->entry('4-40000', 'Trucking Revenue'),
            $this->entry('4-40100', 'Sales Discount'),
            $this->entry('5-50000', 'Cost of Sales'),
            $this->entry('5-50100', 'Purchase Discounts', false, 'Purchase Discounts'),
            $this->entry('5-50203', 'Transportation'),
            $this->entry('5-50204', 'Uang Jalan'),
            $this->entry('5-50205', 'Repair and Maintenance HPP'),
            $this->entry('5-50300', 'Shipping/Freight & Delivery'),
            $this->entry('5-50500', 'Cost of Production'),
            $this->entry('6-60002', 'Commission & Fees'),
            $this->entry('6-60003', 'Fuel, Toll and Parking - Sales'),
            $this->entry('6-60004', 'Travelling - Sales'),
            $this->entry('6-60005', 'Communication - Sales'),
            $this->entry('6-60007', 'Uniform'),
            $this->entry('6-60008', 'Document Handling Expense'),
            $this->entry('6-60101', 'Salaries'),
            $this->entry('6-60103', 'Meals and Transport'),
            $this->entry('6-60104', 'Overtime'),
            $this->entry('6-60105', 'Medical'),
            $this->entry('6-60106', 'THR and Bonus'),
            $this->entry('6-60108', 'Incentive'),
            $this->entry('6-60110', 'Other Benefit and Allowances'),
            $this->entry('6-60200', 'Donations'),
            $this->entry('6-60201', 'Entertainment'),
            $this->entry('6-60202', 'Fuel, Toll and Parking - General'),
            $this->entry('6-60203', 'Repair and Maintenance'),
            $this->entry('6-60204', 'Travelling - General'),
            $this->entry('6-60205', 'Meals'),
            $this->entry('6-60206', 'Communication - General'),
            $this->entry('6-60208', 'Insurance'),
            $this->entry('6-60209', 'Legal & Professional Fees'),
            $this->entry('6-60210', 'Employee Benefit Expense', false, 'Employee Benefit Expense'),
            $this->entry('6-60211', 'Utilities Expense'),
            $this->entry('6-60212', 'Training & Developments'),
            $this->entry('6-60214', 'Taxes and Licenses'),
            $this->entry('6-60217', 'Electricity'),
            $this->entry('6-60218', 'Water'),
            $this->entry('6-60222', 'Employee Receivables (Expenses)', false, 'Employee Receivables (Expenses)'),
            $this->entry('6-60223', 'Kasbon'),
            $this->entry('6-60224', 'Food'),
            $this->entry('6-60226', 'Maintenance - General'),
            $this->entry('6-60227', 'Shippingan'),
            $this->entry('6-60228', 'Souvenir'),
            $this->entry('6-60300', 'Office Expense'),
            $this->entry('6-60301', 'Stationery & Printing'),
            $this->entry('6-60303', 'Securities and Cleaning'),
            $this->entry('6-60502', 'Depreciation - Vehicle'),
            $this->entry('6-60503', 'Depreciation - Machinery & Equipment'),
            $this->entry('8-80004', 'Bank Administration Fee'),
            $this->entry('8-80005', 'Bank Loan', true),
            $this->entry('8-80100', 'Inventory Adjustments'),
            $this->entry('8-80999', 'Other Miscellaneous Expense'),
        ];
    }

    private function entry($code, $name, $business2Only = false, $operationalName = null)
    {
        return [
            'code' => $code,
            'name' => $name,
            'business_2_only' => $business2Only,
            'operational_name' => $operationalName,
            'primary_type' => JournalAccountTypeResolver::fromGlCode($code),
        ];
    }

    private function hasRequiredSchema()
    {
        foreach (['business', 'accounting_accounts'] as $table) {
            if (! $this->tableExists($table)) {
                return false;
            }
        }

        foreach (['business_id', 'name', 'gl_code', 'account_primary_type', 'created_by'] as $column) {
            if (! $this->hasColumn('accounting_accounts', $column)) {
                return false;
            }
        }

        return true;
    }

    private function findOperationalAccountId($businessId, $name, $code, array &$result)
    {
        if (! $this->tableExists('accounts')
            || ! $this->hasColumn('accounts', 'business_id')
            || ! $this->hasColumn('accounts', 'name')) {
            $this->addMappingSkip($result, $code, 'operational_accounts_table_unavailable');

            return null;
        }

        $matches = DB::table('accounts')
            ->where('business_id', $businessId)
            ->get(['id', 'name'])
            ->filter(function ($account) use ($name) {
                return $this->sameName($account->name, $name);
            })
            ->values();

        if ($matches->count() !== 1) {
            $reason = $matches->isEmpty()
                ? 'operational_account_name_not_found'
                : 'operational_account_name_not_unique';
            $this->addMappingSkip($result, $code, $reason);

            return null;
        }

        return (int) $matches->first()->id;
    }

    private function findOrCreateAccount($business, array $entry, $accountName, $operationalAccountId, &$rows, array &$result)
    {
        $businessId = (int) $business->id;
        $code = $entry['code'];
        $primaryType = $entry['primary_type'];

        $codeMatches = $rows->filter(function ($row) use ($code) {
            return trim((string) $row->gl_code) === $code;
        })->values();

        if ($codeMatches->count() > 1) {
            $this->addConflict($result, $code, 'duplicate_gl_code');

            return null;
        }

        if ($codeMatches->count() === 1) {
            $account = $codeMatches->first();
            if (! $this->ensureCompatibleAccount($account, $entry, $result)) {
                return null;
            }

            $result['reused']++;

            return $this->rememberAccount($rows, $this->reloadAccount($account->id));
        }

        if ($operationalAccountId && $this->hasColumn('accounting_accounts', 'account_id')) {
            $linkedMatches = $rows->filter(function ($row) use ($operationalAccountId) {
                return (int) ($row->account_id ?? 0) === (int) $operationalAccountId;
            })->values();

            if ($linkedMatches->count() > 1) {
                $this->addConflict($result, $code, 'duplicate_operational_crosswalk');
            } elseif ($linkedMatches->count() === 1) {
                $linkedAccount = $linkedMatches->first();
                $linkedCode = trim((string) $linkedAccount->gl_code);
                if ($linkedCode === '' && $this->ensureCompatibleAccount($linkedAccount, $entry, $result)) {
                    $result['reused']++;

                    return $this->rememberAccount($rows, $this->reloadAccount($linkedAccount->id));
                }

                if ($linkedCode !== '' && $linkedCode !== $code) {
                    $this->addConflict($result, $code, 'operational_link_has_different_gl_code');
                }
            }
        }

        $nameMatches = $rows->filter(function ($row) use ($accountName) {
            return $this->sameName($row->name, $accountName);
        })->values();

        if ($nameMatches->count() > 1) {
            $this->addConflict($result, $code, 'duplicate_account_name');

            return null;
        }

        if ($nameMatches->count() === 1) {
            $account = $nameMatches->first();
            $existingCode = trim((string) $account->gl_code);
            if ($existingCode !== '' && $existingCode !== $code) {
                $this->addConflict($result, $code, 'account_name_already_has_different_gl_code');

                return null;
            }

            if (! $this->ensureCompatibleAccount($account, $entry, $result)) {
                return null;
            }

            $result['reused']++;

            return $this->rememberAccount($rows, $this->reloadAccount($account->id));
        }

        $now = date('Y-m-d H:i:s');
        $attributes = [
            'name' => $accountName,
            'gl_code' => $code,
            'business_id' => $businessId,
            'account_primary_type' => $primaryType,
            'status' => 'active',
            'created_by' => ! empty($business->owner_id) ? (int) $business->owner_id : 1,
            'account_sub_type_id' => null,
            'detail_type_id' => null,
            'parent_account_id' => null,
            'description' => 'Provisioned from the Journal GL account catalog.',
            'account_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (array_keys($attributes) as $column) {
            if (! $this->hasColumn('accounting_accounts', $column)) {
                unset($attributes[$column]);
            }
        }

        $id = DB::table('accounting_accounts')->insertGetId($attributes);
        $result['created']++;

        return $this->rememberAccount($rows, $this->reloadAccount($id));
    }

    private function ensureCompatibleAccount($account, array $entry, array &$result)
    {
        $type = strtolower(trim((string) $account->account_primary_type));
        $expectedType = $entry['primary_type'];

        if ($type !== '' && ! $this->samePrimaryType($type, $expectedType)) {
            $this->addConflict($result, $entry['code'], 'account_primary_type_mismatch');

            return false;
        }

        $updates = [];
        if (trim((string) $account->gl_code) === '') {
            $updates['gl_code'] = $entry['code'];
        }
        if ($type === '') {
            $updates['account_primary_type'] = $expectedType;
        }
        if (! empty($updates)) {
            if ($this->hasColumn('accounting_accounts', 'updated_at')) {
                $updates['updated_at'] = date('Y-m-d H:i:s');
            }
            DB::table('accounting_accounts')->where('id', $account->id)->update($updates);
        }

        return true;
    }

    private function attachOperationalAccount($businessId, $account, $operationalAccountId, $code, array &$result)
    {
        if (! $operationalAccountId) {
            return false;
        }

        if (! $this->hasColumn('accounting_accounts', 'account_id')) {
            $this->addMappingSkip($result, $code, 'account_id_column_unavailable');

            return false;
        }

        $currentAccountId = (int) ($account->account_id ?? 0);
        if ($currentAccountId && $currentAccountId !== (int) $operationalAccountId) {
            $this->addConflict($result, $code, 'coa_row_has_different_operational_account');

            return false;
        }

        $linkedRows = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('account_id', $operationalAccountId)
            ->get(['id'])
            ->filter(function ($row) use ($account) {
                return (int) $row->id !== (int) $account->id;
            });

        if ($linkedRows->isNotEmpty()) {
            $this->addConflict($result, $code, 'operational_account_already_linked');

            return false;
        }

        if ($currentAccountId === (int) $operationalAccountId) {
            return false;
        }

        $updates = ['account_id' => (int) $operationalAccountId];
        if ($this->hasColumn('accounting_accounts', 'updated_at')) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
        }
        $updated = DB::table('accounting_accounts')
            ->where('business_id', $businessId)
            ->where('id', $account->id)
            ->where(function ($query) {
                $query->whereNull('account_id')->orWhere('account_id', 0);
            })
            ->update($updates);

        return $updated > 0;
    }

    private function reloadAccount($id)
    {
        return DB::table('accounting_accounts')->where('id', $id)->first();
    }

    private function rememberAccount(&$rows, $account)
    {
        $rows->put((int) $account->id, $account);

        return $account;
    }

    private function sameName($left, $right)
    {
        return strtolower(trim((string) $left)) === strtolower(trim((string) $right));
    }

    private function samePrimaryType($left, $right)
    {
        $left = $left === 'expense' ? 'expenses' : $left;
        $right = $right === 'expense' ? 'expenses' : $right;

        return $left === $right;
    }

    private function addConflict(array &$result, $code, $reason)
    {
        $result['conflicts'][] = ['code' => $code, 'reason' => $reason];
    }

    private function addMappingSkip(array &$result, $code, $reason)
    {
        $result['mapping_skips'][] = ['code' => $code, 'reason' => $reason];
    }

    private function tableExists($table)
    {
        if (! array_key_exists($table, $this->tableExistsCache)) {
            $this->tableExistsCache[$table] = Schema::hasTable($table);
        }

        return $this->tableExistsCache[$table];
    }

    private function hasColumn($table, $column)
    {
        if (! array_key_exists($table, $this->columnCache)) {
            $this->columnCache[$table] = $this->tableExists($table)
                ? array_flip(Schema::getColumnListing($table))
                : [];
        }

        return isset($this->columnCache[$table][$column]);
    }
}
