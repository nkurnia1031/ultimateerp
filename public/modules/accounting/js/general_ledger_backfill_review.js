(function ($) {
    'use strict';

    $(function () {
        var $page = $('#gl_backfill_review_page');
        if (!$page.length) {
            return;
        }

        var state = { page: 1, perPage: 25, lastPage: 1, groups: [], accounts: [], pollTimer: null };
        var urls = {
            rows: $page.data('rows-url'),
            options: $page.data('options-url'),
            locationMapping: $page.data('location-mapping-url'),
            mainMapping: $page.data('main-account-mapping-url'),
            vatMapping: $page.data('vat-mapping-url'),
            retry: $page.data('retry-url'),
            batchBase: $page.data('batch-url-base')
        };

        function escapeHtml(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character];
            });
        }

        function request(url, method, data) {
            return $.ajax({
                url: url,
                method: method,
                data: data || {},
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') || '' }
            });
        }

        function showError(xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Permintaan gagal. Silakan muat ulang data dan coba lagi.';
            if (xhr.responseJSON && xhr.responseJSON.errors) {
                var keys = Object.keys(xhr.responseJSON.errors);
                if (keys.length) {
                    message = xhr.responseJSON.errors[keys[0]][0];
                }
            }
            if (window.toastr) {
                toastr.error(message);
            } else {
                window.alert(message);
            }
        }

        function filters() {
            return {
                search: $('#gl_backfill_search').val(),
                reason: $('#gl_backfill_reason').val(),
                source_type: $('#gl_backfill_source_type').val(),
                status: $('#gl_backfill_status').val(),
                start_date: $('#gl_backfill_start_date').val(),
                end_date: $('#gl_backfill_end_date').val(),
                page: state.page,
                per_page: state.perPage
            };
        }

        function renderRows(payload) {
            var $body = $('#gl_backfill_review_table tbody');
            var rows = payload.data || [];
            var meta = payload.meta || { page: 1, last_page: 1, total: 0 };
            state.page = meta.page || 1;
            state.lastPage = meta.last_page || 1;
            $('#gl_backfill_unique_count').text(meta.unique_total || 0);
            $('#gl_backfill_page_summary').text(meta.total ? 'Menampilkan ' + (((state.page - 1) * state.perPage) + 1) + '–' + Math.min(state.page * state.perPage, meta.total) + ' dari ' + meta.total : 'Tidak ada sumber yang cocok.');
            $('#gl_backfill_page_number').text('Halaman ' + state.page + ' / ' + state.lastPage);
            $('#gl_backfill_prev').prop('disabled', state.page <= 1);
            $('#gl_backfill_next').prop('disabled', state.page >= state.lastPage);

            if (!rows.length) {
                $body.html('<tr><td colspan="5" class="text-center text-muted">Tidak ada sumber untuk filter ini.</td></tr>');
                return;
            }

            $body.html(rows.map(function (row) {
                var amount = row.amount === null ? '' : '<div class="text-muted">Nominal: ' + escapeHtml(Number(row.amount).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + '</div>';
                var statusClass = row.status === 'resolved' ? 'label-success' : (row.status === 'retrying' ? 'label-info' : 'label-warning');
                var statusLabel = row.status === 'resolved' ? 'Selesai' : (row.status === 'retrying' ? 'Sedang dicoba' : 'Menunggu');
                var reasonLabels = {
                    mapping_lokasi_tidak_lengkap: 'Mapping lokasi belum lengkap',
                    akun_mapping_tidak_ada_di_business: 'Akun mapping tidak ada di business',
                    akun_utama_belum_dimapping: 'Akun sumber belum dipetakan ke CoA',
                    akun_vat_tidak_ditemukan: 'Akun VAT belum dipilih',
                    akun_vat_ambigu: 'Ada lebih dari satu akun VAT yang cocok',
                    nominal_transaksi_tidak_valid: 'Nominal transaksi tidak valid',
                    mapping_akun_lawan_tidak_lengkap: 'Mapping akun lawan belum lengkap',
                    pergerakan_rekening_tanpa_akun_lawan: 'Mutasi rekening belum memiliki akun lawan',
                    saldo_awal_rekening_tidak_disalin: 'Saldo awal rekening belum disalin',
                    pasangan_transfer_tidak_valid: 'Pasangan transfer perlu diperiksa',
                    transfer_tidak_seimbang: 'Nilai transfer tidak seimbang'
                };
                var reasonLabel = reasonLabels[row.skip_reason] || String(row.skip_reason || '').replace(/_/g, ' ');
                return '<tr>' +
                    '<td><strong>' + escapeHtml(row.source_type) + ' #' + escapeHtml(row.source_record_id) + '</strong><div>' + escapeHtml(row.source_reference) + '</div></td>' +
                    '<td>' + escapeHtml(row.operation_date || '—') + '</td>' +
                    '<td>' + escapeHtml(row.source_summary) + amount + '</td>' +
                    '<td>' + escapeHtml(reasonLabel) + '<div class="text-muted"><small>' + escapeHtml(row.skip_reason) + '</small></div></td>' +
                    '<td><span class="label ' + statusClass + '">' + statusLabel + '</span></td>' +
                    '</tr>';
            }).join(''));
        }

        function loadRows() {
            $('#gl_backfill_review_table tbody').html('<tr><td colspan="5" class="text-center">Memuat…</td></tr>');
            request(urls.rows, 'GET', filters()).done(renderRows).fail(showError);
        }

        function addOptions(selector, items, value, label) {
            var $select = $(selector);
            var firstOption = $select.find('option:first').clone();
            $select.empty().append(firstOption);
            (items || []).forEach(function (item) {
                $select.append($('<option>').val(value(item)).text(label(item)));
            });
        }

        function populateAccounts(options) {
            var accountMarkup = (options.accounts || []).map(function (account) {
                var prefix = account.code ? account.code + ' — ' : '';
                return $('<option>').val(account.id).text(prefix + account.name + (account.type ? ' (' + account.type + ')' : ''));
            });
            ['#gl_backfill_payment_account', '#gl_backfill_deposit_account', '#gl_backfill_main_coa', '#gl_backfill_vat_input', '#gl_backfill_vat_output'].forEach(function (selector) {
                var $select = $(selector);
                if (!$select.length) { return; }
                var firstOption = $select.find('option:first').clone();
                $select.empty().append(firstOption);
                accountMarkup.forEach(function ($option) { $select.append($option.clone()); });
            });
            if (options.vat_mappings) {
                $('#gl_backfill_vat_input').val(options.vat_mappings.input || '');
                $('#gl_backfill_vat_output').val(options.vat_mappings.output || '');
            }
        }

        function loadOptions() {
            request(urls.options, 'GET').done(function (options) {
                state.groups = options.groups || [];
                state.accounts = options.accounts || [];
                addOptions('#gl_backfill_mapping_group', state.groups, function (item) { return String(item.location_id) + '|' + item.mapping_key; }, function (item) { return item.label + ' (' + item.pending_count + ')'; });
                addOptions('#gl_backfill_main_account', options.main_accounts || [], function (item) { return item.id; }, function (item) { return item.name + ' (' + item.pending_count + ' sumber)'; });
                addOptions('#gl_backfill_reason', options.reasons || [], function (item) { return item; }, function (item) { return item; });
                addOptions('#gl_backfill_source_type', options.source_types || [], function (item) { return item.value; }, function (item) { return item.label; });
                populateAccounts(options);
                updateMappingFields();
                if (options.active_batch) {
                    watchBatch(options.active_batch.id);
                }
            }).fail(showError);
        }

        function selectedGroup() {
            var values = String($('#gl_backfill_mapping_group').val() || '').split('|');
            if (values.length !== 2 || !values[0] || !values[1]) { return null; }
            return { location_id: Number(values[0]), mapping_key: values[1] };
        }

        function updateMappingFields() {
            var group = selectedGroup();
            var key = group ? group.mapping_key : '';
            var selected = group ? state.groups.find(function (item) { return Number(item.location_id) === group.location_id && item.mapping_key === group.mapping_key; }) : null;
            var isSellPayment = key === 'sell_payment';
            var isPurchasePayment = key === 'purchase_payment';
            $('#gl_backfill_payment_account').val(selected && selected.current_payment_account_id ? selected.current_payment_account_id : '');
            $('#gl_backfill_deposit_account').val(selected && selected.current_deposit_to_id ? selected.current_deposit_to_id : '');
            $('#gl_backfill_payment_account').prop('disabled', isPurchasePayment);
            $('#gl_backfill_deposit_account').prop('disabled', isSellPayment);
            $('#gl_backfill_location_mapping_result').text('');
            $('#gl_backfill_location_retry').hide();
        }

        function showRetryButton(selector, response) {
            $(selector).data('retry-scope', response.scope || {}).data('matched-count', response.matched_count || 0)
                .text('Coba ulang ' + (response.matched_count || 0) + ' item cocok')
                .toggle(Number(response.matched_count || 0) > 0 && $page.data('can-retry') == 1);
        }

        function startRetry(scope, count) {
            if (!scope || !count) { return; }
            if (!window.confirm('Coba ulang ' + count + ' sumber yang cocok dengan mapping ini? Proses berjalan di worker dan jurnal yang sudah tertaut tidak akan dibuat ulang.')) {
                return;
            }
            request(urls.retry, 'POST', scope).done(function (batch) {
                if (batch.total_items > 0) {
                    watchBatch(batch.batch_id);
                } else {
                    $('#gl_backfill_retry_progress').removeClass('alert-info').addClass('alert-warning').text('Tidak ada item pending yang cocok.').show();
                }
                loadRows();
            }).fail(showError);
        }

        function watchBatch(batchId) {
            if (state.pollTimer) { window.clearInterval(state.pollTimer); }
            $('#gl_backfill_retry_progress').removeClass('alert-warning alert-success').addClass('alert-info').text('Batch #' + batchId + ' masuk antrean worker…').show();
            var poll = function () {
                request(urls.batchBase + '/' + batchId, 'GET').done(function (batch) {
                    $('#gl_backfill_retry_progress').text('Batch #' + batch.id + ': ' + batch.status + ' — ' + batch.processed_items + '/' + batch.total_items + ' diproses; ' + batch.resolved_items + ' selesai; ' + batch.failed_items + ' masih perlu ditinjau. ' + (batch.last_message || ''));
                    if (['completed', 'completed_with_errors', 'failed', 'empty'].indexOf(batch.status) !== -1) {
                        window.clearInterval(state.pollTimer);
                        state.pollTimer = null;
                        $('#gl_backfill_retry_progress').removeClass('alert-info').addClass(batch.failed_items > 0 ? 'alert-warning' : 'alert-success');
                        loadRows();
                        loadOptions();
                    }
                }).fail(function () {
                    window.clearInterval(state.pollTimer);
                    state.pollTimer = null;
                });
            };
            poll();
            state.pollTimer = window.setInterval(poll, 2000);
        }

        $('#gl_backfill_prev').on('click', function () { if (state.page > 1) { state.page -= 1; loadRows(); } });
        $('#gl_backfill_next').on('click', function () { if (state.page < state.lastPage) { state.page += 1; loadRows(); } });
        $('#gl_backfill_reason, #gl_backfill_source_type, #gl_backfill_status, #gl_backfill_start_date, #gl_backfill_end_date').on('change', function () { state.page = 1; loadRows(); });
        var searchTimer;
        $('#gl_backfill_search').on('input', function () { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(function () { state.page = 1; loadRows(); }, 300); });

        $('#gl_backfill_mapping_group').on('change', updateMappingFields);
        $('#gl_backfill_save_location_mapping').on('click', function () {
            var group = selectedGroup();
            if (!group) { window.alert('Pilih kelompok lokasi dan jenis transaksi terlebih dahulu.'); return; }
            request(urls.locationMapping, 'POST', {
                location_id: group.location_id,
                mapping_key: group.mapping_key,
                payment_account_id: $('#gl_backfill_payment_account').val(),
                deposit_to_id: $('#gl_backfill_deposit_account').val()
            }).done(function (response) {
                $('#gl_backfill_location_mapping_result').text(response.message);
                showRetryButton('#gl_backfill_location_retry', response);
                loadRows();
                loadOptions();
            }).fail(showError);
        });
        $('#gl_backfill_location_retry').on('click', function () { startRetry($(this).data('retry-scope'), Number($(this).data('matched-count'))); });

        $('#gl_backfill_save_main_mapping').on('click', function () {
            request(urls.mainMapping, 'POST', {
                main_account_id: $('#gl_backfill_main_account').val(),
                accounting_account_id: $('#gl_backfill_main_coa').val()
            }).done(function (response) {
                $('#gl_backfill_main_mapping_result').text(response.message);
                showRetryButton('#gl_backfill_main_retry', response);
                loadRows();
                loadOptions();
            }).fail(showError);
        });
        $('#gl_backfill_main_retry').on('click', function () { startRetry($(this).data('retry-scope'), Number($(this).data('matched-count'))); });

        $('.gl-backfill-save-vat').on('click', function () {
            var direction = $(this).data('direction');
            var selector = direction === 'input' ? '#gl_backfill_vat_input' : '#gl_backfill_vat_output';
            var retryButton = direction === 'input' ? '#gl_backfill_vat_input_retry' : '#gl_backfill_vat_output_retry';
            var result = direction === 'input' ? '#gl_backfill_vat_input_result' : '#gl_backfill_vat_output_result';
            request(urls.vatMapping, 'POST', { direction: direction, accounting_account_id: $(selector).val() }).done(function (response) {
                $(result).text(response.message);
                showRetryButton(retryButton, response);
                loadRows();
            }).fail(showError);
        });
        $('#gl_backfill_vat_input_retry').on('click', function () { startRetry($(this).data('retry-scope'), Number($(this).data('matched-count'))); });
        $('#gl_backfill_vat_output_retry').on('click', function () { startRetry($(this).data('retry-scope'), Number($(this).data('matched-count'))); });

        loadOptions();
        loadRows();
    });
})(jQuery);
