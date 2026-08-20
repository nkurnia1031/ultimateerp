<?php

namespace App\Services;

use App\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ContactBusinessImportService
{
    /**
     * Fields that are safe to copy between businesses. Financial balances,
     * reward totals, timestamps, and ownership fields are intentionally
     * excluded because they belong to the destination business.
     */
    private const COPY_FIELDS = [
        'type', 'contact_type', 'supplier_business_name', 'name', 'prefix',
        'first_name', 'middle_name', 'last_name', 'contact_id', 'tax_number',
        'email', 'mobile', 'landline', 'alternate_number', 'city', 'state',
        'country', 'landmark', 'land_mark', 'street_name', 'building_number',
        'additional_number', 'address_line_1', 'address_line_2', 'zip_code',
        'dob', 'pay_term_number', 'pay_term_type', 'credit_limit',
        'custom_field1', 'custom_field2', 'custom_field3', 'custom_field4',
        'custom_field5', 'custom_field6', 'custom_field7', 'custom_field8',
        'custom_field9', 'custom_field10', 'shipping_address', 'position',
        'shipping_custom_field_details', 'is_export', 'export_custom_field_1',
        'export_custom_field_2', 'export_custom_field_3', 'export_custom_field_4',
        'export_custom_field_5', 'export_custom_field_6',
    ];

    public function preview(int $sourceBusinessId, int $targetBusinessId, string $type): array
    {
        $source = $this->sourceContacts($sourceBusinessId, $type)->get();
        $target = Contact::query()->where('business_id', $targetBusinessId)->get();
        $index = $this->buildIndex($target);
        $rows = [];
        $counts = ['total' => $source->count(), 'new' => 0, 'existing' => 0];

        foreach ($source as $contact) {
            $match = $this->findMatch($contact, $index);
            if ($match) {
                $counts['existing']++;
                $status = 'existing';
            } else {
                $counts['new']++;
                $status = 'new';
            }

            if (count($rows) < 100) {
                $rows[] = [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'business_name' => $contact->supplier_business_name,
                    'contact_id' => $contact->contact_id,
                    'email' => $contact->email,
                    'mobile' => $contact->mobile,
                    'status' => $status,
                    'matched_by' => $match['matched_by'] ?? null,
                    'matched_contact_id' => $match['contact']->id ?? null,
                ];
            }

            // Include source duplicates in the same preview as existing rows.
            if (! $match) {
                $this->addToIndex($index, $contact);
            }
        }

        return [
            'source_business_id' => $sourceBusinessId,
            'target_business_id' => $targetBusinessId,
            'type' => $type,
            'counts' => $counts,
            'rows' => $rows,
            'truncated' => $source->count() > count($rows),
        ];
    }

    public function import(int $sourceBusinessId, int $targetBusinessId, int $createdBy, string $type, bool $skipExisting = true): array
    {
        if ($sourceBusinessId === $targetBusinessId) {
            throw new RuntimeException('Bisnis sumber dan tujuan harus berbeda.');
        }

        return DB::transaction(function () use ($sourceBusinessId, $targetBusinessId, $createdBy, $type, $skipExisting) {
            $source = $this->sourceContacts($sourceBusinessId, $type)->lockForUpdate()->get();
            $target = Contact::query()->where('business_id', $targetBusinessId)->lockForUpdate()->get();
            $index = $this->buildIndex($target);
            $created = 0;
            $skipped = 0;
            $errors = [];

            foreach ($source as $contact) {
                $match = $this->findMatch($contact, $index);
                if ($match && $skipExisting) {
                    $skipped++;
                    continue;
                }

                try {
                    $payload = $this->copyPayload($contact, $targetBusinessId, $createdBy);
                    $payload['contact_id'] = $this->uniqueContactId($payload['contact_id'] ?? null, $targetBusinessId);
                    $newContact = Contact::create($payload);
                    $this->addToIndex($index, $newContact);
                    $created++;
                } catch (\Throwable $exception) {
                    $errors[] = [
                        'source_id' => $contact->id,
                        'name' => $contact->name,
                        'message' => $exception->getMessage(),
                    ];
                }
            }

            if ($errors !== []) {
                throw new RuntimeException('Import dibatalkan karena ada data yang gagal disimpan: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
            }

            return [
                'total' => $source->count(),
                'created' => $created,
                'skipped' => $skipped,
            ];
        });
    }

    private function sourceContacts(int $businessId, string $type)
    {
        $types = $type === 'supplier' ? ['supplier', 'both'] : ['customer', 'both'];

        return Contact::query()
            ->where('business_id', $businessId)
            ->whereIn('type', $types)
            ->whereNull('deleted_at')
            ->orderBy('id');
    }

    private function copyPayload(Contact $contact, int $businessId, int $createdBy): array
    {
        $source = $contact->getAttributes();
        $payload = [];
        foreach (self::COPY_FIELDS as $field) {
            if (array_key_exists($field, $source)) {
                $payload[$field] = $source[$field];
            }
        }
        $payload['business_id'] = $businessId;
        $payload['created_by'] = $createdBy;
        $payload['is_default'] = 0;
        $payload['balance'] = 0;
        $payload['is_advance'] = 0;

        // Customer groups are business-scoped and must not be copied by ID.
        $payload['customer_group_id'] = null;

        return $payload;
    }

    private function uniqueContactId(?string $contactId, int $businessId): string
    {
        $contactId = trim((string) $contactId);
        if ($contactId !== '' && ! Contact::where('business_id', $businessId)->where('contact_id', $contactId)->exists()) {
            return $contactId;
        }

        $prefix = 'IMP-'.now()->format('ymd').'-';
        do {
            $generated = $prefix.Str::upper(Str::random(6));
        } while (Contact::where('business_id', $businessId)->where('contact_id', $generated)->exists());

        return $generated;
    }

    private function buildIndex($contacts): array
    {
        $index = ['contact_id' => [], 'email' => [], 'mobile' => [], 'name' => []];
        foreach ($contacts as $contact) {
            $this->addToIndex($index, $contact);
        }

        return $index;
    }

    private function addToIndex(array &$index, Contact $contact): void
    {
        foreach ($this->identities($contact) as $key => $value) {
            if ($value !== null && ! isset($index[$key][$value])) {
                $index[$key][$value] = $contact;
            }
        }
    }

    private function findMatch(Contact $contact, array $index): ?array
    {
        foreach ($this->identities($contact) as $matchedBy => $value) {
            if ($value !== null && isset($index[$matchedBy][$value])) {
                return ['contact' => $index[$matchedBy][$value], 'matched_by' => $matchedBy];
            }
        }

        return null;
    }

    private function identities(Contact $contact): array
    {
        $name = $this->normalize($contact->name);
        $businessName = $this->normalize($contact->supplier_business_name);

        return [
            'contact_id' => $this->normalize($contact->contact_id),
            'email' => $this->normalize($contact->email),
            'mobile' => $this->normalizePhone($contact->mobile),
            'name' => ($name || $businessName) ? $name.'|'.$businessName : null,
        ];
    }

    private function normalize($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_strtolower(preg_replace('/\s+/', ' ', $value));
    }

    private function normalizePhone($value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);
        return $value === '' ? null : $value;
    }
}
