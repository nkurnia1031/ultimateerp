(function ($) {
    'use strict';

    var form = $('#expense_import_form');
    var table = $('#expense_import_batches_table');
    var config = $('#expense_import_index_config');
    if (!form.length || !table.length || !config.length) {
        return;
    }

    var timers = {};
    var csrf = form.find('input[name="_token"]').val();

    function route(template, id) {
        return String(template || '').replace('__BATCH__', encodeURIComponent(id));
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    function statusLabel(status) {
        if (status.state === 'queued') { return 'Menunggu antrean'; }
        if (status.state === 'processing') { return status.operation === 'import' ? 'Import berjalan' : 'Preview berjalan'; }
        if (status.state === 'failed') { return 'Gagal'; }
        if (status.state === 'cancelled') { return 'Dibatalkan'; }
        if (status.state === 'completed' && status.domain_status === 'preview') { return 'Preview siap'; }
        if (status.state === 'completed' && status.domain_status === 'imported') { return 'Import selesai'; }
        return status.domain_status || status.state || 'Status belum diketahui';
    }

    function labelClass(status) {
        if (status.state === 'failed') { return 'danger'; }
        if (status.state === 'cancelled') { return 'default'; }
        if (status.domain_status === 'imported' && status.state === 'completed') { return 'success'; }
        if (status.state === 'completed') { return 'info'; }
        return 'warning';
    }

    function rowFor(id) {
        return table.find('[data-expense-import-batch="' + String(id).replace(/[^a-zA-Z0-9_-]/g, '') + '"]');
    }

    function createRow(id, fileName) {
        var row = $('<tr>', { id: 'expense_import_batch_' + id, 'data-expense-import-batch': id });
        row.append('<td data-label="File" data-role="file"></td>');
        row.append('<td data-label="Dibuat" data-role="created"></td>');
        row.append('<td data-label="Status"><span class="label label-warning" data-role="status">Upload diterima</span><div class="small text-muted" data-role="message">Menunggu proses preview.</div></td>');
        row.append('<td data-label="Progress"><div class="progress expense-import-row-progress"><div class="progress-bar progress-bar-success progress-bar-striped active" data-role="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%">0%</div></div><small class="text-muted" data-role="progress-meta">0 / 0</small></td>');
        row.append('<td data-label="Ringkasan"><span data-role="summary">Menyiapkan ringkasan...</span></td>');
        row.append('<td data-label="Aksi"><button type="button" class="btn btn-xs btn-success expense-import-open-preview" data-batch="' + escapeHtml(id) + '" style="display:none"><i class="fa fa-eye"></i> Periksa preview</button><button type="button" class="btn btn-xs btn-warning expense-import-cancel" data-batch="' + escapeHtml(id) + '" style="display:none"><i class="fa fa-ban"></i> Batalkan</button><span class="text-muted expense-import-done" style="display:none">Selesai</span></td>');
        row.find('[data-role="file"]').text(fileName || 'Workbook Expense');
        row.find('[data-role="created"]').text(new Date().toLocaleString('id-ID'));
        table.find('tbody').prepend(row);
        return row;
    }

    function updateRow(id, status) {
        var row = rowFor(id);
        if (!row.length) {
            row = createRow(id, status.file_name || 'Workbook Expense');
        }

        var percent = Math.max(0, Math.min(100, Number(status.percent || 0)));
        var bar = row.find('[data-role="progress"]');
        bar.css('width', percent + '%').attr('aria-valuenow', percent).text(percent + '%');
        bar.toggleClass('active progress-bar-striped', status.state === 'queued' || status.state === 'processing');
        bar.toggleClass('progress-bar-danger', status.state === 'failed');
        bar.toggleClass('progress-bar-success', status.state !== 'failed');

        row.find('[data-role="status"]')
            .removeClass('label-success label-info label-warning label-danger label-default')
            .addClass('label-' + labelClass(status))
            .text(status.stale ? statusLabel(status) + ' · lama tidak diperbarui' : statusLabel(status));
        row.find('[data-role="message"]').text(status.error || status.message || 'Menunggu pembaruan status.');
        var progressMeta = Number(status.current || 0) + ' / ' + Number(status.total || 0);
        if (status.heartbeat_at) {
            var heartbeat = new Date(status.heartbeat_at);
            if (!isNaN(heartbeat.getTime())) { progressMeta += ' · terakhir ' + heartbeat.toLocaleTimeString('id-ID'); }
        }
        row.find('[data-role="progress-meta"]').text(progressMeta);

        var summary = status.summary || {};
        if (summary.total !== undefined || summary.valid !== undefined || summary.errors !== undefined) {
            row.find('[data-role="summary"]').text(
                Number(summary.valid || 0) + ' valid · ' + Number(summary.errors || 0) + ' error · ' + Number(summary.skipped || 0) + ' dilewati'
            );
        }

        var ready = status.domain_status === 'preview' && (status.state === 'completed' || status.state === 'failed');
        row.find('.expense-import-open-preview').toggle(ready);
        row.find('.expense-import-cancel').toggle(status.state === 'queued');
        row.find('.expense-import-done').toggle(status.domain_status === 'imported' || status.domain_status === 'cancelled');
        row.attr('data-process-state', status.state || '');
        return row;
    }

    function scrollToHistory() {
        var element = document.getElementById('expense_import_batches_table');
        if (!element) { return; }
        window.setTimeout(function () {
            var top = element.getBoundingClientRect().top + window.pageYOffset - 24;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }, 50);
    }

    function showUploadProgress(percent, message, detail) {
        var panel = $('#expense_import_upload_status');
        var value = Math.max(0, Math.min(100, Number(percent || 0)));
        $('#expense_import_upload_progress').css('width', value + '%').text(value + '%');
        $('#expense_import_upload_message').text(message || 'Mengunggah workbook...');
        $('#expense_import_upload_detail').text(detail || 'Progres preview akan muncul pada baris batch di tabel ini.');
        panel.show();
    }

    function stopPolling(id) {
        if (timers[id]) {
            window.clearTimeout(timers[id]);
            delete timers[id];
        }
    }

    function pollBatch(id, delay) {
        stopPolling(id);
        $.ajax({
            url: route(config.data('status-template'), id),
            dataType: 'json',
            headers: { Accept: 'application/json' }
        }).done(function (status) {
            var row = updateRow(id, status);
            if (status.state === 'queued' || status.state === 'processing' || status.stale) {
                timers[id] = window.setTimeout(function () { pollBatch(id, status.stale ? 5000 : 2500); }, status.stale ? 5000 : 2500);
            }
            if (window.location.hash === '#expense_import_batch_' + id) {
                row.addClass('info');
            }
        }).fail(function () {
            var row = rowFor(id);
            if (row.length) { row.find('[data-role="message"]').text('Status belum dapat dibaca. Sistem akan mencoba kembali.'); }
            timers[id] = window.setTimeout(function () { pollBatch(id, 5000); }, 5000);
        });
    }

    function startUpload() {
        var fileInput = document.getElementById('expense_import_file');
        if (!fileInput || !fileInput.files || !fileInput.files.length) { return; }

        var file = fileInput.files[0];
        var request = new XMLHttpRequest();
        var data = new FormData(form[0]);
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
                var row = createRow(response.batch_id, file.name);
                row.attr('id', 'expense_import_batch_' + response.batch_id);
                showUploadProgress(100, 'Upload diterima.', 'Preview berjalan di latar belakang. Progres selanjutnya terlihat pada baris workbook di bawah.');
                window.history.replaceState(null, '', window.location.pathname + window.location.search + '#expense_import_batch_' + response.batch_id);
                scrollToHistory();
                pollBatch(response.batch_id, 0);
                form[0].reset();
                return;
            }

            var message = (response.errors && response.errors.expense_csv && response.errors.expense_csv[0])
                || response.message
                || 'Upload gagal. Periksa file lalu coba lagi.';
            $('#expense_import_upload_status').removeClass('alert-info').addClass('alert-danger');
            showUploadProgress(0, message, 'Upload belum diterima.');
        };
        request.send(data);
    }

    form.on('submit', function (event) {
        event.preventDefault();
        startUpload();
    });

    table.on('click', '.expense-import-open-preview', function () {
        window.location.assign(route(config.data('preview-page-template'), $(this).data('batch')));
    });

    table.on('click', '.expense-import-cancel', function () {
        var button = $(this);
        var id = button.data('batch');
        button.prop('disabled', true);
        $.ajax({
            url: route(config.data('cancel-template'), id),
            method: 'POST',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: { _token: csrf }
        }).done(function (response) {
            if (response.status) { updateRow(id, response.status); }
        }).fail(function (xhr) {
            var status = xhr.responseJSON && xhr.responseJSON.status;
            if (status) { updateRow(id, status); }
            else if (window.toastr) { toastr.error('Batch sudah berjalan atau status belum dapat diperbarui.'); }
        }).always(function () {
            button.prop('disabled', false);
        });
    });

    table.find('[data-expense-import-batch]').each(function () {
        var row = $(this);
        if (row.attr('data-process-state') === 'queued' || row.attr('data-process-state') === 'processing') {
            pollBatch(row.data('expense-import-batch'), 0);
        }
    });

    var focusBatch = new URLSearchParams(window.location.search).get('focus_batch');
    if (focusBatch) {
        scrollToHistory();
    } else if (window.location.hash.indexOf('#expense_import_batch_') === 0) {
        scrollToHistory();
    }

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
})(window.jQuery);
