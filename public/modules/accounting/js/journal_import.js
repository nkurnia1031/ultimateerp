(function ($) {
    'use strict';

    var page = $('#journal_import_page');
    var statusBase = String(page.data('status-url-base') || '').replace(/\/$/, '');
    var activeStatuses = String(page.data('active-statuses') || '').split(',').filter(Boolean);
    var waitingStatuses = String(page.data('waiting-statuses') || '').split(',').filter(Boolean);
    var queuedStatuses = String(page.data('queued-statuses') || '').split(',').filter(Boolean);
    var watchers = {};

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
        if (isActive(response.status)) {
            var activeContent = $('<span class="text-muted">').text(statusLabel(response.status) + '... ');
            actionCell.empty().append(activeContent);
            if (response.cancel_requested) {
                actionCell.append($('<button type="button" class="btn btn-default btn-xs journal-import-terminate" disabled>')
                    .text('Menunggu berhenti'));
            } else {
                actionCell.append($('<button type="button" class="btn btn-warning btn-xs journal-import-terminate">')
                    .attr('data-id', response.id)
                    .text('Terminate'));
            }
        } else if (['analyzed', 'partially_imported', 'failed', 'cancelled'].indexOf(response.status) !== -1) {
            actionCell.empty().append($('<a class="btn btn-primary btn-xs">')
                .attr('href', actionCell.data('review-url'))
                .text(response.status === 'analyzed' ? 'Review & Import' : (response.status === 'cancelled' ? 'Lanjutkan import' : 'Review & Continue')));
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
        }
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
        } else if (row.source_link_status === 'ambiguous') {
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

    function renderDraftRow(row) {
        draftRowsById[String(row.id)] = row;
        var tr = $('<tr>').attr('data-row-id', row.id).data('draft-row', row);
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
            if (candidates.length) {
                var candidateSelect = $('<select class="form-control input-sm journal-draft-source" aria-label="Pilih sumber transaksi">');
                candidateSelect.append($('<option>').val('').text('Pilih sumber...'));
                candidates.forEach(function (candidate) {
                    var value = String(candidate.record_type || '') + '|' + String(candidate.id || '');
                    candidateSelect.append($('<option>').val(value).text(candidate.label || ('Sumber #' + candidate.id)));
                });
                if (row.source_link_status === 'ambiguous') {
                    candidateSelect.append($('<option>').val('create').text('Buat baru secara eksplisit'));
                }
                if (row.source_record_type && row.source_record_id) {
                    candidateSelect.val(String(row.source_record_type) + '|' + String(row.source_record_id));
                } else if (row.source_action === 'create') {
                    candidateSelect.val('create');
                }
                candidateSelect.prop('disabled', ['queued', 'running', 'completed'].indexOf(row.processing_status) !== -1);
                sourceCell.append(candidateSelect);
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
                $('<tr>').append($('<td colspan="9" class="text-center text-muted">').text(noDraftRowsMessage()))
            );
        }
        updateDraftPaginationDisplay();
    }

    function loadDraftRows(pageNumber) {
        var draft = $('#journal_import_draft');
        if (!draft.length || !draftType) {
            return;
        }
        draftPage = pageNumber || 1;
        var requestNumber = ++draftRequestNumber;
        $('#journal_import_draft_status').text('Memuat baris...');
        $('#journal_import_draft_table tbody').empty().append(
            $('<tr>').append($('<td colspan="9" class="text-center text-muted">').text('Memuat draft...'))
        );

        $.ajax({
            method: 'GET',
            url: draft.data('rows-url'),
            dataType: 'json',
            data: {
                type: draftType,
                processing_filter: $('#journal_import_processing_filter').val() || 'remaining',
                review_filter: $('#journal_import_review_filter').val() || 'all',
                status_filter: $('#journal_import_status_filter').val() || 'all',
                search: $('#journal_import_draft_search').val() || '',
                page: draftPage,
                per_page: 50
            }
        }).done(function (response) {
            if (requestNumber !== draftRequestNumber) return;
            var tbody = $('#journal_import_draft_table tbody').empty();
            if (!response.success || !response.rows.length) {
                tbody.append($('<tr>').append($('<td colspan="9" class="text-center text-muted">').text(noDraftRowsMessage())));
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
        }).fail(function (xhr) {
            if (requestNumber !== draftRequestNumber) return;
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Gagal memuat baris draft.';
            $('#journal_import_draft_status').text(message).addClass('text-danger');
            $('#journal_import_draft_table tbody').empty().append(
                $('<tr>').append($('<td colspan="9" class="text-center text-danger">').text(message))
            );
        });
    }

    function saveDraftRow(rowId, payload, rowElement) {
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
                    rowElement.removeClass('journal-draft-saving').addClass('success');
                    window.setTimeout(function () { rowElement.removeClass('success'); }, 900);
                }
                status.text(response.msg || 'Draft tersimpan.');
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
                rowElement.find('.journal-draft-action').val(savedRow.source_action || 'review');
                rowElement.find('.journal-draft-reviewed').prop('checked', Boolean(savedRow.is_reviewed));
                var savedSource = savedRow.source_action === 'create'
                    ? 'create'
                    : (savedRow.source_record_type && savedRow.source_record_id
                        ? savedRow.source_record_type + '|' + savedRow.source_record_id
                        : '');
                rowElement.find('.journal-draft-source').val(savedSource);
            }
            var message = xhr.responseJSON && xhr.responseJSON.msg ? xhr.responseJSON.msg : 'Perubahan belum tersimpan. Coba lagi.';
            status.addClass('text-danger').text(message);
        });
    }

    function selectedSource(rowElement) {
        var value = rowElement.find('.journal-draft-source').val();
        if (!value || value === 'create') {
            return { type: null, id: null };
        }
        var parts = String(value).split('|');
        return { type: parts[0] || null, id: Number(parts[1]) || null };
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
        configureDraftSelect('#journal_draft_location', 'location', row.source_type, data.location_id || null);
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
        loadDraftRows(1);
    });

    $(document).on('change', '#journal_import_review_filter', function () {
        loadDraftRows(1);
    });
    $(document).on('change', '#journal_import_processing_filter', function () {
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

    $(document).on('change', '.journal-draft-action', function () {
        var rowElement = $(this).closest('tr');
        saveDraftRow(rowElement.data('row-id'), actionPayload(rowElement, $(this).val()), rowElement);
    });

    $(document).on('change', '.journal-draft-source', function () {
        var rowElement = $(this).closest('tr');
        var value = $(this).val();
        var action = value === 'create' ? 'create' : (value ? 'update' : 'review');
        rowElement.find('.journal-draft-action').val(action);
        saveDraftRow(rowElement.data('row-id'), actionPayload(rowElement, action), rowElement);
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
        }
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

    $(function () {
        var button = $('.journal-import-watch').first();
        if (button.length) {
            button.prop('disabled', true).text('Memantau...');
            pollWatch(button.data('id'));
        }
    });
})(jQuery);
