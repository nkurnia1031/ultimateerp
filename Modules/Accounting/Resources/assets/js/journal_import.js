(function ($) {
    'use strict';

    var page = $('#journal_import_page');
    var statusBase = String(page.data('status-url-base') || '').replace(/\/$/, '');
    var watchers = {};

    function statusUrl(importId) {
        return statusBase + '/' + encodeURIComponent(importId) + '/status';
    }

    function isActive(status) {
        return ['queued', 'analyzing', 'queued_import', 'importing', 'reconciling', 'processing'].indexOf(status) !== -1;
    }

    function statusLabel(status) {
        var labels = {
            queued: 'Menunggu worker',
            analyzing: 'Menganalisis workbook',
            analyzed: 'Siap ditinjau',
            queued_import: 'Menunggu worker import',
            importing: 'Menyimpan entry',
            reconciling: 'Merekonsiliasi pajak',
            processing: 'Memproses',
            imported: 'Selesai',
            failed: 'Gagal'
        };

        return labels[status] || status;
    }

    function updatedAtText(value) {
        if (!value) {
            return 'belum tersedia';
        }

        var date = new Date(String(value).replace(' ', 'T'));
        return isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    function progressPercent(response) {
        var percent = response.total_entries > 0
            ? Math.round((response.processed_entries / response.total_entries) * 100)
            : 0;

        percent = Math.max(0, Math.min(100, percent));
        if (response.status === 'reconciling') {
            percent = Math.min(95, percent);
        } else if (response.status === 'imported') {
            percent = 100;
        }

        return percent;
    }

    function indeterminate(response) {
        return ['queued', 'analyzing', 'queued_import'].indexOf(response.status) !== -1 ||
            (response.status === 'importing' && response.total_entries <= 0);
    }

    function actionForStatus(actionCell, response) {
        if (isActive(response.status)) {
            actionCell.empty().append($('<span class="text-muted">').text(statusLabel(response.status) + '...'));
        } else if (response.status === 'analyzed') {
            actionCell.empty().append($('<a class="btn btn-primary btn-xs">')
                .attr('href', actionCell.data('review-url'))
                .text('Review & Import'));
        } else if (response.status === 'imported') {
            var form = $('<form>', {
                action: actionCell.data('revert-url'),
                method: 'POST',
                style: 'display:inline;'
            });
            form.append($('<input>', {
                type: 'hidden',
                name: '_token',
                value: actionCell.data('csrf-token')
            }));
            form.append($('<button type="submit" class="btn btn-danger btn-xs">').text('Revert'));
            form.on('submit', function (event) {
                if (!window.confirm('Revert import ini? Journal yang dibuat dari batch ini akan dihapus.')) {
                    event.preventDefault();
                }
            });
            actionCell.empty().append(form);
        } else if (response.status === 'failed') {
            actionCell.empty().append($('<span class="text-danger">').text('Gagal'));
        }
    }

    function updateRow(response) {
        var id = String(response.id);
        var status = response.status || 'queued';
        var statusElement = $('#journal-import-status-' + id);
        var progressElement = $('#journal-import-progress-' + id);
        var percent = progressPercent(response);
        var statusClass = status === 'imported' ? 'bg-green' : (status === 'failed' ? 'bg-red' : (status === 'analyzed' ? 'bg-aqua' : 'bg-yellow'));

        statusElement.text(statusLabel(status)).attr('class', 'label ' + statusClass);

        if (indeterminate(response)) {
            progressElement.css('width', '100%').text('').attr('aria-valuenow', 0);
        } else {
            progressElement.css('width', percent + '%').text(percent + '%').attr('aria-valuenow', percent);
        }

        var countText;
        if (['importing', 'reconciling', 'processing', 'imported'].indexOf(status) !== -1) {
            countText = response.processed_entries + ' / ' + response.total_entries + ' entry (' + response.imported_entries + ' dibuat, ' + response.skipped_entries + ' dilewati)';
        } else if (status === 'analyzed') {
            countText = response.total_entries + ' entry siap ditinjau';
        } else if (response.total_entries > 0) {
            countText = '0 / ' + response.total_entries + ' entry';
        } else {
            countText = 'Menunggu checkpoint worker';
        }

        $('#journal-import-count-' + id).text(countText);
        $('#journal-import-message-' + id)
            .removeClass('text-danger')
            .addClass('text-muted')
            .text(response.last_message || 'Menunggu update worker.');
        $('#journal-import-heartbeat-' + id).text(updatedAtText(response.updated_at));

        var updatedAt = response.updated_at ? new Date(String(response.updated_at).replace(' ', 'T')) : null;
        var age = updatedAt && !isNaN(updatedAt.getTime())
            ? Math.max(0, Math.floor((Date.now() - updatedAt.getTime()) / 1000))
            : null;
        var staleElement = $('#journal-import-stale-' + id);
        if (isActive(status) && age !== null && age >= 120) {
            staleElement.removeClass('hide').text(status === 'queued' || status === 'queued_import'
                ? 'Belum diambil worker selama ' + age + ' detik. Pastikan worker queue imports sedang berjalan.'
                : 'Tidak ada checkpoint selama ' + age + ' detik. Periksa worker queue dan laravel.log.');
        } else {
            staleElement.addClass('hide').text('');
        }

        if (status === 'failed') {
            $('#journal-import-message-' + id).removeClass('text-muted').addClass('text-danger')
                .text(response.error_message || response.last_message || 'Import gagal.');
        }

        actionForStatus($('#journal-import-action-' + id), response);
    }

    function appendProgressLog(message) {
        if (!message) {
            return;
        }

        var list = $('#journal_import_progress_log');
        if (list.length === 0 || list.children().first().data('message') === message) {
            return;
        }

        list.prepend($('<li>').text(new Date().toLocaleTimeString() + ' · ' + message).data('message', message));
        while (list.children().length > 10) {
            list.children().last().remove();
        }
    }

    function updateProgressPanel(response, message) {
        var progressBar = $('#journal_import_progress_bar');
        var percent = progressPercent(response);
        progressBar.css('width', indeterminate(response) ? '100%' : percent + '%')
            .text(indeterminate(response) ? '' : percent + '%');

        $('#journal_import_progress_stage').text(statusLabel(response.status));
        $('#journal_import_progress_count').text(response.total_entries > 0
            ? ' · ' + response.processed_entries + ' / ' + response.total_entries + ' entry (' + response.imported_entries + ' dibuat, ' + response.skipped_entries + ' dilewati)'
            : '');
        $('#journal_import_progress_heartbeat').text(updatedAtText(response.updated_at));

        var updatedAt = response.updated_at ? new Date(String(response.updated_at).replace(' ', 'T')) : null;
        var age = updatedAt && !isNaN(updatedAt.getTime())
            ? Math.max(0, Math.floor((Date.now() - updatedAt.getTime()) / 1000))
            : null;
        if (isActive(response.status) && age !== null && age >= 120) {
            $('#journal_import_progress_stale').removeClass('hide').text(response.status === 'queued' || response.status === 'queued_import'
                ? 'Belum diambil worker selama ' + age + ' detik. Pastikan worker queue imports sedang berjalan.'
                : 'Tidak ada checkpoint selama ' + age + ' detik. Periksa worker queue dan laravel.log.');
        } else {
            $('#journal_import_progress_stale').addClass('hide').text('');
        }

        $('#journal_import_progress_text').text(message || response.last_message || 'Menunggu update worker.');
        appendProgressLog(message || response.last_message);

        if (response.status === 'failed') {
            $('#journal_import_progress_error').removeClass('hide')
                .text(response.error_message || 'Import gagal. Periksa detail batch dan laravel.log.');
        }
    }

    function pollWatch(importId) {
        var id = String(importId);
        if (watchers[id]) {
            return;
        }
        watchers[id] = true;

        function poll() {
            $.ajax({
                method: 'GET',
                url: statusUrl(id),
                dataType: 'json'
            }).done(function (response) {
                if (!response.success) {
                    $('#journal-import-message-' + id).text(response.msg || 'Status import tidak tersedia.');
                    window.setTimeout(poll, 5000);
                    return;
                }

                updateRow(response);
                if (isActive(response.status)) {
                    window.setTimeout(poll, 2000);
                } else {
                    delete watchers[id];
                }
            }).fail(function () {
                $('#journal-import-message-' + id).text('Gagal mengambil update status; mencoba lagi.');
                window.setTimeout(poll, 5000);
            });
        }

        poll();
    }

    function pollForm(importId, submitButton) {
        var id = String(importId);

        function poll() {
            $.ajax({
                method: 'GET',
                url: statusUrl(id),
                dataType: 'json'
            }).done(function (response) {
                if (!response.success) {
                    $('#journal_import_progress_text').text(response.msg || 'Status import tidak tersedia.');
                    window.setTimeout(poll, 5000);
                    return;
                }

                updateRow(response);
                updateProgressPanel(response);

                if (response.status === 'imported') {
                    submitButton.prop('disabled', true).text('Import selesai');
                    toastr.success('Import journal selesai.');
                } else if (response.status === 'failed') {
                    submitButton.prop('disabled', true).text('Import gagal');
                    toastr.error(response.error_message || 'Import gagal. Periksa detail batch.');
                } else {
                    window.setTimeout(poll, 2000);
                }
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.msg
                    ? xhr.responseJSON.msg
                    : 'Gagal mengambil update status; mencoba lagi.';
                $('#journal_import_progress_text').text(message);
                appendProgressLog(message);
                window.setTimeout(poll, 5000);
            });
        }

        poll();
    }

    $(document).on('submit', '#journal_import_form', function (event) {
        event.preventDefault();

        if (!window.confirm('Import journal dari file ini sekarang?')) {
            return false;
        }

        var form = $(this);
        var submitButton = form.find('button[type="submit"]');
        submitButton.prop('disabled', true);
        $('#journal_import_progress_box').removeClass('hide');
        $('#journal_import_progress_stale, #journal_import_progress_error').addClass('hide').text('');
        $('#journal_import_progress_log').empty();
        $('#journal_import_progress_stage').text('Menyiapkan import');
        $('#journal_import_progress_count').text('');
        $('#journal_import_progress_text').text('Mengirim permintaan ke worker queue imports.');
        appendProgressLog('Mengirim permintaan ke worker queue imports.');

        $.ajax({
            method: 'POST',
            url: form.attr('action'),
            data: form.serialize(),
            dataType: 'json'
        }).done(function (response) {
            if (response.success) {
                pollForm(response.import_id, submitButton);
            } else {
                submitButton.prop('disabled', false);
                toastr.error(response.msg || 'Import tidak dapat dimulai.');
            }
        }).fail(function (xhr) {
            submitButton.prop('disabled', false);
            toastr.error(xhr.responseJSON && xhr.responseJSON.msg
                ? xhr.responseJSON.msg
                : 'Import tidak dapat dimulai. Periksa input dan coba lagi.');
        });
    });

    $(document).on('click', '.journal-import-watch', function () {
        var button = $(this);
        button.prop('disabled', true).text('Memantau...');
        pollWatch(button.data('id'));
    });

    $(function () {
        var button = $('.journal-import-watch').first();
        if (button.length) {
            button.prop('disabled', true).text('Memantau...');
            pollWatch(button.data('id'));
        }
    });
})(jQuery);
