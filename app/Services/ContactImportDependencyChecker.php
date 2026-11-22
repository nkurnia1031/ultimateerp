<?php

namespace App\Services;

use App\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContactImportDependencyChecker
{
    private const REFERENCE_COLUMNS = [
        ['bookings', 'contact_id'],
        ['crm_proposals', 'contact_id'],
        ['crm_lead_users', 'contact_id'],
        ['crm_schedules', 'contact_id'],
        ['surat_kontrak', 'contact_id'],
        ['pjt_projects', 'contact_id'],
        ['journal_entries', 'contact_id'],
        ['user_contact_access', 'contact_id'],
        ['users', 'crm_contact_id'],
        ['crm_contact_person_commissions', 'contact_person_id'],
        ['heavy_equipment_trips', 'customer_id'],
        ['heavy_equipment_vendor_loadings', 'supplier_id'],
        ['heavy_equipment_vendor_loadings', 'customer_id'],
        ['heavy_equipment_vendor_loading_documents', 'supplier_id'],
        ['heavy_equipment_vendor_loading_documents', 'customer_id'],
        ['transaction_payments', 'payment_for'],
        ['transactions', 'contact_id'],
    ];

    public function hasReferences(array $contactIds, array $excludedTransactionIds = []): bool
    {
        if ($contactIds === []) {
            return false;
        }

        foreach (self::REFERENCE_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->whereIn($column, $contactIds);
            if ($table === 'transactions' && $excludedTransactionIds !== []) {
                $query->whereNotIn('id', $excludedTransactionIds);
            }
            if ($query->exists()) {
                return true;
            }
        }

        if (Schema::hasTable('document_and_notes')
            && Schema::hasColumn('document_and_notes', 'notable_type')
            && Schema::hasColumn('document_and_notes', 'notable_id')) {
            if (DB::table('document_and_notes')->where('notable_type', Contact::class)->whereIn('notable_id', $contactIds)->exists()) {
                return true;
            }
        }

        if (Schema::hasTable('crm_campaigns') && Schema::hasColumn('crm_campaigns', 'contact_ids')) {
            $contactIds = array_map('strval', $contactIds);
            foreach (DB::table('crm_campaigns')->whereNotNull('contact_ids')->pluck('contact_ids') as $storedIds) {
                $campaignContactIds = preg_split('/[^0-9]+/', (string) $storedIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (array_intersect($contactIds, $campaignContactIds) !== []) {
                    return true;
                }
            }
        }

        return false;
    }
}
