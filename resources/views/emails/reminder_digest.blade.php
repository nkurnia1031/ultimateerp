<!doctype html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family: Arial, Helvetica, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6fb; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:680px; max-width:680px; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(15, 23, 42, 0.08);">
                <tr>
                    <td style="background:#0f172a; padding:24px 28px; color:#ffffff;">
                        <div style="font-size:20px; font-weight:700; line-height:1.3;">Reminder Digest</div>
                        <div style="font-size:13px; opacity:0.82; margin-top:6px;">{{ $business_name }} - {{ $display_date }}</div>
                    </td>
                </tr>

                @if (! empty($hero_summary))
                    <tr>
                        <td style="padding:24px 28px 0 28px;">
                            <div style="border:1px solid #dbeafe; background:#eff6ff; border-radius:12px; padding:16px 18px;">
                                <div style="font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#1d4ed8; margin-bottom:6px;">Prioritas terdekat</div>
                                <div style="font-size:16px; line-height:1.5; font-weight:700; color:#111827;">{{ $hero_summary }}</div>
                            </div>
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:24px 28px 8px 28px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                @foreach (['today', 'tomorrow'] as $cardKey)
                                    <td style="width:50%; padding:0 {{ $cardKey === 'today' ? '8px 12px 0 0' : '0 0 12px 8px' }};">
                                        <div style="border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; background:#f8fafc;">
                                            <div style="font-size:12px; color:#6b7280;">{{ $summary_counts[$cardKey]['label'] }}</div>
                                            <div style="font-size:24px; font-weight:700; margin-top:4px; color:#111827;">{{ $summary_counts[$cardKey]['total'] }}</div>
                                            <div style="font-size:12px; color:#6b7280; margin-top:6px;">
                                                Asset {{ $summary_counts[$cardKey]['asset_count'] }} &bull; Reminder {{ $summary_counts[$cardKey]['reminder_count'] }}
                                            </div>
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach (['within_7_days', 'within_30_days'] as $cardKey)
                                    <td style="width:50%; padding:0 {{ $cardKey === 'within_7_days' ? '8px 12px 0 0' : '0 0 12px 8px' }};">
                                        <div style="border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; background:#f8fafc;">
                                            <div style="font-size:12px; color:#6b7280;">{{ $summary_counts[$cardKey]['label'] }}</div>
                                            <div style="font-size:24px; font-weight:700; margin-top:4px; color:#111827;">{{ $summary_counts[$cardKey]['total'] }}</div>
                                            <div style="font-size:12px; color:#6b7280; margin-top:6px;">
                                                Asset {{ $summary_counts[$cardKey]['asset_count'] }} &bull; Reminder {{ $summary_counts[$cardKey]['reminder_count'] }}
                                            </div>
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 28px 12px 28px;">
                        <div style="font-size:16px; font-weight:700; color:#111827;">Yang perlu diperhatikan</div>
                        <div style="font-size:13px; color:#6b7280; margin-top:4px;">
                            Diurutkan dari yang paling dekat dulu, supaya tidak perlu menghitung ulang prioritasnya.
                        </div>
                    </td>
                </tr>

                @foreach ($list_groups as $group)
                    <tr>
                        <td style="padding:0 28px 18px 28px;">
                            <div style="font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:10px;">
                                {{ $group['label'] }}
                            </div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                @foreach ($group['items'] as $item)
                                    @php
                                        $statusStyles = match ($item['status_tone']) {
                                            'today' => ['bg' => '#fee2e2', 'border' => '#fecaca', 'color' => '#991b1b'],
                                            'tomorrow' => ['bg' => '#fff7ed', 'border' => '#fed7aa', 'color' => '#9a3412'],
                                            'week' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1d4ed8'],
                                            default => ['bg' => '#f3f4f6', 'border' => '#e5e7eb', 'color' => '#374151'],
                                        };
                                    @endphp
                                    <tr>
                                        <td style="padding:16px 18px; border:1px solid #e5e7eb; border-radius:12px; background:#ffffff;">
                                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                                <tr>
                                                    <td style="padding:0 8px 12px 0; vertical-align:top;">
                                                        <span style="display:inline-block; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:700;">
                                                            {{ $item['source_name'] }} &middot; {{ $item['source_detail'] }}
                                                        </span>
                                                    </td>
                                                    <td align="right" style="padding:0 0 12px 8px; vertical-align:top;">
                                                        <span style="display:inline-block; background:{{ $statusStyles['bg'] }}; color:{{ $statusStyles['color'] }}; border:1px solid {{ $statusStyles['border'] }}; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:700;">
                                                            {{ $item['relative_due_label'] }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>

                                            <div style="font-size:16px; font-weight:700; color:#111827; line-height:1.45;">
                                                {{ $item['title'] }}
                                            </div>

                                            <div style="font-size:13px; font-weight:700; color:#1f2937; margin-top:8px;">
                                                Jatuh tempo: {{ $item['due_date_full_label'] }}
                                            </div>

                                            <div style="font-size:12px; color:#6b7280; line-height:1.7; margin-top:8px;">
                                                @foreach ($item['meta'] as $meta)
                                                    <span style="display:inline-block; margin-right:14px;">
                                                        <strong>{{ $meta['label'] }}:</strong> {{ $meta['value'] }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                    @if (! $loop->last)
                                        <tr><td style="height:10px;"></td></tr>
                                    @endif
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endforeach

                <tr>
                    <td style="padding:0 28px 28px 28px; font-size:12px; color:#6b7280; line-height:1.6;">
                        Reminder ini dikirim otomatis untuk membantu memantau jatuh tempo terdekat.
                        <br>
                        {{ $owner_name ?: $business_name }}.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
