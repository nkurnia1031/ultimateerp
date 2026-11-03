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
            var formatted = new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 2,
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
            }
        });

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
