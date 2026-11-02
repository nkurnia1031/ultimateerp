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
        var pendingRequest = null;
        var searchTimer = null;

        function loadPage(pageNumber) {
            var data = new FormData(form);
            data.set('page', String(pageNumber || 1));
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
                document.getElementById('finding_rows').innerHTML = payload.rows_html;
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
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
