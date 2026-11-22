@extends('layouts.app')

@section('title', 'Preview import kategori biaya dari bisnis')

@section('content')
<section class="content-header">
    <a class="btn btn-default pull-right" href="{{ route('expense-categories.index') }}"><i class="fa fa-arrow-left"></i> Kembali ke daftar</a>
    <h1>Preview import kategori biaya dari bisnis</h1>
    <p class="text-muted">Periksa kategori baru dan kategori yang sudah cocok sebelum mengantrekan import.</p>
</section>

<section class="content">
    <div id="business-copy-preview-page" class="box box-primary"
        data-kind="expense_categories"
        data-preview-url="{{ route('expense-categories.import.business.preview') }}"
        data-import-url="{{ route('expense-categories.import.business') }}"
        data-return-url="{{ route('expense-categories.index') }}"
        data-source-business-id="{{ $sourceBusiness->id }}"
        data-skip-existing="{{ $skipExisting ? '1' : '0' }}">
        <div class="box-header with-border">
            <h3 class="box-title">Sumber: {{ $sourceBusiness->name }}</h3>
        </div>
        <div class="box-body">
            <div id="business-copy-preview-loading" class="alert alert-warning" role="status"><i class="fa fa-spinner fa-spin"></i> Memeriksa kategori sumber...</div>
            <div id="business-copy-preview-error" class="alert alert-danger" role="alert" hidden></div>
            <div id="business-copy-preview-success" class="alert alert-success" role="status" hidden></div>
            <div id="business-copy-preview-content" hidden>
                <div class="row">
                    <div class="col-sm-4"><div class="small-box bg-blue"><div class="inner"><h3 data-business-copy-count="total">0</h3><p>Total sumber</p></div></div></div>
                    <div class="col-sm-4"><div class="small-box bg-green"><div class="inner"><h3 data-business-copy-count="new">0</h3><p>Kategori baru</p></div></div></div>
                    <div class="col-sm-4"><div class="small-box bg-yellow"><div class="inner"><h3 data-business-copy-count="existing">0</h3><p>Sudah ada / dilewati</p></div></div></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-condensed table-bordered" id="business-copy-preview-table">
                        <thead><tr><th>Path kategori</th><th>Nama</th><th>Kode</th><th>Status</th><th>Metode</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="text-muted" data-business-copy-truncated hidden>Preview menampilkan maksimal 100 baris. Jumlah total tetap dihitung server.</p>
            </div>
            <div class="form-group" style="margin-top:15px">
                <label class="checkbox-inline"><input type="checkbox" id="business-copy-preview-skip-existing" {{ $skipExisting ? 'checked' : '' }}> Lewati kategori yang sudah ada</label>
            </div>
            <div class="btn-toolbar">
                <button type="button" class="btn btn-success" id="business-copy-preview-import" disabled><i class="fa fa-upload"></i> Import kategori baru</button>
                <a class="btn btn-default" href="{{ route('expense-categories.index') }}">Kembali ke daftar</a>
            </div>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script src="{{ versioned_asset_url(asset('js/business-copy-import-preview.js'), $asset_v ?? null) }}"></script>
@endsection
