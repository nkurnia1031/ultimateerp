@extends('layouts.app')

@section('title', 'Tambah Dokumen Loading Vendor')

@section('content')
@includeIf('assetmanagement::layouts.nav')
<section class="content-header">
    <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default pull-right">Kembali</a>
    <h1><i class="fas fa-truck-loading"></i> Tambah Dokumen Loading Vendor</h1>
    <p class="text-muted">Isi identitas dokumen, lalu tambahkan satu atau lebih tiket angkutan.</p>
</section>
<section class="content">
    <div id="vendor-document-create-feedback" class="alert" role="alert" hidden></div>
    <form id="vendor-document-create-form" method="POST" action="{{ route('asset.fleet.vendor-loadings.store') }}">
        @csrf
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Informasi Wajib</h3><small class="text-muted pull-right">Sesuai identitas pada workbook</small></div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-4 form-group"><label for="vendor-goods-name">Nama Barang *</label><input id="vendor-goods-name" name="goods_name" class="form-control" maxlength="191" placeholder="Contoh: Loading Cangkang" required></div>
                    <div class="col-md-4 form-group"><label for="vendor-customer-id">Customer *</label><div class="input-group"><select id="vendor-customer-id" name="customer_id" class="form-control" aria-required="true"><option value="">Pilih customer</option></select>@if($canCreateCustomer)<span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle="modal" data-target="#vendor-minimal-customer-modal" title="Tambah customer minimal"><i class="fas fa-plus"></i></button></span>@endif</div></div>
                    <div class="col-md-4 form-group"><label for="vendor-supplier-id">Bendera / Supplier *</label><div class="input-group"><select id="vendor-supplier-id" name="supplier_id" class="form-control" aria-required="true"><option value="">Pilih bendera / supplier</option></select>@if($canCreateSupplier)<span class="input-group-btn"><button type="button" class="btn btn-default" data-toggle="modal" data-target="#vendor-minimal-supplier-modal" title="Tambah supplier minimal"><i class="fas fa-plus"></i></button></span>@endif</div><small class="text-muted">Pilih supplier dari business aktif.</small></div>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group"><label for="vendor-vessel-name">Nama Kapal *</label><input id="vendor-vessel-name" name="vessel_name" class="form-control" maxlength="191" placeholder="Contoh: MV. Bright King" required></div>
                    <div class="col-md-6 form-group"><label for="vendor-destination">Tujuan *</label><input id="vendor-destination" name="destination" class="form-control" maxlength="191" placeholder="Contoh: Pelabuhan Pelindo" required></div>
                </div>
            </div>
        </div>

        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Opsional / Relasi</h3><small class="text-muted pull-right">Tidak wajib untuk menyimpan dokumen</small></div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-4 form-group"><label for="vendor-goods-type">Jenis Barang</label><input id="vendor-goods-type" name="goods_type" class="form-control" maxlength="191" placeholder="Opsional"></div>
                    <div class="col-md-4 form-group"><label for="vendor-document-route">Rute Dokumen</label><select id="vendor-document-route" name="route_id" class="form-control"><option value="">Tidak terkait rute master</option>@foreach($routes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                </div>
            </div>
        </div>

        <div class="box box-info">
            <div class="box-header with-border"><h3 class="box-title">Line Angkutan</h3><button type="button" id="vendor-add-line" class="btn btn-primary btn-sm pull-right"><i class="fas fa-plus"></i> Tambah Line</button></div>
            <div class="box-body">
                <p class="text-muted">Tanggal wajib. Nett dihitung dari Gross − Tare dan total biaya dihitung otomatis saat tarif tersedia.</p>
                <div class="table-responsive vendor-lines-table-wrap">
                    <table class="table table-bordered table-striped vendor-create-lines-table">
                        <thead><tr><th>#</th><th>Tanggal *</th><th>No. Tiket</th><th>No. Polisi / Unit</th><th>Nama Sopir</th><th>Gross</th><th>Tare</th><th>Nett</th><th>Ton Tagihan</th><th>Tarif / Ton (Rp)</th><th>Total (Rp)</th><th>Detail</th><th>Aksi</th></tr></thead>
                        <tbody id="vendor-create-lines"></tbody>
                        <tfoot><tr class="active"><th colspan="5" class="text-right">SUBTOTAL DOKUMEN</th><th id="vendor-total-gross" class="text-right">0,0000</th><th id="vendor-total-tare" class="text-right">0,0000</th><th id="vendor-total-net" class="text-right">0,0000</th><th id="vendor-total-billable" class="text-right">0,0000</th><th></th><th id="vendor-total-amount" class="text-right">Rp 0</th><th colspan="2"></th></tr></tfoot>
                    </table>
                </div>
            </div>
            <div class="box-footer">
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Dokumen</button>
                <a href="{{ route('asset.fleet.vendor-loadings.index') }}" class="btn btn-default">Batal</a>
            </div>
        </div>
    </form>

    @if($canCreateSupplier)
    <div class="modal fade" id="vendor-minimal-supplier-modal" tabindex="-1" role="dialog" aria-labelledby="vendor-minimal-supplier-title">
        <div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="vendor-minimal-supplier-title">Tambah Supplier Minimal</h4></div><div class="modal-body"><label>Nama supplier / bendera</label><input id="vendor-minimal-supplier-name" class="form-control" maxlength="191"><div id="vendor-minimal-supplier-feedback" class="alert" hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="button" id="vendor-minimal-supplier-save" class="btn btn-primary">Simpan Supplier</button></div></div></div>
    </div>
    @endif
    @if($canCreateCustomer)
    <div class="modal fade" id="vendor-minimal-customer-modal" tabindex="-1" role="dialog" aria-labelledby="vendor-minimal-customer-title">
        <div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="vendor-minimal-customer-title">Tambah Customer Minimal</h4></div><div class="modal-body"><label>Nama customer</label><input id="vendor-minimal-customer-name" class="form-control" maxlength="191"><div id="vendor-minimal-customer-feedback" class="alert" hidden></div></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="button" id="vendor-minimal-customer-save" class="btn btn-primary">Simpan Customer</button></div></div></div>
    </div>
    @endif
</section>
<style>
    .vendor-create-lines-table { min-width: 1380px; }
    .vendor-create-lines-table td, .vendor-create-lines-table th { vertical-align: middle !important; }
    .vendor-create-lines-table .form-control { min-width: 95px; }
    .vendor-create-lines-table .vendor-line-details td { background: #f7fafc; }
    .vendor-create-lines-table input[readonly] { background: #edf2f5; font-weight: 600; }
    .vendor-line-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 10px; }
    .vendor-line-detail-grid label { display: block; margin-bottom: 4px; }
    .vendor-create-lines-table tfoot { font-weight: 700; }
    @media (max-width: 767px) { .vendor-line-detail-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); } .box-header small.pull-right { float: none !important; display: block; margin-top: 6px; } }
</style>
@push('scripts')
<script>
window.vendorLoadingDocumentCreateConfig = {
    csrf: @json(csrf_token()),
    supplierUrl: @json(route('asset.fleet.vendor-loadings.suppliers')),
    customerUrl: @json(route('asset.fleet.vendor-loadings.customers')),
    createSupplierUrl: @json(route('asset.fleet.vendor-loadings.suppliers.minimal')),
    createCustomerUrl: @json(route('asset.fleet.vendor-loadings.customers.minimal')),
    canCreateSupplier: @json($canCreateSupplier),
    canCreateCustomer: @json($canCreateCustomer),
    projects: @json($projects),
    today: @json(now()->format('Y-m-d'))
};
</script>
<script src="{{ versioned_asset_url(asset('modules/assetmanagement/js/vendor-loading-document-create.js')) }}"></script>
@endpush
@endsection
