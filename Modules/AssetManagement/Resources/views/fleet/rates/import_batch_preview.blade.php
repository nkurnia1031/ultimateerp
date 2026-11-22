@extends('layouts.app')

@php($isPreview = ($details['batch']['status'] ?? null) === 'preview_ready')
@section('title', $isPreview ? 'Preview Import Tarif' : 'Detail Import Tarif')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@php
    $batch = $details['batch'] ?? [];
    $rows = $details['rows'] ?? [];
    $summary = $batch['summary'] ?? [];
    $backUrl = route('asset.fleet.rates.index').'#fleet-rate-batches-table';
@endphp
<section class="content-header">
    <a href="{{ $backUrl }}" class="btn btn-default pull-right"><i class="fa fa-arrow-left"></i> Kembali ke daftar import</a>
    <h1><i class="fas fa-search"></i> {{ $isPreview ? 'Preview Import Tarif' : 'Detail Import Tarif' }}</h1>
    <p class="text-muted">{{ $isPreview ? 'Periksa rute, unit, nominal, dan catatan setiap baris sebelum mengaktifkan batch.' : 'Rincian baris dan status batch import tarif.' }}</p>
</section>
<section class="content">
    <div id="rate-import-preview-feedback" class="alert" role="status" hidden></div>
    <div class="box box-info">
        <div class="box-header with-border"><h3 class="box-title">Ringkasan batch</h3></div>
        <div class="box-body">
            <div class="row">
                <div class="col-sm-6"><strong>File:</strong> {{ $batch['file_name'] ?? '—' }}</div>
                <div class="col-sm-3"><strong>Status:</strong> {{ $batch['status'] ?? '—' }}</div>
                <div class="col-sm-3"><strong>Batch tarif:</strong> #{{ $batch['domain_batch_id'] ?? '—' }}</div>
                <div class="col-sm-12" style="margin-top:8px"><strong>Pesan:</strong> {{ $batch['message'] ?? '—' }}</div>
            </div>
            @if(!empty($batch['error']))
                <div class="alert alert-danger" style="margin-top:12px">{{ $batch['error'] }}</div>
            @endif
            @if($summary)
                <hr>
                <div class="row">
                    @foreach(['source_rows' => 'Baris sumber', 'ready_rows' => 'Baris siap', 'error_rows' => 'Baris bermasalah', 'valid_row_count' => 'Tarif valid', 'unit_count' => 'Unit'] as $key => $label)
                        @if(array_key_exists($key, $summary))
                            <div class="col-xs-6 col-sm-2"><strong>{{ $label }}:</strong> {{ $summary[$key] }}</div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Baris workbook ({{ count($rows) }})</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead><tr><th>Baris Excel</th><th>Rute / Unit sumber</th><th>Pemetaan unit</th><th>Supir / ton</th><th>Uang jalan</th><th>Harga / ton</th><th>Status / catatan</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr class="{{ !empty($row['errors']) ? 'danger' : '' }}">
                        <td>{{ $row['source_row'] ?? '—' }}</td>
                        <td>{{ $row['route'] ?? '—' }}<br><small class="text-muted">{{ $row['unit_source'] ?? '—' }}</small></td>
                        <td>
                            @forelse(($row['units'] ?? []) as $unit)
                                <div>{{ $unit['label'] ?? 'Unit' }}
                                    @if(!empty($unit['asset'])) → {{ $unit['asset'] }}
                                    @else <span class="text-danger">(belum dicocokkan)</span>
                                    @endif
                                </div>
                            @empty
                                —
                            @endforelse
                        </td>
                        <td>{{ $row['driver_rate_per_ton'] ?? '—' }}</td>
                        <td>{{ $row['travel_allowance'] ?? '—' }}</td>
                        <td>{{ $row['sale_rate_per_ton'] ?? '—' }}</td>
                        <td>
                            <strong>{{ $row['status'] ?? 'draft' }}</strong>
                            @foreach(($row['errors'] ?? []) as $error)
                                <div class="text-danger"><span data-import-history-message-source>{{ is_array($error) ? ($error['message'] ?? json_encode($error)) : $error }}</span></div>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">Batch ini belum memiliki rincian baris.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="box-footer">
            <a href="{{ $backUrl }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> Kembali ke daftar import</a>
            @if($canManageRates && ($batch['status'] ?? null) === 'preview_ready')
                <button type="button" class="btn btn-success pull-right" id="rate-import-preview-confirm"
                    data-confirm-url="{{ route('asset.fleet.rates.import.batches.confirm', ['id' => $batch['id']]) }}"
                    data-csrf-token="{{ csrf_token() }}">
                    <i class="fas fa-check"></i> Konfirmasi &amp; aktifkan tarif
                </button>
            @endif
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script>
$(function () {
    $('#rate-import-preview-confirm').on('click', function () {
        var button = this;
        if (!window.confirm('Aktifkan tarif pada batch ini? Tarif lama untuk unit/rute yang sama akan ditutup.')) return;
        button.disabled = true;
        var feedback = $('#rate-import-preview-feedback');
        feedback.prop('hidden', false).removeClass('alert-success alert-danger').addClass('alert-info').text('Mengirim konfirmasi import...');
        fetch(button.dataset.confirmUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': button.dataset.csrfToken
            },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || data.success === false) throw new Error(data.msg || 'Konfirmasi import belum berhasil.');
                feedback.removeClass('alert-info alert-danger').addClass('alert-success').text(data.msg || 'Konfirmasi diterima.');
                window.setTimeout(function () { window.location.assign(@json($backUrl)); }, 900);
            });
        }).catch(function (error) {
            feedback.removeClass('alert-info alert-success').addClass('alert-danger').text(error.message || 'Konfirmasi import belum berhasil.');
            button.disabled = false;
        });
    });
});
</script>
@endsection
