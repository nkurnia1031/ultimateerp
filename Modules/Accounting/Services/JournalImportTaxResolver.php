<?php

namespace Modules\Accounting\Services;

use App\TaxRate;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccount;

class JournalImportTaxResolver
{
    public function resolveForEntry(array $entry, int $businessId, array $options, bool $requireExplicitTaxDecision = false): ?array
    {
        $selections = $options['tax_account_mappings'] ?? [];
        $accountMappings = $options['account_mappings'] ?? [];
        $accountIds = [];
        $taxRateIds = [];
        $taxCandidates = [];

        foreach ($entry['lines'] ?? [] as $line) {
            $key = md5(strtolower(trim((string) ($line['account_no'] ?? '')).'|'.trim((string) ($line['account_name'] ?? ''))));
            $selection = trim((string) ($selections[$key] ?? ''));
            if ($selection === '__clear__') {
                continue;
            }
            if ($selection !== '' && ctype_digit($selection)) {
                $taxRateIds[(int) $selection] = true;

                continue;
            }

            $accountId = null;
            if (! empty($accountMappings[$key])) {
                $accountId = AccountingAccount::where('business_id', $businessId)
                    ->where('id', (int) $accountMappings[$key])
                    ->value('id');
            }
            if (empty($accountId)) {
                $accountNo = trim((string) ($line['account_no'] ?? ''));
                $accountName = trim((string) ($line['account_name'] ?? ''));
                $query = AccountingAccount::where('business_id', $businessId);
                if ($accountNo !== '') {
                    $query->where('gl_code', $accountNo);
                } else {
                    $query->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($accountName)]);
                }
                $accountId = $query->value('id');
            }
            if (! empty($accountId)) {
                $accountIds[(int) $accountId] = true;
            }
            if ($requireExplicitTaxDecision && $selection === '' && $this->looksLikeTaxAccount($line)) {
                $taxCandidates[] = [
                    'account_id' => empty($accountId) ? null : (int) $accountId,
                    'account_name' => trim((string) ($line['account_name'] ?? '')),
                    'account_no' => trim((string) ($line['account_no'] ?? '')),
                ];
            }
        }

        $storedTaxMappedAccountIds = [];
        if ($accountIds !== []) {
            $storedTaxMappings = DB::table('accounting_journal_tax_account_mappings')
                ->where('business_id', $businessId)
                ->whereIn('accounting_account_id', array_keys($accountIds))
                ->get(['accounting_account_id', 'tax_rate_id']);
            foreach ($storedTaxMappings as $mapping) {
                $storedTaxMappedAccountIds[(int) $mapping->accounting_account_id] = true;
                $taxRateIds[(int) $mapping->tax_rate_id] = true;
            }
        }

        if ($requireExplicitTaxDecision) {
            foreach ($taxCandidates as $candidate) {
                if ($candidate['account_id'] !== null && isset($storedTaxMappedAccountIds[$candidate['account_id']])) {
                    continue;
                }

                $label = $candidate['account_name'] !== '' ? $candidate['account_name'] : $candidate['account_no'];
                throw new \RuntimeException('Akun Journal "'.$label.'" tampak sebagai akun pajak tetapi belum dipetakan. Pilih TaxRate/grup pajak business yang tersedia, atau pilih "Hapus mapping" bila akun itu memang bukan akun pajak.');
            }
        }

        if (count($taxRateIds) > 1) {
            throw new \RuntimeException('Akun pajak Journal dipetakan ke lebih dari satu TaxRate/grup; pilih satu mapping sebelum melanjutkan.');
        }
        if ($taxRateIds === []) {
            return null;
        }

        $taxRate = TaxRate::where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->with('sub_taxes')
            ->find(array_key_first($taxRateIds));
        if (! $taxRate) {
            throw new \RuntimeException('TaxRate hasil mapping tidak aktif pada business ini.');
        }

        $rate = $taxRate->is_tax_group
            ? (float) $taxRate->sub_taxes->where('business_id', $businessId)->sum('amount')
            : (float) $taxRate->amount;
        if ($taxRate->is_tax_group && $rate <= 0) {
            throw new \RuntimeException('Grup pajak tidak memiliki komponen aktif yang valid.');
        }

        return ['id' => (int) $taxRate->id, 'rate' => $rate];
    }

    private function looksLikeTaxAccount(array $line): bool
    {
        $label = mb_strtolower(trim((string) ($line['account_no'] ?? '').' '.(string) ($line['account_name'] ?? '')));

        return preg_match('/(?:^|[^a-z0-9])(ppn|pph|pajak|vat|gst|tax)(?:[^a-z0-9]|$)/u', $label) === 1;
    }
}
