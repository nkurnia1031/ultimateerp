(function ($) {
    'use strict';

    var page = $('#journal_import_page');
    var statusBase = String(page.data('status-url-base') || '').replace(/\/$/, '');
    var activeStatuses = String(page.data('active-statuses') || '').split(',').filter(Boolean);
    var waitingStatuses = String(page.data('waiting-statuses') || '').split(',').filter(Boolean);
    var queuedStatuses = String(page.data('queued-statuses') || '').split(',').filter(Boolean);
    var watchers = {};
    var historyTable = null;

    function statusUrl(importId) {
        return statusBase + '/' + encodeURIComponent(importId) + '/status';
    }

    function isActive(status) {
        return activeStatuses.indexOf(status) !== -1;
    }

    function isQueued(status) {
        return queuedStatuses.indexOf(status) !== -1;
    }

    function statusLabel(status) {
        var labels = {
            queued: 'Menunggu worker',
            analyzing: 'Menganalisis workbook',
            workbook_analysis: 'Menganalisis workbook',
            source_matching: 'Mencocokkan transaksi sumber',
            analyzed: 'Siap ditinjau',
            preparing_import: 'Menyiapkan antrean import',
            queued_import: 'Menunggu worker import',
            importing: 'Menyimpan entry',
            tax_reconciliation: 'Merekonsiliasi pajak',
            reconciling: 'Merekonsiliasi pajak',
            processing: 'Memproses',
            imported: 'Selesai',
            partially_imported: 'Sebagian selesai',
            failed: 'Gagal',
            cancelled: 'Dihentikan'
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

    function phaseProgressText(response) {
        var progress = response.phase_progress || null;
        if (!progress || typeof progress !== 'object') {
            return '';
        }

        var total = Number(progress.total || 0);
        var unit = progress.unit || 'baris';
        var text = total > 0
            ? Number(progress.processed || 0) + ' / ' + total + ' ' + unit
            : 'Menyiapkan total ' + unit;
        var counts = progress.counts || {};
        var labels = [
            ['unprocessed', 'belum diperiksa'],
            ['queued', 'siap'],
            ['pending', 'perlu ditinjau'],
            ['completed', 'selesai'],
            ['failed', 'gagal'],
            ['running', 'sedang diproses'],
            ['reconciled', 'sesuai'],
            ['review', 'perlu ditinjau pajak'],
            ['not_applicable', 'tidak berlaku']
        ];
        var parts = [];
        labels.forEach(function (mapping) {
            if (counts[mapping[0]] !== undefined) {
                parts.push(Number(counts[mapping[0]]) + ' ' + mapping[1]);
            }
        });
        if (parts.length) {
            text += ' (' + parts.join(', ') + ')';
        }

        return text;
    }

    function progressPercent(response) {
        var phaseProgress = response.phase_progress || null;
        if (phaseProgress && typeof phaseProgress === 'object') {
            var phaseTotal = Number(phaseProgress.total || 0);
            var phaseProcessed = Number(phaseProgress.processed || 0);
            if (phaseTotal <= 0) {
                return 0;
            }
            return Math.max(0, Math.min(100, Math.round((phaseProcessed / phaseTotal) * 100)));
        }

        var queueTotal = Number(response.queue_total || 0);
        var percent = queueTotal > 0
            ? Math.round((Number(response.queue_processed || 0) / queueTotal) * 100)
            : (response.total_entries > 0
                ? Math.round((response.processed_entries / response.total_entries) * 100)
                : 0);

        percent = Math.max(0, Math.min(100, percent));
        if (response.status === 'reconciling') {
            percent = Math.min(95, percent);
        } else if (response.status === 'imported') {
            percent = 100;
        }

        return percent;
    }

    function indeterminate(response) {
        if (response.phase_progress && typeof response.phase_progress === 'object') {
            return Number(response.phase_progress.total || 0) <= 0;
        }
        return (waitingStatuses.indexOf(response.status) !== -1 && Number(response.queue_total || 0) <= 0) ||
            (response.status === 'importing' && Number(response.queue_total || response.total_entries || 0) <= 0);
    }

    function actionForStatus(actionCell, response) {
        var detailButton = $('<button type="button" class="btn btn-info btn-xs journal-import-detail">')
            .attr('data-id', response.id)
            .text('Detail');
        var restoreUrl = actionCell.data('restore-url');
        var canRevert = String(actionCell.attr('data-can-revert') || '0') === '1';

        actionCell.empty().append(detailButton);
        if (restoreUrl) {
            actionCell.append($('<a class="btn btn-success btn-xs" target="_blank" rel="noopener">')
                .attr('href', restoreUrl)
                .text('Unduh workbook'));
        }

        if (isActive(response.status)) {
            actionCell.append($('<button type="button" class="btn btn-default btn-xs journal-import-watch">')
                .attr('data-id', response.id)
                .text('Pantau progres'));
            if (response.cancel_requested) {
                actionCell.append($('<button type="button" class="btn btn-default btn-xs journal-import-terminate" disabled>')
                    .attr('data-id', response.id)
                    .text('Menunggu berhenti'));
            } else {
                actionCell.append($('<button type="button" class="btn btn-warning btn-xs journal-import-terminate">')
                    .attr('data-id', response.id)
                    .text('Terminate'));
            }
        } else if (['analyzed', 'partially_imported', 'failed', 'cancelled'].indexOf(response.status) !== -1) {
            actionCell.append($('<a class="btn btn-primary btn-xs">')
                .attr('href', actionCell.data('review-url'))
                .text(response.status === 'analyzed' ? 'Review & Import' : (response.status === 'cancelled' ? 'Lanjutkan import' : 'Review & Continue')));
        } else if (response.status === 'imported' && canRevert) {
            var form = $('<form>', {
                action: actionCell.data('revert-url'),
                method: 'POST',
                'class': 'journal-import-revert',
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
            actionCell.append(form);
        }
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function historyStatusCell(row, type) {
        if (type !== 'display') {
            return row.domain_status || row.status || '';
        }

        var id = Number(row.domain_batch_id || 0);
        var status = String(row.domain_status || row.status || '');
        var active = isActive(status);
        var phase = row.phase_progress || {};
        var total = Number(phase.total || row.total_entries || 0);
        var current = Number(phase.processed || row.processed_entries || 0);
        var percent = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
        var badgeClass = status === 'imported' ? 'bg-green' : (status === 'failed' ? 'bg-red' : (status === 'cancelled' || status === 'reverted' ? 'bg-gray' : (status === 'analyzed' || status === 'partially_imported' ? 'bg-aqua' : 'bg-yellow')));
        var html = '<span class="label ' + badgeClass + '" id="journal-import-status-' + id + '">' + escapeHtml(statusLabel(status)) + '</span>';
        html += '<div class="progress" style="margin:5px 0 2px; min-width:95px;">'
            + '<div id="journal-import-progress-' + id + '" class="progress-bar' + (active ? ' progress-bar-striped active' : '') + '" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100" style="width:' + (active && total <= 0 ? 100 : percent) + '%">'
            + (active && total <= 0 ? '' : percent + '%') + '</div></div>';
        html += '<small id="journal-import-count-' + id + '" class="text-muted">'
            + escapeHtml(total > 0 ? current + ' / ' + total + ' ' + (phase.unit || 'entry') : (active ? 'Menunggu checkpoint worker' : ''))
            + '</small><br><small id="journal-import-message-' + id + '" class="' + (status === 'failed' ? 'text-danger' : 'text-muted') + '">'
            + escapeHtml(row.error_message || row.last_message || '') + '</small>';
        if (active) {
            html += '<br><small class="text-muted">Terakhir update: <span id="journal-import-heartbeat-' + id + '">' + escapeHtml(updatedAtText(row.updated_at)) + '</span></small>';
            html += '<br><small id="journal-import-stale-' + id + '" class="text-warning hide"></small>';
        }

        return html;
    }

    function historyActionsCell(row, type) {
        if (type !== 'display') {
            return '';
        }

        var id = Number(row.domain_batch_id || 0);
        var actionCell = $('<div class="journal-import-history-actions">')
            .attr('id', 'journal-import-action-' + id)
            .attr('data-review-url', row.review_url || '')
            .attr('data-revert-url', row.revert_url || '')
            .attr('data-terminate-url', row.terminate_url || '')
            .attr('data-detail-url', row.detail_url || '')
            .attr('data-restore-url', row.restore_url || '')
            .attr('data-file-name', row.file_name || '')
            .attr('data-can-revert', row.can_revert_permission ? '1' : '0')
            .attr('data-csrf-token', page.data('csrf-token') || '');

        actionForStatus(actionCell, {
            id: id,
            status: row.domain_status || row.status,
            cancel_requested: !!row.cancel_requested
        });

        return actionCell[0].outerHTML;
    }

    function renderHistoryDetail(detail) {
        var body = $('#journal_import_history_detail_body').empty();
        body.append($('<h4>').text(detail.file_name || ('Journal import #' + detail.domain_batch_id)));
        body.append($('<p>').text('Status: ' + (detail.domain_status || detail.status || '-') + ' · Periode: ' + (detail.source_date_range || '-')));
        if (detail.phase_progress) {
            var phase = detail.phase_progress;
            body.append($('<p>').text('Tahap: ' + (phase.phase || detail.phase || '-') + ' · ' + Number(phase.processed || 0) + ' / ' + Number(phase.total || 0) + ' ' + (phase.unit || 'baris')));
        }

        var outcomes = detail.row_outcomes || {};
        var outcomeList = $('<ul class="list-inline">');
        Object.keys(outcomes).sort().forEach(function (status) {
            outcomeList.append($('<li>').text(status + ': ' + Number(outcomes[status] || 0)));
        });
        body.append($('<h5>').text('Hasil baris')).append(outcomeList);
        if (detail.last_message) {
            body.append($('<p class="text-muted">').text(detail.last_message));
        }
        if (detail.error_message) {
            body.append($('<div class="alert alert-danger">').text(detail.error_message));
        }

        if (Array.isArray(detail.problem_rows) && detail.problem_rows.length) {
            var table = $('<div class="table-responsive"><table class="table table-condensed table-bordered"><thead><tr><th>Baris</th><th>Jenis</th><th>Referensi</th><th>Status</th><th>Catatan</th></tr></thead><tbody></tbody></table></div>');
            detail.problem_rows.forEach(function (row) {
                var message = row.error || row.message || '';
                table.find('tbody').append($('<tr>')
                    .append($('<td>').text(row.entry_index))
                    .append($('<td>').text(row.source_type || ''))
                    .append($('<td>').text(row.source_number || ''))
                    .append($('<td>').text(row.status || ''))
                    .append($('<td>').text(message)));
            });
            body.append(table);
            if (detail.problem_rows.length >= 100) {
                body.append($('<p class="help-block">').text('Maksimal 100 baris bermasalah ditampilkan. Buka Review untuk melihat seluruh draft.'));
            }
        }

        var links = $('<div class="journal-import-history-detail-links">');
        if (detail.review_url) {
            links.append($('<a class="btn btn-primary btn-sm">').attr('href', detail.review_url).text('Buka Review Journal'));
        }
        if (detail.restore_url) {
            links.append($('<a class="btn btn-success btn-sm" target="_blank" rel="noopener">').attr('href', detail.restore_url).text('Unduh workbook'));
        }
        body.append(links);
    }

    function updateRow(response) {
        var id = String(response.id);
        var status = response.status || 'queued';
        var statusElement = $('#journal-import-status-' + id);
        var progressElement = $('#journal-import-progress-' + id);
        var percent = progressPercent(response);
        var statusClass = status === 'imported' ? 'bg-green' : (status === 'failed' ? 'bg-red' : (status === 'cancelled' ? 'bg-gray' : (['analyzed', 'partially_imported'].indexOf(status) !== -1 ? 'bg-aqua' : 'bg-yellow')));

        statusElement.text(statusLabel(status)).attr('class', 'label ' + statusClass);

        if (indeterminate(response)) {
            progressElement.css('width', '100%').text('').attr('aria-valuenow', 0);
        } else {
            progressElement.css('width', percent + '%').text(percent + '%').attr('aria-valuenow', percent);
        }

        var countText = phaseProgressText(response);
        if (!countText && (Number(response.queue_total || 0) > 0 || ['preparing_import', 'importing', 'reconciling', 'processing', 'imported', 'partially_imported', 'failed', 'cancelled'].indexOf(status) !== -1)) {
            var queueCounts = response.queue_counts || {};
            countText = (response.queue_processed || 0) + ' / ' + (response.queue_total || 0) + ' baris queue ('
                + (queueCounts.completed || 0) + ' selesai, ' + (queueCounts.failed || 0) + ' gagal, '
                + (queueCounts.pending || 0) + ' pending)';
        } else if (!countText && status === 'analyzed') {
            countText = response.total_entries + ' entry siap ditinjau';
        } else if (!countText && response.total_entries > 0) {
            countText = '0 / ' + response.total_entries + ' entry';
        } else if (!countText) {
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
            staleElement.removeClass('hide').text(isQueued(status)
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

        var queueCounts = response.queue_counts || {};
        var activeProgressText = phaseProgressText(response);
        var progressCount = activeProgressText
            ? ' · ' + activeProgressText
            : Number(response.queue_total || 0) > 0
            ? ' · ' + response.queue_processed + ' / ' + response.queue_total + ' baris queue ('
                + (queueCounts.completed || 0) + ' selesai, ' + (queueCounts.failed || 0) + ' gagal)'
            : (response.total_entries > 0 ? ' · ' + response.processed_entries + ' / ' + response.total_entries + ' entry' : '');
        $('#journal_import_progress_stage').text(statusLabel(response.progress_phase || response.status));
        $('#journal_import_progress_count').text(progressCount);
        $('#journal_import_progress_heartbeat').text(updatedAtText(response.updated_at));

        var updatedAt = response.updated_at ? new Date(String(response.updated_at).replace(' ', 'T')) : null;
        var age = updatedAt && !isNaN(updatedAt.getTime())
            ? Math.max(0, Math.floor((Date.now() - updatedAt.getTime()) / 1000))
            : null;
        if (isActive(response.status) && age !== null && age >= 120) {
            $('#journal_import_progress_stale').removeClass('hide').text(isQueued(response.status)
                ? 'Belum diambil worker selama ' + age + ' detik. Pastikan worker queue imports sedang berjalan.'
                : 'Tidak ada checkpoint selama ' + age + ' detik. Periksa worker queue dan laravel.log.');
        } else {
            $('#journal_import_progress_stale').addClass('hide').text('');
        }

        $('#journal_import_progress_text').text(message || response.last_message || 'Menunggu update worker.');
        appendProgressLog(message || response.last_message);

        var terminateButton = $('#journal_import_terminate');
        terminateButton.attr('data-id', response.id);
        if (isActive(response.status)) {
            terminateButton.removeClass('hide')
                .prop('disabled', !!response.cancel_requested)
                .html(response.cancel_requested
                    ? '<i class="fas fa-hourglass-half"></i> Menunggu baris berjalan selesai'
                    : '<i class="fas fa-stop"></i> Hentikan proses dengan aman');
        } else {
            terminateButton.addClass('hide').prop('disabled', false);
        }

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
                    if (historyTable) {
                        historyTable.ajax.reload(null, false);
                    }
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
                } else if (response.status === 'partially_imported') {
                    submitButton.prop('disabled', false).text('Lanjutkan draft');
                    toastr.info(response.last_message || 'Sebagian baris masih menunggu kelengkapan atau handler.');
                } else if (response.status === 'failed') {
                    submitButton.prop('disabled', false).text('Coba lagi setelah diperbaiki');
                    toastr.error(response.error_message || 'Import gagal. Periksa detail batch.');
                } else if (response.status === 'cancelled') {
                    submitButton.prop('disabled', false).text('Lanjutkan import');
                    $('#journal_import_progress_text').text(response.last_message || 'Import dihentikan dengan aman. Draft dapat dilanjutkan dari web.');
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

    var draftPage = 1;
    var draftLastPage = 1;
    var draftTotal = 0;
    var draftPerPage = 50;
    var draftType = null;
    var draftRowsById = {};
    var activeDetailRow = null;
    var detailSaveTimer = null;
    var detailSaveInFlight = false;
    var detailSavePending = null;
    var draftSearchTimer = null;
    var draftRequestNumber = 0;
    var sourceGroupPreview = null;
    var cohortMappingPreview = null;
    var reviewGroupContext = null;
    var reviewGroupKey = null;
    var reviewImportPollers = {};
    var reviewImportPollers = {};

    function draftActionLabel(action) {
        var labels = {
            journal: 'Journal Entry (GL)',
            create: 'Buat baru',
            update: 'Lengkapi sumber',
            link: 'Tautkan sumber',
            review: 'Perlu ditinjau'
        };

        return labels[action] || 'Perlu ditinjau';
    }

    function draftActionOptions(row) {
        var actions;
        if (row.source_type === 'Journal Entry') {
            actions = ['journal'];
        } else if (row.source_link_status === 'matched') {
            actions = ['update', 'link', 'review'];
        } else if (row.source_link_status === 'ambiguous' || row.source_link_status === 'suggested') {
            actions = ['update', 'link', 'create', 'review'];
        } else {
            actions = ['create', 'review'];
        }

        return actions;
    }

    function formatDraftNumber(value) {
        var number = Number(value || 0);
        return isFinite(number) ? number.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
    }

    function candidatesForRow(row) {
        var candidates = Array.isArray(row.source_link_candidate_details) ? row.source_link_candidate_details.slice() : [];
        if (!candidates.length && row.source_link_status === 'matched' && row.source_record_id) {
            candidates.push({
                id: row.source_record_id,
                record_type: row.source_record_type,
                label: row.source_link_label || ('Sumber #' + row.source_record_id)
            });
        }

        return candidates;
    }

    function candidateAmount(value) {
        return value === null || value === undefined || value === '' ? '—' : formatDraftNumber(value);
    }

    function appendCandidateField(container, label, value) {
        if (value === null || value === undefined || String(value).trim() === '') return;
        container.append($('<p class="small">').append($('<strong>').text(label + ': ')).append(document.createTextNode(String(value))));
    }

    function appendSourceGroupSuggestions(container, row, blocked) {
        var groups = Array.isArray(row.source_link_group_suggestions) ? row.source_link_group_suggestions : [];
        if (row.source_link_group_suggestion_status === 'limited') {
            container.append($('<h4>').text('Pencarian grup sumber dibatasi'));
            container.append($('<p class="text-warning">').text('Jumlah kandidat terlalu besar untuk dipastikan otomatis. Pilih sumber secara manual.'));
            return;
        }
        if (!groups.length) return;

        container.append($('<h4>').text(groups.length > 1 ? 'Beberapa grup exact ditemukan' : 'Saran grup split exact'));

        groups.forEach(function (group, index) {
            var panel = $('<div class="panel panel-info">');
            var body = $('<div class="panel-body">');
            body.append($('<p>').text('Grup ' + (index + 1) + ': ' + group.members.length + ' transaksi sumber; total ' + formatDraftNumber(group.total_amount)
                + ' (basis ' + formatDraftNumber(group.base_amount) + ', pajak ' + formatDraftNumber(group.tax_amount) + ').'));
            var table = $('<table class="table table-condensed table-bordered" style="margin-bottom:8px">');
            table.append($('<thead>').append($('<tr>')
                .append($('<th>').text('Transaksi sumber'))
                .append($('<th>').text('Tanggal / referensi'))
                .append($('<th class="text-right">').text('Basis'))
                .append($('<th class="text-right">').text('Pajak'))
                .append($('<th class="text-right">').text('Total'))));
            var rows = $('<tbody>');
            (group.members || []).forEach(function (member) {
                rows.append($('<tr>')
                    .append($('<td>').text(member.label || ('Transaksi #' + member.id)))
                    .append($('<td>').text((member.date || '—') + ' · ' + (member.references || []).join(', ')))
                    .append($('<td class="text-right">').text(formatDraftNumber(member.base_amount)))
                    .append($('<td class="text-right">').text(formatDraftNumber(member.tax_amount)))
                    .append($('<td class="text-right">').text(formatDraftNumber(member.amount))));
            });
            table.append(rows);
            body.append(table);
            body.append($('<p class="small text-muted">').text('Journal: basis ' + formatDraftNumber(group.journal_base_amount)
                + ', pajak ' + formatDraftNumber(group.journal_tax_amount) + ', total ' + formatDraftNumber(group.journal_total_amount) + '.'));
            body.append($('<button type="button" class="btn btn-primary btn-sm journal-draft-source-group-select">')
                .data('member-ids', group.member_ids || [])
                .text('Pilih grup ini')
                .prop('disabled', blocked));
            panel.append(body);
            container.append(panel);
        });
    }

    function sourceGroupPreviewSummary(response) {
        var summary = response.summary || {};
        var table = $('<table class="table table-bordered table-condensed">');
        table.append($('<thead>').append($('<tr>')
            .append($('<th>').text('Komponen'))
            .append($('<th class="text-right">').text('Transaksi sumber'))
            .append($('<th class="text-right">').text('Journal'))));
        var body = $('<tbody>');
        [
            ['Basis', summary.source_base, summary.journal_base],
            ['Pajak', summary.source_tax, summary.journal_tax],
            ['Total', summary.source_total, summary.journal_total]
        ].forEach(function (item) {
            body.append($('<tr>')
                .append($('<td>').text(item[0]))
                .append($('<td class="text-right">').text(formatDraftNumber(item[1])))
                .append($('<td class="text-right">').text(formatDraftNumber(item[2]))));
        });
        table.append(body);

        return table;
    }

    function openSourceCandidates(row) {
        var candidates = candidatesForRow(row);
        var rowElement = $('#journal_import_draft_table tbody tr').filter(function () {
            return String($(this).data('row-id')) === String(row.id);
        });
        var blocked = ['queued', 'running', 'completed'].indexOf(row.processing_status) !== -1;
        var journalBox = $('#journal_import_candidates_journal').empty();
        var list = $('#journal_import_candidates_list').empty();
        $('#journal_import_candidates_summary').text(
            row.source_type + ' #' + (row.source_number || '—') + ' · ' + (row.date || 'Tanggal kosong')
                + ' · Total Journal ' + formatDraftNumber(row.debit_total)
        );

        var journalTable = $('<table class="table table-condensed table-bordered" style="margin-bottom:0">');
        journalTable.append($('<thead>').append($('<tr>')
            .append($('<th>').text('Komponen Journal'))
            .append($('<th class="text-right">').text('Debit'))
            .append($('<th class="text-right">').text('Kredit'))));
        var journalBody = $('<tbody>');
        (Array.isArray(row.journal_components) ? row.journal_components : []).forEach(function (component) {
            var account = [component.account_no, component.account_name].filter(Boolean).join(' · ') || 'Akun tanpa nama';
            journalBody.append($('<tr>')
                .append($('<td>').text((component.is_tax ? 'Pajak · ' : 'Basis · ') + account))
                .append($('<td class="text-right">').text(formatDraftNumber(component.debit)))
                .append($('<td class="text-right">').text(formatDraftNumber(component.credit))));
        });
        journalTable.append(journalBody);
        journalBox.append(journalTable);

        $('#journal_import_candidates_empty').toggleClass('hide', candidates.length > 0);
        $('.journal-draft-candidate-create').toggle(row.source_link_status !== 'matched').prop('disabled', blocked);
        appendSourceGroupSuggestions(list, row, blocked);
        if (!candidates.length) {
            list.append($('<p class="text-muted">').text('Tidak ada kandidat dengan referensi ini. Pastikan detail produk, kategori, akun, dan lokasi sebelum membuat transaksi baru.'));
        }

        candidates.forEach(function (candidate, index) {
            var panel = $('<div class="panel panel-default">');
            var heading = $('<div class="panel-heading">');
            heading.append($('<strong>').text((index + 1) + '. ' + (candidate.label || ('Sumber #' + candidate.id))));
            if (row.source_record_type === candidate.record_type && Number(row.source_record_id) === Number(candidate.id)) {
                heading.append(' ').append($('<span class="label label-success">').text('Dipilih'));
            }
            heading.append($('<span class="pull-right text-muted">').text(candidate.date_match ? 'Tanggal cocok' : 'Tanggal berbeda'));
            panel.append(heading);

            var body = $('<div class="panel-body">');
            var amountDifference = candidate.amount_difference;
            var sourceSummary = $('<div class="row">');
            [
                ['Tanggal', candidate.date || '—'],
                ['Referensi', (candidate.references || []).join(', ') || '—'],
                ['Basis', candidateAmount(candidate.base_amount)],
                ['Selisih basis vs Journal', candidateAmount(candidate.base_difference)],
                ['Pajak', candidateAmount(candidate.tax_amount)],
                ['Selisih pajak vs Journal', candidateAmount(candidate.tax_difference)],
                ['Arah pajak', candidate.tax_direction_match === null ? '—' : (candidate.tax_direction_match ? 'Cocok' : 'Tidak cocok')],
                ['Total', candidateAmount(candidate.amount)],
                ['Selisih total vs Journal', amountDifference === null ? '—' : formatDraftNumber(amountDifference)]
            ].forEach(function (field) {
                sourceSummary.append($('<div class="col-sm-4 col-md-4">')
                    .append($('<p class="small">').append($('<strong>').text(field[0] + ': ')).append(document.createTextNode(field[1]))));
            });
            body.append(sourceSummary);

            var metadata = $('<div class="row">');
            appendCandidateField(metadata, 'Kategori', candidate.category_name);
            appendCandidateField(metadata, 'Kontak', candidate.contact_name);
            appendCandidateField(metadata, 'Lokasi', candidate.location_name);
            appendCandidateField(metadata, 'Akun', [candidate.account_number, candidate.account_name].filter(Boolean).join(' · '));
            appendCandidateField(metadata, 'Metode pembayaran', candidate.payment_method);
            appendCandidateField(metadata, 'Invoice terkait', candidate.invoice_reference);
            appendCandidateField(metadata, 'Jenis transaksi', candidate.transaction_type);
            appendCandidateField(metadata, 'Catatan', candidate.note);
            body.append(metadata);

            var actions = $('<div class="text-right">');
            var linkButton = $('<button type="button" class="btn btn-default btn-sm journal-draft-candidate-select">')
                .attr('data-action', 'link')
                .attr('data-source-type', candidate.record_type)
                .attr('data-source-id', candidate.id)
                .text('Tautkan saja')
                .prop('disabled', blocked);
            var updateButton = $('<button type="button" class="btn btn-primary btn-sm journal-draft-candidate-select" style="margin-left:6px">')
                .attr('data-action', 'update')
                .attr('data-source-type', candidate.record_type)
                .attr('data-source-id', candidate.id)
                .text('Update dari Journal')
                .prop('disabled', blocked);
            actions.append(linkButton).append(updateButton);
            body.append(actions);
            panel.append(body);
            list.append(panel);
        });

        $('#journal_import_candidates_modal').data('draft-row', row).data('row-element', rowElement).modal('show');
    }

    function renderDraftRow(row) {
        draftRowsById[String(row.id)] = row;
        var tr = $('<tr>').attr('data-row-id', row.id).data('draft-row', row);
        var selectableDifference = ['mismatch', 'auto_approved'].indexOf(row.reconciliation_status) !== -1
            && ['pending', 'failed'].indexOf(row.processing_status) !== -1
            && (row.reconciliation_status !== 'auto_approved' || !row.is_reviewed);
        var selectTitle = selectableDifference
            ? 'Pilih untuk meninjau selisih ini.'
            : (row.reconciliation_status === 'approved' || (row.reconciliation_status === 'auto_approved' && row.is_reviewed)
                ? 'Selisih sudah ditinjau.'
                : (['queued', 'running', 'completed'].indexOf(row.processing_status) !== -1
                    ? 'Baris sedang diproses atau sudah selesai.'
                    : 'Tidak ada selisih yang perlu ditinjau.'));
        var pick = $('<input type="checkbox" class="journal-draft-difference-select">')
            .prop('disabled', !selectableDifference)
            .attr('aria-label', 'Pilih selisih pada baris ' + (row.source_number || row.id))
            .attr('title', selectTitle);
        tr.append($('<td class="text-center">').append(pick));
        var dateNumber = $('<strong>').text(row.source_type + ' #' + (row.source_number || '—'));
        var date = $('<small class="text-muted" style="display:block">').text(row.date || 'Tanggal kosong');
        tr.append($('<td>').append(dateNumber).append(date));
        tr.append($('<td>').text(row.description || '—'));

        var processLabels = {
            pending: 'Menunggu',
            queued: 'Di antrean',
            running: 'Diproses',
            completed: 'Selesai',
            failed: 'Gagal'
        };
        var processCell = $('<td>').append($('<strong>').text(processLabels[row.processing_status] || row.processing_status || 'Menunggu'));
        if (row.processing_message) {
            processCell.append($('<small class="text-muted" style="display:block">').text(row.processing_message));
        }
        if (row.processing_error) {
            processCell.append($('<small class="text-danger" style="display:block">').text(row.processing_error));
        }
        if (row.reconciliation_status === 'mismatch') {
            processCell.append($('<span class="label label-warning" style="display:inline-block;margin-top:4px">').text('Selisih diproses · Journal menjadi acuan'));
            processCell.append($('<small class="text-warning" style="display:block">').text(
                'Selisih total: ' + formatDraftNumber(row.difference_amount)
            ));
        } else if (row.reconciliation_status === 'auto_approved') {
            processCell.append($('<span class="label label-warning" style="display:inline-block;margin-top:4px">').text('Selisih diproses otomatis · Journal menjadi acuan'));
            processCell.append($('<small class="text-muted" style="display:block">').text(
                'Selisih total: ' + formatDraftNumber(row.difference_amount)
            ));
        } else if (row.reconciliation_status === 'approved') {
            processCell.append($('<span class="label label-success" style="display:inline-block;margin-top:4px">').text('Selisih diproses · persetujuan lama tercatat'));
            processCell.append($('<small class="text-muted" style="display:block">').text(row.difference_approval_reason || 'Alasan tercatat'));
        }
        if (Array.isArray(row.journal_components) && row.journal_components.length) {
            row.journal_components.forEach(function (component) {
                var label = component.account_name || component.account_no || 'Akun tanpa nama';
                var type = component.is_tax ? 'Pajak' : (row.source_type === 'Expense' ? 'Biaya' : 'Akun');
                var sideAmounts = [];
                if (Number(component.debit || 0) > 0) sideAmounts.push('D ' + formatDraftNumber(component.debit));
                if (Number(component.credit || 0) > 0) sideAmounts.push('K ' + formatDraftNumber(component.credit));
                processCell.append($('<small class="text-muted" style="display:block">').text(
                    type + ' · ' + label + ': ' + sideAmounts.join(' / ')
                ));
            });
        }
        if (row.source_components) {
            var baseDifference = row.source_components.base === null
                ? null
                : Number(row.source_components.base) - Number(row.journal_base_total || 0);
            var taxDifference = row.source_components.tax === null
                ? null
                : Number(row.source_components.tax) - Number(row.journal_tax_total || 0);
            if (baseDifference !== null || taxDifference !== null) {
                var breakdown = [];
                if (baseDifference !== null) breakdown.push('basis ' + formatDraftNumber(baseDifference));
                if (taxDifference !== null) breakdown.push('pajak ' + formatDraftNumber(taxDifference));
                processCell.append($('<small class="text-warning" style="display:block">').text('Selisih transaksi − jurnal: ' + breakdown.join(' · ')));
            }
        }
        tr.append(processCell);
        tr.append($('<td class="text-right">').text(formatDraftNumber(row.debit_total)));
        tr.append($('<td class="text-right">').text(formatDraftNumber(row.credit_total)));

        var actionSelect = $('<select class="form-control input-sm journal-draft-action" aria-label="Aksi transaksi sumber">');
        draftActionOptions(row).forEach(function (action) {
            actionSelect.append($('<option>').val(action).text(draftActionLabel(action)));
        });
        actionSelect.val(row.source_action);
        actionSelect.prop('disabled', ['queued', 'running', 'completed'].indexOf(row.processing_status) !== -1);
        tr.append($('<td>').append(actionSelect));

        var sourceCell = $('<td>');
        if (row.source_type === 'Journal Entry') {
            sourceCell.text('Tidak ada transaksi sumber; GL Journal Entry saja.');
        } else {
            var candidates = candidatesForRow(row);
            if (row.source_link_group && row.source_link_group.status === 'confirmed') {
                sourceCell.append($('<strong class="small" style="display:block">')
                    .text('Grup dipilih: ' + (row.source_link_group.members || []).length + ' transaksi sumber'));
            }
            if (candidates.length) {
                var selectedCandidate = candidates.filter(function (candidate) {
                    return row.source_record_type === candidate.record_type
                        && Number(row.source_record_id) === Number(candidate.id);
                })[0];
                if (selectedCandidate) {
                    sourceCell.append($('<strong class="small" style="display:block">').text('Dipilih: ' + (selectedCandidate.label || ('Sumber #' + selectedCandidate.id))));
                }
                var candidateButton = $('<button type="button" class="btn btn-default btn-xs journal-draft-show-candidates">')
                    .text((selectedCandidate ? 'Lihat' : 'Pilih') + ' kandidat (' + candidates.length + ')')
                    .prop('disabled', ['queued', 'running', 'completed'].indexOf(row.processing_status) !== -1);
                sourceCell.append(candidateButton);
            } else {
                sourceCell.append($('<span>').text(row.source_link_status === 'unmatched'
                    ? 'Tidak ditemukan; baris akan dibuat sebagai transaksi baru.'
                    : 'Pilih keputusan sumber.'));
            }
            if (row.source_link_note) {
                sourceCell.append($('<small class="text-muted" style="display:block">').text(row.source_link_note));
            }
        }
        tr.append(sourceCell);

        var reviewedId = 'journal-draft-reviewed-' + row.id;
        var reviewedLabel = $('<label class="checkbox-inline">').attr('for', reviewedId);
        reviewedLabel.append($('<input type="checkbox" class="journal-draft-reviewed">').attr('id', reviewedId)
            .prop('checked', row.is_reviewed)
            .prop('disabled', ['queued', 'running'].indexOf(row.processing_status) !== -1));
        reviewedLabel.append(' Ditinjau');
        tr.append($('<td>').append(reviewedLabel));

        var detailsButton = $('<button type="button" class="btn btn-default btn-xs journal-draft-edit-details">')
            .text('Edit detail');
        if (row.source_type === 'Journal Entry') {
            detailsButton.prop('disabled', true).attr('title', 'Journal Entry tidak memiliki detail transaksi sumber.');
        } else if (['queued', 'running', 'completed'].indexOf(row.processing_status) !== -1) {
            detailsButton.prop('disabled', true).attr('title', 'Baris yang sudah masuk antrean tidak dapat diubah.');
        }
        tr.append($('<td>').append(detailsButton));

        return tr;
    }

    function rowMatchesVisibleFilters(row) {
        var processingFilter = $('#journal_import_processing_filter').val() || 'remaining';
        var reviewFilter = $('#journal_import_review_filter').val() || 'all';
        var statusFilter = $('#journal_import_status_filter').val() || 'all';
        if (processingFilter === 'remaining' && ['pending', 'failed'].indexOf(row.processing_status) === -1) return false;
        if (processingFilter === 'completed' && row.processing_status !== 'completed') return false;
        if (reviewFilter === 'unreviewed' && row.is_reviewed) return false;
        if (reviewFilter === 'reviewed' && !row.is_reviewed) return false;
        if (statusFilter === 'errors' && row.processing_status !== 'failed' && !row.processing_error) return false;
        if (statusFilter === 'differences' && ['mismatch', 'auto_approved', 'approved'].indexOf(row.reconciliation_status) === -1) return false;
        return true;
    }

    function noDraftRowsMessage() {
        var processingFilter = $('#journal_import_processing_filter').val() || 'remaining';
        if (processingFilter === 'remaining') {
            return 'Tidak ada baris yang perlu ditindaklanjuti pada filter ini. Baris selesai tetap tersimpan; pilih Selesai (arsip) atau Semua baris untuk melihatnya.';
        }
        if (processingFilter === 'completed') {
            return 'Tidak ada baris selesai yang cocok dengan filter ini.';
        }
        return 'Tidak ada baris untuk filter ini.';
    }

    function updateDraftPaginationDisplay() {
        var first = draftTotal ? ((draftPage - 1) * draftPerPage) + 1 : 0;
        var last = Math.min(draftPage * draftPerPage, draftTotal);
        $('#journal_import_draft_pagination_info').text(first + '–' + last + ' dari ' + draftTotal + ' baris');
        draftLastPage = Math.max(1, Math.ceil(draftTotal / draftPerPage));
        $('#journal_import_draft_previous').prop('disabled', draftPage <= 1);
        $('#journal_import_draft_next').prop('disabled', draftPage >= draftLastPage);
    }

    function selectedDifferenceRowIds() {
        return $('#journal_import_draft_table .journal-draft-difference-select:checked').map(function () {
            return Number($(this).closest('tr').data('row-id'));
        }).get().filter(function (id) {
            return id > 0;
        });
    }

    function updateBulkDifferenceReviewControls() {
        var eligible = $('#journal_import_draft_table .journal-draft-difference-select:not(:disabled)');
        var selected = $('#journal_import_draft_table .journal-draft-difference-select:checked');
        var selectedIds = selectedDifferenceRowIds();
        var hasUnexplained = selectedIds.some(function (id) {
            var row = draftRowsById[String(id)];
            return row && row.reconciliation_status === 'mismatch';
        });
        var allSelected = eligible.length > 0 && selected.length === eligible.length;
        var someSelected = selected.length > 0 && !allSelected;

        $('#journal_import_select_page_differences, #journal_import_select_all_differences_header')
            .prop('disabled', eligible.length === 0)
            .prop('checked', allSelected)
            .prop('indeterminate', someSelected);
        $('#journal_import_review_selected_differences')
            .prop('disabled', selectedIds.length === 0)
            .text(selectedIds.length ? 'Tinjau ' + selectedIds.length.toLocaleString() + ' selisih terpilih' : 'Tinjau selisih terpilih');
        $('#journal_import_bulk_review_reason_wrap').toggle(hasUnexplained);
        if (!hasUnexplained) {
            $('#journal_import_bulk_review_reason').val('');
        }

        var status = selectedIds.length
            ? selectedIds.length.toLocaleString() + ' selisih dipilih pada halaman ini.'
            : (eligible.length ? 'Belum ada selisih dipilih.' : 'Tidak ada selisih yang dapat dipilih pada halaman ini.');
        $('#journal_import_bulk_review_status').removeClass('text-danger').text(status);
    }

    function updateDraftRowsInline(rows) {
        var removed = 0;
        rows.forEach(function (row) {
            var rowId = String(row.id);
            draftRowsById[rowId] = row;
            var current = $('#journal_import_draft_table tbody tr').filter(function () {
                return String($(this).data('row-id')) === rowId;
            });
            if (!current.length) return;
            if (rowMatchesVisibleFilters(row)) {
                current.replaceWith(renderDraftRow(row));
            } else {
                current.remove();
                removed++;
            }
        });
        if (removed) draftTotal = Math.max(0, draftTotal - removed);
        if (!$('#journal_import_draft_table tbody tr').length) {
            $('#journal_import_draft_table tbody').append(
                $('<tr>').append($('<td colspan="10" class="text-center text-muted">').text(noDraftRowsMessage()))
            );
        }
        updateDraftPaginationDisplay();
        updateBulkDifferenceReviewControls();
    }

    function loadDraftRows(pageNumber) {
        var draft = $('#journal_import_draft');
        if (!draft.length || !draftType) {
            return;
        }
        $('#journal_import_preview_source_groups').toggle(
            ['Expense', 'Purchase Invoice'].indexOf(draftType) !== -1
            && ($('#journal_import_processing_filter').val() || 'remaining') === 'remaining'
        );
        $('#journal_import_preview_cohort_mappings').toggle(
            ['Expense', 'Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Receive Payment', 'Purchase Payment'].indexOf(draftType) !== -1
            && ($('#journal_import_processing_filter').val() || 'remaining') === 'remaining'
        );
        draftPage = pageNumber || 1;
        var requestNumber = ++draftRequestNumber;
        $('#journal_import_draft_status').text('Memuat baris...');
        $('#journal_import_draft_table tbody').empty().append(
            $('<tr>').append($('<td colspan="10" class="text-center text-muted">').text('Memuat draft...'))
        );
        $('#journal_import_select_page_differences, #journal_import_select_all_differences_header').prop('checked', false).prop('indeterminate', false);
        $('#journal_import_bulk_review_reason').val('');
        $('#journal_import_bulk_review_reason_wrap').hide();
        $('#journal_import_review_selected_differences').prop('disabled', true);
        $('#journal_import_bulk_review_status').removeClass('text-danger').text('Memuat baris...');

        var requestData = {
            type: draftType,
            processing_filter: $('#journal_import_processing_filter').val() || 'remaining',
            review_filter: $('#journal_import_review_filter').val() || 'all',
            status_filter: $('#journal_import_status_filter').val() || 'all',
            search: $('#journal_import_draft_search').val() || '',
            page: draftPage,
            per_page: 50
        };
        if (reviewGroupContext && reviewGroupKey) {
            requestData.group_context = reviewGroupContext;
            requestData.group_key = reviewGroupKey;
        }

        $.ajax({
            method: 'GET',
            url: draft.data('rows-url'),
            dataType: 'json',
            data: requestData
        }).done(function (response) {
            if (requestNumber !== draftRequestNumber) return;
            var tbody = $('#journal_import_draft_table tbody').empty();
            if (!response.success || !response.rows.length) {
                tbody.append($('<tr>').append($('<td colspan="10" class="text-center text-muted">').text(noDraftRowsMessage())));
            } else {
                response.rows.forEach(function (row) {
                    tbody.append(renderDraftRow(row));
                });
            }

            draftPage = Number(response.page || 1);
            draftTotal = Number(response.total || 0);
            draftPerPage = Number(response.per_page || 50);
            draftLastPage = Number(response.last_page || 1);
            var first = response.total ? ((response.page - 1) * response.per_page) + 1 : 0;
            var last = Math.min(response.page * response.per_page, response.total);
            $('#journal_import_draft_pagination_info').text(first + '–' + last + ' dari ' + response.total + ' baris');
            $('#journal_import_draft_previous').prop('disabled', response.page <= 1);
            $('#journal_import_draft_next').prop('disabled', response.page >= draftLastPage);
            $('#journal_import_draft_status').text('Draft tersimpan otomatis.');
            updateBulkDifferenceReviewControls();
        }).fail(function (xhr) {
            if (requestNumber !== draftRequestNumber) return;
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Gagal memuat baris draft.';
            $('#journal_import_draft_status').text(message).addClass('text-danger');
            $('#journal_import_draft_table tbody').empty().append(
                $('<tr>').append($('<td colspan="10" class="text-center text-danger">').text(message))
            );
            updateBulkDifferenceReviewControls();
        });
    }

    function loadReviewGroups() {
        var draft = $('#journal_import_draft');
        if (!draft.length || !draftType) return;
        var status = $('#journal_import_review_groups_status');
        var tbody = $('#journal_import_review_groups_table tbody').empty();
        status.removeClass('text-danger').text('Mengelompokkan baris yang belum selesai...');
        tbody.append($('<tr>').append($('<td colspan="5" class="text-center text-muted">').text('Memuat kelompok...')));

        $.ajax({
            method: 'GET',
            url: draft.data('review-groups-url'),
            dataType: 'json',
            data: { type: draftType }
        }).done(function (response) {
            tbody.empty();
            if (!response.success || !Array.isArray(response.groups) || response.groups.length === 0) {
                tbody.append($('<tr>').append($('<td colspan="5" class="text-center text-muted">').text('Tidak ada baris pending/gagal untuk jenis transaksi ini.')));
                status.text('0 kelompok · 0 baris tersisa.');
                $('.journal-import-type-tab').filter(function () {
                    return String($(this).data('type')) === draftType;
                }).find('[data-type-count]').text('0');
                return;
            }

            response.groups.forEach(function (group) {
                var row = $('<tr>');
                row.append($('<td class="text-right">').text(Number(group.row_count || 0).toLocaleString()));
                var reason = $('<td>');
                reason.append($('<strong>').text(group.source_type + ' · ' + (group.source_action || 'review')));
                reason.append($('<div class="text-muted">').text(group.blocking_reason || 'Alasan belum tersedia.'));
                row.append(reason);
                row.append($('<td>').text(group.missing_field_label || 'Informasi transaksi'));
                var nextAction = $('<td>').text(group.next_action || 'Tinjau baris satu per satu.');
                if (group.suggested_direction) {
                    nextAction.append($('<div class="text-success">').text('Arah dari akun persediaan: ' + (group.suggested_direction === 'in' ? 'Masuk' : 'Keluar')));
                }
                row.append(nextAction);

                var actions = $('<td>');
                var viewButton = $('<button type="button" class="btn btn-default btn-xs journal-import-view-review-group">')
                    .text('Lihat baris')
                    .data('group-context', group.group_context)
                    .data('group-key', group.group_key)
                    .data('source-type', group.source_type);
                actions.append(viewButton);
                (group.bulk_actions || []).forEach(function (bulkAction) {
                    actions.append(' ');
                    actions.append($('<button type="button" class="btn btn-primary btn-xs journal-import-resolve-review-group" style="margin-top:3px;">')
                        .text(bulkAction.label + ' & coba lagi')
                        .data('group-context', group.group_context)
                        .data('group-key', group.group_key)
                        .data('expected-count', Number(group.row_count || 0))
                        .data('field', bulkAction.field)
                        .data('value', bulkAction.value)
                        .data('label', bulkAction.label));
                });
                row.append(actions);
                tbody.append(row);
            });
            $('.journal-import-type-tab').filter(function () {
                return String($(this).data('type')) === draftType;
            }).find('[data-type-count]').text(Number(response.total_rows || 0));
            status.text(Number(response.total_groups || 0).toLocaleString() + ' kelompok · ' + Number(response.total_rows || 0).toLocaleString() + ' baris pending/gagal.');
            $('.journal-import-type-tab').filter(function () {
                return String($(this).data('type')) === draftType;
            }).find('[data-type-count]').text(Number(response.total_rows || 0));
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Gagal mengelompokkan baris tersisa.';
            tbody.empty().append($('<tr>').append($('<td colspan="5" class="text-center text-danger">').text(message)));
            status.addClass('text-danger').text(message);
        });
    }

    function pollReviewImport(importId) {
        var id = String(importId);
        if (reviewImportPollers[id]) return;
        reviewImportPollers[id] = true;

        function poll() {
            $.ajax({
                method: 'GET',
                url: statusUrl(id),
                dataType: 'json'
            }).done(function (response) {
                if (response.success) updateRow(response);
                if (response.success && isActive(response.status)) {
                    window.setTimeout(poll, 2000);
                    return;
                }
                delete reviewImportPollers[id];
                if (response.success) {
                    $('#journal_import_draft_status').text(response.last_message || 'Pemrosesan selesai.');
                    loadDraftRows(1);
                    loadReviewGroups();
                }
            }).fail(function () {
                window.setTimeout(poll, 5000);
            });
        }

        poll();
    }

    function pollReviewImport(importId) {
        var id = String(importId);
        if (reviewImportPollers[id]) return;
        reviewImportPollers[id] = true;

        function poll() {
            $.ajax({
                method: 'GET',
                url: statusUrl(id),
                dataType: 'json'
            }).done(function (response) {
                if (response.success) updateRow(response);
                if (response.success && isActive(response.status)) {
                    window.setTimeout(poll, 2000);
                    return;
                }
                delete reviewImportPollers[id];
                if (response.success) {
                    $('#journal_import_draft_status').text(response.last_message || 'Pemrosesan selesai.');
                    loadDraftRows(1);
                    loadReviewGroups();
                }
            }).fail(function () {
                window.setTimeout(poll, 5000);
            });
        }

        poll();
    }

    function saveDraftRow(rowId, payload, rowElement, afterSave) {
        var draft = $('#journal_import_draft');
        var status = $('#journal_import_draft_status');
        status.removeClass('text-danger').text('Menyimpan perubahan...');
        rowElement && rowElement.addClass('journal-draft-saving');

        $.ajax({
            method: 'PATCH',
            url: draft.data('row-url-base') + '/' + encodeURIComponent(rowId),
            contentType: 'application/json',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': draft.data('csrf-token') },
            data: JSON.stringify(payload)
        }).done(function (response) {
            if (response.success && response.row) {
                draftRowsById[String(rowId)] = response.row;
                if (rowElement) {
                    var updatedElement = renderDraftRow(response.row).addClass('success');
                    rowElement.replaceWith(updatedElement);
                    window.setTimeout(function () { updatedElement.removeClass('success'); }, 900);
                }
                status.text(response.msg || 'Draft tersimpan.');
                if (typeof afterSave === 'function') afterSave(response.row);
                window.setTimeout(function () {
                    if (status.text() === (response.msg || 'Draft tersimpan.')) {
                        status.text('Draft tersimpan otomatis.');
                    }
                }, 1800);
            }
        }).fail(function (xhr) {
            if (rowElement) {
                rowElement.removeClass('journal-draft-saving');
                var savedRow = rowElement.data('draft-row') || {};
                rowElement.replaceWith(renderDraftRow(savedRow));
            }
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Perubahan belum tersimpan. Coba lagi.';
            status.addClass('text-danger').text(message);
        });
    }

    function selectedSource(rowElement) {
        var row = rowElement.data('draft-row') || {};
        return {
            type: row.source_record_type || null,
            id: Number(row.source_record_id) || null
        };
    }

    function actionPayload(rowElement, action) {
        var row = rowElement.data('draft-row');
        var source = selectedSource(rowElement);
        var payload = {
            source_action: action,
            source_record_type: (action === 'link' || action === 'update') ? source.type : null,
            source_record_id: (action === 'link' || action === 'update') ? source.id : null
        };
        if (action === 'journal') {
            payload.source_record_type = null;
            payload.source_record_id = null;
        }
        return payload;
    }

    function optionUrlData(kind, sourceType, selectedId, extraData) {
        return $.extend({
            kind: kind,
            source_type: sourceType,
            selected_id: selectedId || undefined
        }, extraData || {});
    }

    function configureDraftSelect(select, kind, sourceType, selectedId, extraData) {
        var draft = $('#journal_import_draft');
        var element = $(select);
        if (element.hasClass('select2-hidden-accessible')) {
            element.select2('destroy');
        }
        element.empty().append($('<option>'));
        element.select2({
            width: '100%',
            allowClear: true,
            placeholder: 'Pilih dari master data',
            minimumInputLength: 1,
            language: {
                inputTooShort: function () { return 'Ketik minimal 1 karakter untuk mencari.'; },
                noResults: function () { return 'Tidak ada data yang cocok.'; },
                searching: function () { return 'Mencari...'; },
                errorLoading: function () { return 'Gagal memuat pilihan. Periksa koneksi lalu coba lagi.'; }
            },
            dropdownParent: $('#journal_import_detail_modal'),
            ajax: {
                url: draft.data('options-url'),
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return $.extend(optionUrlData(kind, sourceType, null, extraData), { q: params.term || '' });
                },
                processResults: function (data) {
                    return { results: data.results || [] };
                }
            }
        });
        if (selectedId) {
            $.getJSON(draft.data('options-url'), optionUrlData(kind, sourceType, selectedId, extraData)).done(function (data) {
                var item = (data.results || [])[0];
                if (item) {
                    element.append(new Option(item.text, item.id, true, true)).trigger('change');
                }
            });
        }
    }

    function showDraftFields(sourceType, selectedContactId) {
        var contactKind = sourceType === 'Sales Invoice' || sourceType === 'Receive Payment'
            ? 'customer'
            : (sourceType === 'Purchase Invoice' || sourceType === 'Purchase Payment' ? 'supplier' : 'contact');
        var contactTypes = ['Sales Invoice', 'Purchase Invoice', 'Expense'];
        var paymentTypes = ['Receive Payment', 'Purchase Payment', 'Bank Deposit', 'Bank Transfer', 'Bank Withdrawal', 'Reversal'];
        var invoicePaymentTypes = ['Receive Payment', 'Purchase Payment'];
        var detailTypes = ['Sales Invoice', 'Purchase Invoice', 'Stock Adjustment', 'Stock In/Out', 'Opening Balance'];
        var transferTypes = ['Bank Deposit', 'Bank Transfer'];

        $('.journal-draft-field-contact').toggle(contactTypes.indexOf(sourceType) !== -1);
        $('.journal-draft-field-location').toggle(sourceType !== 'Journal Entry');
        $('.journal-draft-field-payment-account').toggle(paymentTypes.indexOf(sourceType) !== -1);
        $('.journal-draft-field-transfer-account').toggle(transferTypes.indexOf(sourceType) !== -1);
        $('.journal-draft-field-reversal-target').toggle(sourceType === 'Reversal');
        $('.journal-draft-field-payment-method').toggle(invoicePaymentTypes.indexOf(sourceType) !== -1);
        $('.journal-draft-field-invoice').toggle(invoicePaymentTypes.indexOf(sourceType) !== -1);
        $('.journal-draft-field-expense-category').toggle(sourceType === 'Expense');
        $('.journal-draft-field-expense-splits').toggle(sourceType === 'Expense');
        $('.journal-draft-field-details').toggle(detailTypes.indexOf(sourceType) !== -1);
        $('.journal-draft-field-stock-direction').toggle(sourceType === 'Stock In/Out');
        var accountLabel = sourceType === 'Bank Deposit' ? 'Akun penerima deposit'
            : (sourceType === 'Bank Transfer' ? 'Akun asal transfer'
                : (sourceType === 'Bank Withdrawal' ? 'Akun yang ditarik' : 'Akun pembayaran / bank'));
        $('#journal_draft_payment_account_label').text(accountLabel);
        $('#journal_draft_transfer_account_label').text(sourceType === 'Bank Deposit' ? 'Asal dana internal (opsional)' : 'Akun tujuan transfer');
        $('#journal_draft_transfer_account_help').text(sourceType === 'Bank Deposit'
            ? 'Biarkan kosong jika sumber dana berada di luar akun kas/bank yang tercatat.'
            : 'Pilih akun kas/bank penerima transfer.');
        configureDraftSelect('#journal_draft_contact', contactKind, sourceType, selectedContactId || null);
    }

    function addDraftDetailLine(detail, sourceType) {
        detail = detail || {};
        var row = $('<tr class="journal-draft-detail-row">');
        var product = $('<select class="form-control journal-draft-select journal-draft-product" data-kind="product" style="width:100%">').append($('<option>'));
        row.append($('<td>').append(product));
        var unit = $('<select class="form-control journal-draft-select journal-draft-unit" data-kind="unit" style="width:100%">').append($('<option>'));
        row.append($('<td>').append(unit));
        row.append($('<td>').append($('<input type="number" min="0.0001" step="0.0001" class="form-control input-sm journal-draft-quantity" aria-label="Jumlah">').val(detail.quantity || '')));
        row.append($('<td>').append($('<input type="number" min="0" step="0.01" class="form-control input-sm journal-draft-unit-price" aria-label="Harga">').val(detail.unit_price || '')));
        var account = $('<select class="form-control journal-draft-select journal-draft-detail-account" data-kind="payment_account" style="width:100%">').append($('<option>'));
        row.append($('<td>').append(account));
        var remove = $('<button type="button" class="btn btn-link text-danger journal-draft-remove-detail" aria-label="Hapus baris detail">').text('Hapus');
        row.append($('<td>').append(remove));
        $('#journal_draft_details_table tbody').append(row);
        configureDraftSelect(product, 'product', sourceType, detail.variation_id || null);
        configureDraftSelect(unit, 'unit', sourceType, detail.unit_id || null, { variation_id: detail.variation_id || null });
        product.on('change', function () {
            configureDraftSelect(unit, 'unit', sourceType, null, { variation_id: product.val() || null });
        });
        configureDraftSelect(account, 'payment_account', sourceType, detail.account_id || null);
    }

    function collectDraftData() {
        return {
            contact_id: $('#journal_draft_contact').val() || null,
            location_id: $('#journal_draft_location').val() || null,
            payment_account_id: $('#journal_draft_payment_account').val() || null,
            transfer_account_id: $('#journal_draft_transfer_account').val() || null,
            reversal_target_id: $('#journal_draft_reversal_target').val() || null,
            movement_direction: $('#journal_draft_movement_direction').val() || null,
            payment_method: $('#journal_draft_payment_method').val() || null,
            invoice_reference: $('#journal_draft_invoice').val() || null,
            expense_category_id: $('#journal_draft_expense_category').val() || null,
            expense_category_mappings: collectExpenseCategoryMappings(),
            details: collectDraftDetails()
        };
    }

    function collectExpenseCategoryMappings() {
        var mappings = {};
        $('#journal_draft_expense_splits_table tbody tr').each(function () {
            var key = String($(this).data('account-key') || '');
            if (key) mappings[key] = $(this).find('.journal-draft-expense-category').val() || null;
        });
        return mappings;
    }

    function renderExpenseCategorySplits(row, draftData) {
        var tbody = $('#journal_draft_expense_splits_table tbody').empty();
        var components = (Array.isArray(row.journal_components) ? row.journal_components : [])
            .filter(function (component) { return !component.is_tax; });
        components.forEach(function (component) {
            var key = String(component.account_key || '');
            if (!key) return;
            var line = $('<tr>').attr('data-account-key', key);
            var label = [component.account_no, component.account_name].filter(Boolean).join(' · ') || 'Akun tanpa nama';
            line.append($('<td>').text(label));
            line.append($('<td class="text-right">').text(formatDraftNumber(component.debit)));
            var select = $('<select class="form-control journal-draft-select journal-draft-expense-category" style="width:100%">').append($('<option>'));
            line.append($('<td>').append(select));
            tbody.append(line);
            var selectedId = (draftData.expense_category_mappings || {})[key] || null;
            if (!selectedId && components.length === 1) selectedId = draftData.expense_category_id || null;
            configureDraftSelect(select, 'expense_category', 'Expense', selectedId);
        });
        if (!components.length) {
            tbody.append($('<tr>').append($('<td colspan="3" class="text-muted">').text('Akun biaya jurnal belum teridentifikasi. Isi kategori bawaan dan tinjau total sebelum diproses.')));
        }
    }

    function saveDraftDetails(snapshot, closeAfterSave) {
        snapshot = snapshot || (activeDetailRow ? {
            rowId: activeDetailRow.id,
            draftData: collectDraftData()
        } : null);
        if (!snapshot) return;
        if (detailSaveInFlight) {
            detailSavePending = { snapshot: snapshot, closeAfterSave: Boolean(closeAfterSave) };
            return;
        }

        var rowId = snapshot.rowId;
        var saveButton = $('#journal_import_detail_form').find('button[type="submit"]');
        detailSaveInFlight = true;
        saveButton.prop('disabled', true);
        $('#journal_import_detail_error').addClass('hide').empty();

        $.ajax({
            method: 'PATCH',
            url: $('#journal_import_draft').data('row-url-base') + '/' + encodeURIComponent(rowId),
            contentType: 'application/json',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': $('#journal_import_draft').data('csrf-token') },
            data: JSON.stringify({ draft_data: snapshot.draftData })
        }).done(function (response) {
            if (response.success && response.row) {
                draftRowsById[String(rowId)] = response.row;
                if (activeDetailRow && String(activeDetailRow.id) === String(rowId)) {
                    activeDetailRow = response.row;
                }
                $('#journal_import_draft_table tbody tr').filter(function () {
                    return String($(this).data('row-id')) === String(rowId);
                }).data('draft-row', response.row);
                $('#journal_import_draft_status').removeClass('text-danger').text('Perubahan detail tersimpan otomatis.');
                if (closeAfterSave && activeDetailRow && String(activeDetailRow.id) === String(rowId)) {
                    $('#journal_import_detail_modal').modal('hide');
                    loadDraftRows(draftPage);
                }
            }
        }).fail(function (xhr) {
            var errors = xhr.responseJSON && xhr.responseJSON.errors;
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Detail belum tersimpan.';
            if (errors) {
                var firstError = Object.keys(errors)[0];
                message = errors[firstError][0] || message;
            }
            $('#journal_import_detail_error').removeClass('hide').text(message);
            $('#journal_import_draft_status').addClass('text-danger').text(message);
        }).always(function () {
            detailSaveInFlight = false;
            saveButton.prop('disabled', false);
            if (detailSavePending) {
                var pending = detailSavePending;
                detailSavePending = null;
                saveDraftDetails(pending.snapshot, pending.closeAfterSave);
            }
        });
    }

    function scheduleDraftDetailSave() {
        if (detailSaveTimer) window.clearTimeout(detailSaveTimer);
        var snapshot = activeDetailRow ? {
            rowId: activeDetailRow.id,
            draftData: collectDraftData()
        } : null;
        detailSaveTimer = window.setTimeout(function () {
            detailSaveTimer = null;
            saveDraftDetails(snapshot, false);
        }, 450);
    }

    function openDraftDetails(row) {
        activeDetailRow = row;
        var data = row.draft_data || {};
        $('#journal_import_detail_title').text('Detail ' + row.source_type + ' #' + (row.source_number || ''));
        $('#journal_import_detail_error').addClass('hide').empty();
        $('#journal_draft_details_table tbody').empty();
        showDraftFields(row.source_type, data.contact_id || null);
        configureDraftSelect('#journal_draft_location', 'location', row.source_type, data.location_id || row.default_location_id || null);
        configureDraftSelect('#journal_draft_payment_account', 'payment_account', row.source_type, data.payment_account_id || null);
        configureDraftSelect('#journal_draft_transfer_account', 'transfer_account', row.source_type, data.transfer_account_id || null);
        configureDraftSelect('#journal_draft_reversal_target', 'reversal_target', row.source_type, data.reversal_target_id || null);
        $('#journal_draft_movement_direction').val(data.movement_direction || '');
        configureDraftSelect('#journal_draft_invoice', 'invoice', row.source_type, data.invoice_reference || null);
        configureDraftSelect('#journal_draft_payment_method', 'payment_method', row.source_type, data.payment_method || null);
        configureDraftSelect('#journal_draft_expense_category', 'expense_category', row.source_type, data.expense_category_id || null);
        renderExpenseCategorySplits(row, data);
        (Array.isArray(data.details) ? data.details : []).forEach(function (detail) {
            addDraftDetailLine(detail, row.source_type);
        });
        $('#journal_import_detail_modal').modal('show');
    }

    function collectDraftDetails() {
        var details = [];
        $('#journal_draft_details_table tbody .journal-draft-detail-row').each(function () {
            var detail = {
                variation_id: $(this).find('.journal-draft-product').val() || null,
                unit_id: $(this).find('.journal-draft-unit').val() || null,
                quantity: $(this).find('.journal-draft-quantity').val() || null,
                unit_price: $(this).find('.journal-draft-unit-price').val() || null,
                account_id: $(this).find('.journal-draft-detail-account').val() || null
            };
            details.push(detail);
        });
        return details;
    }

    $(document).on('click', '.journal-import-type-tab', function () {
        $('.journal-import-type-tabs li').removeClass('active');
        $('.journal-import-type-tab').attr('aria-selected', 'false');
        $(this).closest('li').addClass('active');
        $(this).attr('aria-selected', 'true');
        draftType = String($(this).data('type'));
        reviewGroupContext = null;
        reviewGroupKey = null;
        $('#journal_import_group_filter_notice').addClass('hide');
        loadDraftRows(1);
        loadReviewGroups();
    });

    function renderCohortMappings(response) {
        var tbody = $('#journal_import_cohort_mappings_table tbody').empty();
        cohortMappingPreview = response;
        var groups = Array.isArray(response.groups) ? response.groups : [];
        if (!groups.length) {
            $('#journal_import_cohort_mappings_summary').text('Tidak ada kelompok mapping yang dapat diproses otomatis pada jenis transaksi ini. Kandidat ambigu tetap tersedia untuk review per baris.');
            $('#journal_import_apply_cohort_mappings').prop('disabled', true);
            $('#journal_import_cohort_mappings_modal').modal('show');
            return;
        }

        var hasEmptyOptions = false;
        groups.forEach(function (group) {
            var row = $('<tr>')
                .attr('data-cohort-key', group.cohort_key)
                .attr('data-fingerprint', group.fingerprint)
                .attr('data-field', group.field);
            row.append($('<td>').text(group.source_type || ''));
            row.append($('<td>').text(group.context_label || ''));
            row.append($('<td>').text(group.field_label || group.field || ''));
            row.append($('<td class="text-right">').text(Number(group.row_count || 0).toLocaleString()));
            row.append($('<td class="text-right">').text(
                group.field === 'invoice_reference' ? '—' : formatDraftNumber(group.component_total)
            ));
            row.append($('<td class="text-right">').text(formatDraftNumber(group.journal_total)));

            var options = Array.isArray(group.options) ? group.options : [];
            var select = $('<select class="form-control journal-import-cohort-value">')
                .append($('<option>').val('').text(options.length ? 'Pilih nilai mapping' : 'Tidak ada pilihan aktif'));
            options.forEach(function (option) {
                select.append($('<option>').val(String(option.id)).text(option.name));
            });
            if (group.suggested_value) select.val(String(group.suggested_value));
            if (!options.length) hasEmptyOptions = true;
            row.append($('<td>').append(select));
            tbody.append(row);
        });

        $('#journal_import_cohort_mappings_summary').text(
            groups.length + ' kelompok dari ' + Number(response.total_group_count || groups.length).toLocaleString()
            + ' kelompok mencakup ' + Number(response.total_row_count || 0).toLocaleString()
            + ' baris unik. Total komponen adalah nilai akun Journal terkait; total Journal menunjukkan debit penuh. Mapping berlaku hanya pada kelompok yang ditampilkan.'
            + (response.has_more ? ' Terapkan kelompok ini lalu muat ulang preview untuk kelompok berikutnya.' : '')
        );
        $('#journal_import_cohort_mappings_error').addClass('hide').empty();
        $('#journal_import_apply_cohort_mappings').prop('disabled', hasEmptyOptions);
        $('#journal_import_cohort_mappings_modal').modal('show');
    }

    $(document).on('click', '#journal_import_preview_cohort_mappings', function () {
        var draft = $('#journal_import_draft');
        var button = $(this);
        button.prop('disabled', true).text('Memuat kelompok...');
        $('#journal_import_cohort_mappings_status').text('Mengelompokkan baris dengan konteks Journal yang sama.');
        $.ajax({
            method: 'GET',
            url: draft.data('cohort-mappings-preview-url'),
            dataType: 'json',
            data: { source_type: draftType }
        }).done(function (response) {
            if (!response.success) {
                $('#journal_import_cohort_mappings_status').text(response.msg || 'Preview mapping tidak tersedia.');
                return;
            }
            renderCohortMappings(response);
            $('#journal_import_cohort_mappings_status').text('Preview mapping siap ditinjau.');
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.msg
                ? xhr.responseJSON.msg
                : 'Gagal memuat kelompok mapping.';
            $('#journal_import_cohort_mappings_status').text(message);
            if (window.toastr) toastr.error(message);
        }).always(function () {
            button.prop('disabled', false).text('Mapping berulang massal');
        });
    });

    $(document).on('click', '#journal_import_apply_cohort_mappings', function () {
        if (!cohortMappingPreview) return;
        var mappings = [];
        var missingSelection = false;
        var selectedRowIds = {};
        $('#journal_import_cohort_mappings_table tbody tr').each(function () {
            var row = $(this);
            var value = row.find('.journal-import-cohort-value').val();
            if (!value) {
                missingSelection = true;
                return;
            }
            mappings.push({
                cohort_key: String(row.data('cohort-key') || ''),
                fingerprint: String(row.data('fingerprint') || ''),
                field: String(row.data('field') || ''),
                value: String(value)
            });
            var group = (cohortMappingPreview.groups || []).find(function (item) {
                return String(item.cohort_key) === String(row.data('cohort-key') || '');
            });
            (group && group.row_ids || []).forEach(function (rowId) {
                selectedRowIds[String(rowId)] = true;
            });
        });
        if (missingSelection || !mappings.length) {
            $('#journal_import_cohort_mappings_error').removeClass('hide').text('Pilih nilai untuk setiap kelompok sebelum melanjutkan.');
            return;
        }

        var mappedRows = Object.keys(selectedRowIds).length;
        if (!window.confirm('Terapkan mapping ke ' + mappedRows.toLocaleString() + ' baris transaksi yang ditampilkan, lalu mulai ulang persiapan batch? Baris yang sudah selesai tetap tidak diproses ulang.')) {
            return;
        }

        var draft = $('#journal_import_draft');
        var form = $('#journal_import_form');
        var submitButton = form.find('button[type="submit"]');
        var button = $(this);
        button.prop('disabled', true).text('Menerapkan dan memulai...');
        $('#journal_import_cohort_mappings_error').addClass('hide').empty();
        $('#journal_import_progress_box').removeClass('hide');
        $('#journal_import_progress_stale, #journal_import_progress_error').addClass('hide').text('');
        $('#journal_import_progress_log').empty();
        $('#journal_import_progress_stage').text('Menyimpan mapping transaksi');
        $('#journal_import_progress_count').text('');
        $('#journal_import_progress_text').text('Menerapkan mapping lalu mengantrekan baris yang dapat diproses.');
        appendProgressLog('Menerapkan mapping berulang massal.');

        var payload = form.serializeArray();
        payload.push({ name: 'source_type', value: draftType });
        mappings.forEach(function (mapping, index) {
            payload.push({ name: 'mappings[' + index + '][cohort_key]', value: mapping.cohort_key });
            payload.push({ name: 'mappings[' + index + '][fingerprint]', value: mapping.fingerprint });
            payload.push({ name: 'mappings[' + index + '][field]', value: mapping.field });
            payload.push({ name: 'mappings[' + index + '][value]', value: mapping.value });
        });
        $.ajax({
            method: 'POST',
            url: draft.data('cohort-mappings-apply-url'),
            data: $.param(payload),
            dataType: 'json'
        }).done(function (response) {
            if (!response.success) {
                button.prop('disabled', false).text('Terapkan mapping dan proses ulang');
                var message = response.msg || 'Mapping tersimpan, tetapi batch belum dapat dimulai.';
                $('#journal_import_cohort_mappings_error').removeClass('hide').text(message);
                return;
            }
            $('#journal_import_cohort_mappings_modal').modal('hide');
            $('#journal_import_cohort_mappings_status').text(response.msg || 'Mapping diterapkan.');
            if (window.toastr) toastr.success(response.msg || 'Mapping diterapkan.');
            cohortMappingPreview = null;
            submitButton.prop('disabled', true);
            pollForm(response.import_id || draft.data('import-id'), submitButton);
            loadDraftRows(1);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var message = response.msg
                ? response.msg
                : (response.errors && response.errors.mappings
                    ? response.errors.mappings[0]
                    : 'Mapping belum diterapkan. Muat ulang preview sebelum mencoba lagi.');
            if (response.mapping_saved) message = 'Mapping tersimpan. ' + message;
            button.prop('disabled', false).text('Terapkan mapping dan proses ulang');
            $('#journal_import_cohort_mappings_error').removeClass('hide').text(message);
        });
    });

    $(document).on('click', '#journal_import_preview_source_groups', function () {
        var draft = $('#journal_import_draft');
        var button = $(this);
        var status = $('#journal_import_source_groups_status');
        button.prop('disabled', true).text('Menganalisis grup...');
        status.text('Memeriksa kandidat exact di seluruh batch.');
        $.ajax({
            method: 'GET',
            url: draft.data('source-groups-preview-url'),
            dataType: 'json'
        }).done(function (response) {
            sourceGroupPreview = response;
            $('#journal_import_source_groups_error').addClass('hide').empty();
            $('#journal_import_source_groups_summary').text(
                response.unique_group_count + ' baris Journal memiliki grup sumber exact yang unik, mencakup '
                + response.source_record_count + ' transaksi Expense/Purchase Invoice. Beberapa grup exact: '
                + response.ambiguous_row_count + ' baris; kandidat yang pencariannya dibatasi: '
                + response.limited_row_count + ' baris; konflik tautan sumber antarbaris Journal: '
                + response.conflicting_row_count + ' baris.'
            );
            $('#journal_import_source_groups_totals').empty().append(sourceGroupPreviewSummary(response));
            $('#journal_import_source_groups_limits').text(
                'Konfirmasi hanya menautkan transaksi yang sudah ada dan tidak mem-posting Journal. Baris dengan lebih dari satu grup tetap menunggu pilihan manual.'
            );
            $('#journal_import_confirm_source_groups')
                .prop('disabled', Number(response.unique_group_count || 0) < 1)
                .text('Konfirmasi ' + Number(response.unique_group_count || 0) + ' grup unik');
            $('#journal_import_source_groups_modal').modal('show');
            status.text('Preview grup sumber siap ditinjau.');
        }).fail(function (xhr) {
            status.text(xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Gagal menganalisis grup sumber.');
        }).always(function () {
            button.prop('disabled', false).text('Tinjau grup sumber split');
        });
    });

    $(document).on('click', '#journal_import_confirm_source_groups', function () {
        if (!sourceGroupPreview || !sourceGroupPreview.fingerprint) return;
        var draft = $('#journal_import_draft');
        var button = $(this);
        button.prop('disabled', true).text('Mengonfirmasi grup...');
        $('#journal_import_source_groups_error').addClass('hide').empty();
        $.ajax({
            method: 'POST',
            url: draft.data('source-groups-confirm-url'),
            contentType: 'application/json',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': draft.data('csrf-token') },
            data: JSON.stringify({ fingerprint: sourceGroupPreview.fingerprint })
        }).done(function (response) {
            $('#journal_import_source_groups_modal').modal('hide');
            $('#journal_import_source_groups_status').text(response.msg || 'Grup sumber berhasil dikonfirmasi.');
            if (window.toastr) toastr.success(response.msg || 'Grup sumber berhasil dikonfirmasi.');
            sourceGroupPreview = null;
            loadDraftRows(draftPage);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Konfirmasi grup sumber gagal.';
            $('#journal_import_source_groups_error').removeClass('hide').text(message);
            button.prop('disabled', false).text('Konfirmasi grup unik');
        });
    });

    $(document).on('change', '#journal_import_select_page_differences, #journal_import_select_all_differences_header', function () {
        var checked = $(this).is(':checked');
        $('#journal_import_draft_table .journal-draft-difference-select:not(:disabled)').prop('checked', checked);
        updateBulkDifferenceReviewControls();
    });

    $(document).on('change', '#journal_import_draft_table .journal-draft-difference-select', function () {
        updateBulkDifferenceReviewControls();
    });

    $(document).on('click', '#journal_import_review_selected_differences', function () {
        var button = $(this);
        var draft = $('#journal_import_draft');
        var rowIds = selectedDifferenceRowIds();
        if (!rowIds.length) return;

        var hasUnexplained = rowIds.some(function (id) {
            var row = draftRowsById[String(id)];
            return row && row.reconciliation_status === 'mismatch';
        });
        var reason = String($('#journal_import_bulk_review_reason').val() || '').trim();
        if (hasUnexplained && reason.length < 5) {
            $('#journal_import_bulk_review_status').addClass('text-danger').text('Isi alasan minimal 5 karakter untuk selisih yang belum terjelaskan.');
            $('#journal_import_bulk_review_reason').trigger('focus');
            return;
        }

        var payload = { row_ids: rowIds };
        if (hasUnexplained) payload.reason = reason;
        button.prop('disabled', true).text('Menyimpan review...');
        $('#journal_import_bulk_review_status').removeClass('text-danger').text('Memvalidasi dan menyimpan pilihan secara atomik...');

        $.ajax({
            method: 'PATCH',
            url: draft.data('bulk-review-url'),
            contentType: 'application/json',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': draft.data('csrf-token') },
            data: JSON.stringify(payload)
        }).done(function (response) {
            updateDraftRowsInline(response.rows || []);
            $('#journal_import_bulk_review_reason').val('');
            $('#journal_import_bulk_review_status').removeClass('text-danger').text(response.msg || 'Review selisih tersimpan.');
            if (window.toastr) toastr.success(response.msg || 'Review selisih tersimpan.');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var validationErrors = response.errors || {};
            var message = response.msg
                || (validationErrors.reason && validationErrors.reason[0])
                || (validationErrors.row_ids && validationErrors.row_ids[0])
                || 'Review selisih gagal disimpan.';
            $('#journal_import_bulk_review_status').addClass('text-danger').text(message);
            if (window.toastr) toastr.error(message);
        }).always(function () {
            var remainingSelected = selectedDifferenceRowIds();
            button.prop('disabled', remainingSelected.length === 0)
                .text(remainingSelected.length ? 'Tinjau ' + remainingSelected.length.toLocaleString() + ' selisih terpilih' : 'Tinjau selisih terpilih');
        });
    });

    $(document).on('change', '#journal_import_review_filter', function () {
        loadDraftRows(1);
    });
    $(document).on('change', '#journal_import_processing_filter', function () {
        if ($(this).val() !== 'remaining') {
            reviewGroupContext = null;
            reviewGroupKey = null;
            $('#journal_import_group_filter_notice').addClass('hide');
        }
        loadDraftRows(1);
    });
    $(document).on('change', '#journal_import_status_filter', function () {
        loadDraftRows(1);
    });
    $(document).on('input', '#journal_import_draft_search', function () {
        if (draftSearchTimer) window.clearTimeout(draftSearchTimer);
        draftSearchTimer = window.setTimeout(function () {
            draftSearchTimer = null;
            loadDraftRows(1);
        }, 300);
    });
    $(document).on('click', '#journal_import_draft_previous', function () {
        if (draftPage > 1) loadDraftRows(draftPage - 1);
    });
    $(document).on('click', '#journal_import_draft_next', function () {
        if (draftPage < draftLastPage) loadDraftRows(draftPage + 1);
    });

    $(document).on('click', '#journal_import_refresh_review_groups', function () {
        loadReviewGroups();
    });

    $(document).on('click', '.journal-import-view-review-group', function () {
        reviewGroupContext = String($(this).data('group-context') || '');
        reviewGroupKey = String($(this).data('group-key') || '');
        draftType = String($(this).data('source-type') || draftType);
        $('#journal_import_processing_filter').val('remaining');
        $('#journal_import_group_filter_notice').removeClass('hide');
        loadDraftRows(1);
    });

    $(document).on('click', '#journal_import_clear_group_filter', function () {
        reviewGroupContext = null;
        reviewGroupKey = null;
        $('#journal_import_group_filter_notice').addClass('hide');
        loadDraftRows(1);
    });

    $(document).on('click', '.journal-import-resolve-review-group', function () {
        var button = $(this);
        var draft = $('#journal_import_draft');
        var expectedCount = Number(button.data('expected-count') || 0);
        var label = String(button.data('label') || 'Pilihan kelompok');
        var field = String(button.data('field') || '');
        var directionLabel = button.data('value') === 'in' ? 'Masuk' : (button.data('value') === 'out' ? 'Keluar' : '');
        if (!expectedCount || !window.confirm('Terapkan ' + label + (directionLabel ? ' (' + directionLabel + ')' : '') + ' ke ' + expectedCount.toLocaleString() + ' baris pending/gagal, lalu mulai mencoba ulang melalui antrean standar?')) {
            return;
        }

        button.prop('disabled', true).text('Memvalidasi dan menyimpan...');
        $('#journal_import_review_groups_status').removeClass('text-danger').text('Menyimpan perubahan kelompok dalam bagian kecil...');
        $.ajax({
            method: 'POST',
            url: draft.data('review-group-resolve-url'),
            contentType: 'application/json',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': draft.data('csrf-token') },
            data: JSON.stringify({
                group_key: button.data('group-key'),
                group_context: button.data('group-context'),
                expected_count: expectedCount,
                field: field,
                value: String(button.data('value') || '')
            })
        }).done(function (response) {
            reviewGroupContext = null;
            reviewGroupKey = null;
            $('#journal_import_group_filter_notice').addClass('hide');
            $('#journal_import_review_groups_status').text(response.msg || 'Kelompok diperbarui dan dicoba ulang.');
            $('#journal_import_draft_status').text(response.last_message || response.msg || 'Batch dimulai kembali.');
            if (window.toastr) toastr.success(response.msg || 'Kelompok diperbarui dan dicoba ulang.');
            loadDraftRows(1);
            if (response.import_id) {
                pollWatch(response.import_id);
                pollReviewImport(response.import_id);
            }
            button.prop('disabled', false).text(label + ' & coba lagi');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var message = response.msg || 'Kelompok gagal disimpan.';
            if (response.mapping_saved) {
                message = 'Perubahan tersimpan untuk ' + Number(response.updated_rows || 0).toLocaleString() + ' baris, tetapi batch belum mulai. ' + message;
            }
            $('#journal_import_review_groups_status').addClass('text-danger').text(message);
            button.prop('disabled', false).text(label + ' & coba lagi');
            if (window.toastr) toastr.error(message);
        });
    });

    $(document).on('change', '.journal-draft-action', function () {
        var rowElement = $(this).closest('tr');
        saveDraftRow(rowElement.data('row-id'), actionPayload(rowElement, $(this).val()), rowElement);
    });

    $(document).on('click', '.journal-draft-show-candidates', function () {
        var row = $(this).closest('tr').data('draft-row');
        if (row) openSourceCandidates(row);
    });

    $(document).on('click', '.journal-draft-candidate-select', function () {
        var modal = $('#journal_import_candidates_modal');
        var row = modal.data('draft-row');
        var rowElement = modal.data('row-element');
        if (!row) return;
        var action = String($(this).data('action') || 'link');
        var payload = {
            source_action: action,
            source_record_type: String($(this).data('source-type') || ''),
            source_record_id: Number($(this).data('source-id')) || null
        };
        saveDraftRow(row.id, payload, rowElement, function () {
            modal.modal('hide');
        });
    });

    $(document).on('click', '.journal-draft-source-group-select', function () {
        var button = $(this);
        var modal = $('#journal_import_candidates_modal');
        var row = modal.data('draft-row');
        var rowElement = modal.data('row-element');
        var memberIds = button.data('member-ids') || [];
        if (!row || memberIds.length < 2) return;
        button.prop('disabled', true).text('Menyimpan grup...');
        $.ajax({
            method: 'POST',
            url: $('#journal_import_draft').data('row-url-base') + '/' + encodeURIComponent(row.id) + '/source-group',
            contentType: 'application/json',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': $('#journal_import_draft').data('csrf-token') },
            data: JSON.stringify({ member_ids: memberIds })
        }).done(function (response) {
            if (response.success && response.row) {
                draftRowsById[String(row.id)] = response.row;
                if (rowElement && rowElement.length) rowElement.replaceWith(renderDraftRow(response.row));
                modal.modal('hide');
                $('#journal_import_draft_status').text(response.msg || 'Grup sumber ditautkan.');
                if (window.toastr) toastr.success(response.msg || 'Grup sumber ditautkan.');
            }
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Pilihan grup sumber gagal disimpan.';
            $('#journal_import_draft_status').addClass('text-danger').text(message);
            button.prop('disabled', false).text('Pilih grup ini');
        });
    });

    $(document).on('click', '.journal-draft-candidate-create', function () {
        var modal = $('#journal_import_candidates_modal');
        var row = modal.data('draft-row');
        var rowElement = modal.data('row-element');
        if (!row) return;
        saveDraftRow(row.id, {
            source_action: 'create',
            source_record_type: null,
            source_record_id: null
        }, rowElement, function (updatedRow) {
            modal.modal('hide');
            openDraftDetails(updatedRow);
        });
    });

    $(document).on('change', '.journal-draft-reviewed', function () {
        var rowElement = $(this).closest('tr');
        var action = rowElement.find('.journal-draft-action').val();
        var payload = actionPayload(rowElement, action);
        payload.is_reviewed = $(this).is(':checked');
        saveDraftRow(rowElement.data('row-id'), payload, rowElement);
    });

    $(document).on('click', '.journal-draft-edit-details', function () {
        var row = $(this).closest('tr').data('draft-row');
        if (row) openDraftDetails(row);
    });
    $(document).on('click', '#journal_draft_add_detail', function () {
        if (activeDetailRow) {
            addDraftDetailLine({}, activeDetailRow.source_type);
            scheduleDraftDetailSave();
        }
    });
    $(document).on('click', '.journal-draft-remove-detail', function () {
        $(this).closest('tr').remove();
        scheduleDraftDetailSave();
    });

    $(document).on('change input', '#journal_import_detail_form .journal-draft-select, #journal_import_detail_form input', function () {
        scheduleDraftDetailSave();
    });

    $(document).on('submit', '#journal_import_detail_form', function (event) {
        event.preventDefault();
        if (!activeDetailRow) return;
        if (detailSaveTimer) window.clearTimeout(detailSaveTimer);
        detailSaveTimer = null;
        saveDraftDetails({
            rowId: activeDetailRow.id,
            draftData: collectDraftData()
        }, true);
    });

    $(function () {
        var draft = $('#journal_import_draft');
        if (draft.length) {
            draftType = String(draft.data('default-type') || 'Journal Entry');
            loadDraftRows(1);
            loadReviewGroups();
        }
    });

    $(document).on('submit', '#journal_import_upload_form', function (event) {
        event.preventDefault();

        var form = $(this);
        var button = form.find('button[type="submit"]');
        var feedback = $('#journal_import_upload_feedback');
        var formData = new FormData(this);
        button.prop('disabled', true).text('Mengunggah dan mengantrekan…');
        feedback.removeClass('text-danger text-success').text('Upload berjalan. Analisis akan diproses oleh worker imports.');

        $.ajax({
            method: 'POST',
            url: form.attr('action'),
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (response) {
            button.prop('disabled', false).html('<i class="fas fa-search"></i> Unggah & Analisa di Background');
            if (!response.success) {
                feedback.addClass('text-danger').text(response.msg || 'File belum dapat dianalisis.');
                return;
            }

            form.find('input[type="file"]').val('');
            feedback.addClass('text-success').text(response.msg || 'File diterima dan analisis berjalan di background.');
            if (historyTable) {
                $('#journal_import_history_status').val('');
                historyTable.search('').ajax.reload(function () {
                    var history = document.getElementById('journal_import_history_table');
                    if (history && typeof history.scrollIntoView === 'function') {
                        history.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    pollWatch(response.import_id || response.batch_id);
                }, true);
            } else {
                pollWatch(response.import_id || response.batch_id);
            }
            if (window.toastr) {
                toastr.success('Batch Journal Import masuk ke antrean.');
            }
        }).fail(function (xhr) {
            button.prop('disabled', false).html('<i class="fas fa-search"></i> Unggah & Analisa di Background');
            var message = xhr.responseJSON && (xhr.responseJSON.msg || xhr.responseJSON.message)
                ? (xhr.responseJSON.msg || xhr.responseJSON.message)
                : 'Upload gagal. Periksa format file, lalu coba kembali.';
            feedback.addClass('text-danger').text(message);
            if (window.toastr) {
                toastr.error(message);
            }
        });
    });

    $(document).on('submit', '#journal_import_form', function (event) {
        event.preventDefault();

        if (!window.confirm('Proses baris Journal Entry yang valid dari draft tersimpan?')) {
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

    $(document).on('click', '.journal-import-detail', function () {
        var button = $(this);
        var actionCell = $('#journal-import-action-' + String(button.data('id')));
        var url = actionCell.data('detail-url');
        var body = $('#journal_import_history_detail_body').empty().append($('<p class="text-muted">').text('Memuat detail batch...'));
        $('#journal_import_history_detail_modal').modal('show');
        if (!url) {
            body.empty().append($('<div class="alert alert-danger">').text('Alamat detail batch tidak tersedia.'));
            return;
        }

        $.getJSON(url).done(function (detail) {
            renderHistoryDetail(detail);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : 'Detail batch tidak dapat dimuat. Periksa izin dan business aktif.';
            body.empty().append($('<div class="alert alert-danger">').text(message));
        });
    });

    function terminateImport(importId, button) {
        if (!window.confirm('Hentikan import setelah baris yang sedang berjalan selesai? Hasil yang sudah tersimpan akan dipertahankan.')) {
            return;
        }

        var id = String(importId || '');
        var actionCell = $('#journal-import-action-' + id);
        var url = actionCell.data('terminate-url') || (String(page.data('terminate-url-base') || '').replace(/\/$/, '') + '/' + encodeURIComponent(id) + '/terminate');
        button.prop('disabled', true).text('Mengirim permintaan...');

        $.ajax({
            method: 'POST',
            url: url,
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': actionCell.data('csrf-token') || $('#journal_import_terminate').data('csrf-token') }
        }).done(function (response) {
            if (response.success) {
                toastr.info(response.msg || 'Permintaan berhenti diterima.');
                if (response.status === 'cancelled') {
                    updateRow({ id: id, status: 'cancelled', last_message: response.msg });
                } else {
                    button.text('Menunggu berhenti');
                    pollWatch(id);
                }
            } else {
                button.prop('disabled', false).text('Terminate');
                toastr.error(response.msg || 'Permintaan terminate gagal.');
            }
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.msg
                ? xhr.responseJSON.msg
                : 'Permintaan terminate gagal. Muat ulang status batch sebelum mencoba lagi.';
            button.prop('disabled', false).text('Terminate');
            toastr.error(message);
        });
    }

    $(document).on('click', '.journal-import-terminate', function () {
        terminateImport($(this).data('id'), $(this));
    });

    $(document).on('submit', '.journal-import-revert', function (event) {
        event.preventDefault();
        var form = $(this);
        var actionCell = form.closest('[id^="journal-import-action-"]');
        var fileName = actionCell.data('file-name') || 'batch Journal Import ini';
        if (!window.confirm('Revert ' + fileName + '? Journal yang dibuat dari batch ini akan dihapus.')) {
            return;
        }

        var button = form.find('button[type="submit"]');
        button.prop('disabled', true).text('Merevert…');
        $.ajax({
            method: 'POST',
            url: form.attr('action'),
            data: form.serialize(),
            dataType: 'json',
            headers: { Accept: 'application/json' }
        }).done(function (response) {
            if (!response.success) {
                button.prop('disabled', false).text('Revert');
                if (window.toastr) {
                    toastr.error(response.msg || 'Revert tidak dapat dilakukan.');
                }
                return;
            }
            if (window.toastr) {
                toastr.success(response.msg || 'Import Journal berhasil direvert.');
            }
            if (historyTable) {
                historyTable.ajax.reload(null, false);
            }
        }).fail(function (xhr) {
            button.prop('disabled', false).text('Revert');
            var message = xhr.responseJSON && xhr.responseJSON.msg
                ? xhr.responseJSON.msg
                : 'Revert gagal. Muat ulang status batch sebelum mencoba kembali.';
            if (window.toastr) {
                toastr.error(message);
            }
        });
    });

    // Server-rendered controls stay disabled until this handler is ready.
    $('.journal-import-terminate[data-cancel-requested="0"]').prop('disabled', false);

    $(function () {
        var tableElement = $('#journal_import_history_table');
        if (!page.length || !tableElement.length || !$.fn.DataTable) {
            return;
        }

        function watchVisibleBatches() {
            tableElement.find('.journal-import-watch').each(function () {
                var button = $(this);
                var id = String(button.data('id') || '');
                if (id && !watchers[id]) {
                    button.prop('disabled', true).text('Memantau...');
                    pollWatch(id);
                }
            });
        }

        historyTable = tableElement.DataTable({
            processing: true,
            serverSide: true,
            searching: true,
            pageLength: 25,
                ordering: false,
            ajax: {
                url: page.data('history-url'),
                data: function (request) {
                    request.status = $('#journal_import_history_status').val() || '';
                },
                error: function () {
                    $('#journal_import_history_message').addClass('text-danger').text('Riwayat import gagal dimuat. Coba perbarui atau periksa izin akses.');
                }
            },
            columns: [
                {
                    data: 'created_at',
                    render: function (value, type) {
                        if (type !== 'display' || !value) {
                            return value || '';
                        }
                        var date = new Date(String(value).replace(' ', 'T'));
                        return isNaN(date.getTime()) ? escapeHtml(value) : escapeHtml(date.toLocaleString());
                    }
                },
                { data: 'file_name', render: function (value, type) { return type === 'display' ? escapeHtml(value || 'Impor tanpa nama file') : (value || ''); } },
                { data: 'period', defaultContent: '', render: function (value, type) { return type === 'display' ? escapeHtml(value || '—') : (value || ''); } },
                { data: 'total_entries', defaultContent: 0 },
                { data: 'imported_entries', defaultContent: 0 },
                { data: 'skipped_entries', defaultContent: 0 },
                { data: 'domain_status', render: function (value, type, row) { return historyStatusCell(row, type); } },
                { data: null, orderable: false, searchable: false, render: function (row, type, data) { return historyActionsCell(data || row, type); } }
            ],
            language: {
                processing: 'Memuat…',
                search: 'Cari:',
                lengthMenu: 'Tampilkan _MENU_ batch',
                info: 'Menampilkan _START_–_END_ dari _TOTAL_ batch',
                infoEmpty: 'Belum ada batch import',
                zeroRecords: 'Tidak ada batch yang cocok',
                paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' }
            },
            drawCallback: watchVisibleBatches
        });

        $('#journal_import_history_status').on('change', function () {
            historyTable.ajax.reload(null, true);
        });
        $('#journal_import_history_refresh').on('click', function () {
            $('#journal_import_history_message').removeClass('text-danger').text('Memperbarui riwayat…');
            historyTable.ajax.reload(function () {
                $('#journal_import_history_message').text('Riwayat diperbarui.');
            }, false);
        });
        window.setInterval(function () {
            if (historyTable && document.visibilityState !== 'hidden') {
                historyTable.ajax.reload(null, false);
            }
        }, 12000);
    });
})(jQuery);
