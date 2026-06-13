<?php

namespace Tests\Unit;

use App\Business;
use App\Services\ReminderDigestService;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ReminderDigestServiceTest extends TestCase
{
    public function test_it_builds_an_urgency_first_digest_payload(): void
    {
        $service = new ReminderDigestService();

        $business = new Business();
        $business->name = 'CV BM TRANSPORT';

        $owner = new User();
        $owner->surname = 'Mr';
        $owner->first_name = 'Sahar';
        $owner->last_name = '';
        $business->setRelation('owner', $owner);

        $items = new Collection([
            [
                'business_id' => 1,
                'source' => 'asset',
                'source_name' => 'Asset',
                'source_detail' => 'STNK',
                'owner_label' => 'Mr Sahar',
                'title' => 'STNK Asset 2026/0007 - BM 1789 HF',
                'meta' => [
                    ['label' => 'Sumber', 'value' => 'Asset'],
                    ['label' => 'Pemilik', 'value' => 'Mr Sahar'],
                    ['label' => 'Tipe asset', 'value' => 'STNK'],
                ],
                'source_ref' => 10,
                'days_left' => 0,
                'due_date' => '2026-06-08',
                'relative_due_label' => 'Hari ini',
                'due_date_full_label' => 'Senin, 08 Juni 2026',
                'group_key' => 'today',
                'group_label' => 'Hari ini',
                'status_tone' => 'today',
            ],
            [
                'business_id' => 1,
                'source' => 'reminder',
                'source_name' => 'Reminder',
                'source_detail' => 'Bulanan',
                'owner_label' => 'Mr Sahar',
                'title' => 'Bayar listrik kantor',
                'meta' => [
                    ['label' => 'Sumber', 'value' => 'Reminder Umum'],
                    ['label' => 'Pemilik', 'value' => 'Mr Sahar'],
                    ['label' => 'Repeat', 'value' => 'Bulanan'],
                ],
                'source_ref' => 11,
                'days_left' => 1,
                'due_date' => '2026-06-09',
                'relative_due_label' => 'Besok',
                'due_date_full_label' => 'Selasa, 09 Juni 2026',
                'group_key' => 'tomorrow',
                'group_label' => 'Besok',
                'status_tone' => 'tomorrow',
            ],
            [
                'business_id' => 1,
                'source' => 'reminder',
                'source_name' => 'Reminder',
                'source_detail' => 'Tahunan',
                'owner_label' => 'Mr Sahar',
                'title' => 'Perpanjang izin usaha',
                'meta' => [
                    ['label' => 'Sumber', 'value' => 'Reminder Umum'],
                    ['label' => 'Pemilik', 'value' => 'Mr Sahar'],
                    ['label' => 'Repeat', 'value' => 'Tahunan'],
                ],
                'source_ref' => 12,
                'days_left' => 8,
                'due_date' => '2026-06-16',
                'relative_due_label' => '8 hari lagi',
                'due_date_full_label' => 'Selasa, 16 Juni 2026',
                'group_key' => 'later',
                'group_label' => 'Berikutnya',
                'status_tone' => 'later',
            ],
        ]);

        $payload = $service->buildDigestPayload($business, $items, Carbon::parse('2026-06-08'));

        $this->assertSame('Terdekat: Hari ini - STNK Asset 2026/0007 - BM 1789 HF - jatuh tempo Senin, 08 Juni 2026', $payload['hero_summary']);
        $this->assertSame(1, $payload['summary_counts']['today']['total']);
        $this->assertSame(1, $payload['summary_counts']['tomorrow']['total']);
        $this->assertSame(2, $payload['summary_counts']['within_7_days']['total']);
        $this->assertSame(3, $payload['summary_counts']['within_30_days']['total']);
        $this->assertSame(['today', 'tomorrow', 'later'], array_column($payload['list_groups'], 'key'));
        $this->assertSame('Hari ini', $payload['list_groups'][0]['items'][0]['relative_due_label']);
        $this->assertSame('Besok', $payload['list_groups'][1]['items'][0]['relative_due_label']);
        $this->assertSame('8 hari lagi', $payload['list_groups'][2]['items'][0]['relative_due_label']);
        $this->assertStringNotContainsString('1-7 hari lagi', json_encode($payload));
        $this->assertStringNotContainsString('8-30 hari lagi', json_encode($payload));
    }

    public function test_it_changes_digest_signature_when_content_changes(): void
    {
        $service = new ReminderDigestService();
        $method = new \ReflectionMethod($service, 'buildDigestSignature');
        $method->setAccessible(true);

        $payload = [
            'business_name' => 'CV BM TRANSPORT',
            'owner_name' => 'Mr Sahar',
            'display_date' => '08 Juni 2026',
            'subject' => 'Reminder Digest - 08 Juni 2026',
            'summary' => [
                'total_count' => 1,
                'asset_count' => 1,
                'reminder_count' => 0,
            ],
            'summary_counts' => [],
            'hero_summary' => 'Terdekat: Hari ini - STNK Asset 2026/0007 - BM 1789 HF - jatuh tempo Senin, 08 Juni 2026',
            'list_groups' => [
                [
                    'key' => 'today',
                    'label' => 'Hari ini',
                    'items' => [
                        ['title' => 'A'],
                    ],
                ],
            ],
        ];

        $first = $method->invoke($service, $payload, 'mail@example.com');
        $payload['list_groups'][0]['items'][0]['title'] = 'B';
        $second = $method->invoke($service, $payload, 'mail@example.com');

        $this->assertNotSame($first, $second);
    }
}
