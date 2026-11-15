(function () {
    'use strict';

    function initialize() {
        var page = document.getElementById('reconciliation_findings_page');
        var form = document.getElementById('reconciliation_findings_filter');
        if (!page || !form || !window.fetch) {
            return;
        }

        var results = document.getElementById('finding_results');
        var statusMessage = document.getElementById('finding_filter_status_message');
        var rows = document.getElementById('finding_rows');
        var modalElement = document.getElementById('reconciliation_finding_action_modal');
        var modal = window.jQuery && modalElement ? window.jQuery(modalElement) : null;
        var pendingRequest = null;
        var searchTimer = null;
        var currentPage = 1;
        var currentAction = null;
        var accountOptionsElement = document.getElementById('finding_account_options');
        var accountOptions = [];
        try {
            accountOptions = accountOptionsElement ? JSON.parse(accountOptionsElement.textContent || '[]') : [];
        } catch (error) {
            accountOptions = [];
        }

        function modalShow() {
            if (modal && typeof modal.modal === 'function') {
                modal.modal('show');
            }
        }

        function modalHide() {
            if (modal && typeof modal.modal === 'function') {
                modal.modal('hide');
            }
        }

        function urlFor(template, findingId) {
            return template.replace('999999999999', encodeURIComponent(String(findingId)));
        }

        function formatMoney(value, symbol) {
            var precision = Math.max(0, Math.min(4, Number(page.dataset.currencyPrecision || 2)));
            var formatted = new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: precision,
                maximumFractionDigits: 4
            }).format(Number(value || 0));
            return (symbol ? symbol + ' ' : '') + formatted;
        }

        function errorMessage(payload, fallback) {
            if (payload && payload.errors) {
                var keys = Object.keys(payload.errors);
                if (keys.length && payload.errors[keys[0]].length) {
                    return payload.errors[keys[0]][0];
                }
            }
            return payload && payload.message ? payload.message : fallback;
        }

        function requestJson(url, payload) {
            var csrf = document.querySelector('meta[name="csrf-token"]');
            return window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf ? csrf.content : ''
                },
                body: JSON.stringify(payload || {})
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (!response.ok) {
                        var error = new Error(errorMessage(body, 'Permintaan gagal (' + response.status + ').'));
                        error.payload = body;
                        error.status = response.status;
                        throw error;
                    }
                    return body;
                });
            });
        }

        function setActionStatus(message, kind) {
            var status = document.getElementById('finding_action_status');
            status.className = 'alert alert-' + (kind || 'info');
            status.textContent = message;
        }

        function resetActionModal(title) {
            currentAction = null;
            document.getElementById('finding_action_title').textContent = title;
            document.getElementById('finding_source_review').classList.add('hidden');
            document.getElementById('finding_action_proposal').classList.add('hidden');
            document.getElementById('finding_action_fallback').classList.add('hidden');
            document.getElementById('finding_accept_reason_wrap').classList.add('hidden');
            document.getElementById('finding_action_confirm').classList.add('hidden');
            document.getElementById('finding_action_accept').classList.add('hidden');
            document.getElementById('finding_accept_reason').value = '';
            document.getElementById('finding_action_values').textContent = '';
            document.getElementById('finding_adjustment_wrap').classList.add('hidden');
            document.getElementById('finding_adjustment_review').classList.add('hidden');
            document.getElementById('finding_adjustment_reason').value = '';
            document.getElementById('finding_adjustment_lines').textContent = '';
            document.getElementById('finding_adjustment_comparison').textContent = '';
            document.getElementById('finding_adjustment_review_lines').textContent = '';
            document.getElementById('finding_adjustment_confirm_intent').checked = false;
            document.getElementById('finding_adjustment_confirm').classList.add('hidden');
            document.getElementById('finding_adjustment_confirm').disabled = true;
        }

        function addProposalRow(label, before, after) {
            var row = document.createElement('tr');
            [label, before, after].forEach(function (value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
            });
            document.getElementById('finding_action_values').appendChild(row);
        }

        function addCell(row, value, className) {
            var cell = document.createElement('td');
            cell.textContent = value === null || value === undefined ? '' : String(value);
            if (className) {
                cell.className = className;
            }
            row.appendChild(cell);
        }

        function addMetaRow(list, label, value) {
            if (value === null || value === undefined || String(value).trim() === '') {
                return;
            }
            var term = document.createElement('dt');
            term.textContent = label;
            var description = document.createElement('dd');
            description.textContent = String(value);
            list.appendChild(term);
            list.appendChild(description);
        }

        function renderOperationalReview(review, currencySymbol) {
            var meta = document.getElementById('finding_action_operational_meta');
            var message = document.getElementById('finding_action_operational_message');
            var lines = document.getElementById('finding_action_operational_lines');
            var edit = document.getElementById('finding_action_operational_edit');
            var operational = review.operational || {};
            var transaction = operational.transaction || {};

            meta.textContent = '';
            lines.textContent = '';
            addMetaRow(meta, 'Jenis', operational.found ? transaction.type : review.source_type);
            addMetaRow(meta, 'Nomor', transaction.number || review.source_number);
            addMetaRow(meta, 'Tanggal', transaction.date || review.source_date);
            addMetaRow(meta, 'Supplier / pelanggan', transaction.contact);
            addMetaRow(meta, transaction.is_snapshot ? 'Akun pembayaran' : 'Lokasi', transaction.location);
            addMetaRow(meta, 'Status', transaction.status);
            if (operational.found) {
                if (transaction.is_snapshot) {
                    addMetaRow(meta, 'Nominal operasional', transaction.final_total === null ? '—' : formatMoney(transaction.final_total, currencySymbol));
                    addMetaRow(meta, 'Pajak sumber', transaction.tax_amount === null ? '—' : formatMoney(transaction.tax_amount, currencySymbol));
                } else {
                    addMetaRow(meta, 'Total sebelum pajak', formatMoney(transaction.total_before_tax, currencySymbol));
                    addMetaRow(meta, 'Pajak transaksi', formatMoney(transaction.tax_amount, currencySymbol));
                    addMetaRow(meta, 'Total transaksi', formatMoney(transaction.final_total, currencySymbol));
                }
                addMetaRow(meta, 'Catatan transaksi', transaction.note);
            }

            message.textContent = operational.message || (!operational.found ? 'Sumber operasional belum dapat dicocokkan dengan satu transaksi.' : '');
            message.classList.toggle('hidden', !message.textContent);
            edit.classList.toggle('hidden', !(review.can_edit_operational && operational.edit_url));
            if (review.can_edit_operational && operational.edit_url) {
                edit.href = operational.edit_url;
            } else {
                edit.removeAttribute('href');
            }

            (operational.lines || []).forEach(function (line) {
                var row = document.createElement('tr');
                if (line.snapshot) {
                    var snapshotDetail = [line.category, line.description]
                        .filter(function (value) { return value && String(value).trim() !== ''; }).join(' · ');
                    addCell(row, snapshotDetail || 'Movement kas/bank');
                    addCell(row, '');
                    addCell(row, '');
                    addCell(row, line.source_vat === null ? '—' : formatMoney(line.source_vat, currencySymbol), 'text-right');
                    var snapshotAmount = line.amount === null ? '—' : formatMoney(line.amount, currencySymbol);
                    if (line.journal_amount !== null) {
                        snapshotAmount += ' · GL ' + formatMoney(line.journal_amount, currencySymbol);
                    }
                    addCell(row, snapshotAmount, 'text-right');
                } else if (transaction.type === 'expense') {
                    var expenseDetail = [line.category, line.description, line.note, line.document ? 'Dokumen: ' + line.document : '']
                        .filter(function (value) { return value && String(value).trim() !== ''; }).join(' · ');
                    addCell(row, expenseDetail || 'Expense line');
                    addCell(row, '');
                    addCell(row, '');
                    addCell(row, '');
                    addCell(row, formatMoney(line.amount, currencySymbol), 'text-right');
                } else {
                    var product = line.product || 'Produk';
                    if (line.code) {
                        product += ' (' + line.code + ')';
                    }
                    addCell(row, product);
                    addCell(row, new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(Number(line.quantity || 0)), 'text-right');
                    var unitPrice = formatMoney(line.unit_price, currencySymbol);
                    if (line.unit_price_inc_tax !== null && line.unit_price_inc_tax !== undefined) {
                        unitPrice += ' / ' + formatMoney(line.unit_price_inc_tax, currencySymbol) + ' inc. pajak';
                    }
                    addCell(row, unitPrice, 'text-right');
                    addCell(row, formatMoney(line.unit_tax, currencySymbol) + (line.tax_name ? ' · ' + line.tax_name : ''), 'text-right');
                    addCell(row, formatMoney(line.line_total, currencySymbol), 'text-right');
                }
                lines.appendChild(row);
            });

            if (!lines.children.length) {
                var empty = document.createElement('tr');
                var emptyCell = document.createElement('td');
                emptyCell.colSpan = 5;
                emptyCell.className = 'text-muted';
                emptyCell.textContent = 'Rincian line operasional tidak tersedia.';
                empty.appendChild(emptyCell);
                lines.appendChild(empty);
            }
        }

        function renderJournalReview(review, currencySymbol) {
            var container = document.getElementById('finding_action_journals');
            container.textContent = '';
            var journals = review.journals || [];

            if (!journals.length) {
                var empty = document.createElement('p');
                empty.className = 'text-muted';
                empty.textContent = 'Detail baris Journal belum tersedia pada audit tersimpan.';
                container.appendChild(empty);
            }

            journals.forEach(function (journal) {
                var panel = document.createElement('div');
                panel.className = 'panel panel-default';
                var heading = document.createElement('div');
                heading.className = 'panel-heading clearfix';
                var title = document.createElement('strong');
                title.textContent = 'Journal ' + (journal.number ? '#' + journal.number : 'sumber');
                heading.appendChild(title);
                if (journal.date) {
                    var date = document.createElement('span');
                    date.className = 'text-muted';
                    date.textContent = ' · ' + journal.date;
                    heading.appendChild(date);
                }
                if (review.can_edit_journal && journal.edit_url && journal.id) {
                    var edit = document.createElement('a');
                    edit.className = 'btn btn-xs btn-primary pull-right';
                    edit.href = journal.edit_url;
                    edit.target = '_blank';
                    edit.rel = 'noopener';
                    edit.textContent = 'Edit Journal';
                    heading.appendChild(edit);
                }
                panel.appendChild(heading);

                var body = document.createElement('div');
                body.className = 'panel-body';
                if (journal.note) {
                    var note = document.createElement('p');
                    note.textContent = journal.note;
                    body.appendChild(note);
                }
                if (journal.snapshot_summary) {
                    var summary = document.createElement('p');
                    summary.textContent = 'Nilai GL hasil audit: '
                        + (journal.amount === null ? '—' : formatMoney(journal.amount, currencySymbol))
                        + ' · VAT Journal: ' + (journal.vat === null ? '—' : formatMoney(journal.vat, currencySymbol));
                    body.appendChild(summary);
                }
                if (!journal.id) {
                    var snapshot = document.createElement('p');
                    snapshot.className = 'text-warning';
                    snapshot.textContent = 'Rincian ini berasal dari snapshot audit; mapping Journal yang dapat diedit tidak ditemukan.';
                    body.appendChild(snapshot);
                }

                var tableWrap = document.createElement('div');
                tableWrap.className = 'table-responsive';
                var table = document.createElement('table');
                table.className = 'table table-condensed table-striped';
                var thead = document.createElement('thead');
                var headerRow = document.createElement('tr');
                ['Akun', 'Kode', 'Debit', 'Kredit', 'Keterangan'].forEach(function (label) {
                    var th = document.createElement('th');
                    th.textContent = label;
                    headerRow.appendChild(th);
                });
                thead.appendChild(headerRow);
                table.appendChild(thead);
                var tbody = document.createElement('tbody');
                (journal.lines || []).forEach(function (line) {
                    var row = document.createElement('tr');
                    addCell(row, line.account);
                    addCell(row, line.account_code);
                    addCell(row, formatMoney(line.debit, currencySymbol), 'text-right');
                    addCell(row, formatMoney(line.credit, currencySymbol), 'text-right');
                    addCell(row, line.note);
                    tbody.appendChild(row);
                });
                if (!tbody.children.length) {
                    var noLines = document.createElement('tr');
                    var noLinesCell = document.createElement('td');
                    noLinesCell.colSpan = 5;
                    noLinesCell.className = 'text-muted';
                    noLinesCell.textContent = 'Baris akun GL belum tersedia.';
                    noLines.appendChild(noLinesCell);
                    tbody.appendChild(noLines);
                }
                table.appendChild(tbody);
                tableWrap.appendChild(table);
                body.appendChild(tableWrap);
                panel.appendChild(body);
                container.appendChild(panel);
            });

            if (review.journal_totals && (review.journal_totals.debit !== null || review.journal_totals.credit !== null)) {
                var debit = review.journal_totals.debit === null ? '—' : formatMoney(review.journal_totals.debit, currencySymbol);
                var credit = review.journal_totals.credit === null ? '—' : formatMoney(review.journal_totals.credit, currencySymbol);
                var totals = document.createElement('p');
                totals.className = 'text-muted';
                totals.textContent = 'Total audit — Debit: ' + debit + ' · Kredit: ' + credit;
                container.appendChild(totals);
            }
        }

        function renderSourceReview(payload) {
            var review = payload.source_review || {};
            document.getElementById('finding_source_review').classList.remove('hidden');
            renderOperationalReview(review, payload.currency_symbol);
            renderJournalReview(review, payload.currency_symbol);
        }

        function loadPage(pageNumber) {
            currentPage = Number(pageNumber || 1);
            var data = new FormData(form);
            data.set('page', String(currentPage));
            var params = new URLSearchParams(data);
            var url = new URL(page.dataset.dataUrl, window.location.origin);
            url.search = params.toString();

            if (pendingRequest) {
                pendingRequest.abort();
            }
            var currentRequest = new AbortController();
            pendingRequest = currentRequest;
            results.setAttribute('aria-busy', 'true');
            statusMessage.textContent = 'Memuat temuan…';

            window.fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: currentRequest.signal
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Permintaan temuan gagal (' + response.status + ').');
                }
                return response.json();
            }).then(function (payload) {
                rows.innerHTML = payload.rows_html;
                document.getElementById('finding_summary_rows').innerHTML = payload.summary_html;
                document.getElementById('finding_pagination').innerHTML = payload.pagination_html;
                document.getElementById('finding_open_count').textContent = new Intl.NumberFormat('id-ID').format(payload.open_count);
                document.getElementById('finding_filtered_count').textContent = new Intl.NumberFormat('id-ID').format(payload.filtered_count);
                document.getElementById('finding_last_audited_at').textContent = payload.last_audited_at || 'Belum ada audit tersimpan';
                document.getElementById('finding_last_audited_card').textContent = payload.last_audited_at || '—';
                statusMessage.textContent = new Intl.NumberFormat('id-ID').format(payload.filtered_count) + ' temuan ditampilkan.';
                var formUrl = new URL(form.action, window.location.origin);
                formUrl.search = params.toString();
                window.history.replaceState({}, '', formUrl.toString());
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    statusMessage.textContent = error.message || 'Temuan tidak dapat dimuat. Coba lagi.';
                }
            }).finally(function () {
                if (pendingRequest === currentRequest) {
                    pendingRequest = null;
                    results.setAttribute('aria-busy', 'false');
                }
            });
        }

        function reviewSource(findingId) {
            resetActionModal('Tinjau detail operasional dan Journal');
            setActionStatus('Memuat detail transaksi dan baris Journal…', 'info');
            modalShow();

            requestJson(urlFor(page.dataset.previewUrlTemplate, findingId), {}).then(function (payload) {
                renderSourceReview(payload);
                if (!payload.supported) {
                    document.getElementById('finding_action_fallback').classList.remove('hidden');
                    document.getElementById('finding_action_reason').textContent = payload.reason || 'Koreksi tidak dapat dihitung dengan bukti yang tersedia.';
                    setActionStatus('Detail sumber dimuat. Koreksi pajak otomatis tidak tersedia untuk temuan ini.', 'warning');
                    return;
                }

                var proposal = payload.proposal;
                currentAction = { type: 'correction', findingId: findingId, fingerprint: proposal.fingerprint };
                document.getElementById('finding_action_proposal').classList.remove('hidden');
                document.getElementById('finding_action_source').textContent = proposal.source.type + ' #' + proposal.source.id + ' · ' + proposal.source.number + ' · ' + proposal.source.date;
                addProposalRow('VAT In', formatMoney(proposal.before.tax_amount, payload.currency_symbol), formatMoney(proposal.after.tax_amount, payload.currency_symbol) + ' (' + proposal.after.tax_rate_name + ')');
                addProposalRow('Total sebelum pajak', formatMoney(proposal.before.total_before_tax, payload.currency_symbol), formatMoney(proposal.after.total_before_tax, payload.currency_symbol));
                addProposalRow('Total transaksi', formatMoney(proposal.before.final_total, payload.currency_symbol), formatMoney(proposal.after.final_total, payload.currency_symbol));
                document.getElementById('finding_action_journal').textContent = 'Koreksi pajak ini hanya mengubah transaksi operasional. Edit Journal, jika diperlukan, dilakukan terpisah melalui tombol Edit Journal.';
                if (payload.can_confirm) {
                    document.getElementById('finding_action_confirm').classList.remove('hidden');
                } else {
                    setActionStatus('Usulan tersedia, tetapi akun ini tidak memiliki izin expense.edit untuk menerapkannya.', 'warning');
                }
                if (payload.can_confirm) {
                    setActionStatus('Periksa detail operasional, baris Journal, dan usulan pajak sebelum mengonfirmasi.', 'info');
                }
            }).catch(function (error) {
                setActionStatus(error.message || 'Bukti sumber tidak dapat dimuat.', 'danger');
            });
        }

        function acceptFinding(findingId) {
            resetActionModal('Terima selisih setelah pemeriksaan');
            currentAction = { type: 'accept', findingId: findingId };
            document.getElementById('finding_action_fallback').classList.remove('hidden');
            document.getElementById('finding_action_reason').textContent = 'Selisih tetap tersimpan pada histori audit dan tidak membuat atau mengubah transaksi maupun Journal.';
            document.getElementById('finding_accept_reason_wrap').classList.remove('hidden');
            document.getElementById('finding_action_accept').classList.remove('hidden');
            setActionStatus('Masukkan alasan pemeriksaan untuk menandai temuan sebagai diterima.', 'warning');
            modalShow();
        }

        function addAdjustmentLine(accountId, debit, credit) {
            var tbody = document.getElementById('finding_adjustment_lines');
            var row = document.createElement('tr');
            var accountCell = document.createElement('td');
            var account = document.createElement('select');
            account.className = 'form-control input-sm js-adjustment-account';
            account.setAttribute('aria-label', 'Pilih akun CoA');
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Pilih CoA';
            account.appendChild(placeholder);
            accountOptions.forEach(function (item) {
                var option = document.createElement('option');
                option.value = String(item.id);
                option.textContent = item.name + ' (#' + item.id + ')';
                account.appendChild(option);
            });
            if (accountId) {
                account.value = String(accountId);
            }
            accountCell.appendChild(account);
            row.appendChild(accountCell);

            [['debit', debit], ['credit', credit]].forEach(function (field) {
                var cell = document.createElement('td');
                var input = document.createElement('input');
                input.type = 'number';
                input.min = '0';
                input.step = '0.0001';
                input.value = field[1] || '';
                input.className = 'form-control input-sm text-right js-adjustment-' + field[0];
                input.setAttribute('aria-label', field[0] === 'debit' ? 'Nominal debit' : 'Nominal kredit');
                cell.appendChild(input);
                row.appendChild(cell);
            });

            var actionCell = document.createElement('td');
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-xs btn-default js-remove-adjustment-line';
            remove.textContent = 'Hapus';
            remove.setAttribute('aria-label', 'Hapus baris akun');
            actionCell.appendChild(remove);
            row.appendChild(actionCell);
            tbody.appendChild(row);
        }

        function adjustmentLines() {
            return Array.prototype.map.call(document.querySelectorAll('#finding_adjustment_lines tr'), function (row) {
                return {
                    accounting_account_id: row.querySelector('.js-adjustment-account').value,
                    debit: row.querySelector('.js-adjustment-debit').value || '0',
                    credit: row.querySelector('.js-adjustment-credit').value || '0'
                };
            });
        }

        function clearAdjustmentReview() {
            if (currentAction && currentAction.type === 'journal') {
                currentAction.fingerprint = null;
            }
            document.getElementById('finding_adjustment_review').classList.add('hidden');
            document.getElementById('finding_adjustment_confirm').classList.add('hidden');
            document.getElementById('finding_adjustment_confirm').disabled = true;
            document.getElementById('finding_adjustment_confirm_intent').checked = false;
        }

        function appendAdjustmentComparison(label, before, after, symbol) {
            var row = document.createElement('tr');
            [label, formatMoney(before, symbol), formatMoney(after, symbol)].forEach(function (value, index) {
                var cell = document.createElement('td');
                cell.textContent = value;
                if (index > 0) {
                    cell.className = 'text-right';
                }
                row.appendChild(cell);
            });
            document.getElementById('finding_adjustment_comparison').appendChild(row);
        }

        function startAdjustment(findingId, actionType, variance) {
            var title = actionType === 'rounding' ? 'Tinjau jurnal pembulatan' : 'Tinjau jurnal penyesuaian';
            resetActionModal(title);
            currentAction = { type: 'journal', action: actionType, findingId: findingId, variance: Number(variance || 0) };
            var amount = Math.abs(Number(variance || 0)).toFixed(4);
            var roundingAccountId = page.dataset.roundingAccountId;
            var preferredAccount = actionType === 'rounding' ? roundingAccountId : '';
            addAdjustmentLine(preferredAccount, amount, '');
            addAdjustmentLine('', '', amount);
            document.getElementById('finding_adjustment_context').textContent =
                'Temuan #' + findingId + ' Â· selisih ' + formatMoney(variance, '') + '. Pilih akun untuk setiap baris; ' +
                (actionType === 'rounding'
                    ? 'akun pembulatan yang dipetakan harus dipakai tepat satu kali.'
                    : 'nilai jurnal tidak boleh melebihi selisih. Baris VAT In/Out tetap dapat dipisahkan.');
            document.getElementById('finding_adjustment_wrap').classList.remove('hidden');
            document.getElementById('finding_adjustment_confirm').classList.remove('hidden');
            document.getElementById('finding_adjustment_confirm').disabled = true;
            setActionStatus('Isi alasan dan periksa semua akun serta nominal sebelum meminta preview.', 'info');
            modalShow();
        }

        function previewAdjustment(button) {
            if (!currentAction || currentAction.type !== 'journal') {
                return;
            }
            var reason = document.getElementById('finding_adjustment_reason').value.trim();
            if (!reason) {
                setActionStatus('Alasan jurnal wajib diisi.', 'danger');
                document.getElementById('finding_adjustment_reason').focus();
                return;
            }
            button.disabled = true;
            setActionStatus('Memvalidasi CoA, keseimbangan jurnal, dan nilai temuanâ€¦', 'info');
            requestJson(urlFor(page.dataset.adjustmentPreviewUrlTemplate, currentAction.findingId), {
                action: currentAction.action,
                reason: reason,
                lines: adjustmentLines()
            }).then(function (payload) {
                var proposal = payload.proposal;
                currentAction.fingerprint = proposal.fingerprint;
                currentAction.reason = proposal.reason;
                document.getElementById('finding_adjustment_context').textContent =
                    'Sumber: ' + (proposal.source.type || 'â€”') + ' #' + (proposal.source.number || 'â€”') +
                    ' Â· ' + (proposal.source.date || 'tanggal tidak tersedia') + ' Â· ' + (proposal.source.description || '') +
                    ' Â· Akun: ' + [proposal.source.account_no, proposal.source.account_name].filter(Boolean).join(' ') +
                    ' Â· Komponen: ' + proposal.component + ' Â· Alasan: ' + proposal.reason;
                document.getElementById('finding_adjustment_comparison').textContent = '';
                document.getElementById('finding_adjustment_review_lines').textContent = '';
                appendAdjustmentComparison('Nilai pembanding', proposal.before.expected_amount, proposal.after.expected_amount, payload.currency_symbol);
                appendAdjustmentComparison('Nilai aktual', proposal.before.actual_amount, proposal.after.actual_amount, payload.currency_symbol);
                appendAdjustmentComparison('Sisa selisih', proposal.before.variance, proposal.after.variance, payload.currency_symbol);
                proposal.lines.forEach(function (line) {
                    var row = document.createElement('tr');
                    [line.account_name + ' (#' + line.accounting_account_id + ')', formatMoney(line.debit_minor / 10000, payload.currency_symbol), formatMoney(line.credit_minor / 10000, payload.currency_symbol)].forEach(function (value, index) {
                        var cell = document.createElement('td');
                        cell.textContent = value;
                        if (index > 0) { cell.className = 'text-right'; }
                        row.appendChild(cell);
                    });
                    document.getElementById('finding_adjustment_review_lines').appendChild(row);
                });
                document.getElementById('finding_adjustment_debit_total').textContent = formatMoney(proposal.debit_total, payload.currency_symbol);
                document.getElementById('finding_adjustment_credit_total').textContent = formatMoney(proposal.credit_total, payload.currency_symbol);
                document.getElementById('finding_adjustment_vat_components').textContent = 'Bukti pajak terpisah Â· VAT In Journal/operasional: ' +
                    formatMoney(proposal.vat_components.journal_vat_in, payload.currency_symbol) + ' / ' + formatMoney(proposal.vat_components.operational_vat_in, payload.currency_symbol) +
                    ' Â· VAT Out Journal/operasional: ' + formatMoney(proposal.vat_components.journal_vat_out, payload.currency_symbol) + ' / ' + formatMoney(proposal.vat_components.operational_vat_out, payload.currency_symbol);
                document.getElementById('finding_adjustment_review').classList.remove('hidden');
                document.getElementById('finding_adjustment_confirm').classList.remove('hidden');
                document.getElementById('finding_adjustment_confirm').disabled = !document.getElementById('finding_adjustment_confirm_intent').checked;
                setActionStatus('Periksa nilai sumber, rincian VAT, akun, dan jurnal. Konfirmasi akan memposting jurnal tertaut.', 'info');
            }).catch(function (error) {
                setActionStatus(error.message || 'Preview jurnal tidak dapat dibuat.', 'danger');
            }).finally(function () {
                button.disabled = false;
            });
        }

        function confirmAdjustment(button) {
            if (!currentAction || currentAction.type !== 'journal' || !currentAction.fingerprint) {
                setActionStatus('Buat preview ulang sebelum memposting jurnal.', 'danger');
                return;
            }
            if (!document.getElementById('finding_adjustment_confirm_intent').checked) {
                setActionStatus('Konfirmasi bahwa jurnal ini ditujukan untuk mengurangi selisih.', 'danger');
                return;
            }
            button.disabled = true;
            setActionStatus('Memposting jurnal dan memeriksa ulang selisihâ€¦', 'info');
            requestJson(urlFor(page.dataset.adjustmentConfirmUrlTemplate, currentAction.findingId), {
                action: currentAction.action,
                reason: document.getElementById('finding_adjustment_reason').value.trim(),
                lines: adjustmentLines(),
                fingerprint: currentAction.fingerprint,
                confirmation_ack: true
            }).then(function (payload) {
                setActionStatus(payload.message || 'Jurnal tertaut berhasil diposting.', payload.status === 'resolved' ? 'success' : 'warning');
                document.getElementById('finding_adjustment_confirm').classList.add('hidden');
                loadPage(currentPage);
            }).catch(function (error) {
                setActionStatus(error.message || 'Jurnal tidak dapat diposting.', 'danger');
            }).finally(function () {
                button.disabled = false;
            });
        }

        function saveRoundingAccount(button) {
            var select = document.getElementById('finding_rounding_account');
            if (!select) { return; }
            button.disabled = true;
            var status = document.getElementById('finding_rounding_account_status');
            status.textContent = 'Menyimpan mappingâ€¦';
            requestJson(page.dataset.roundingAccountUrl, { accounting_account_id: select.value || null }).then(function (payload) {
                page.dataset.roundingAccountId = payload.accounting_account_id || '';
                status.textContent = payload.message;
                loadPage(currentPage);
            }).catch(function (error) {
                status.textContent = error.message || 'Mapping tidak dapat disimpan.';
            }).finally(function () {
                button.disabled = false;
            });
        }

        function confirmCorrection(button) {
            if (!currentAction || currentAction.type !== 'correction') {
                return;
            }
            button.disabled = true;
            setActionStatus('Menerapkan koreksi pajak dan memeriksa ulang selisih…', 'info');
            requestJson(urlFor(page.dataset.confirmUrlTemplate, currentAction.findingId), {
                fingerprint: currentAction.fingerprint
            }).then(function (payload) {
                setActionStatus(payload.message || 'Koreksi tercatat.', payload.status === 'resolved' ? 'success' : 'warning');
                document.getElementById('finding_action_confirm').classList.add('hidden');
                loadPage(currentPage);
            }).catch(function (error) {
                setActionStatus(error.message || 'Koreksi tidak dapat diterapkan.', 'danger');
            }).finally(function () {
                button.disabled = false;
            });
        }

        function confirmAccept(button) {
            if (!currentAction || currentAction.type !== 'accept') {
                return;
            }
            var reason = document.getElementById('finding_accept_reason').value.trim();
            if (!reason) {
                setActionStatus('Alasan wajib diisi.', 'danger');
                document.getElementById('finding_accept_reason').focus();
                return;
            }
            button.disabled = true;
            setActionStatus('Menyimpan alasan dan histori…', 'info');
            requestJson(urlFor(page.dataset.acceptUrlTemplate, currentAction.findingId), { reason: reason }).then(function (payload) {
                setActionStatus(payload.idempotent ? 'Temuan ini sudah pernah diterima.' : 'Temuan diterima dan alasan tersimpan.', 'success');
                document.getElementById('finding_action_accept').classList.add('hidden');
                loadPage(currentPage);
            }).catch(function (error) {
                setActionStatus(error.message || 'Temuan tidak dapat diterima.', 'danger');
            }).finally(function () {
                button.disabled = false;
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            loadPage(1);
        });

        ['finding_filter_year', 'finding_filter_type', 'finding_filter_status'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', function () { loadPage(1); });
        });

        document.getElementById('finding_filter_search').addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { loadPage(1); }, 350);
        });

        document.getElementById('finding_pagination').addEventListener('click', function (event) {
            var link = event.target.closest('[data-finding-page]');
            if (!link || link.parentElement.classList.contains('disabled')) {
                return;
            }
            event.preventDefault();
            loadPage(link.dataset.findingPage);
        });

        rows.addEventListener('click', function (event) {
            var reviewButton = event.target.closest('.js-review-finding-source');
            if (reviewButton) {
                reviewSource(reviewButton.dataset.findingId);
                return;
            }
            var acceptButton = event.target.closest('.js-accept-finding');
            if (acceptButton) {
                acceptFinding(acceptButton.dataset.findingId);
                return;
            }
            var adjustmentButton = event.target.closest('.js-post-finding-adjustment');
            if (adjustmentButton) {
                startAdjustment(adjustmentButton.dataset.findingId, adjustmentButton.dataset.action, adjustmentButton.dataset.variance);
            }
        });

        document.getElementById('finding_adjustment_lines').addEventListener('input', clearAdjustmentReview);
        document.getElementById('finding_adjustment_lines').addEventListener('change', clearAdjustmentReview);
        document.getElementById('finding_adjustment_lines').addEventListener('click', function (event) {
            var remove = event.target.closest('.js-remove-adjustment-line');
            if (!remove) { return; }
            remove.closest('tr').remove();
            clearAdjustmentReview();
        });
        document.getElementById('finding_adjustment_reason').addEventListener('input', clearAdjustmentReview);
        document.getElementById('finding_adjustment_add_line').addEventListener('click', function () {
            addAdjustmentLine('', '', '');
            clearAdjustmentReview();
        });
        document.getElementById('finding_adjustment_reverse_sides').addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#finding_adjustment_lines tr'), function (row) {
                var debit = row.querySelector('.js-adjustment-debit');
                var credit = row.querySelector('.js-adjustment-credit');
                var previousDebit = debit.value;
                debit.value = credit.value;
                credit.value = previousDebit;
            });
            clearAdjustmentReview();
        });
        document.getElementById('finding_adjustment_preview').addEventListener('click', function () {
            previewAdjustment(this);
        });
        document.getElementById('finding_adjustment_confirm_intent').addEventListener('change', function () {
            document.getElementById('finding_adjustment_confirm').disabled = !this.checked || !currentAction || !currentAction.fingerprint;
        });
        document.getElementById('finding_adjustment_confirm').addEventListener('click', function () {
            confirmAdjustment(this);
        });

        var saveRoundingButton = document.getElementById('finding_save_rounding_account');
        if (saveRoundingButton) {
            saveRoundingButton.addEventListener('click', function () {
                saveRoundingAccount(this);
            });
        }

        document.getElementById('finding_action_confirm').addEventListener('click', function () {
            confirmCorrection(this);
        });
        document.getElementById('finding_action_accept').addEventListener('click', function () {
            confirmAccept(this);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
