@forelse($rows as $finding)
    @php
        $evidence = json_decode($finding->evidence, true) ?: [];
        $resolutionHistory = json_decode($finding->resolution_history, true) ?: [];
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
            @if($resolutionHistory !== [])
                <details><summary>Histori ({{ count($resolutionHistory) }})</summary>
                    <ul class="list-unstyled">
                        @foreach($resolutionHistory as $event)
                            <li><strong>{{ ['accepted' => 'Diterima', 'operational_tax_corrected' => 'Pajak dikoreksi', 'audit_cleared' => 'Lunas saat audit', 'reopened' => 'Dibuka kembali'][$event['action'] ?? ''] ?? ($event['action'] ?? 'Aksi') }}</strong>
                                @if(! empty($event['actor_id']))<br><small>Pengguna #{{ $event['actor_id'] }}</small>@endif
                                @if(! empty($event['at']))<br><small>{{ $event['at'] }}</small>@endif
                                @if(! empty($event['reason']))<br><small>{{ $event['reason'] }}</small>@endif
                                @if(! empty($event['before']) || ! empty($event['after']))<br><small>Nilai sebelum → sesudah: {{ json_encode($event['before'] ?? []) }} → {{ json_encode($event['after'] ?? []) }}</small>@endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @if($evidence !== [])<details><summary>Bukti</summary><pre>{{ json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
        </td>
        <td class="text-nowrap">
            @if($finding->status === 'open' && (bool) $finding->is_current && (auth()->user()->can('superadmin') || auth()->user()->can('accounting.manage_accounts')))
                @if(in_array($finding->type, ['transaction', 'cash_bank'], true))
                    <button type="button" class="btn btn-xs btn-info js-review-finding-source" data-finding-id="{{ $finding->id }}">Tinjau sumber</button><br>
                @elseif(in_array($finding->type, ['unbalanced_journal', 'account_balance', 'control'], true))
                    <a class="btn btn-xs btn-default" href="{{ route('journal-entry.index', ['search' => $finding->source_number ?: $finding->account_no]) }}">Buka jurnal</a><br>
                @endif
                <button type="button" class="btn btn-xs btn-warning js-accept-finding" data-finding-id="{{ $finding->id }}">Terima selisih</button>
            @else
                <span class="text-muted">—</span>
            @endif
        </td>
    </tr>
@empty
    <tr><td colspan="9" class="text-center text-muted">Tidak ada temuan untuk filter ini.</td></tr>
@endforelse
