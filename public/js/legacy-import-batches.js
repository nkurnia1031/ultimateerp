(function ($) {
    'use strict';

    var form = $('#legacy-import-batch-form');
    var historyTable = $('#legacy_import_batch_history');
    if (!form.length || !historyTable.length) return;

    var table = null;
    var pollTimer = null;
    var escapeText = function (value) { return $('<div>').text(value == null ? '' : String(value)).html(); };
    var status = function (message, isError) {
        $('#legacy_import_status').text(message || '');
        if (message && window.toastr) (isError ? toastr.error : toastr.info)(message);
    };
    var reload = function () { if (table) table.ajax.reload(null, false); };

    if ($.fn.DataTable && !$.fn.DataTable.isDataTable(historyTable)) {
        table = historyTable.DataTable({
            processing: true,
            serverSide: true,
            searching: true,
            pageLength: 10,
            ajax: {
                url: historyTable.data('history-url'),
                data: function (data) { data.status = $('#legacy_import_status_filter').val() || ''; },
            },
            columns: [
                { data: 'id' },
                { data: 'file_name', render: escapeText },
                { data: 'status', render: function (value, type, row) {
                    var message = row.error_message || row.last_message || '';
                    if (type !== 'display') return (value || '') + ' ' + message;
                    return escapeText(value) + (message ? '<br><small>' + window.importHistoryMessageCell(message, 'display') + '</small>' : '');
                } },
                { data: 'progress_percent', render: function (value, type, row) {
                    return escapeText(value) + '% (' + escapeText(row.progress_current) + '/' + escapeText(row.progress_total) + ')';
                } },
                { data: 'created_at', render: escapeText },
                { data: null, orderable: false, searchable: false, render: function (row) {
                    var actions = '<button type="button" class="btn btn-xs btn-info js-legacy-import-detail" data-url="' + escapeText(row.detail_url) + '">Detail</button> ';
                    if (row.source_url) actions += '<a class="btn btn-xs btn-default" href="' + escapeText(row.source_url) + '">Workbook</a> ';
                    if (row.can_retry) actions += '<button type="button" class="btn btn-xs btn-warning js-legacy-import-retry" data-url="' + escapeText(row.retry_url) + '">Retry</button> ';
                    if (row.can_revert) actions += '<button type="button" class="btn btn-xs btn-danger js-legacy-import-revert" data-url="' + escapeText(row.revert_url) + '">Revert</button>';
                    return actions;
                } },
            ],
            order: [[0, 'desc']],
        });
    }

    $('#legacy_import_status_filter').on('change', reload);

    var showDetail = function (url) {
        $.getJSON(url).done(function (detail) {
            var lines = (detail.rows || []).map(function (row) {
                var target = row.product_id ? ' product #' + row.product_id : '';
                if (row.variation_id) target += ' variation #' + row.variation_id;
                if (row.location_id) target += ' location #' + row.location_id;
                return 'Baris ' + row.source_row + ' [' + (row.sku || row.name || '-') + '] ' + row.status + target + (row.message ? ': ' + row.message : '');
            });
            var records = (detail.records || []).map(function (row) {
                var label = escapeText((row.name || row.invoice_no || row.ref_no || 'Record') + ' (#' + row.id + ')');
                if (row.sku) label += ' SKU ' + escapeText(row.sku);
                return row.url ? '<a href="' + escapeText(row.url) + '" target="_blank" rel="noopener">' + label + '</a>' : label;
            });
            if ($('#legacy_import_detail_modal').length) {
                $('#legacy_import_detail_title').text(detail.batch.file_name || 'Detail import');
                $('#legacy_import_detail_status').text('Status: ' + detail.batch.status + ' — ' + (detail.batch.error_message || detail.batch.last_message || ''));
                $('#legacy_import_detail_records').html(records.length ? '<strong>Transaksi / record:</strong><ul><li>' + records.join('</li><li>') + '</li></ul>' : '');
                $('#legacy_import_detail_rows').text(lines.join('\n'));
                $('#legacy_import_detail_modal').modal('show');
            } else {
                var text = 'Status: ' + detail.batch.status + '\n' + (detail.batch.error_message || detail.batch.last_message || '') + '\n\n' +
                    (detail.records || []).map(function (row) { return (row.name || row.invoice_no || row.ref_no || 'Record') + ' (#' + row.id + ') ' + (row.url || ''); }).join('\n') + '\n\n' + lines.join('\n');
                window.alert(text);
            }
        }).fail(function (xhr) {
            status(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Detail batch tidak dapat dimuat.', true);
        });
    };

    form.on('submit', function (event) {
        event.preventDefault();
        if (!this.reportValidity()) return;
        var data = new FormData(this);
        var button = form.find('[type="submit"]');
        button.prop('disabled', true);
        $('#legacy_import_progress_wrap').show();
        $('#legacy_import_progress').css('width', '0%').text('0%');
        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json',
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) xhr.upload.addEventListener('progress', function (progress) {
                    if (!progress.lengthComputable) return;
                    var percent = Math.round(progress.loaded / progress.total * 100);
                    $('#legacy_import_progress').css('width', percent + '%').text(percent + '%');
                });
                return xhr;
            },
        }).done(function (response) {
            status(response.msg || 'Upload diterima; worker sedang memproses workbook.');
            reload();
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(function () {
                $.getJSON(response.status_url).done(function (detail) {
                    status(detail.batch.last_message || detail.batch.status, detail.batch.status === 'failed');
                    reload();
                    if (detail.batch.status === 'preview_ready' && response.preview_url) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                        button.prop('disabled', false);
                        window.location.assign(response.preview_url);
                        return;
                    }
                    if (detail.batch.status === 'imported' || detail.batch.status === 'failed' || detail.batch.status === 'reverted') {
                        clearInterval(pollTimer);
                        pollTimer = null;
                        button.prop('disabled', false);
                        if (detail.batch.status === 'failed') showDetail(response.status_url);
                    }
                }).fail(function (xhr) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                    button.prop('disabled', false);
                    status(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Status batch tidak dapat dimuat.', true);
                });
            }, 1500);
        }).fail(function (xhr) {
            button.prop('disabled', false);
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Upload workbook gagal.';
            status(message, true);
        });
    });

    $(document).on('click', '.js-legacy-import-detail', function () { showDetail($(this).data('url')); });
    $(document).on('click', '.js-legacy-import-retry, .js-legacy-import-revert', function () {
        var button = $(this);
        if (button.hasClass('js-legacy-import-revert') && !window.confirm('Revert batch ini jika belum dipakai oleh transaksi lain?')) return;
        $.post(button.data('url'), { _token: $('meta[name="csrf-token"]').attr('content') })
            .done(function () { status('Aksi batch berhasil diproses.'); reload(); })
            .fail(function (xhr) { status(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Aksi batch gagal.', true); });
    });
})(jQuery);
