@extends('layouts.app')
@section('title', __('lang_v1.update_product_price'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang( 'lang_v1.update_product_price' )
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
    @if (session('notification') || !empty($notification))
        <div class="row">
            <div class="col-sm-12">
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    @if(!empty($notification['msg']))
                        {{$notification['msg']}}
                    @elseif(session('notification.msg'))
                        {{ session('notification.msg') }}
                    @endif
                </div>
            </div>  
        </div>     
    @endif
    @component('components.widget', ['class' => 'box-primary', 'title' => __('lang_v1.import_export_product_price')])
            <div class="row">
                <div class="col-sm-6">
                    <a href="{{action([\App\Http\Controllers\SellingPriceGroupController::class, 'export'])}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('lang_v1.export_product_prices')</a>
                </div>
                <div class="col-sm-6">
                    {!! Form::open(['url' => action([\App\Http\Controllers\SellingPriceGroupController::class, 'import']), 'method' => 'post', 'enctype' => 'multipart/form-data', 'id' => 'selling-price-import-form' ]) !!}
                    <div class="form-group">
                        {!! Form::label('name', __( 'product.file_to_import' ) . ':') !!}
                        {!! Form::file('product_group_prices', ['required' => 'required']); !!}
                    </div>
                    <div class="form-group">
                        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="selling-price-import-submit">@lang('messages.submit')</button>
                    </div>
                    <div id="selling-price-upload-progress" class="progress hidden"><div class="progress-bar" style="width:0%">0%</div></div>
                    <p id="selling-price-upload-message" class="text-muted" aria-live="polite"></p>
                    {!! Form::close() !!}
                </div>
                <div class="col-sm-12">
                    <h4>@lang('lang_v1.instructions'):</h4>
                    <ol>
                        <li>@lang('lang_v1.price_import_instruction_1')</li>
                        <li>@lang('lang_v1.price_import_instruction_2')</li>
                        <li>@lang('lang_v1.price_import_instruction_3')</li>
                        <li>@lang('lang_v1.price_import_instruction_4')</li>
                    </ol>
                    
                </div>
            </div>
    @endcomponent

    @component('components.widget', ['class' => 'box-default', 'title' => 'Riwayat Import Selling Price Group'])
        <div class="row"><div class="col-sm-3 form-group"><label for="selling-price-history-status">Status</label><select id="selling-price-history-status" class="form-control"><option value="">Semua status</option><option>queued_preview</option><option>analyzing</option><option>importing</option><option>imported</option><option>failed</option><option>reverted</option></select></div></div>
        <div class="table-responsive"><table id="selling-price-history-table" class="table table-bordered table-striped" width="100%"><thead><tr><th>File</th><th>Status</th><th>Progress</th><th>Pesan</th><th>Dibuat</th><th>Aksi</th></tr></thead></table></div>
        <div id="selling-price-history-detail" class="well hidden"><h4>Detail import harga</h4><pre style="white-space:pre-wrap"></pre></div>
    @endcomponent

</section>
<!-- /.content -->
@stop

@section('javascript')
<script>
$(function () {
    var csrf = $('meta[name="csrf-token"]').attr('content');
    var table = $('#selling-price-history-table').DataTable({
        processing: true,
        serverSide: true,
        searchDelay: 350,
        ajax: {
            url: @json(route('selling-price-group.import.history')),
            data: function (data) { data.status = $('#selling-price-history-status').val() || ''; }
        },
        columns: [
            {data: 'file_name', name: 'file_name', render: $.fn.dataTable.render.text()},
            {data: 'status', name: 'status', render: $.fn.dataTable.render.text()},
            {data: null, orderable: false, searchable: false, render: function (row) {
                var percent = Math.max(0, Math.min(100, parseInt(row.progress_percent || 0, 10)));
                return '<div class="progress"><div class="progress-bar" style="width:' + percent + '%">' + percent + '%</div></div><small>' + parseInt(row.progress_current || 0, 10) + ' / ' + (parseInt(row.progress_total || 0, 10) || '—') + '</small>';
            }},
            {data: 'last_message', name: 'last_message', defaultContent: '', render: $.fn.dataTable.render.text()},
            {data: 'created_at', name: 'created_at', render: $.fn.dataTable.render.text()},
            {data: null, orderable: false, searchable: false, render: function (row) {
                var escape = function (value) { return $('<div>').text(value || '').html(); };
                var actions = '<button type="button" class="btn btn-xs btn-default selling-price-detail" data-url="' + escape(row.detail_url) + '">Detail</button> ';
                if (row.restore_url) actions += '<a class="btn btn-xs btn-success" href="' + escape(row.restore_url) + '">Unduh sumber</a> ';
                if (row.can_retry) actions += '<button type="button" class="btn btn-xs btn-warning selling-price-retry" data-url="' + escape(row.retry_url) + '">Coba lagi</button> ';
                if (row.can_revert) actions += '<button type="button" class="btn btn-xs btn-danger selling-price-revert" data-url="' + escape(row.revert_url) + '">Revert</button>';
                return actions;
            }}
        ],
        order: [[4, 'desc']]
    });
    $('#selling-price-history-status').on('change', function () { table.ajax.reload(); });
    window.setInterval(function () {
        if (table.rows().data().toArray().some(function (row) { return row.active; })) table.ajax.reload(null, false);
    }, 5000);

    $('#selling-price-import-form').on('submit', function (event) {
        event.preventDefault();
        var form = this;
        var progress = $('#selling-price-upload-progress').removeClass('hidden').find('.progress-bar').css('width', '0%').text('0%');
        $('#selling-price-import-submit').prop('disabled', true);
        $('#selling-price-upload-message').text('Mengunggah workbook…');
        $.ajax({
            url: $(form).attr('action'), method: 'POST', data: new FormData(form), processData: false, contentType: false,
            dataType: 'json', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) { var percent = Math.round(e.loaded / e.total * 100); progress.css('width', percent + '%').text(percent + '%'); }
                });
                return xhr;
            }
        }).done(function (payload) {
            $('#selling-price-upload-message').text(payload.message || 'Workbook masuk antrean.');
            table.ajax.reload(null, false);
        }).fail(function (xhr) {
            $('#selling-price-import-submit').prop('disabled', false);
            $('#selling-price-upload-message').text((xhr.responseJSON && xhr.responseJSON.message) || 'Upload workbook gagal.');
        });
    });

    $('#selling-price-history-table').on('click', '.selling-price-detail', function () {
        var detail = $('#selling-price-history-detail');
        $.get($(this).data('url')).done(function (payload) {
            detail.find('pre').text(JSON.stringify(payload, null, 2));
            detail.removeClass('hidden');
        });
    });
    $('#selling-price-history-table').on('click', '.selling-price-retry, .selling-price-revert', function () {
        var button = $(this), reverting = button.hasClass('selling-price-revert');
        if (reverting && !window.confirm('Revert diblokir jika harga berubah lagi atau produk sudah dipakai transaksi. Lanjutkan?')) return;
        $.ajax({url: button.data('url'), method: 'POST', data: {_token: csrf}}).done(function () { table.ajax.reload(null, false); })
            .fail(function (xhr) { if (window.toastr) toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Aksi batch gagal.'); });
    });
});
</script>
@endsection
