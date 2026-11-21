(function ($) {
    'use strict';

    var form = $('#expense_import_form');
    var table = $('#expense_import_history_table');
    var config = $('#expense_import_index_config');
    if (!form.length || !table.length || !config.length || !($.fn && $.fn.DataTable)) { return; }

    var timers = {};
    var csrf = form.find('input[name="_token"]').val();
    var referenceUrl = '';
    var referenceSearchTimer = null;
    var referenceRequest = null;
    var referenceRequestId = 0;
    var referencePage = 1;
    var columns = ['Batch', 'File', 'Dibuat', 'Oleh', 'Status', 'Progress', 'Ringkasan', 'Aksi'];

    function route(template, id) {
        return String(template || '').replace('__BATCH__', encodeURIComponent(id));
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    function badgeClass(status) {
        return {
            queued_preview: 'warning', analyzing: 'warning', preview_ready: 'info',
            queued_import: 'warning', importing: 'warning', imported: 'success',
            partial: 'warning', failed: 'danger', cancelled: 'default', reverted: 'default'
        }[status] || 'default';
    }

    function dateLabel(value) {
        if (!value) { return '-'; }
        var date = new Date(String(value).replace(' ', 'T'));
        return isNaN(date.getTime()) ? String(value) : date.toLocaleString('id-ID');
    }

    function renderStatus(row) {
        var label = row.status_label || row.status || 'Tidak diketahui';
        var message = row.status_message || '';
        return '<span class="label label-' + badgeClass(row.status) + '">' + escapeHtml(label) + '</span>'
            + (message ? '<div class="small text-muted">' + escapeHtml(message) + '</div>' : '');
    }

    function renderProgress(row) {
        var percent = Math.max(0, Math.min(100, Number(row.progress_percent || 0)));
        var active = ['queued_preview', 'analyzing', 'queued_import', 'importing'].indexOf(row.status) !== -1;
        var color = row.status === 'failed' ? 'danger' : 'success';
        return '<div class="progress expense-import-progress"><div class="progress-bar progress-bar-' + color
            + (active ? ' progress-bar-striped active' : '') + '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'
            + percent + '" style="width:' + percent + '%">' + percent + '%</div></div><small class="text-muted">'
            + escapeHtml(row.progress_meta || (Number(row.progress_current || 0) + ' / ' + Number(row.progress_total || 0))) + '</small>';
    }

    function renderSummary(row) {
        var summary = escapeHtml(row.summary_text || '');
        var details = row.summary_detail || '';
        if (!details) { return summary; }
        return summary + '<details class="small text-muted"><summary>Referensi (' + Number(row.reference_count || 0)
            + ')</summary><div>' + escapeHtml(details) + '</div></details>';
    }

    function renderActions(row) {
        var id = row.batch_id || (row.batch_number ? 'legacy-' + row.batch_number : '');
        var actions = [];
        if (row.can_preview && row.batch_id) {
            actions.push('<button type="button" class="btn btn-xs btn-success expense-import-open-preview" data-batch="' + escapeHtml(row.batch_id)
                + '"><i class="fa fa-eye"></i> Periksa preview</button>');
        }
        if (row.can_cancel && row.batch_id) {
            actions.push('<button type="button" class="btn btn-xs btn-warning expense-import-cancel" data-batch="' + escapeHtml(row.batch_id)
                + '"><i class="fa fa-ban"></i> Batalkan</button>');
        }
        if (row.can_view_references && row.references_url && Number(row.reference_count || 0) > 0) {
            actions.push('<button type="button" class="btn btn-xs btn-info expense-import-open-references" data-batch="' + escapeHtml(id)
                + '" data-references-url="' + escapeHtml(row.references_url) + '" data-reference-count="' + Number(row.reference_count)
                + '"><i class="fa fa-list"></i> Referensi (' + Number(row.reference_count) + ')</button>');
        }
        if (row.restore_url) {
            actions.push('<a href="' + escapeHtml(row.restore_url) + '" class="btn btn-xs btn-success expense-import-restore-workbook"><i class="fa fa-file-excel-o"></i> Unduh Excel</a>');
        }
        if (row.revert_url) {
            actions.push('<a href="' + escapeHtml(row.revert_url) + '" class="tw-dw-btn tw-dw-btn-outline tw-dw-btn-xs tw-dw-btn-error revert_import"><i class="fas fa-undo"></i> Revert import</a>');
        }
        return '<div class="expense-import-history-actions">' + (actions.length ? actions.join(' ') : '<span class="text-muted">-</span>') + '</div>';
    }

    var dataTable = table.DataTable({
        processing: true,
        serverSide: true,
        autoWidth: false,
        order: [[2, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        ajax: {
            url: config.data('history-url'),
            data: function (request) {
                request.status = $('#expense_import_history_status').val() || '';
            }
        },
        columns: [
            { data: 'batch_number', render: function (value) { return escapeHtml(value || 'Preview'); } },
            { data: 'file_name', render: function (value) { return escapeHtml(value || 'Impor Expense'); } },
            { data: 'created_at', render: function (value) { return escapeHtml(dateLabel(value)); } },
            { data: 'created_by', render: function (value) { return escapeHtml(value || '-'); } },
            { data: null, orderable: false, searchable: false, render: function (value, type, row) { return renderStatus(row); } },
            { data: null, orderable: false, searchable: false, render: function (value, type, row) { return renderProgress(row); } },
            { data: null, orderable: false, searchable: true, render: function (value, type, row) { return renderSummary(row); } },
            { data: null, orderable: false, searchable: false, render: function (value, type, row) { return renderActions(row); } }
        ],
        createdRow: function (row, data) {
            var id = data.batch_id || data.history_id;
            if (id) { row.id = 'expense_import_batch_' + String(id).replace(/[^a-zA-Z0-9_-]/g, ''); }
            $(row).attr('data-expense-import-batch', data.batch_id || '').attr('data-process-state', data.process_state || '');
            $(row).children('td').each(function (index) { $(this).attr('data-label', columns[index]); });
        },
        drawCallback: function () {
            table.find('tbody tr').each(function () {
                var row = $(this);
                var id = row.attr('data-expense-import-batch');
                var state = row.attr('data-process-state');
                if (id && (state === 'queued' || state === 'processing')) { pollBatch(id, 0); }
            });
        },
        language: {
            processing: 'Memuat riwayat import...', search: 'Cari:', searchPlaceholder: 'Cari file, batch, atau referensi',
            lengthMenu: 'Tampilkan _MENU_ data', info: 'Menampilkan _START_–_END_ dari _TOTAL_ data',
            infoEmpty: 'Belum ada riwayat import', infoFiltered: '(disaring dari _MAX_ data)', zeroRecords: 'Data tidak ditemukan',
            emptyTable: 'Belum ada riwayat import', paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' }
        }
    });

    function stopPolling(id) {
        if (timers[id]) { window.clearTimeout(timers[id]); delete timers[id]; }
    }

    function pollBatch(id, delay) {
        if (timers[id]) { return; }
        timers[id] = window.setTimeout(function () {
            delete timers[id];
            $.ajax({ url: route(config.data('status-template'), id), dataType: 'json', headers: { Accept: 'application/json' } })
                .done(function (status) {
                    dataTable.ajax.reload(null, false);
                    if (status.state === 'queued' || status.state === 'processing' || status.stale) {
                        pollBatch(id, status.stale ? 5000 : 2500);
                    } else {
                        stopPolling(id);
                    }
                })
                .fail(function () { pollBatch(id, 5000); });
        }, Math.max(0, delay || 0));
    }

    function scrollToHistory() {
        var element = document.getElementById('expense_import_history_table');
        if (!element) { return; }
        window.setTimeout(function () { element.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
    }

    function showUploadProgress(percent, message, detail) {
        var value = Math.max(0, Math.min(100, Number(percent || 0)));
        $('#expense_import_upload_progress').css('width', value + '%').text(value + '%');
        $('#expense_import_upload_message').text(message || 'Mengunggah workbook...');
        $('#expense_import_upload_detail').text(detail || 'Progres preview terlihat pada tabel riwayat import.');
        $('#expense_import_upload_status').show();
    }

    function startUpload() {
        var fileInput = document.getElementById('expense_import_file');
        if (!fileInput || !fileInput.files || !fileInput.files.length) { return; }
        var file = fileInput.files[0];
        var request = new XMLHttpRequest();
        $('#expense_import_submit').prop('disabled', true);
        $('#expense_import_upload_status').removeClass('alert-danger').addClass('alert-info');
        showUploadProgress(0, 'Mengunggah workbook...', 'File: ' + file.name);
        scrollToHistory();
        request.open('POST', form.attr('action'), true);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.upload.onprogress = function (event) {
            if (event.lengthComputable) {
                var percent = Math.round((event.loaded / event.total) * 100);
                showUploadProgress(percent, 'Mengunggah workbook...', 'File: ' + file.name + ' · ' + percent + '%');
            }
        };
        request.onreadystatechange = function () {
            if (request.readyState !== 4) { return; }
            $('#expense_import_submit').prop('disabled', false);
            var response = {};
            try { response = JSON.parse(request.responseText || '{}'); } catch (ignore) {}
            if (request.status >= 200 && request.status < 300 && response.batch_id) {
                $('#expense_import_history_status').val('');
                dataTable.search('').page('first');
                dataTable.ajax.reload(function () { scrollToHistory(); }, true);
                showUploadProgress(100, 'Upload diterima.', 'Preview berjalan di latar belakang. Progres tampil pada baris batch di bawah.');
                window.history.replaceState(null, '', window.location.pathname + window.location.search + '#expense_import_batch_' + response.batch_id);
                form[0].reset();
                pollBatch(response.batch_id, 1200);
                return;
            }
            var message = (response.errors && response.errors.expense_csv && response.errors.expense_csv[0]) || response.message || 'Upload gagal. Periksa file lalu coba lagi.';
            $('#expense_import_upload_status').removeClass('alert-info').addClass('alert-danger');
            showUploadProgress(0, message, 'Upload belum diterima.');
        };
        request.send(new FormData(form[0]));
    }

    form.on('submit', function (event) { event.preventDefault(); startUpload(); });
    $('#expense_import_history_status').on('change', function () { dataTable.ajax.reload(null, true); });
    table.on('click', '.expense-import-open-preview', function () {
        window.location.assign(route(config.data('preview-page-template'), $(this).data('batch')));
    });
    table.on('click', '.expense-import-cancel', function () {
        var button = $(this);
        var id = button.data('batch');
        button.prop('disabled', true);
        $.ajax({
            url: route(config.data('cancel-template'), id), method: 'POST', dataType: 'json',
            headers: { Accept: 'application/json' }, data: { _token: csrf }
        }).done(function () { dataTable.ajax.reload(null, false); stopPolling(id); })
            .fail(function (xhr) {
                if (xhr.responseJSON && xhr.responseJSON.status) { dataTable.ajax.reload(null, false); }
                else if (window.toastr) { toastr.error('Batch sudah berjalan atau status belum dapat diperbarui.'); }
            }).always(function () { button.prop('disabled', false); });
    });

    function loadReferences(page, append) {
        if (!referenceUrl) { return; }
        if (referenceRequest && referenceRequest.readyState !== 4) { referenceRequest.abort(); }
        var requestId = ++referenceRequestId;
        var modal = $('#expense_import_references_modal');
        var results = $('#expense_import_reference_results');
        var status = $('#expense_import_references_status');
        if (!append) { results.empty(); }
        status.text('Memuat nomor referensi...');
        $('#expense_import_references_load_more').prop('disabled', true).hide();
        referenceRequest = $.ajax({
            url: referenceUrl, dataType: 'json',
            data: { search: $('#expense_import_reference_search').val() || '', page: page, per_page: 50 },
            headers: { Accept: 'application/json' }
        }).done(function (response) {
            if (requestId !== referenceRequestId || !modal.hasClass('in')) { return; }
            var references = response.data || [];
            references.forEach(function (reference) {
                results.append($('<button>', { type: 'button', class: 'list-group-item expense-import-reference-item' })
                    .text(reference.reference_no || '-').attr('data-show-url', reference.show_url || ''));
            });
            referencePage = Number(response.current_page || page);
            var hasMore = referencePage < Number(response.last_page || 1);
            status.text(Number(response.total || 0) + ' nomor referensi');
            $('#expense_import_references_load_more').toggle(hasMore).prop('disabled', false);
            if (!references.length && !append) {
                results.append($('<p>', { class: 'list-group-item-text text-muted expense-import-reference-empty' }).text('Tidak ada nomor referensi yang cocok.'));
            }
        }).fail(function (xhr, textStatus) {
            if (textStatus === 'abort' || requestId !== referenceRequestId) { return; }
            status.text((xhr.responseJSON && xhr.responseJSON.message) || 'Nomor referensi belum dapat dimuat. Coba lagi.');
            $('#expense_import_references_load_more').prop('disabled', false).hide();
        });
    }

    table.on('click', '.expense-import-open-references', function () {
        referenceUrl = $(this).attr('data-references-url') || '';
        referencePage = 1;
        $('#expense_import_reference_search').val('');
        $('#expense_import_reference_results').empty();
        $('#expense_import_references_status').text('Memuat nomor referensi...');
        $('#expense_import_references_modal').modal('show');
        loadReferences(1, false);
    });
    $('#expense_import_reference_search').on('input', function () {
        window.clearTimeout(referenceSearchTimer);
        referenceSearchTimer = window.setTimeout(function () { loadReferences(1, false); }, 250);
    });
    $('#expense_import_references_load_more').on('click', function () { loadReferences(referencePage + 1, true); });
    $('#expense_import_references_modal').on('click', '.expense-import-reference-item', function () {
        var button = $(this);
        var showUrl = button.attr('data-show-url');
        if (!showUrl) { return; }
        var status = $('#expense_import_references_status');
        button.prop('disabled', true);
        status.text('Memuat detail Expense...');
        $.ajax({ url: showUrl, dataType: 'html', headers: { Accept: 'text/html' } })
            .done(function (html) {
                var modal = $('#expense_import_references_modal');
                modal.one('hidden.bs.modal', function () { $('.payment_modal').html(html).modal('show'); }).modal('hide');
            }).fail(function (xhr) {
                button.prop('disabled', false);
                status.text((xhr.responseJSON && xhr.responseJSON.message) || 'Detail Expense tidak dapat dibuka.');
            });
    });

    $(document).on('click', 'a.revert_import', function (event) {
        event.preventDefault();
        var link = $(this);
        if (window.swal) {
            swal({ title: (window.LANG && LANG.sure) || 'Yakin?', icon: 'warning', buttons: true, dangerMode: true }).then(function (confirmed) {
                if (confirmed) { window.location = link.attr('href'); }
            });
        } else if (window.confirm('Yakin ingin membatalkan import ini?')) {
            window.location = link.attr('href');
        }
    });

    var focusBatch = new URLSearchParams(window.location.search).get('focus_batch');
    if (focusBatch || window.location.hash.indexOf('#expense_import_batch_') === 0) { scrollToHistory(); }
})(window.jQuery);
