@forelse($rows as $finding)
    <tr id="source-finding-{{ $finding['id'] }}" data-source-finding-id="{{ $finding['id'] }}">
        <td>
            <strong>{{ $finding['source_number'] ?: 'Tanpa nomor' }}</strong><br>
            <span>{{ $finding['source_date'] ?: 'Tanggal tidak tersedia' }}</span><br>
            <small class="text-muted">Run #{{ $finding['run_id'] }} · {{ substr($finding['source_identity'], 0, 12) }}</small>
        </td>
        <td>
            <strong>{{ $finding['source_type'] }}</strong><br>
            @if($finding['source_amount'] !== null)
                {{ session('currency')['symbol'] ?? '' }} {{ number_format($finding['source_amount'], (int) (session('business.currency_precision') ?? 2), session('currency')['decimal_separator'] ?? '.', session('currency')['thousand_separator'] ?? ',') }}
            @else
                <span class="text-muted">Nominal tidak tersedia</span>
            @endif
        </td>
        <td>
            <details>
                <summary>Lihat bukti</summary>
                <div class="small" style="min-width:230px;max-width:480px;white-space:normal">
                    @if(!empty($finding['evidence']['contact_name']))<div><strong>Kontak:</strong> {{ $finding['evidence']['contact_name'] }}</div>@endif
                    @if(!empty($finding['evidence']['description']))<div><strong>Keterangan:</strong> {{ $finding['evidence']['description'] }}</div>@endif
                    @if(!empty($finding['evidence']['category_name']))<div><strong>Kategori:</strong> {{ $finding['evidence']['category_name'] }}</div>@endif
                    <div><strong>Basis:</strong> {{ is_numeric($finding['evidence']['base'] ?? null) ? number_format((float) $finding['evidence']['base'], 2) : '—' }} · <strong>Pajak:</strong> {{ is_numeric($finding['evidence']['tax'] ?? null) ? number_format((float) $finding['evidence']['tax'], 2) : '—' }} · <strong>Total:</strong> {{ is_numeric($finding['evidence']['total'] ?? null) ? number_format((float) $finding['evidence']['total'], 2) : '—' }}</div>
                    @if(!empty($finding['evidence']['lines']))
                        <strong>Produk</strong>
                        <ul class="list-unstyled">
                            @foreach($finding['evidence']['lines'] as $line)
                                <li>{{ $line['product_name'] ?: 'Produk tanpa nama' }} @if($line['product_code'])({{ $line['product_code'] }})@endif · Qty {{ $line['quantity'] ?? '—' }} · {{ number_format((float) ($line['line_base'] ?? 0), 2) }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if(!empty($finding['evidence']['expense_lines']))
                        <strong>Rincian biaya</strong>
                        <ul class="list-unstyled">
                            @foreach($finding['evidence']['expense_lines'] as $line)
                                <li>{{ $line['category_name'] ?: 'Tanpa kategori' }} · {{ $line['description'] }} · {{ number_format((float) ($line['amount'] ?? 0), 2) }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if(!empty($finding['evidence']['sources']))
                        <strong>File sumber</strong>
                        <ul class="list-unstyled">
                            @foreach($finding['evidence']['sources'] as $source)
                                <li>{{ $source['file'] ?: 'File tidak diketahui' }}@if($source['row']) · baris {{ $source['row'] }}@endif</li>
                            @endforeach
                        </ul>
                    @endif
                    @if(!empty($finding['history']))
                        <details><summary>Riwayat tindakan ({{ count($finding['history']) }})</summary>
                            <ul class="list-unstyled">
                                @foreach($finding['history'] as $event)
                                    <li>{{ $event['at'] ?? '' }} · {{ str_replace('_', ' ', $event['action'] ?? 'aksi') }}@if(!empty($event['actor_id'])) · pengguna #{{ $event['actor_id'] }}@endif @if(!empty($event['reason']))<br>{{ $event['reason'] }}@endif</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            </details>
        </td>
        <td>
            <strong>{{ str_replace('_', ' ', $finding['reason_code']) }}</strong><br>
            <span>{{ $finding['reason'] }}</span><br>
            @if($finding['status'] === 'resolved')
                <span class="label label-success">Selesai</span>
                @if($finding['target_record_id'])<a href="{{ route('accounting.reconciliation.findings') }}#source-finding-{{ $finding['id'] }}">Transaksi #{{ $finding['target_record_id'] }}</a>@endif
                @if($finding['resolution_reason'])<small class="text-muted">{{ $finding['resolution_reason'] }}</small>@endif
            @else
                <span class="label label-warning">Terbuka</span>
            @endif
        </td>
        <td style="min-width:210px">
            @if($finding['status'] === 'open')
                @if(!empty($finding['mapping_candidates']))
                    <details class="small" style="margin-bottom:6px">
                        <summary>Mapping akun tersimpan ({{ count($finding['mapping_candidates']) }})</summary>
                        @foreach($finding['mapping_candidates'] as $mapping)
                            <div style="white-space:normal;margin:5px 0">
                                <strong>{{ $mapping['mapping_label'] }}</strong> · {{ $mapping['location_name'] }}<br>
                                @if($mapping['payment_account'])
                                    Pembayaran: {{ $mapping['payment_account']['name'] }}
                                    @if($mapping['payment_account']['code']) ({{ $mapping['payment_account']['code'] }}) @endif<br>
                                @endif
                                @if($mapping['deposit_to'])
                                    Akun transaksi: {{ $mapping['deposit_to']['name'] }}
                                    @if($mapping['deposit_to']['code']) ({{ $mapping['deposit_to']['code'] }}) @endif
                                @endif
                            </div>
                        @endforeach
                        <small class="text-muted">Referensi mapping pada lokasi aktif; tindakan ini tidak mengubah mapping atau jurnal.</small>
                    </details>
                @endif
                @if($canManageSources)
                    @forelse($finding['candidates'] as $candidate)
                        <button type="button" class="btn btn-xs btn-info js-source-finding-action"
                            data-action="link" data-finding-id="{{ $finding['id'] }}" data-transaction-id="{{ $candidate['id'] }}"
                            data-target-label="{{ $candidate['reference'] ?: 'Transaksi #'.$candidate['id'] }} · {{ $candidate['date'] }} · {{ session('currency')['symbol'] ?? '' }} {{ number_format((float) ($candidate['total'] ?? 0), 2) }} (selisih {{ number_format((float) ($candidate['amount_difference'] ?? 0), 2) }})">
                            Tautkan #{{ $candidate['id'] }}
                        </button><br>
                    @empty
                        <span class="text-muted">Belum ada kandidat transaksi.</span><br>
                    @endforelse
                    @if($finding['create_url'])<a class="btn btn-xs btn-default" href="{{ $finding['create_url'] }}" target="_blank" rel="noopener">Lengkapi di form {{ strtolower($finding['source_type']) }}</a><br>@endif
                    <button type="button" class="btn btn-xs btn-warning js-source-finding-action" data-action="retry" data-finding-id="{{ $finding['id'] }}">Coba ulang setelah disimpan</button>
                @else
                    <span class="text-muted">Perlu izin accounting.manage_accounts untuk menautkan sumber.</span>
                @endif
            @else
                <span class="text-muted">Riwayat temuan tersedia pada bukti.</span>
            @endif
        </td>
    </tr>
@empty
    <tr><td colspan="5" class="text-center text-muted">Tidak ada temuan sumber untuk filter ini.</td></tr>
@endforelse
