@extends('layouts.app')

@section('title', 'Settlement Payroll Angkutan')

@section('content')
@includeIf('assetmanagement::layouts.nav')
@include('layouts.partials.fleet-datatables')
<section class="content-header"><h1><i class="fas fa-money-check-alt"></i> Settlement Payroll Angkutan</h1></section>
<section class="content">
    @if (session('status'))
        <div class="alert {{ session('status.success') ? 'alert-success' : 'alert-danger' }}" role="alert">
            {{ session('status.msg') }}
        </div>
    @endif
    <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">Pilih sumber payroll</h3></div><div class="box-body"><p class="text-muted">Pilih sopir dan periode untuk meninjau SPB, Bonus Ayak, dan Kasbon yang belum diproses. User dapat mengeluarkan item pada halaman review sebelum membuat settlement.</p><form method="POST" action="{{ route('asset.fleet.settlements.preview') }}">@csrf<div class="row"><div class="col-md-3 form-group"><label>Sopir *</label><select name="driver_id" class="form-control" required><option value="">Pilih sopir</option>@foreach($drivers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div><div class="col-md-3 form-group"><label>Periode mulai *</label><input type="date" name="period_start" class="form-control" required></div><div class="col-md-3 form-group"><label>Periode sampai *</label><input type="date" name="period_end" class="form-control" required></div><div class="col-md-3" style="padding-top:25px"><button class="btn btn-primary">Review Settlement</button></div></div></form></div></div>
    <div class="box box-solid"><div class="box-body table-responsive fleet-datatable-wrap"><table id="fleet-settlements-table" class="table table-bordered table-striped fleet-datatable"><thead><tr><th>No</th><th>Sopir</th><th>Periode</th><th>SPB</th><th>Bonus</th><th>Kasbon</th><th>Bruto</th><th>Netto</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($settlements as $settlement)<tr><td>{{ $settlement->settlement_no }}</td><td>{{ optional($settlement->driver)->user_full_name ?: '-' }}</td><td>{{ optional($settlement->period_start)->format('d-m-Y') }} - {{ optional($settlement->period_end)->format('d-m-Y') }}</td><td>{{ $settlement->trip_count }}</td><td>@format_currency($settlement->total_bonus_ayak)</td><td>@format_currency($settlement->total_kasbon)</td><td>@format_currency($settlement->gross_payable ?: $settlement->total_payable)</td><td>@format_currency($settlement->net_payable ?: $settlement->total_payable)</td><td>{{ strtoupper($settlement->status) }}</td><td>@if($settlement->status === 'draft')<form class="d-inline" method="POST" action="{{ route('asset.fleet.settlements.post', $settlement->id) }}" onsubmit="return confirm('Posting settlement ke Payroll Essentials?')">@csrf<button class="btn btn-xs btn-success">Post Payroll</button></form><form class="d-inline" method="POST" action="{{ route('asset.fleet.settlements.cancel', $settlement->id) }}" onsubmit="return confirm('Batalkan draft settlement ini?')">@csrf<button class="btn btn-xs btn-danger">Batalkan</button></form>@elseif($settlement->payroll_transaction_id)<a href="{{ action([Modules\Essentials\Http\Controllers\PayrollController::class, 'show'], [$settlement->payroll_transaction_id]) }}" class="btn btn-xs btn-default">Lihat Payroll</a>@endif</td></tr>@empty<tr><td colspan="10" class="text-center">Belum ada settlement.</td></tr>@endforelse</tbody></table></div></div>
</section>
@push('scripts')
<script>
$(function () {
    initFleetDataTable('#fleet-settlements-table', {
        order: [[2, 'desc']],
        columnDefs: [{ targets: [3, 4, 5, 6, 7, 8, 9], orderable: false }]
    });
});
</script>
@endpush
@endsection
