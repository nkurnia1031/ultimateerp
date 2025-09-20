<?php

namespace App\Utils;

use App\TaxRate;
use App\Account;

class AccountTransactionUtil extends Util
{
    /**
     * Updates tax amount of a tax group
     *
     * @param  int  $group_tax_id
     * @return void
     */
    public function updateGroupTaxAmount($group_tax_id)
    {
        $amount = 0;
        $tax_rate = TaxRate::where('id', $group_tax_id)->with(['sub_taxes'])->first();
        foreach ($tax_rate->sub_taxes as $sub_tax) {
            $amount += $sub_tax->amount;
        }
        $tax_rate->amount = $amount;
        $tax_rate->save();
    }
    public function getPaymentAccount($accountName, $business_id)
    {
        // kalau kosong → return null
        if (empty($accountName)) {
            return null;
        }

        // cari account dengan nama sama

        $normalizedName = strtolower(str_replace([' ', '-'], '', $accountName));

        $account = Account::where('business_id', $business_id)
            ->whereRaw("LOWER(REPLACE(REPLACE(name, ' ', ''), '-', '')) = ?", [$normalizedName])
            ->first();

        // kalau tidak ketemu → buat baru

        if (empty($account)) {
            $data = [
                ["label" => null, "value" => null],
                ["label" => null, "value" => null],
                ["label" => null, "value" => null],
                ["label" => null, "value" => null],
                ["label" => null, "value" => null],
                ["label" => null, "value" => null],
            ];
            $account = Account::create([
                'business_id'     => $business_id,
                'name'            => $accountName,
                'account_number'  => '-', // default
                'account_details' => json_encode($data),
                'account_type_id' => 1, // default
                'created_by'      => auth()->user()->id,
            ]);
        }

        return $account->id;
    }
}
