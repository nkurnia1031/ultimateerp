<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\GeneralLedgerBackfillService;

class BackfillGeneralLedger extends Command
{
    protected $signature = 'accounting:backfill-general-ledger
        {business_id : ID business yang akan diproses}
        {--apply : Simpan jurnal; tanpa opsi ini command hanya melakukan dry-run}';

    protected $description = 'Backfill jurnal buku besar dari transaksi utama dengan dry-run sebagai mode default.';

    public function handle(GeneralLedgerBackfillService $service): int
    {
        $businessId = $this->argument('business_id');
        if (! ctype_digit((string) $businessId) || (int) $businessId < 1) {
            $this->error('business_id harus berupa bilangan bulat positif.');

            return self::FAILURE;
        }

        $applying = (bool) $this->option('apply');
        $summary = $service->run((int) $businessId, $applying);
        if (isset($summary['error'])) {
            $this->error($summary['error']);

            return self::FAILURE;
        }

        $this->line('Business: '.$summary['business_name'].' (#'.(int) $businessId.')');
        $this->line('Mode: '.($applying ? 'APPLY' : 'DRY-RUN'));
        $this->line('Transaction date range: '.($summary['date_range'] ?? '—'));
        $this->line('Candidate groups: '.(int) $summary['candidate_groups']);
        $this->line('Planned groups: '.(int) $summary['planned_groups']);
        $this->line('Planned rows: '.(int) $summary['planned_rows']);
        $this->line('Applied groups: '.(int) $summary['applied_groups']);
        $this->line('Already linked: '.(int) $summary['already_linked']);
        $this->line('Groups using current location maps: '.(int) $summary['current_map_groups']);
        $this->line('Groups using unique operational evidence: '.(int) ($summary['operational_evidence_groups'] ?? 0));
        $this->line('Auto-mapped groups: '.(int) ($summary['auto_mapped_groups'] ?? 0));
        $this->line('Groups with separated VAT: '.(int) ($summary['vat_split_groups'] ?? 0));
        $this->line('Ambiguous VAT accounts: '.(int) $summary['ambiguous_vat_accounts']);
        $this->line('Missing VAT accounts: '.(int) $summary['missing_vat_accounts']);
        $this->line('Debit total: '.number_format((float) $summary['debit_total'], 2, '.', ','));
        $this->line('Credit total: '.number_format((float) $summary['credit_total'], 2, '.', ','));
        $this->line(($applying ? 'Actionable review sources queued: ' : 'Unique actionable review sources (would queue): ')
            .(int) ($summary['actionable_review_sources'] ?? 0));

        $accountAudit = $summary['account_mapping_audit'];
        $this->line('Main account mapping audit:');
        $this->line('  Unique account_id matches: '.(int) $accountAudit['account_id_matches']);
        $this->line('  Exact unique-name matches: '.(int) $accountAudit['exact_name_matches']);
        $this->line('  Ambiguous account_id matches: '.(int) $accountAudit['ambiguous_account_id']);
        $this->line('  Ambiguous exact names: '.(int) $accountAudit['ambiguous_exact_name']);
        $this->line('  Conflicting crosswalks: '.(int) $accountAudit['conflicting_crosswalk']);
        $this->line('  Nearest CoA matches: '.(int) ($accountAudit['nearest_matches'] ?? 0));
        $this->line('  Unmatched main accounts: '.(int) $accountAudit['unmatched']);
        if (! empty($accountAudit['matches'])) {
            $this->line('  Safe account matches:');
            foreach ($accountAudit['matches'] as $match) {
                $this->line(sprintf(
                    '    account #%d %s -> accounting #%d %s [%s]',
                    $match['account_id'],
                    $match['account_name'],
                    $match['accounting_account_id'],
                    $match['accounting_name'],
                    $match['method']
                ));
            }
        }
        if (! empty($accountAudit['nearest_suggestions'])) {
            $this->line('  Automatic nearest account mappings (not saved as one-to-one crosswalks):');
            foreach ($accountAudit['nearest_suggestions'] as $match) {
                $this->line(sprintf(
                    '    account #%d %s -> accounting #%d %s [%s; score %s]',
                    $match['account_id'],
                    $match['account_name'],
                    $match['accounting_account_id'],
                    $match['accounting_name'],
                    $match['method'] ?? 'nearest_name',
                    number_format((float) $match['score'], 4, '.', '')
                ));
            }
        }

        if (! empty($summary['current_map_details'])) {
            $this->line('Current location maps used (review these historical mappings):');
            foreach ($summary['current_map_details'] as $map) {
                $this->line(sprintf(
                    '  location #%d [%s]: payment_account #%d %s; deposit_to #%d %s; groups=%d',
                    $map['location_id'],
                    $map['mapping_key'],
                    $map['payment_account_id'],
                    $map['payment_account_name'],
                    $map['deposit_to_id'],
                    $map['deposit_to_name'],
                    $map['groups']
                ));
            }
        }

        if (! empty($summary['auto_map_details'])) {
            $this->line('Automatic nearest CoA mappings used:');
            foreach ($summary['auto_map_details'] as $map) {
                $this->line(sprintf(
                    '  location #%d [%s]: payment_account #%d %s (%s; score %s); deposit_to #%d %s (%s; score %s); groups=%d',
                    $map['location_id'],
                    $map['mapping_key'],
                    $map['payment_account_id'],
                    $map['payment_account_name'],
                    $map['payment_account_method'] ?? 'location-map',
                    $map['payment_account_score'] === null ? 'location-map' : number_format((float) $map['payment_account_score'], 4, '.', ''),
                    $map['deposit_to_id'],
                    $map['deposit_to_name'],
                    $map['deposit_to_method'] ?? 'location-map',
                    $map['deposit_to_score'] === null ? 'location-map' : number_format((float) $map['deposit_to_score'], 4, '.', ''),
                    $map['groups']
                ));
            }
        }

        if (! empty($summary['account_links'])) {
            $this->line('Account crosswalks '.($applying ? 'saved' : 'to save').':');
            foreach ($summary['account_links'] as $link) {
                $this->line('  accounting_account_id='.$link['accounting_account_id'].' -> account_id='.$link['account_id']);
            }
        }

        if (! empty($summary['skipped'])) {
            $this->line('Skipped:');
            foreach ($summary['skipped'] as $reason => $count) {
                $this->line('  '.$reason.': '.(int) $count);
            }
        }

        if (! $applying) {
            $this->comment('Dry-run tidak menulis data. Tinjau ringkasan, lalu tambahkan --apply untuk menyimpan jurnal.');
        }

        return self::SUCCESS;
    }
}
