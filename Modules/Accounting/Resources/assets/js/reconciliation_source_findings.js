(function () {
    'use strict';

    function initialize() {
        var page = document.getElementById('reconciliation_findings_page');
        var form = document.getElementById('legacy_source_findings_filter');
        var panel = document.getElementById('legacy_source_findings_panel');
        if (!page || !form || !panel || !window.fetch) {
            return;
        }

        var currentPage = 1;
        var pendingRequest = null;
        var searchTimer = null;
        var action = null;
        var modalElement = document.getElementById('legacy_source_finding_action_modal');
        var modal = window.jQuery && modalElement ? window.jQuery(modalElement) : null;
        var status = document.getElementById('source_finding_status_message');
        var results = document.getElementById('source_finding_results');

        function findingUrl(template, findingId) {
            return template.replace('999999999999', encodeURIComponent(String(findingId)));
        }

        function showModal() {
            if (modal && typeof modal.modal === 'function') {
                modal.modal('show');
            }
        }

        function hideModal() {
            if (modal && typeof modal.modal === 'function') {
                modal.modal('hide');
            }
        }

        function getError(payload, fallback) {
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
                        var error = new Error(getError(body, 'Permintaan gagal (' + response.status + ').'));
                        error.payload = body;
                        throw error;
                    }
                    return body;
                });
            });
        }

        function filterParams(pageNumber) {
            var params = new URLSearchParams(new FormData(form));
            params.set('source_page', String(pageNumber || 1));
            Array.from(params.keys()).forEach(function (key) {
                if (params.get(key) === '') {
                    params.delete(key);
                }
            });
            return params;
        }

        function load(pageNumber) {
            currentPage = Number(pageNumber || 1);
            var params = filterParams(currentPage);
            var url = new URL(page.dataset.sourceDataUrl, window.location.origin);
            url.search = params.toString();
            if (pendingRequest) {
                pendingRequest.abort();
            }
            var controller = new AbortController();
            pendingRequest = controller;
            results.setAttribute('aria-busy', 'true');
            status.textContent = 'Memuat kelompok sumber…';

            window.fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Permintaan sumber gagal (' + response.status + ').');
                }
                return response.json();
            }).then(function (payload) {
                document.getElementById('source_finding_rows').innerHTML = payload.rows_html;
                document.getElementById('source_finding_pagination').innerHTML = payload.pagination_html;
                document.getElementById('source_finding_open_count').textContent = new Intl.NumberFormat('id-ID').format(payload.open_count) + ' terbuka';
                status.textContent = new Intl.NumberFormat('id-ID').format(payload.filtered_count)
                    + ' kelompok ditampilkan. Audit sumber terakhir: ' + (payload.last_seen_at || 'belum ada') + '.';

                var currentUrl = new URL(window.location.href);
                ['source_year', 'source_type', 'source_status', 'source_reason', 'source_search', 'source_page'].forEach(function (key) {
                    currentUrl.searchParams.delete(key);
                });
                params.forEach(function (value, key) { currentUrl.searchParams.set(key, value); });
                window.history.replaceState({}, '', currentUrl.toString());
            }).catch(function (error) {
                if (error.name !== 'AbortError') {
                    status.textContent = error.message || 'Kelompok sumber tidak dapat dimuat. Coba lagi.';
                }
            }).finally(function () {
                if (pendingRequest === controller) {
                    pendingRequest = null;
                    results.setAttribute('aria-busy', 'false');
                }
            });
        }

        function beginAction(button) {
            var actionType = button.dataset.action;
            action = {
                type: actionType,
                findingId: button.dataset.findingId,
                transactionId: button.dataset.transactionId || null
            };
            document.getElementById('source_finding_action_title').textContent = actionType === 'retry'
                ? 'Coba ulang tautan sumber'
                : 'Tautkan ke transaksi yang dipilih';
            document.getElementById('source_finding_action_target').textContent = button.dataset.targetLabel || 'Sistem akan mencari satu transaksi final yang cocok berdasarkan nomor, tanggal, dan nominal sumber.';
            document.getElementById('source_finding_action_reason').value = '';
            document.getElementById('source_finding_action_status').className = 'alert alert-info';
            document.getElementById('source_finding_action_status').textContent = actionType === 'retry'
                ? 'Coba ulang hanya menautkan sumber jika ditemukan satu transaksi aktif dengan identitas dan nominal yang sesuai.'
                : 'Periksa transaksi yang dipilih. Tindakan ini tidak mengubah data transaksi, Journal, atau GL.';
            showModal();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            load(1);
        });

        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { load(1); });
        });

        var search = document.getElementById('source_filter_search');
        search.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { load(1); }, 300);
        });

        panel.addEventListener('click', function (event) {
            var pageButton = event.target.closest('.js-source-finding-page');
            if (pageButton) {
                load(pageButton.dataset.page);
                return;
            }
            var actionButton = event.target.closest('.js-source-finding-action');
            if (actionButton && !actionButton.disabled) {
                beginAction(actionButton);
            }
        });

        document.getElementById('source_finding_action_confirm').addEventListener('click', function () {
            if (!action) {
                return;
            }
            var reason = document.getElementById('source_finding_action_reason').value.trim();
            var actionStatus = document.getElementById('source_finding_action_status');
            if (reason.length < 3) {
                actionStatus.className = 'alert alert-warning';
                actionStatus.textContent = 'Masukkan alasan tindakan minimal 3 karakter.';
                return;
            }

            var confirmButton = document.getElementById('source_finding_action_confirm');
            confirmButton.disabled = true;
            actionStatus.className = 'alert alert-info';
            actionStatus.textContent = 'Menyimpan relasi sumber…';
            var url = action.type === 'retry'
                ? findingUrl(page.dataset.sourceRetryUrlTemplate, action.findingId)
                : findingUrl(page.dataset.sourceLinkUrlTemplate, action.findingId);
            var payload = { reason: reason };
            if (action.type === 'link') {
                payload.transaction_id = Number(action.transactionId);
            }

            requestJson(url, payload).then(function (result) {
                actionStatus.className = 'alert alert-success';
                actionStatus.textContent = result.message || 'Tindakan sumber berhasil disimpan.';
                load(currentPage);
                window.setTimeout(hideModal, 450);
            }).catch(function (error) {
                actionStatus.className = 'alert alert-danger';
                actionStatus.textContent = error.message || 'Tindakan gagal. Temuan tetap terbuka.';
            }).finally(function () {
                confirmButton.disabled = false;
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
