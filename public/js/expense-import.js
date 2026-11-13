(function ($) {
    'use strict';

    var form = $('#expense_import_form');
    if (!form.length) {
        return;
    }

    var progressModal = $('#expense_import_progress_modal');
    var previewModal = $('#expense_import_preview_modal');
    var dateModal = $('#expense_import_date_modal');
    var activeKey = 'expense_import_active_batch';
    var batchId = null;
    var operation = 'preview';
    var pollTimer = null;
    var pollDelay = 1000;
    var previewTable = null;

    function statusUrl(id) {
        return $('#expense_import_progress_modal').data('status-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function previewUrl(id) {
        return $('#expense_import_preview_modal').data('preview-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function refreshPreviewUrl(id) {
        return $('#expense_import_preview_modal').data('refresh-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function confirmUrl(id) {
        return $('#expense_import_preview_modal').data('confirm-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function cancelUrl(id) {
        return $('#expense_import_progress_modal').data('cancel-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function dateUrl(id, row) {
        return $('#expense_import_preview_modal').data('date-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function taxUrl(id, row) {
        return $('#expense_import_preview_modal').data('tax-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function journalUrl(id, row) {
        return $('#expense_import_preview_modal').data('journal-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function existingExpenseUrl(id, row) {
        return $('#expense_import_preview_modal').data('existing-expense-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    function formatRupiah(value) {
        if (value === null || typeof value === 'undefined' || value === '') { return '—'; }
        return 'Rp ' + Number(value || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function selectableJournalCandidates(journalMatch) {
        if (Array.isArray(journalMatch.selectable_candidates)) {
            return journalMatch.selectable_candidates;
        }
        return (journalMatch.candidates || []).filter(function (candidate) {
            return Number(candidate.mapping_id || 0) > 0 && candidate.ready;
        });
    }

    function renderJournalCandidateDetails(journalMatch) {
        var candidates = journalMatch.candidates || [];
        if (!candidates.length) { return ''; }

        var selectable = selectableJournalCandidates(journalMatch);
        var expanded = candidates.length === 2 ? ' open' : '';
        var html = '<details class="expense-import-journal-candidates"' + expanded + '><summary>Bandingkan isi ' + candidates.length + ' posting Journal</summary>';
        candidates.forEach(function (candidate) {
            var state = candidate.ready ? '<span class="label label-success">Siap ditautkan</span>' : '<span class="label label-warning">Belum siap</span>';
            var lines = candidate.lines || [];
            html += '<div class="tw-mt-2 tw-mb-3"><div><strong>Baris #' + Number(candidate.journal_row_id || 0) + '</strong> · impor #' + Number(candidate.journal_import_id || 0) + ' · GL #' + Number(candidate.mapping_id || 0) + ' · ' + state + '</div>';
            html += '<div class="text-muted">Tanggal ' + escapeHtml(candidate.date || '—') + ' · Referensi ' + escapeHtml(candidate.reference || '—') + '</div>';
            html += '<div>' + escapeHtml(candidate.description || 'Tanpa deskripsi') + '</div>';
            html += '<div>Debit ' + escapeHtml(formatRupiah(candidate.debit_total)) + ' · Kredit ' + escapeHtml(formatRupiah(candidate.credit_total)) + ' · Pajak ' + escapeHtml(candidate.tax_label || formatRupiah(candidate.tax_amount)) + '</div>';
            if (candidate.issue || candidate.tax_issue) {
                html += '<div class="text-danger">' + escapeHtml(candidate.issue || candidate.tax_issue) + '</div>';
            }
            html += '<div class="table-responsive"><table class="table table-condensed table-bordered"><thead><tr><th>Akun</th><th class="text-right">Debit</th><th class="text-right">Kredit</th><th>Klasifikasi</th></tr></thead><tbody>';
            if (!lines.length) {
                html += '<tr><td colspan="4" class="text-muted">Rincian akun belum tersedia; perbarui preview untuk membaca isi Journal terbaru.</td></tr>';
            } else {
                lines.forEach(function (line) {
                    var accountLabel = (line.account_no ? line.account_no + ' · ' : '') + (line.account_name || 'Akun tanpa nama');
                    var classification = line.is_tax ? ('Pajak ' + (line.tax_direction || 'belum terklasifikasi')) : 'Non-pajak';
                    html += '<tr><td>' + escapeHtml(accountLabel) + '</td><td class="text-right">' + escapeHtml(formatRupiah(line.debit)) + '</td><td class="text-right">' + escapeHtml(formatRupiah(line.credit)) + '</td><td>' + escapeHtml(classification) + '</td></tr>';
                });
            }
            html += '</tbody></table></div></div>';
        });
        html += '</details>';
        return html;
    }

    function formatDuration(seconds) {
        seconds = Math.max(0, Number(seconds || 0));
        var minutes = Math.floor(seconds / 60);
        var remainder = Math.floor(seconds % 60);
        return (minutes ? minutes + 'm ' : '') + remainder + 's';
    }

    function setProgress(percent, message, stage, current, total, heartbeat) {
        var value = Math.max(0, Math.min(100, Number(percent || 0)));
        $('#expense_import_progress_bar').css('width', value + '%').text(value + '%');
        $('#expense_import_progress_message').text(message || 'Memproses...');
        $('#expense_import_progress_stage').text(stage || 'processing');
        $('#expense_import_progress_count').text((current || 0) + ' / ' + (total || 0));
        var heartbeatDate = heartbeat ? new Date(heartbeat) : new Date();
        $('#expense_import_progress_heartbeat').text(isNaN(heartbeatDate.getTime()) ? '-' : heartbeatDate.toLocaleTimeString());
    }

    function showProgress(id, currentOperation) {
        batchId = id;
        operation = currentOperation || 'preview';
        localStorage.setItem(activeKey, batchId);
        $('#expense_import_progress_error').hide().empty();
        $('#expense_import_retry_status').hide();
        $('#expense_import_retry_preview').hide();
        $('#expense_import_close_progress').hide();
        $('#expense_import_cancel').show();
        progressModal.modal({ backdrop: 'static', keyboard: false, show: true });
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function updateSummary(summary) {
        summary = summary || {};
        $('[data-expense-summary="total"]').text(summary.total || 0);
        $('[data-expense-summary="valid"]').text(summary.valid || 0);
        $('[data-expense-summary="errors"]').text(summary.errors || 0);
        $('[data-expense-summary="skipped"]').text(summary.skipped || 0);
        $('#expense_import_journal_summary').text(
            'Journal cocok: ' + Number(summary.journal_matched_groups || 0) + ' grup · perlu pilihan: ' + Number(summary.journal_review_groups || 0) + ' grup'
        );
        $('#expense_import_existing_summary').text(
            'Expense lama diperbarui: ' + Number(summary.existing_expense_updated_groups || 0)
            + ' grup · grup split cocok: ' + Number(summary.existing_expense_group_matched_groups || 0)
            + ' · perlu pilihan: ' + Number(summary.existing_expense_review_groups || 0) + ' grup'
        );
        var hasStaleReadyJournalCandidate = Boolean(summary.journal_candidate_refresh_required);
        $('#expense_import_confirm').prop('disabled', Number(summary.importable || 0) < 1 && !hasStaleReadyJournalCandidate);
    }

    function pollStatus(id) {
        stopPolling();
        $.ajax({ url: statusUrl(id), dataType: 'json', headers: { Accept: 'application/json' } })
            .done(function (status) {
                setProgress(status.percent, status.message, status.stage, status.current, status.total, status.heartbeat_at);
                $('#expense_import_progress_elapsed').text(formatDuration(status.elapsed_seconds));
                if (status.stale) {
                    $('#expense_import_progress_error').text('Tidak ada pembaruan dari server. Anda dapat mencoba cek ulang.').show();
                    $('#expense_import_retry_status').show();
                } else {
                    $('#expense_import_progress_error').hide();
                    $('#expense_import_retry_status').hide();
                }
                if (status.state === 'failed') {
                    stopPolling();
                    $('#expense_import_progress_error').text(status.error || 'Proses import gagal.').show();
                    $('#expense_import_cancel').hide();
                    if (status.domain_status === 'preview') {
                        $('#expense_import_retry_preview').show();
                    }
                    $('#expense_import_close_progress').show();
                    return;
                }
                if (status.state === 'cancelled') {
                    stopPolling();
                    $('#expense_import_progress_error').text(status.message || 'Proses dibatalkan.').show();
                    $('#expense_import_cancel').hide();
                    $('#expense_import_close_progress').show();
                    return;
                }
                if (status.state === 'completed') {
                    stopPolling();
                    updateSummary(status.summary);
                    if ((status.operation || operation) === 'import') {
                        localStorage.removeItem(activeKey);
                        progressModal.modal('hide');
                        if (window.toastr) {
                            toastr.success(status.message || 'Import Expense selesai.');
                        }
                        window.setTimeout(function () { window.location.reload(); }, 500);
                    } else {
                        progressModal.modal('hide');
                        openPreview(id);
                    }
                    return;
                }
                pollDelay = status.stale ? 5000 : Math.min(5000, pollDelay + 500);
                pollTimer = window.setTimeout(function () { pollStatus(id); }, pollDelay);
            })
            .fail(function () {
                $('#expense_import_progress_error').text('Status belum dapat dibaca. Coba cek lagi.').show();
                $('#expense_import_retry_status').show();
            });
    }

    function startUpload() {
        var input = document.getElementById('expense_import_file');
        if (!input || !input.files || !input.files.length) {
            return;
        }
        var xhr = new XMLHttpRequest();
        var data = new FormData(form[0]);
        $('#expense_import_submit').prop('disabled', true);
        showProgress('', 'preview');
        setProgress(0, 'Mengunggah file...', 'uploading', 0, 0);
        xhr.open('POST', form.attr('action'), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = function (event) {
            if (event.lengthComputable) {
                setProgress(Math.round((event.loaded / event.total) * 10), 'Mengunggah file...', 'uploading', event.loaded, event.total);
            }
        };
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            $('#expense_import_submit').prop('disabled', false);
            var result = {};
            try { result = JSON.parse(xhr.responseText || '{}'); } catch (ignore) {}
            if (xhr.status >= 200 && xhr.status < 300 && result.batch_id) {
                showProgress(result.batch_id, 'preview');
                pollDelay = 1000;
                pollStatus(result.batch_id);
            } else {
                $('#expense_import_progress_error').text(result.message || 'Upload gagal.').show();
                $('#expense_import_cancel').hide();
                $('#expense_import_close_progress').show();
            }
        };
        xhr.send(data);
    }

    function openPreview(id) {
        batchId = id;
        updateSummary({});
        previewModal.modal({ backdrop: 'static', keyboard: false, show: true });
        if (previewTable) {
            previewTable.ajax.url(previewUrl(id)).load();
            return;
        }
        if (!$.fn.DataTable) {
            return;
        }
        var previewTableOptions = {
            processing: true,
            serverSide: true,
            autoWidth: false,
            pageLength: 25,
            lengthMenu: [[25, 50, 100], [25, 50, 100]],
            ajax: {
                url: previewUrl(id),
                data: function (request) {
                    request.status = $('#expense_import_preview_status').val() || '';
                },
                dataSrc: function (json) {
                    updateSummary(json.summary);
                    return json.data || [];
                }
            },
            columns: [
                { data: 'source_row' },
                { data: 'reference_no', render: function (value) { return escapeHtml(value || '-'); } },
                { data: 'expense_date', render: function (value, type, row) { return escapeHtml(value || row.raw_expense_date || '-'); } },
                { data: 'paid_on_date', render: function (value, type, row) { return escapeHtml(value || row.raw_paid_on_date || '-'); } },
                { data: 'tax_status', render: function (value, type, row) {
                    var journalMatch = row.journal_match || {};
                    var candidateCount = (journalMatch.candidates || []).length;
                    var selectableCount = selectableJournalCandidates(journalMatch).length;
                    var candidateSummary = candidateCount > 1
                        ? '<div class="text-muted">' + candidateCount + ' posting ditemukan · ' + selectableCount + ' siap. Rincian ada di kolom Detail.</div>'
                        : '';
                    if (journalMatch.status === 'matched') {
                        var selected = journalMatch.selected || {};
                        if (selected.issue || selected.tax_issue) {
                            return '<span class="label label-danger">Perlu ditinjau</span><div class="text-danger">' + escapeHtml(selected.issue || selected.tax_issue) + '</div>' + candidateSummary;
                        }
                        return '<span class="label label-info">' + escapeHtml(selected.tax_label || 'Journal cocok') + '</span>' + candidateSummary;
                    }
                    if (journalMatch.status === 'ambiguous' || journalMatch.status === 'selection_invalid') {
                        return '<span class="label label-warning">Pilih 1 dari ' + selectableCount + ' posting siap</span>' + candidateSummary;
                    }
                    if (journalMatch.status === 'unavailable') {
                        return '<span class="label label-warning">' + candidateCount + ' posting ditemukan, belum siap</span>' + candidateSummary;
                    }
                    if (value === 'unknown') {
                        return '<span class="label label-warning">Belum dikenal: ' + escapeHtml(row.raw_applicable_tax || '-') + '</span>';
                    }
                    if (value === 'none') { return '<span class="text-muted">Excel: tanpa pajak</span>'; }
                    return escapeHtml(row.tax_name || (row.payload && row.payload.applicable_tax) || '-');
                } },
                { data: 'status', render: function (value, type, row) {
                    var output = value === 'valid' ? '<span class="label label-success">Valid</span>' : (value === 'skipped' ? '<span class="label label-default">Dilewati</span>' : '<span class="label label-danger">Error</span>');
                    var existing = row.existing_expense_match || {};
                    if (existing.review_required && !existing.action) {
                        output += '<div><span class="label label-warning">Pilih aksi untuk referensi lama</span></div>';
                    } else if (existing.action && existing.action.indexOf('merge:') === 0) {
                        output += '<div><span class="label label-info">Perbarui Expense #' + Number(existing.selected_transaction_id || existing.action.split(':')[1]) + '</span></div>';
                    } else if (existing.action && existing.action.indexOf('group:') === 0) {
                        var selectedGroup = existing.selected_group || {};
                        output += '<div><span class="label label-info">Cocokkan grup split (' + Number((selectedGroup.member_ids || []).length) + ' transaksi; tetap terpisah)</span></div>';
                    } else if (existing.action === 'new') {
                        output += '<div><span class="label label-warning">Expense baru dipilih</span></div>';
                    }
                    return output;
                } },
                { data: 'errors', render: function (value, type, row) {
                    var details = (value || []).map(escapeHtml);
                    var existing = row.existing_expense_match || {};
                    if (existing.review_required) {
                        (existing.candidates || []).forEach(function (candidate) {
                            var dateDiff = candidate.date_difference_days === null ? 'tanggal tidak tersedia' : Number(candidate.date_difference_days) + ' hari';
                            details.push('Expense #' + Number(candidate.id) + ': lama ' + escapeHtml(candidate.date || '-') + ' / Rp ' + Number(candidate.total || 0).toLocaleString('id-ID') + '; Excel ' + escapeHtml(candidate.incoming_date || '-') + ' / Rp ' + Number(candidate.incoming_total || 0).toLocaleString('id-ID') + '; beda ' + dateDiff + ' dan Rp ' + Number(candidate.amount_difference || 0).toLocaleString('id-ID'));
                        });
                        (existing.groups || []).forEach(function (group) {
                            var members = (group.members || []).map(function (member) {
                                return 'Expense #' + Number(member.id) + ' (' + formatRupiah(member.amount) + ')';
                            }).join(' + ');
                            details.push('Grup split cocok: ' + members + ' = ' + formatRupiah(group.total_amount)
                                + ' · dasar ' + formatRupiah(group.base_amount) + ' · pajak ' + formatRupiah(group.tax_amount)
                                + ' · referensi dan tanggal sama. Pilih grup untuk menautkan tanpa menggabungkan transaksi. Saat import, mapping Expense lama hanya dibersihkan bila terbukti sebagai pasangan duplikat Journal.');
                        });
                        if (existing.group_status === 'limited') {
                            details.push('Pencarian kombinasi Expense dibatasi karena kandidat terlalu banyak; tidak ada grup yang disarankan otomatis.');
                        }
                    }
                    return details.join('<br>') + renderJournalCandidateDetails(row.journal_match || {});
                } },
                { data: null, orderable: false, searchable: false, render: function (value, type, row) {
                    var actions = '';
                    var existing = row.existing_expense_match || {};
                    if (existing.review_required) {
                        var existingSelect = '<select class="form-control input-sm expense-import-existing-action" data-row="' + row.id + '"><option value="">Pilih aksi transaksi</option>';
                        (existing.groups || []).forEach(function (group) {
                            var groupValue = 'group:' + String(group.fingerprint || '');
                            var memberIds = (group.member_ids || []).map(function (id) { return '#' + Number(id); }).join(' + ');
                            var groupLabel = 'Cocokkan grup split ' + memberIds + ' · ' + formatRupiah(group.total_amount) + ' (tetap terpisah)';
                            existingSelect += '<option value="' + escapeHtml(groupValue) + '"' + (existing.action === groupValue ? ' selected' : '') + '>' + escapeHtml(groupLabel) + '</option>';
                        });
                        if (!existing.group_linked) {
                            (existing.candidates || []).forEach(function (candidate) {
                                var mergeValue = 'merge:' + Number(candidate.id);
                                var selected = existing.action === mergeValue ? ' selected' : '';
                                existingSelect += '<option value="' + mergeValue + '"' + selected + '>Gabungkan ke Expense #' + Number(candidate.id) + '</option>';
                            });
                        }
                        existingSelect += '<option value="new"' + (existing.action === 'new' ? ' selected' : '') + '>Buat transaksi baru</option>';
                        existingSelect += '<option value="skip"' + (existing.action === 'skip' ? ' selected' : '') + '>Lewati</option></select>';
                        actions += existingSelect + ' ';
                    }
                    if (row.status === 'error' && !row.expense_date && row.raw_expense_date) { actions += '<button type="button" class="btn btn-xs btn-primary expense-import-edit-date" data-row="' + row.id + '" data-field="expense_date"><i class="fa fa-calendar"></i> Tanggal</button> '; }
                    if (row.status === 'error' && !row.paid_on_date && row.raw_paid_on_date) { actions += '<button type="button" class="btn btn-xs btn-primary expense-import-edit-date" data-row="' + row.id + '" data-field="paid_on_date"><i class="fa fa-calendar"></i> Bayar</button> '; }
                    if (row.tax_status === 'unknown' || row.tax_status === 'mapped' || row.tax_mapping_required) {
                        var options = window.expenseImportTaxOptions || [];
                        var select = '<select class="form-control input-sm expense-import-map-tax" data-row="' + row.id + '"><option value="">Pilih TaxRate / grup</option>';
                        options.forEach(function (tax) {
                            var selected = Number(row.tax_id) === Number(tax.id) ? ' selected' : '';
                            select += '<option value="' + Number(tax.id) + '"' + selected + '>' + escapeHtml(tax.label) + '</option>';
                        });
                        actions += select + '</select>';
                    }
                    var journalMatch = row.journal_match || {};
                    if (journalMatch.status === 'ambiguous' || journalMatch.status === 'selection_invalid') {
                        var candidates = journalMatch.candidates || [];
                        var selectable = selectableJournalCandidates(journalMatch);
                        if (candidates.length) {
                            var journalSelect = '<select class="form-control input-sm expense-import-map-journal" data-row="' + row.id + '"><option value="">Pilih posting Journal</option>';
                            candidates.forEach(function (candidate) {
                                var mappingId = Number(candidate.mapping_id || 0);
                                var canSelect = mappingId > 0 && selectable.some(function (ready) { return Number(ready.mapping_id || 0) === mappingId; });
                                var label = (candidate.ready ? 'Siap' : 'Belum siap') + ' · ' + (candidate.date || 'tanggal —')
                                    + ' · debit ' + formatRupiah(candidate.debit_total) + ' · kredit ' + formatRupiah(candidate.credit_total)
                                    + ' · baris #' + Number(candidate.journal_row_id || 0);
                                if (!canSelect && (candidate.issue || candidate.tax_issue)) { label += ' · ' + (candidate.issue || candidate.tax_issue); }
                                journalSelect += '<option value="' + (canSelect ? mappingId : '') + '"' + (canSelect ? '' : ' disabled') + '>' + escapeHtml(label) + '</option>';
                            });
                            actions += journalSelect + '</select>';
                        }
                    }
                    if (journalMatch.status === 'unavailable') {
                        actions += '<span class="text-muted">Posting Journal belum siap</span> ';
                    }
                    return actions || '<span class="text-muted">-</span>';
                } }
            ],
            order: [[0, 'asc']],
            createdRow: function (row) {
                var labels = ['Baris Excel', 'Referensi', 'Tanggal', 'Tanggal bayar', 'Aksi', 'Pajak', 'Status', 'Detail'];
                $(row).children('td').each(function (index) {
                    $(this).attr('data-label', labels[index] || '');
                });
            }
        };
        previewTableOptions.columns.splice(4, 0, previewTableOptions.columns.pop());
        previewTable = $('#expense_import_preview_table').DataTable(previewTableOptions);
    }

    $('#expense_import_preview_status').on('change', function () {
        if (previewTable) {
            previewTable.ajax.reload();
        }
    });

    $('#expense_import_refresh_preview').on('click', function () {
        if (!batchId) { return; }
        var button = $(this);
        button.prop('disabled', true).addClass('disabled');
        $.ajax({
            url: refreshPreviewUrl(batchId),
            method: 'POST',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: { _token: form.find('input[name="_token"]').val() }
        }).done(function (response) {
            previewModal.modal('hide');
            showProgress(response.batch_id || batchId, 'preview');
            pollDelay = 1000;
            pollStatus(response.batch_id || batchId);
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'Preview gagal dijadwalkan ulang.';
            if (window.toastr) { toastr.error(message); }
        }).always(function () {
            button.prop('disabled', false).removeClass('disabled');
        });
    });

    $('#expense_import_preview_table').on('change', '.expense-import-map-tax', function () {
        var select = $(this);
        var taxRateId = select.val();
        if (!batchId || !taxRateId) { return; }

        select.prop('disabled', true);
        $.ajax({
            url: taxUrl(batchId, select.data('row')),
            method: 'PATCH',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: {
                _token: form.find('input[name="_token"]').val(),
                tax_rate_id: taxRateId
            }
        }).done(function (response) {
            updateSummary(response.summary || {});
            if (previewTable) { previewTable.ajax.reload(null, false); }
            if (window.toastr) { toastr.success(response.message || 'TaxRate berhasil dipetakan.'); }
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'TaxRate gagal dipetakan.';
            if (window.toastr) { toastr.error(message); }
            select.val('');
        }).always(function () {
            select.prop('disabled', false);
        });
    });

    $('#expense_import_preview_table').on('change', '.expense-import-map-journal', function () {
        var select = $(this);
        var journalMappingId = select.val();
        if (!batchId || !journalMappingId) { return; }

        select.prop('disabled', true);
        $.ajax({
            url: journalUrl(batchId, select.data('row')),
            method: 'PATCH',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: {
                _token: form.find('input[name="_token"]').val(),
                journal_mapping_id: journalMappingId
            }
        }).done(function (response) {
            updateSummary(response.summary || {});
            if (previewTable) { previewTable.ajax.reload(null, false); }
            if (window.toastr) { toastr.success(response.message || 'Posting Journal dipilih.'); }
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'Posting Journal gagal dipilih.';
            if (window.toastr) { toastr.error(message); }
            select.val('');
        }).always(function () {
            select.prop('disabled', false);
        });
    });

    $('#expense_import_preview_table').on('change', '.expense-import-existing-action', function () {
        var select = $(this);
        var action = select.val();
        if (!batchId || !action) { return; }

        select.prop('disabled', true);
        $.ajax({
            url: existingExpenseUrl(batchId, select.data('row')),
            method: 'PATCH',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: {
                _token: form.find('input[name="_token"]').val(),
                action: action
            }
        }).done(function (response) {
            updateSummary(response.summary || {});
            if (previewTable) { previewTable.ajax.reload(null, false); }
            if (window.toastr) { toastr.success(response.message || 'Aksi transaksi diperbarui.'); }
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'Aksi transaksi gagal disimpan.';
            if (window.toastr) { toastr.error(message); }
            select.val('');
        }).always(function () {
            select.prop('disabled', false);
        });
    });

    form.on('submit', function (event) {
        if (window.FormData && window.XMLHttpRequest) {
            event.preventDefault();
            startUpload();
        }
    });

    $('#expense_import_retry_status').on('click', function () {
        if (batchId) { pollStatus(batchId); }
    });

    $('#expense_import_retry_preview').on('click', function () {
        if (!batchId) { return; }
        progressModal.modal('hide');
        openPreview(batchId);
    });

    $('#expense_import_cancel').on('click', function () {
        if (!batchId) { progressModal.modal('hide'); return; }
        $.post(cancelUrl(batchId), { _token: form.find('input[name="_token"]').val() })
            .always(function () { pollStatus(batchId); });
    });

    $('#expense_import_confirm').on('click', function () {
        if (!batchId || $(this).prop('disabled')) { return; }
        $.ajax({ url: confirmUrl(batchId), method: 'POST', dataType: 'json', headers: { Accept: 'application/json' }, data: { _token: form.find('input[name="_token"]').val() } })
            .done(function (result) {
                previewModal.modal('hide');
                var nextOperation = result.operation || 'import';
                showProgress(result.batch_id || batchId, nextOperation);
                if (result.message) {
                    setProgress(0, result.message, nextOperation === 'preview' ? 'refreshing_preview' : 'queued', 0, 0);
                }
                pollDelay = 1000;
                pollStatus(result.batch_id || batchId);
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Import tidak dapat dimulai.';
                if (window.toastr) { toastr.error(message); }
            });
    });

    $(document).on('click', '.expense-import-edit-date', function () {
        $('#expense_import_date_row').val($(this).data('row'));
        $('#expense_import_date_field').val($(this).data('field'));
        $('#expense_import_date_value').val('');
        $('#expense_import_date_error').hide().empty();
        dateModal.modal('show');
    });

    $('#expense_import_date_form').on('submit', function (event) {
        event.preventDefault();
        var formElement = $(this);
        var submitButton = formElement.find('button[type="submit"]');
        var row = $('#expense_import_date_row').val();
        var field = $('#expense_import_date_field').val();
        var date = $('#expense_import_date_value').val();
        submitButton.prop('disabled', true).addClass('disabled');
        $.ajax({ url: dateUrl(batchId, row), method: 'PATCH', dataType: 'json', headers: { Accept: 'application/json' }, data: { _token: form.find('input[name="_token"]').val(), field: field, date: date } })
            .done(function (result) {
                dateModal.modal('hide');
                updateSummary(result.summary);
                if (previewTable) { previewTable.ajax.reload(null, false); }
                if (window.toastr) { toastr.success('Tanggal diperbarui.'); }
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Tanggal tidak valid.';
                $('#expense_import_date_error').text(message).show();
            })
            .always(function () { submitButton.prop('disabled', false).removeClass('disabled'); });
    });

    $(document).on('click', '.expense-import-resume', function () {
        var id = $(this).data('batch');
        $.getJSON(statusUrl(id)).done(function (status) {
            if (status.state === 'completed' && status.domain_status === 'preview') {
                openPreview(id);
            } else {
                showProgress(id, status.operation || 'preview');
                pollStatus(id);
            }
        });
    });

    var savedBatch = localStorage.getItem(activeKey);
    if (savedBatch) {
        $.getJSON(statusUrl(savedBatch)).done(function (status) {
            if (status.state !== 'completed' || status.domain_status === 'preview') {
                showProgress(savedBatch, status.operation || 'preview');
                pollStatus(savedBatch);
            } else {
                localStorage.removeItem(activeKey);
            }
        }).fail(function () { localStorage.removeItem(activeKey); });
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
