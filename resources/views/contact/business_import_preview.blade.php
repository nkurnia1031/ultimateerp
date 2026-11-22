@extends('layouts.app')

@section('title', 'Preview import kontak dari bisnis')

@section('content')
<section class="content-header">
    <a class="btn btn-default pull-right" href="{{ route('contacts.index', ['type' => $type]) }}"><i class="fa fa-arrow-left"></i> Kembali ke daftar</a>
    <h1>Preview import {{ $type === 'supplier' ? 'supplier' : 'customer' }} dari bisnis</h1>
    <p class="text-muted">Periksa hasil cross-check sebelum mengantrekan import.</p>
</section>

<section class="content">
    <div id="business-copy-preview-page" class="box box-primary"
        data-kind="contacts"
        data-preview-url="{{ route('contacts.import.business.preview') }}"
        data-import-url="{{ route('contacts.import.business') }}"
        data-return-url="{{ route('contacts.index', ['type' => $type]) }}"
        data-type="{{ $type }}"
        data-source-business-id="{{ $sourceBusiness->id }}"
        data-skip-existing="{{ $skipExisting ? '1' : '0' }}">
        <div class="box-header with-border">
            <h3 class="box-title">Sumber: {{ $sourceBusiness->name }}</h3>
        </div>
        <div class="box-body">
            <div id="business-copy-preview-loading" class="alert alert-warning" role="status"><i class="fa fa-spinner fa-spin"></i> Memeriksa kontak sumber...</div>
            <div id="business-copy-preview-error" class="alert alert-danger" role="alert" hidden></div>
            <div id="business-copy-preview-success" class="alert alert-success" role="status" hidden></div>
            <div id="business-copy-preview-content" hidden>
                <div class="row">
                    <div class="col-sm-4"><div class="small-box bg-blue"><div class="inner"><h3 data-business-copy-count="total">0</h3><p>Total sumber</p></div></div></div>
                    <div class="col-sm-4"><div class="small-box bg-green"><div class="inner"><h3 data-business-copy-count="new">0</h3><p>Kontak baru</p></div></div></div>
                    <div class="col-sm-4"><div class="small-box bg-yellow"><div class="inner"><h3 data-business-copy-count="existing">0</h3><p>Sudah ada / dilewati</p></div></div></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-condensed table-bordered" id="business-copy-preview-table">
                        <thead><tr><th>Nama</th><th>Business name</th><th>Contact ID</th><th>Email</th><th>Mobile</th><th>Status</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="text-muted" data-business-copy-truncated hidden>Preview menampilkan maksimal 100 baris. Jumlah total tetap dihitung server.</p>
            </div>
            <div class="form-group" style="margin-top:15px">
                <label class="checkbox-inline"><input type="checkbox" id="business-copy-preview-skip-existing" {{ $skipExisting ? 'checked' : '' }}> Lewati kontak yang sudah ada</label>
            </div>
            <div class="btn-toolbar">
                <button type="button" class="btn btn-success" id="business-copy-preview-import" disabled><i class="fa fa-upload"></i> Import kontak baru</button>
                <a class="btn btn-default" href="{{ route('contacts.index', ['type' => $type]) }}">Kembali ke daftar</a>
            </div>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script src="{{ versioned_asset_url(asset('js/business-copy-import-preview.js'), $asset_v ?? null) }}"></script>
@endsection
