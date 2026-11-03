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
            document.getElementById('finding_action_proposal').classList.add('hidden');
            document.getElementById('finding_action_fallback').classList.add('hidden');
            document.getElementById('finding_action_editor_link').classList.add('hidden');
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
            resetActionModal('Tinjau sumber dan usulan pajak');
            setActionStatus('Memeriksa sumber transaksi dan bukti Journal…', 'info');
            modalShow();

            requestJson(urlFor(page.dataset.previewUrlTemplate, findingId), {}).then(function (payload) {
                if (!payload.supported) {
                    document.getElementById('finding_action_fallback').classList.remove('hidden');
                    document.getElementById('finding_action_reason').textContent = payload.reason || 'Koreksi tidak dapat dihitung dengan bukti yang tersedia.';
                    if (payload.editor_url || payload.journal_url) {
                        var link = document.getElementById('finding_action_editor_link');
                        link.href = payload.editor_url || payload.journal_url;
                        link.textContent = payload.editor_url ? 'Buka transaksi / daftar sumber' : 'Buka alur jurnal manual';
                        link.classList.remove('hidden');
                    }
                    setActionStatus('Koreksi otomatis tidak tersedia untuk temuan ini.', 'warning');
                    return;
                }

                var proposal = payload.proposal;
                currentAction = { type: 'correction', findingId: findingId, fingerprint: proposal.fingerprint };
                document.getElementById('finding_action_proposal').classList.remove('hidden');
                document.getElementById('finding_action_source').textContent = proposal.source.type + ' #' + proposal.source.id + ' · ' + proposal.source.number + ' · ' + proposal.source.date;
                addProposalRow('VAT In', formatMoney(proposal.before.tax_amount, payload.currency_symbol), formatMoney(proposal.after.tax_amount, payload.currency_symbol) + ' (' + proposal.after.tax_rate_name + ')');
                addProposalRow('Total sebelum pajak', formatMoney(proposal.before.total_before_tax, payload.currency_symbol), formatMoney(proposal.after.total_before_tax, payload.currency_symbol));
                addProposalRow('Total transaksi', formatMoney(proposal.before.final_total, payload.currency_symbol), formatMoney(proposal.after.final_total, payload.currency_symbol));
                document.getElementById('finding_action_journal').textContent = 'Journal: VAT In ' + formatMoney(proposal.journal_vat, payload.currency_symbol) + ', total ' + formatMoney(proposal.journal_total, payload.currency_symbol) + '. Journal legacy tidak diubah.';
                if (payload.can_confirm) {
                    document.getElementById('finding_action_confirm').classList.remove('hidden');
                } else {
                    setActionStatus('Usulan tersedia, tetapi akun ini tidak memiliki izin expense.edit untuk menerapkannya.', 'warning');
                }
                if (payload.can_confirm) {
                    setActionStatus('Periksa nilai sebelum mengonfirmasi perubahan pada transaksi sumber.', 'info');
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
