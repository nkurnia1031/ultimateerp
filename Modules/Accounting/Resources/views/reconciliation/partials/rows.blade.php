@forelse($rows as $finding)
    @php
        $evidence = json_decode($finding->evidence, true) ?: [];
    @endphp
    <tr>
        <td>{{ $finding->source_date ?: '—' }}<br><small class="text-muted">{{ $finding->year }}</small></td>
        <td><strong>{{ $findings->typeLabel($finding->type) }}</strong><br>{{ $findings->componentLabel($finding->component) }}<br><small>{{ $finding->description }}</small>
            @if(! empty($evidence['period_start']) && ! empty($evidence['period_end']))<br><small>Periode {{ $evidence['period_start'] }} — {{ $evidence['period_end'] }}</small>@endif
        </td>
        <td>{{ $finding->source_type ?: '—' }}<br><strong>{{ $finding->source_number ?: '—' }}</strong></td>
        <td>{{ trim($finding->account_no.' '.$finding->account_name) ?: '—' }}</td>
        <td class="text-right">@if($finding->expected_amount !== null) @format_currency($finding->expected_amount) @else — @endif</td>
        <td class="text-right">@if($finding->actual_amount !== null) @format_currency($finding->actual_amount) @else — @endif</td>
        <td class="text-right"><strong>@format_currency($finding->variance)</strong></td>
        <td><span class="label label-{{ $finding->status === 'open' ? 'warning' : ($finding->status === 'accepted' ? 'info' : 'success') }}">{{ ['open' => 'Terbuka', 'accepted' => 'Diterima', 'resolved' => 'Selesai'][$finding->status] ?? $finding->status }}</span><br><small>Audit {{ $finding->last_audited_at }}</small>
            @if(! empty($finding->resolution_history))<details><summary>Histori</summary><pre>{{ $finding->resolution_history }}</pre></details>@endif
            @if($evidence !== [])<details><summary>Bukti</summary><pre>{{ json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
        </td>
    </tr>
@empty
    <tr><td colspan="8" class="text-center text-muted">Tidak ada temuan untuk filter ini.</td></tr>
@endforelse
