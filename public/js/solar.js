(function ($) {
    'use strict';

    var app = document.getElementById('solar-app');
    var reconcileApp = document.getElementById('solar-reconcile-app');
    if (!app && !reconcileApp) {
        return;
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    var processModal = $('#solar-process-modal');
    var processTimer = null;
    var processDelay = 1000;
    var processStatusUrl = null;
    var processBatchId = null;
    var processOperation = null;
    var processLastHeartbeat = null;

    function processText(stage) {
        return {
            uploading: 'Mengunggah file',
            reading: 'Membaca workbook',
            matching: 'Mencocokkan transaksi',
            saving: 'Menyimpan hasil',
            recalculating: 'Menghitung ulang saldo',
            finished: 'Selesai',
            failed: 'Gagal',
            cancelled: 'Dibatalkan'
        }[stage] || stage || 'Memproses';
    }

    function formatElapsed(startedAt) {
        if (!startedAt) {
            return '0 detik';
        }
        var seconds = Math.max(0, Math.floor((Date.now() - new Date(startedAt).getTime()) / 1000));
        return seconds < 60 ? seconds + ' detik' : Math.floor(seconds / 60) + ' menit ' + (seconds % 60) + ' detik';
    }

    function resetProcessModal(title) {
        $('#solar-process-title').text(title || 'Memproses');
        $('#solar-process-progress').css('width', '0%').text('0%');
        $('#solar-process-stage').text('Menunggu proses...');
        $('#solar-process-message').text('Memulai...');
        $('#solar-process-elapsed').text('0 detik');
        $('#solar-process-heartbeat').text('—');
        $('#solar-process-error, #solar-process-stale, #solar-process-retry, #solar-process-close').hide();
        $('#solar-process-retry').removeData('retry').text('Coba cek lagi');
        $('#solar-process-cancel').show().prop('disabled', false);
        processModal.modal('show');
    }

    function stopProcessPolling() {
        if (processTimer) {
            window.clearTimeout(processTimer);
            processTimer = null;
        }
    }

    function finishProcess(data) {
        stopProcessPolling();
        $('#solar-process-cancel').hide();
        $('#solar-process-close').show();
        if (data.state === 'failed') {
            $('#solar-process-error').text(data.error || data.message || 'Proses gagal.').show();
            $('#solar-process-retry').text('Proses ulang').show().data('retry', true);
        }
        if (data.state === 'cancelled') {
            $('#solar-process-error').text(data.message || 'Proses dibatalkan.').show();
        }
        if (data.state === 'completed') {
            window.localStorage.removeItem('solar.activeBatch');
            if (data.operation === 'preview') {
                var reconcileTemplate = $('#solar-reconcile-app').attr('data-reconcile-url') || $('#solar-import-form').attr('data-reconcile-url-template');
                window.location.href = reconcileTemplate
                    ? reconcileTemplate.replace('__BATCH__', encodeURIComponent(data.batch_id))
                    : ('imports/' + encodeURIComponent(data.batch_id) + '/reconcile');
            } else if (data.operation === 'import') {
                window.location.href = $('#solar-app').attr('data-base-url') || (reconcileApp ? $(reconcileApp).attr('data-base-url') : '/solar');
            }
        }
    }

    function updateProcess(data) {
        processOperation = data.operation || processOperation;
        var percent = Math.max(0, Math.min(100, Number(data.percent || 0)));
        $('#solar-process-progress').css('width', percent + '%').text(percent + '%');
        $('#solar-process-stage').text(processText(data.stage));
        $('#solar-process-message').text(data.message || 'Memproses...');
        $('#solar-process-elapsed').text(formatElapsed(data.started_at));
        $('#solar-process-heartbeat').text(data.heartbeat_at ? new Date(data.heartbeat_at).toLocaleTimeString() : '—');
        processLastHeartbeat = data.heartbeat_at || processLastHeartbeat;
        $('#solar-process-stale').toggle(!!data.stale);
        if (data.stale) {
            $('#solar-process-retry').show();
            stopProcessPolling();
        }
        if (['completed', 'failed', 'cancelled'].indexOf(data.state) !== -1) {
            finishProcess(data);
        }
    }

    function pollProcess() {
        if (!processStatusUrl) {
            return;
        }
        $.getJSON(processStatusUrl).done(function (data) {
            processDelay = Math.min(5000, processDelay + 500);
            updateProcess(data);
            if (['completed', 'failed', 'cancelled'].indexOf(data.state) === -1 && !data.stale) {
                processTimer = window.setTimeout(pollProcess, processDelay);
            }
        }).fail(function () {
            $('#solar-process-stale').text('Status server tidak dapat diambil. Proses mungkin masih berjalan.').show();
            $('#solar-process-retry').show();
            stopProcessPolling();
        });
    }

    function startProcess(batchId, statusUrl, title) {
        stopProcessPolling();
        processBatchId = batchId;
        processStatusUrl = statusUrl;
        processDelay = 1000;
        window.localStorage.setItem('solar.activeBatch', batchId);
        resetProcessModal(title);
        pollProcess();
    }

    function initProcessUI() {
        if (!processModal.length) {
            return;
        }
        var importForm = $('#solar-import-form');
        var confirmForm = $('#solar-confirm-form');

        importForm.on('submit', function (event) {
            event.preventDefault();
            var file = $('#solar-import-file')[0] && $('#solar-import-file')[0].files[0];
            if (!file) {
                return;
            }
            var form = this;
            var submit = $('#solar-import-submit').prop('disabled', true);
            resetProcessModal('Menyiapkan Preview');
            $('#solar-process-stage').text('Mengunggah file');
            var xhr = $.ajax({
                url: $(form).attr('data-ajax-url') || form.action,
                method: 'POST',
                data: new FormData(form),
                processData: false,
                contentType: false,
                headers: { Accept: 'application/json' },
                xhr: function () {
                    var request = $.ajaxSettings.xhr();
                    if (request.upload) {
                        request.upload.addEventListener('progress', function (event) {
                            if (event.lengthComputable) {
                                var percent = Math.floor((event.loaded / event.total) * 20);
                                $('#solar-process-progress').css('width', percent + '%').text(percent + '%');
                                $('#solar-process-message').text('Upload ' + Math.floor((event.loaded / event.total) * 100) + '%');
                            }
                        });
                    }
                    return request;
                }
            }).done(function (response) {
                $('#solar-import-modal').modal('hide');
                startProcess(response.batch_id, response.status_url, 'Memproses Preview');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Upload gagal.';
                $('#solar-process-error').text(message).show();
                $('#solar-process-cancel').hide();
                $('#solar-process-close').show();
            }).always(function () {
                submit.prop('disabled', false);
            });
            xhr.fail(function () { submit.prop('disabled', false); });
        });

        confirmForm.on('submit', function (event) {
            event.preventDefault();
            var form = this;
            var button = $(form).find('button[type="submit"]').prop('disabled', true);
            resetProcessModal('Mengimpor Solar');
            $.ajax({
                url: $(form).attr('data-ajax-url') || form.action,
                method: 'POST',
                data: $(form).serialize(),
                headers: { Accept: 'application/json' }
            }).done(function (response) {
                startProcess(response.batch_id, response.status_url, 'Mengimpor Solar');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Import gagal dimulai.';
                $('#solar-process-error').text(message).show();
                $('#solar-process-cancel').hide();
                $('#solar-process-close').show();
            }).always(function () {
                button.prop('disabled', false);
            });
        });

        $('#solar-process-retry').on('click', function () {
            if ($(this).data('retry')) {
                var retryTemplate = $('#solar-import-form').attr('data-retry-url-template');
                var retryUrl = retryTemplate ? retryTemplate.replace('__BATCH__', processBatchId) : (reconcileApp ? $(reconcileApp).attr('data-retry-url') : null);
                if (!retryUrl) {
                    return;
                }
                $(this).prop('disabled', true);
                $.post(retryUrl, { _token: $('input[name="_token"]').first().val() }).done(function (response) {
                    $(this).data('retry', false).text('Coba cek lagi').prop('disabled', false);
                    $('#solar-process-error').hide();
                    startProcess(processBatchId, response.status_url || processStatusUrl, processOperation === 'import' ? 'Mengimpor Solar' : 'Memproses Preview');
                }.bind(this)).fail(function () {
                    $(this).prop('disabled', false);
                }.bind(this));
                return;
            }
            $('#solar-process-stale, #solar-process-retry').hide();
            processDelay = 1000;
            pollProcess();
        });
        $('#solar-process-cancel').on('click', function () {
            if (!processBatchId || !processStatusUrl) {
                processModal.modal('hide');
                return;
            }
            var cancelTemplate = importForm.attr('data-cancel-url-template');
            var cancelUrl = cancelTemplate ? cancelTemplate.replace('__BATCH__', processBatchId) : (reconcileApp ? $(reconcileApp).attr('data-cancel-url') : null);
            if (!cancelUrl) {
                return;
            }
            $(this).prop('disabled', true);
            $.post(cancelUrl, { _token: $('input[name="_token"]').first().val() }).always(function () {
                pollProcess();
            });
        });

        var activeBatch = window.localStorage.getItem('solar.activeBatch');
        var statusTemplate = importForm.attr('data-status-url-template');
        if (activeBatch && statusTemplate) {
            startProcess(activeBatch, statusTemplate.replace('__BATCH__', activeBatch), 'Melanjutkan Proses');
        }
        if (reconcileApp && $(reconcileApp).attr('data-status-url')) {
            $.getJSON($(reconcileApp).attr('data-status-url')).done(function (data) {
                if (['queued', 'processing'].indexOf(data.state) !== -1) {
                    startProcess(data.batch_id, $(reconcileApp).attr('data-status-url'), data.operation === 'import' ? 'Mengimpor Solar' : 'Memproses Preview');
                }
            });
        }
    }

    function initReconcile() {
        if (!reconcileApp || !$.fn.DataTable) {
            return;
        }

        var table = $('#solar-preview-table').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            lengthMenu: [[25, 50, 100], [25, 50, 100]],
            ajax: {
                url: reconcileApp.getAttribute('data-rows-url'),
                data: function (data) {
                    data.ledger = $('#solar-preview-ledger').val();
                    data.status = $('#solar-preview-status').val();
                },
                dataSrc: function (json) {
                    updateSummary(json.summary || {});
                    return json.data || [];
                }
            },
            order: [[1, 'asc']],
            columns: [
                { data: 'ledger', name: 'ledger' },
                { data: 'source_row', name: 'source_row' },
                {
                    data: null,
                    name: 'normalized_date',
                    render: function (data) {
                        return data.date !== '—' ? escapeHtml(data.date) : '<span class="text-danger">' + escapeHtml(data.raw_date) + '</span>';
                    }
                },
                {
                    data: null,
                    name: 'status',
                    render: function (data) {
                        var color = data.status === 'match' ? 'success' : (data.status === 'only_excel' ? 'info' : (data.status === 'error' ? (data.matched_by === 'ambiguous' ? 'warning' : 'danger') : 'warning'));
                        return '<span class="label label-' + color + '">' + escapeHtml(data.status_label) + '</span>';
                    }
                },
                { data: 'matched_by', name: 'matched_by' },
                { data: 'detail', name: 'differences', render: function (data) { return escapeHtml(data || 'Data dan saldo sesuai.'); } },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function (data) {
                        if (!data.can_edit_date) {
                            return '<span class="text-muted">—</span>';
                        }
                        return '<button type="button" class="btn btn-xs btn-primary solar-preview-edit-date" data-row-id="' + escapeHtml(data.id) + '" data-raw-date="' + escapeHtml(data.raw_date) + '"><i class="fa fa-calendar"></i> Edit tanggal</button>';
                    }
                }
            ]
        });

        function updateSummary(summary) {
            Object.keys(summary).forEach(function (key) {
                $('[data-summary="' + key + '"]').text(summary[key]);
            });
            $('#solar-preview-confirm').prop('disabled', Number(summary.only_excel || 0) < 1);
        }

        $('#solar-preview-ledger, #solar-preview-status').on('change', function () {
            table.ajax.reload(null, true);
        });

        var editRowId = null;
        $('#solar-preview-table').on('click', '.solar-preview-edit-date', function () {
            editRowId = this.getAttribute('data-row-id');
            $('#solar-date-edit-raw').text(this.getAttribute('data-raw-date') || '—');
            $('#solar-date-edit-value').val('');
            $('#solar-date-edit-modal').modal('show');
        });

        $('#solar-date-edit-form').on('submit', function (event) {
            event.preventDefault();
            if (!editRowId) {
                return;
            }
            var url = reconcileApp.getAttribute('data-date-url-template').replace('__ROW__', editRowId);
            var button = $(this).find('button[type="submit"]').prop('disabled', true);
            $.ajax({
                url: url,
                method: 'PATCH',
                data: {
                    _token: $(this).find('input[name="_token"]').val(),
                    date: $('#solar-date-edit-value').val()
                }
            }).done(function (response) {
                updateSummary(response.summary || {});
                $('#solar-date-edit-modal').modal('hide');
                table.ajax.reload(null, false);
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Tanggal tidak dapat diperbarui.';
                window.alert(message);
            }).always(function () {
                button.prop('disabled', false);
            });
        });
    }

    initProcessUI();
    initReconcile();
    if (!app) {
        return;
    }

    var baseUrl = app.getAttribute('data-base-url');
    var currentLedger = app.getAttribute('data-active-ledger') || 'stock';
    var form = document.getElementById('solar-record-form');

    function decodeRecord(value) {
        try {
            var binary = window.atob(value);
            var bytes = Array.prototype.map.call(binary, function (character) {
                return '%' + ('00' + character.charCodeAt(0).toString(16)).slice(-2);
            }).join('');
            return JSON.parse(decodeURIComponent(bytes));
        } catch (error) {
            return {};
        }
    }

    function setValue(id, value) {
        var input = document.getElementById(id);
        if (input) {
            input.value = value === null || typeof value === 'undefined' ? '' : value;
        }
    }

    function showLedgerFields(ledger) {
        $('.ledger-stock').toggle(ledger === 'stock').find(':input').prop('disabled', ledger !== 'stock');
        $('.ledger-quantity').toggle(ledger !== 'stock').find(':input').prop('disabled', ledger === 'stock');
        $('.ledger-mess').toggle(ledger === 'mess').find(':input').prop('disabled', ledger !== 'mess');
    }

    function openRecordModal(ledger, record) {
        record = record || {};
        currentLedger = ledger;
        form.reset();
        showLedgerFields(ledger);
        $('#solar-record-title').text((record.id ? 'Edit ' : 'Tambah ') + 'Transaksi ' + ledger.toUpperCase());
        form.action = baseUrl + '/' + ledger + (record.id ? '/' + record.id : '');
        setValue('solar-form-method', record.id ? 'PUT' : 'POST');
        setValue('solar-tanggal', record.tanggal || new Date().toISOString().slice(0, 10));
        setValue('solar-stock-in', record.stock_in);
        setValue('solar-out-actual', record.out_actual);
        setValue('solar-out-meteran', record.out_meteran);
        setValue('solar-qty-in', record.qty_in);
        setValue('solar-qty-out', record.qty_out);
        setValue('solar-unit', record.unit);
        setValue('solar-operator', record.operator);
        setValue('solar-customer', record.customer_id);
        setValue('solar-nama-kapal', record.nama_kapal);
        setValue('solar-lokasi', record.lokasi);
        setValue('solar-keterangan', record.keterangan);
        $('#solar-customer').trigger('change');
        $('#solar-record-modal').modal('show');
    }

    $('.solar-add').on('click', function () {
        openRecordModal(currentLedger, {});
    });

    $('.solar-edit').on('click', function () {
        openRecordModal(this.getAttribute('data-ledger'), decodeRecord(this.getAttribute('data-record')));
    });

    $('.solar-delete').on('click', function () {
        if (!window.confirm('Hapus transaksi ini? Saldo setelah transaksi akan dihitung ulang.')) {
            return;
        }
        var deleteForm = document.getElementById('solar-delete-form');
        deleteForm.action = baseUrl + '/' + this.getAttribute('data-ledger') + '/' + this.getAttribute('data-id');
        deleteForm.submit();
    });

    $('.solar-search').on('input', function () {
        var needle = this.value.toLowerCase();
        var ledger = this.getAttribute('data-table');
        $('[data-ledger-table="' + ledger + '"] tbody tr').each(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(needle) !== -1);
        });
    });

    $('#solar-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (event) {
        currentLedger = event.target.getAttribute('data-ledger');
        app.setAttribute('data-active-ledger', currentLedger);
        document.getElementById('solar-filter-tab').value = currentLedger;
        $('.solar-add').attr('data-ledger', currentLedger).html('<i class="fa fa-plus"></i> Tambah ' + currentLedger.toUpperCase());
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', currentLedger);
            window.history.replaceState(null, '', url.toString());
        }
    });

    var initialTab = ['stock', 'pool', 'mess'].indexOf(currentLedger) !== -1 ? currentLedger : 'stock';
    $('#solar-tabs a[href="#' + initialTab + '"]').tab('show');
    if ($.fn.select2) {
        $('#solar-customer').select2({
            dropdownParent: $('#solar-record-modal'),
            allowClear: true,
            placeholder: '— Opsional —',
            width: '100%'
        });
    }
})(window.jQuery);
