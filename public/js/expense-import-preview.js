(function ($) {
    'use strict';

    var previewPage = $('#expense_import_preview_page');
    var form = $('#expense_import_csrf_form');
    if (!previewPage.length || !form.length) {
        return;
    }

    var config = $('#expense_import_preview_config');
    var dateModal = $('#expense_import_date_modal');
    var batchId = null;
    var previewTable = null;
    var confirmButton = $('#expense_import_confirm');
    var confirmFeedback = $('#expense_import_confirm_feedback');
    var confirmAvailable = false;
    var confirmPending = false;

    function previewUrl(id) {
        return config.data('preview-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function refreshPreviewUrl(id) {
        return config.data('refresh-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function confirmUrl(id) {
        return config.data('confirm-template').replace('__BATCH__', encodeURIComponent(id));
    }

    function dateUrl(id, row) {
        return config.data('date-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function taxUrl(id, row) {
        return config.data('tax-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function journalUrl(id, row) {
        return config.data('journal-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function existingExpenseUrl(id, row) {
        return config.data('existing-expense-template').replace('__BATCH__', encodeURIComponent(id)).replace('__ROW__', encodeURIComponent(row));
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    function escapeMultiline(value) {
        return escapeHtml(value).replace(/\r\n|\r|\n/g, '<br>');
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
            + ' grup · Expense digabung: ' + Number(summary.existing_expense_group_matched_groups || 0)
            + ' · perlu pilihan: ' + Number(summary.existing_expense_review_groups || 0) + ' grup'
        );
        var hasStaleReadyJournalCandidate = Boolean(summary.journal_candidate_refresh_required);
        confirmAvailable = Number(summary.importable || 0) > 0 || hasStaleReadyJournalCandidate;
        confirmButton.prop('disabled', confirmPending || !confirmAvailable);
    }

    function showConfirmFeedback(message, type, includeHistoryLink) {
        confirmFeedback
            .removeClass('alert-info alert-success alert-warning alert-danger')
            .addClass('alert alert-' + type)
            .attr('role', type === 'danger' ? 'alert' : 'status')
            .text(message)
            .show();

        if (includeHistoryLink) {
            $('<a>')
                .attr('href', previewPage.data('history-url'))
                .addClass('tw-ml-2')
                .text('Buka riwayat import')
                .appendTo(confirmFeedback);
        }
    }

    function requestPreviewRefresh(button) {
        if (!batchId) { return; }
        button.prop('disabled', true).addClass('disabled');
        $.ajax({
            url: refreshPreviewUrl(batchId),
            method: 'POST',
            dataType: 'json',
            headers: { Accept: 'application/json' },
            data: { _token: form.find('input[name="_token"]').val() }
        }).done(function (response) {
            window.location.assign(response.history_url || previewPage.data('history-url'));
        }).fail(function (xhr) {
            var message = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.msg)) || 'Preview gagal dijadwalkan ulang.';
            if (window.toastr) { toastr.error(message); }
        }).always(function () {
            button.prop('disabled', false).removeClass('disabled');
        });
    }

    function openPreview(id) {
        batchId = id;
        updateSummary({});
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
                        var allocation = (row.payload && row.payload.journal_expense_allocation) || {};
                        var allocationNote = '';
                        if (row.payload && row.payload.journal_expense_tax_component) {
                            allocationNote = '<div class="text-info">Nilai baris masuk ke atribut pajak Expense</div>';
                        } else if (allocation.method === 'inclusive_vat_allocation') {
                            allocationNote = '<div class="text-muted">Basis dihitung dari nilai termasuk VAT Journal</div>';
                        }
                        return '<span class="label label-info">' + escapeHtml(selected.tax_label || 'Journal cocok') + '</span>' + allocationNote + candidateSummary;
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
                        var survivorId = Number((selectedGroup.member_ids || [])[0] || 0);
                        output += '<div><span class="label label-info">Gabungkan ' + Number((selectedGroup.member_ids || []).length) + ' Expense menjadi #' + survivorId + '</span></div>';
                    } else if (existing.action === 'new') {
                        output += '<div><span class="label label-warning">Expense baru dipilih</span></div>';
                    }
                    return output;
                } },
                { data: 'errors', render: function (value, type, row) {
                    var details = (value || []).map(escapeHtml);
                    var existing = row.existing_expense_match || {};
                    var journalMatch = row.journal_match || {};
                    if (existing.comparison_year) {
                        details.push('Perbandingan Expense dibatasi pada tahun ' + Number(existing.comparison_year) + '. Total baris workbook: ' + formatRupiah(existing.incoming_total) + '.');
                    }
                    (existing.incoming_lines || []).forEach(function (line) {
                        var label = 'Baris Excel #' + Number(line.source_row || 0);
                        if (line.category) { label += ' · ' + escapeHtml(line.category); }
                        if (line.description) { label += ' · ' + escapeHtml(line.description); }
                        label += ' · ' + escapeHtml(line.date || '-') + ' · ' + formatRupiah(line.amount);
                        if (Number(line.tax || 0) > 0) {
                            label += ' (basis ' + formatRupiah(line.base) + ', pajak ' + formatRupiah(line.tax)
                                + (line.tax_name ? ' ' + escapeHtml(line.tax_name) : '') + ')';
                        }
                        details.push(label);
                        if (line.attachment) {
                            details.push('ATTACH DOCUMENT baris Excel #' + Number(line.source_row || 0) + ': ' + escapeMultiline(line.attachment));
                        }
                    });
                    (row.warnings || []).forEach(function (warning) {
                        details.push('<strong class="text-warning">Peringatan:</strong> ' + escapeMultiline(warning));
                    });
                    if (journalMatch.comparison_year) {
                        details.push('Posting Journal hanya dicocokkan pada tahun ' + Number(journalMatch.comparison_year) + ' sesuai tanggal workbook.');
                        if (journalMatch.status === 'none') {
                            details.push('Tidak ada posting Journal Expense yang cocok pada tahun tersebut; kandidat dari tahun lain diabaikan.');
                        }
                    }
                    if (existing.review_required) {
                        (existing.candidates || []).forEach(function (candidate) {
                            var dateDiff = candidate.date_difference_days === null ? 'tanggal tidak tersedia' : Number(candidate.date_difference_days) + ' hari';
                            details.push('Expense #' + Number(candidate.id) + ': lama ' + escapeHtml(candidate.date || '-') + ' / Rp ' + Number(candidate.total || 0).toLocaleString('id-ID') + '; Excel ' + escapeHtml(candidate.incoming_date || '-') + ' / Rp ' + Number(candidate.incoming_total || 0).toLocaleString('id-ID') + '; beda ' + dateDiff + ' dan Rp ' + Number(candidate.amount_difference || 0).toLocaleString('id-ID'));
                        });
                        if (existing.group_status === 'limited') {
                            details.push('Pencarian kombinasi Expense dibatasi karena kandidat terlalu banyak; tidak ada grup yang disarankan otomatis.');
                        }
                    }
                    if ((existing.source_notes || []).length) {
                        details.push('<strong>Catatan Expense sumber tetap disimpan pada catatan transaksi induk.</strong>');
                        (existing.source_notes || []).forEach(function (source) {
                            details.push('<strong>Catatan Expense #' + Number(source.id || 0) + ':</strong> ' + escapeMultiline(source.note || ''));
                        });
                    }
                    (existing.groups || []).forEach(function (group) {
                        var members = (group.members || []).map(function (member) {
                            return 'Expense #' + Number(member.id) + ' (' + escapeHtml(member.date || 'tanggal kosong') + ', ' + formatRupiah(member.amount) + ')';
                        }).join(' + ');
                        if (group.consolidation_mode === 'same_year_expense') {
                            details.push((group.consolidation_safe === false ? 'Kombinasi nominal ditemukan tetapi belum aman digabung: ' : 'Kandidat gabung satu tahun: ')
                                + members + ' menjadi satu Expense = ' + formatRupiah(group.total_amount)
                                + (group.consolidation_safe === false
                                    ? ' · ' + escapeHtml(group.consolidation_issue || 'perlu pemeriksaan GL tambahan')
                                    : '. Rincian posting GL backfill dan akun asal dipertahankan setelah saldo debit/kredit diverifikasi.'));
                        } else {
                            var journalTax = Number(group.journal_tax_amount || 0);
                            details.push('Gabungkan ' + members + ' menjadi satu Expense = ' + formatRupiah(group.total_amount)
                                + ' · basis Journal ' + formatRupiah(group.journal_base_amount)
                                + ' · pajak Journal ' + formatRupiah(journalTax)
                                + (journalTax > 0 ? ' (dicatat pada atribut pajak)' : ' (tidak ada VAT Journal)')
                                + '. Tanggal transaksi hasil mengikuti tanggal Journal/workbook. Riwayat pembayaran dan lampiran transaksi dipertahankan; rincian biaya mengikuti baris workbook dan baris VAT yang teridentifikasi masuk ke atribut pajak.');
                        }
                    });
                    (existing.consolidation_issues || []).forEach(function (issue) {
                        details.push('Grup belum aman digabung: ' + escapeHtml(issue));
                    });
                    var detailHtml = details.join('<br>') + renderJournalCandidateDetails(row.journal_match || {});
                    var detailText = $('<div>').html(detailHtml
                        .replace(/<br\s*\/?>/gi, '\n')
                        .replace(/<\/(div|tr|p|li|summary)>/gi, '\n')).text();
                    return window.importHistoryMessageCell(detailText, type);
                } },
                { data: null, orderable: false, searchable: false, render: function (value, type, row) {
                    var actions = '';
                    var existing = row.existing_expense_match || {};
                    if (existing.review_required || (existing.groups || []).length > 0) {
                        var existingSelect = '<select class="form-control input-sm expense-import-existing-action" data-row="' + row.id + '"><option value="">Pilih aksi transaksi</option>';
                        (existing.groups || []).forEach(function (group) {
                            if (group.consolidation_safe === false) { return; }
                            var groupValue = 'group:' + String(group.fingerprint || '');
                            var memberIds = (group.member_ids || []).map(function (id) { return '#' + Number(id); }).join(' + ');
                            var groupLabel = 'Gabungkan Expense ' + memberIds + ' menjadi satu · ' + formatRupiah(group.total_amount);
                            existingSelect += '<option value="' + escapeHtml(groupValue) + '"' + (existing.action === groupValue ? ' selected' : '') + '>' + escapeHtml(groupLabel) + '</option>';
                        });
                        if (!existing.group_linked) {
                            (existing.candidates || []).forEach(function (candidate) {
                                var hasJournalLinkedCandidate = (existing.candidates || []).some(function (item) { return item.journal_linked; });
                                if (hasJournalLinkedCandidate && !candidate.journal_linked) { return; }
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
        requestPreviewRefresh($(this));
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

    confirmButton.on('click', function () {
        if (!batchId || confirmPending || confirmButton.prop('disabled')) { return; }
        confirmPending = true;
        confirmButton.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Mengirim import...');
        confirmFeedback.hide().empty();
        showConfirmFeedback('Permintaan import sedang dikirim. Mohon tunggu...', 'info', false);

        var redirecting = false;
        $.ajax({
            url: confirmUrl(batchId),
            method: 'POST',
            dataType: 'json',
            timeout: 60000,
            headers: { Accept: 'application/json' },
            data: { _token: form.find('input[name="_token"]').val() }
        })
            .done(function (result) {
                if (!result || result.success === false) {
                    showConfirmFeedback((result && result.message) || 'Server belum menerima permintaan import.', 'danger', true);
                    if (window.toastr) { toastr.error((result && result.message) || 'Server belum menerima permintaan import.'); }
                    return;
                }
                redirecting = true;
                showConfirmFeedback('Permintaan diterima. Membuka riwayat untuk menampilkan progres...', 'success', false);
                window.location.assign(result.history_url || previewPage.data('history-url'));
            })
            .fail(function (xhr, status) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Import tidak dapat dimulai.';
                if (status === 'timeout') {
                    message = 'Server belum memberi respons. Periksa riwayat import sebelum mencoba kembali.';
                }
                showConfirmFeedback(message, 'danger', true);
                if (window.toastr) { toastr.error(message); }
            })
            .always(function () {
                if (redirecting) { return; }
                confirmPending = false;
                confirmButton.html('<i class="fa fa-check"></i> Import baris valid').prop('disabled', !confirmAvailable);
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

    openPreview(previewPage.data('batch-id'));

})(window.jQuery);
