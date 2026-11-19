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
            <div class="box-header with-border"><h3 class="box-title">Informasi Wajib</h3><small class="text-muted vendor-header-note">Sesuai identitas pada workbook</small></div>
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
            <div class="box-header with-border"><h3 class="box-title">Opsional / Relasi</h3><small class="text-muted vendor-header-note">Tidak wajib untuk menyimpan dokumen</small></div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-4 form-group"><label for="vendor-goods-type">Jenis Barang</label><input id="vendor-goods-type" name="goods_type" class="form-control" maxlength="191" placeholder="Opsional"></div>
                    <div class="col-md-4 form-group"><label for="vendor-document-route">Rute Dokumen</label><select id="vendor-document-route" name="route_id" class="form-control"><option value="">Tidak terkait rute master</option>@foreach($routes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                </div>
            </div>
        </div>

        <div class="box box-info">
            <div class="box-header with-border vendor-lines-header"><div><h3 class="box-title">Line Angkutan</h3><p class="text-muted">Setiap kartu mewakili satu tiket; rincian tambahan dapat dibuka bila diperlukan.</p></div><button type="button" id="vendor-add-line" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Line</button></div>
            <div class="box-body">
                <p class="text-muted">Tanggal wajib. Netto dihitung dari gross dikurangi tare; nilai biaya mengikuti tonase tagihan dan tarif.</p>
                <div id="vendor-create-lines" class="vendor-line-list" aria-live="polite"></div>
                <div class="vendor-document-totals" aria-label="Subtotal seluruh line">
                    <div class="vendor-total-item"><span>Total gross</span><strong id="vendor-total-gross">0,0000 ton</strong></div>
                    <div class="vendor-total-item"><span>Total tare</span><strong id="vendor-total-tare">0,0000 ton</strong></div>
                    <div class="vendor-total-item"><span>Total netto</span><strong id="vendor-total-net">0,0000 ton</strong></div>
                    <div class="vendor-total-item"><span>Total tonase tagihan</span><strong id="vendor-total-billable">0,0000 ton</strong></div>
                    <div class="vendor-total-item vendor-total-item--amount"><span>Subtotal dokumen</span><strong id="vendor-total-amount">Rp 0</strong></div>
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
    .vendor-header-note { float: right; margin-top: 3px; }
    .vendor-lines-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .vendor-lines-header p { margin: 6px 0 0; }
    .vendor-line-list { display: grid; gap: 14px; }
    .vendor-line-card { min-width: 0; border: 1px solid #dfe6eb; border-radius: 4px; background: #fff; }
    .vendor-line-main { min-width: 0; padding: 16px; }
    .vendor-line-card-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 14px; }
    .vendor-line-card-title { margin: 0; color: #34495e; font-size: 15px; font-weight: 700; }
    .vendor-line-card-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .vendor-line-fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0 16px; }
    .vendor-line-fields--three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .vendor-line-field { min-width: 0; margin-bottom: 14px; }
    .vendor-line-field label { display: block; margin-bottom: 6px; font-weight: 600; }
    .vendor-line-field .form-control { width: 100%; min-height: 38px; }
    .vendor-line-field input[readonly] { background: #f3f6f8; font-weight: 600; }
    .vendor-line-detail-toggle { margin: 0; padding: 0; }
    .vendor-line-details { border-top: 1px solid #e8edf1; padding: 16px; background: #f8fafb; }
    .vendor-line-details[hidden] { display: none !important; }
    .vendor-line-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0 16px; }
    .vendor-line-field--full { grid-column: 1 / -1; }
    .vendor-document-totals { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-top: 18px; }
    .vendor-total-item { min-width: 0; padding: 12px; border: 1px solid #e3e9ee; border-radius: 4px; background: #f8fafb; }
    .vendor-total-item span, .vendor-total-item strong { display: block; }
    .vendor-total-item span { color: #687985; font-size: 12px; }
    .vendor-total-item strong { margin-top: 4px; color: #263746; font-size: 15px; overflow-wrap: anywhere; }
    .vendor-total-item--amount { background: #f0f7f2; border-color: #cfe4d4; }
    @media (max-width: 991px) {
        .vendor-line-fields, .vendor-line-fields--three { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .vendor-document-totals { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .vendor-line-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 575px) {
        .vendor-header-note { float: none; display: block; margin: 6px 0 0; }
        .vendor-line-fields, .vendor-line-fields--three, .vendor-line-detail-grid { grid-template-columns: minmax(0, 1fr); }
        .vendor-line-field--full { grid-column: auto; }
        .vendor-document-totals { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .vendor-lines-header { align-items: flex-start; }
    }
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
