@extends('layouts.app')

@section('title', 'Detail Import Solar')

@section('content')
<section class="content-header">
    <h1>Detail Import Solar <small>{{ $batch->file_name ?: 'Workbook Solar' }}</small></h1>
</section>
<section class="content" id="solar-import-detail"
    data-status-url="{{ route('solar.imports.status', $batch->id) }}"
    data-events-url="{{ route('solar.imports.events', $batch->id) }}"
    data-rows-url="{{ route('solar.imports.rows', $batch->id) }}"
    data-last-event-id="{{ $events->max('sequence') ?? 0 }}">
    <div class="box box-primary">
        <div class="box-header with-border">
            <a href="{{ route('solar.imports') }}" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Kembali</a>
            @if ($hasWorkbookSnapshot)
                <a href="{{ route('solar.imports.restore', $batch->id) }}" class="btn btn-success btn-sm"><i class="fa fa-file-excel-o"></i> Unduh ulang workbook</a>
            @endif
            <span class="label label-{{ $batch->status === 'imported' ? 'success' : ($batch->status === 'rolled_back' ? 'default' : 'warning') }} pull-right">{{ ucfirst($batch->status) }}</span>
        </div>
        <div class="box-body">
            <p><strong>Batch:</strong> {{ $batch->id }}</p>
            <p><strong>File hash:</strong> <code>{{ $batch->file_hash }}</code></p>
            <div id="solar-import-detail-progress" class="alert alert-info">
                <strong>{{ ucfirst($batch->process_state ?: 'completed') }}</strong> · {{ (int) ($batch->progress_percent ?? 0) }}% · {{ $batch->progress_message ?: 'Belum ada pesan proses.' }}
                <div class="progress progress-xs" style="margin:7px 0 0"><div class="progress-bar" style="width:{{ (int) ($batch->progress_percent ?? 0) }}%"></div></div>
            </div>
            <h4>Log proses</h4>
            <ol id="solar-import-detail-events" style="max-height:320px;overflow:auto;padding-left:22px">
                @forelse ($events as $event)
                    @php $context = json_decode((string) $event->context, true) ?: []; @endphp
                    <li><small class="text-muted">{{ \Carbon\Carbon::parse($event->created_at)->format('H:i:s') }} · {{ $event->progress_percent === null ? '' : ((int) $event->progress_percent).'% · ' }}</small><strong>{{ ucfirst(str_replace('_', ' ', $event->stage)) }}:</strong> {{ $event->message }}@if (! empty($context['sheet'])) <span class="text-muted">(Sheet {{ $context['sheet'] }}@if (! empty($context['row'])) · baris {{ $context['row'] }}@endif)</span>@endif</li>
                @empty
                    <li class="text-muted">Log proses belum tersedia untuk batch ini.</li>
                @endforelse
            </ol>
            <div id="solar-import-detail-connection" class="text-muted" aria-live="polite"></div>
            <h4>Pemetaan unit dan supir</h4>
            @php $mappingRows = array_merge($mappings['asset'] ?? [], $mappings['driver'] ?? []); @endphp
            @if ($mappingRows === [])
                <p class="text-muted">Tidak ada pemetaan referensi pada batch ini.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-condensed table-bordered">
                        <thead><tr><th>Jenis</th><th>Nilai workbook</th><th>ID terpilih</th><th>Saran</th><th>Baris</th></tr></thead>
                        <tbody>
                            @foreach ($mappingRows as $mapping)
                                <tr>
                                    <td>{{ ($mapping['type'] ?? '') === 'asset' ? 'Unit/Asset' : 'Supir' }}</td>
                                    <td>{{ $mapping['source_value'] ?? '—' }}</td>
                                    <td>{{ !empty($mapping['has_conflict']) ? 'Konflik' : ($mapping['target_id'] ?? '—') }}</td>
                                    <td>{{ collect($mapping['suggestions'] ?? [])->pluck('label')->implode(', ') ?: '—' }}</td>
                                    <td>{{ (int) ($mapping['row_count'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <h4>Baris sumber dan hasil pencocokan</h4>
            <p class="text-muted">Rincian Excel, padanan di aplikasi, dan kesalahan tiap baris tersedia melalui detail.</p>
            <div class="table-responsive">
                <table id="solar-import-source-rows" class="table table-condensed table-bordered table-striped" style="width:100%">
                    <thead><tr><th>Sheet</th><th>Baris</th><th>Ledger</th><th>Tanggal</th><th>Status</th><th>Dicocokkan via</th><th>Perbedaan / Error</th><th>Excel</th><th>Aplikasi</th><th>Import</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            @foreach ($rows as $ledger => $records)
                <h4>{{ $ledger === 'usage' ? 'PEMAKAIAN ALAT' : strtoupper($ledger) }} ({{ $records->count() }} baris)</h4>
                @if ($records->isEmpty())
                    <p class="text-muted">Tidak ada transaksi dari batch ini.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-condensed table-bordered">
                            <thead><tr><th>ID</th><th>Tanggal</th><th>Alat / Keterangan</th><th>Pemakaian</th><th>Source row</th></tr></thead>
                            <tbody>
                                @foreach ($records as $record)
                                    <tr>
                                        <td>{{ $record->id }}</td>
                                        <td>{{ $ledger === 'usage' ? $record->usage_date : $record->tanggal }}</td>
                                        <td>{{ $ledger === 'usage' ? $record->equipment_name : ($record->keterangan ?: '—') }}</td>
                                        <td>{{ $ledger === 'usage' ? number_format((float) $record->quantity_gln, 2, ',', '.').' Gln / '.number_format((float) $record->quantity_gln * 30, 2, ',', '.').' L' : '—' }}</td>
                                        <td>{{ $record->source_row ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script src="{{ asset('js/solar.js?v=' . $asset_v) }}"></script>
@endsection
