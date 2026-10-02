(function ($) {
    'use strict';

    var app = document.getElementById('solar-app');
    var reconcileApp = document.getElementById('solar-reconcile-app');
    var historyApp = document.getElementById('solar-import-history');
    if (!app && !reconcileApp && !historyApp) {
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

    function loadPreviewWorkspace(batchId) {
        var template = $('#solar-import-form').attr('data-reconcile-url-template')
            || (reconcileApp && $(reconcileApp).attr('data-reconcile-url-template'));
        if (!template) {
            $('#solar-process-message').text('Preview selesai. Buka batch dari Riwayat Import untuk meninjaunya.');
            return;
        }
        var url = template.replace('__BATCH__', encodeURIComponent(batchId));
        $.get(url, { fragment: 1 }).done(function (html) {
            $('#solar-preview-workspace-container').html(html);
            reconcileApp = document.getElementById('solar-reconcile-app');
            initReconcile();
            processModal.one('hidden.bs.modal', function () { $('#solar-preview-modal').modal('show'); });
            processModal.modal('hide');
        }).fail(function () {
            $('#solar-process-message').text('Preview selesai, tetapi panel preview tidak dapat dimuat. Batch tersedia di Riwayat Import.');
        });
    }

    function refreshSolarTables() {
        if (!app) return;
        var url = window.location.href.split('#')[0];
        $.get(url).done(function (html) {
            var freshDocument = $('<div>').append($.parseHTML(html));
            var currentTables = $(app).find('[data-ledger-table]');
            currentTables.each(function (index) {
                var ledger = $(this).attr('data-ledger-table');
                var freshTable = freshDocument.find('#solar-app [data-ledger-table="' + ledger + '"]').eq(
                    currentTables.slice(0, index).filter('[data-ledger-table="' + ledger + '"]').length
                );
                if (freshTable.length) $(this).replaceWith(freshTable);
            });
        });
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
            $('#solar-process-retry').text('Jalankan ulang').show().data('retry', true);
        }
        if (data.state === 'completed') {
            window.localStorage.removeItem('solar.activeBatch');
            if (data.operation === 'preview') {
                loadPreviewWorkspace(data.batch_id || processBatchId);
            } else if (data.operation === 'import') {
                $('#solar-process-message').text('Import selesai. Data Solar sedang diperbarui pada halaman ini.');
                refreshSolarTables();
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
                var retryTemplate = $('#solar-import-form').attr('data-retry-url-template') || (reconcileApp && $(reconcileApp).attr('data-retry-url-template'));
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
            var cancelTemplate = importForm.attr('data-cancel-url-template') || (reconcileApp && $(reconcileApp).attr('data-cancel-url-template'));
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
                } else if (['failed', 'cancelled'].indexOf(data.state) !== -1) {
                    processBatchId = data.batch_id;
                    processStatusUrl = $(reconcileApp).attr('data-status-url');
                    processOperation = data.operation;
                    resetProcessModal(data.operation === 'import' ? 'Import gagal' : 'Preview gagal');
                    updateProcess(data);
                }
            });
        }
    }

    function initHistoryUI() {
        var history = $('#solar-import-history');
        if (!history.length) return;
        var timer = null;
        var initialHistoryPoll = true;
        function pollHistory() {
            var batchRows = history.find('tr[data-import-batch]');
            var activeRows = initialHistoryPoll ? batchRows : batchRows.filter(function () {
                return ['queued', 'processing'].indexOf($(this).attr('data-process-state')) !== -1;
            });
            initialHistoryPoll = false;
            if (!activeRows.length) return;
            var pending = activeRows.length;
            var hasActive = false;
            activeRows.each(function () {
                var tr = $(this);
                $.getJSON(tr.attr('data-status-url')).done(function (data) {
                    tr.attr('data-process-state', data.state);
                    if (['queued', 'processing'].indexOf(data.state) !== -1) hasActive = true;
                    var color = data.state === 'failed' ? 'danger' : (data.state === 'completed' ? 'success' : (data.state === 'cancelled' ? 'default' : 'info'));
                    tr.find('[data-process-cell]').html('<span class="label label-' + color + '">' + escapeHtml(data.state) + '</span>' +
                        (['queued', 'processing'].indexOf(data.state) !== -1
                            ? '<div class="progress progress-xs" style="margin:5px 0 0"><div class="progress-bar" data-process-progress style="width:' + Number(data.percent || 0) + '%"></div></div><small class="text-muted" data-process-message>' + Number(data.percent || 0) + '% · ' + escapeHtml(data.message || '') + (data.stale ? ' · Worker tidak merespons' : '') + '</small>'
                            : '<small class="text-muted">' + escapeHtml(data.message || data.error || '') + '</small>'));
                    var counts = { stock: 0, pool: 0, mess: 0, usage: 0 };
                    var summary = data.summary || {};
                    if (Array.isArray(summary.sheets)) {
                        summary.sheets.forEach(function (sheet) {
                            var ledger = String(sheet.ledger || '').toLowerCase();
                            if (Object.prototype.hasOwnProperty.call(counts, ledger)) counts[ledger] += Number(sheet.total || 0);
                        });
                    } else {
                        Object.keys(counts).forEach(function (ledger) { counts[ledger] = Number(summary[ledger] || 0); });
                    }
                    ['stock', 'pool', 'mess', 'usage'].forEach(function (ledger, index) {
                        tr.children().eq(4 + index).text(counts[ledger]);
                    });
                    if (data.domain_status === 'imported') tr.find('[data-domain-status]').removeClass('label-warning label-default').addClass('label-success').text('Imported');
                }).always(function () {
                    pending--;
                    if (pending === 0 && hasActive) timer = window.setTimeout(pollHistory, 4000);
                });
            });
        }
        history.find('tr[data-import-batch]').each(function () {
            var state = $(this).find('[data-process-cell] .label').text().toLowerCase();
            $(this).attr('data-process-state', state);
        });
        pollHistory();
        history.on('submit', '.solar-rollback-form', function (event) {
            event.preventDefault();
            if (!window.confirm('Rollback batch ini? Semua data yang dibuat batch akan dihapus dan saldo dihitung ulang.')) return;
            var form = this;
            var row = $(form).closest('tr');
            var button = $(form).find('button[type="submit"]').prop('disabled', true);
            $.ajax({ url: form.action, method: 'POST', data: $(form).serialize(), headers: { Accept: 'application/json' } })
                .done(function (response) {
                    row.find('[data-domain-status]').removeClass('label-success label-warning').addClass('label-default').text('Rolled back');
                    $(form).replaceWith('<span class="text-muted">Sudah di-rollback</span>');
                    $('#solar-history-feedback').removeClass('alert-danger').addClass('alert-success').text(response.message || 'Rollback selesai.').removeAttr('hidden');
                }).fail(function (xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Rollback gagal.';
                    $('#solar-history-feedback').removeClass('alert-success').addClass('alert-danger').text(message).removeAttr('hidden');
                }).always(function () { button.prop('disabled', false); });
        });
    }

    var previewGridApi = null;
    var previewSheets = [];
    var previewRows = [];
    var previewActiveSheet = null;
    var previewFilter = 'all';
    var previewAssetMap = {};
    var previewCustomerMap = {};

    function previewRowsUrl(sheet) {
        var template = reconcileApp.getAttribute('data-rows-url-template');
        return template.replace('__BATCH__', encodeURIComponent(reconcileApp.getAttribute('data-batch-id')))
            + '?sheet=' + encodeURIComponent(sheet) + '&length=5000';
    }

    function previewUpdateUrl(rowId) {
        return reconcileApp.getAttribute('data-update-url-template')
            .replace('__BATCH__', encodeURIComponent(reconcileApp.getAttribute('data-batch-id')))
            .replace('__ROW__', encodeURIComponent(rowId));
    }

    function updatePreviewStats(summary) {
        var importable = Number(summary.importable || summary.only_excel || 0);
        var needsCheck = Number(summary.needs_check || 0);
        var total = Number(summary.total || 0);
        $('#solar-preview-summary').html(
            '<div class="col-sm-3 col-xs-6"><div class="solar-preview-stat"><span>Total baris</span><strong>' + total + '</strong></div></div>' +
            '<div class="col-sm-3 col-xs-6"><div class="solar-preview-stat"><span>Siap diimpor</span><strong class="text-success">' + importable + '</strong></div></div>' +
            '<div class="col-sm-3 col-xs-6"><div class="solar-preview-stat"><span>Perlu diperiksa</span><strong class="text-warning">' + needsCheck + '</strong></div></div>' +
            '<div class="col-sm-3 col-xs-6"><div class="solar-preview-stat"><span>Sheet</span><strong>' + (summary.sheets || []).length + '</strong></div></div>'
        );
        $('#solar-preview-importable-count').text(importable);
        $('#solar-preview-skip-count').text(Math.max(0, total - importable));
        $('#solar-preview-confirm').prop('disabled', importable < 1).text('Konfirmasi & Antrekan Import (' + importable + ')');
        previewSheets = summary.sheets || previewSheets;
        renderPreviewTabs();
    }

    function renderPreviewTabs() {
        var html = previewSheets.map(function (sheet, index) {
            var active = previewActiveSheet === sheet.sheet_key || (!previewActiveSheet && index === 0);
            var issue = Number(sheet.needs_check || 0);
            return '<button type="button" class="solar-preview-tab' + (active ? ' active' : '') + '" data-sheet-key="' + escapeHtml(sheet.sheet_key) + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '">' +
                escapeHtml(sheet.title || sheet.sheet_key) + ' <span class="badge">' + Number(sheet.total || 0) + '</span><small>Siap ' + Number(sheet.importable || 0) + ' · Isu ' + issue + '</small></button>';
        }).join('');
        $('#solar-preview-tabs').html(html);
    }

    function filteredPreviewRows() {
        return previewRows.filter(function (row) {
            if (previewFilter === 'issues') return row.status === 'error' || row.status === 'different' || row.status === 'only_app';
            if (previewFilter === 'ready') return !!row.importable;
            return true;
        });
    }

    function rowPayload(row) {
        var original = $.extend({}, row.excel || {});
        ['tanggal', 'stock_in', 'out_actual', 'out_meteran', 'qty_in', 'qty_out', 'keterangan', 'unit', 'operator', 'customer_id', 'nama_kapal', 'lokasi', 'equipment_name', 'asset_id', 'quantity_gln'].forEach(function (field) {
            if (Object.prototype.hasOwnProperty.call(row, field)) original[field] = row[field];
        });
        var payload = {
            _token: $('#solar-preview-csrf').val(),
            tanggal: row.tanggal || row.date_iso || '',
            stock_in: original.stock_in,
            out_actual: original.out_actual,
            out_meteran: original.out_meteran,
            qty_in: original.qty_in,
            qty_out: original.qty_out,
            keterangan: original.keterangan,
            unit: original.unit,
            operator: original.operator,
            customer_id: original.customer_id || '',
            nama_kapal: original.nama_kapal,
            lokasi: original.lokasi,
            equipment_name: original.equipment_name || '',
            asset_id: original.asset_id || '',
            quantity_gln: original.quantity_gln || ''
        };
        return payload;
    }

    function applyPreviewFilter() {
        if (previewGridApi) previewGridApi.setGridOption('rowData', filteredPreviewRows());
    }

    function formatSelectValue(map, value, fallback) {
        if (value === null || value === '' || typeof value === 'undefined') return fallback || '—';
        return map[String(value)] || fallback || String(value);
    }

    function previewColumnDefs(ledger) {
        var defs = [
            { headerName: 'Baris Excel', field: 'source_row', width: 100, pinned: 'left', editable: false },
            { headerName: 'Tanggal ✎', field: 'tanggal', width: 140, editable: true, cellEditor: 'agTextCellEditor', cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return p.value || 'Isi tanggal'; } }
        ];
        if (ledger === 'usage') {
            defs.push(
                { headerName: 'Nama alat', field: 'equipment_name', minWidth: 180, editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Asset ✎', field: 'asset_id', minWidth: 190, editable: true, cellEditor: 'agSelectCellEditor', cellEditorParams: { values: [''].concat(Object.keys(previewAssetMap)) }, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewAssetMap, p.value, 'Belum dipetakan · ' + (p.data.equipment_name || '')); } },
                { headerName: 'Jumlah (Gln) ✎', field: 'quantity_gln', minWidth: 145, editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell', valueParser: function (p) { return Number(p.newValue); }, valueFormatter: function (p) { return p.value === '' || p.value == null ? '' : Number(p.value).toLocaleString('id-ID', { maximumFractionDigits: 2 }); } },
                { headerName: 'Liter (Gln × 30)', valueGetter: function (p) { return Number(p.data.quantity_gln || 0) * 30; }, minWidth: 150, valueFormatter: function (p) { return Number(p.value || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }); } }
            );
        } else if (ledger === 'stock') {
            defs.push(
                { headerName: 'Masuk (L) ✎', field: 'stock_in', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keluar Aktual (L) ✎', field: 'out_actual', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keluar Meteran (L) ✎', field: 'out_meteran', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keterangan ✎', field: 'keterangan', editable: true, cellClass: 'solar-preview-editable-cell', minWidth: 180 }
            );
        } else {
            defs.push(
                { headerName: 'Masuk (Gln) ✎', field: 'qty_in', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keluar (Gln) ✎', field: 'qty_out', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Unit ✎', field: 'unit', editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Operator ✎', field: 'operator', editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Customer ✎', field: 'customer_id', minWidth: 190, editable: true, cellEditor: 'agSelectCellEditor', cellEditorParams: { values: [''].concat(Object.keys(previewCustomerMap)) }, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewCustomerMap, p.value, p.data.customer_ref ? 'Belum cocok · ' + p.data.customer_ref : '—'); } }
            );
            if (ledger === 'mess') defs.push(
                { headerName: 'Nama Kapal ✎', field: 'nama_kapal', editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Lokasi ✎', field: 'lokasi', editable: true, cellClass: 'solar-preview-editable-cell' }
            );
        }
        defs.push(
            { headerName: 'Hasil pencocokan', field: 'status_label', minWidth: 145, editable: false },
            { headerName: 'Keterangan / alasan', field: 'detail', minWidth: 280, maxWidth: 420, flex: 1, editable: false, tooltipField: 'detail' }
        );
        return defs.map(function (definition) {
            if (definition.editable === true) {
                definition.editable = function (params) { return !!params.data.has_excel && params.data.status !== 'only_app'; };
            }
            return definition;
        });
    }

    function savePreviewCell(event) {
        var row = event.data;
        var originalValue = event.oldValue;
        if (!row || !row.id) return;
        if (event.colDef.field === 'tanggal') {
            var normalizedDate = String(event.newValue || '').trim();
            var dateParts = normalizedDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            var dateObject = dateParts ? new Date(Number(dateParts[1]), Number(dateParts[2]) - 1, Number(dateParts[3])) : null;
            if (!dateObject || dateObject.getFullYear() !== Number(dateParts[1]) || dateObject.getMonth() !== Number(dateParts[2]) - 1 || dateObject.getDate() !== Number(dateParts[3])) {
                row[event.colDef.field] = originalValue;
                event.api.refreshCells({ rowNodes: [event.node] });
                showPreviewFeedback('Tanggal harus valid dengan format YYYY-MM-DD.', false);
                return;
            }
            row.tanggal = normalizedDate;
        }
        if (event.colDef.field === 'asset_id' || event.colDef.field === 'customer_id') {
            row[event.colDef.field] = event.newValue === '' ? null : Number(event.newValue);
        }
        var body = rowPayload(row);
        $.ajax({ url: previewUpdateUrl(row.id), method: 'PATCH', data: body, headers: { Accept: 'application/json' } })
            .done(function (response) {
                row.status = response.row.status;
                row.importable = !!response.row.importable;
                row.detail = response.row.detail || '';
                row.excel = response.row.excel || row.excel;
                Object.keys(row.excel || {}).forEach(function (key) { row[key] = row.excel[key]; });
                updatePreviewStats(response.summary || {});
                $('#solar-preview-feedback').attr('hidden', true);
                loadPreviewSheet(previewActiveSheet, true);
            })
            .fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Perubahan tidak tersimpan.';
                if (xhr.responseJSON && xhr.responseJSON.errors) message = Object.values(xhr.responseJSON.errors).flat()[0] || message;
                showPreviewFeedback(message, false);
                loadPreviewSheet(previewActiveSheet, true);
            });
    }

    function showPreviewFeedback(message, success) {
        $('#solar-preview-feedback').removeClass('alert-danger alert-success').addClass(success ? 'alert-success' : 'alert-danger').text(message).removeAttr('hidden');
    }

    function loadPreviewSheet(sheetKey, keepGrid) {
        if (!reconcileApp || !sheetKey) return;
        var sheet = previewSheets.find(function (item) { return item.sheet_key === sheetKey; });
        if (!sheet) return;
        previewActiveSheet = sheetKey;
        renderPreviewTabs();
        $('#solar-preview-sheet-title').text(sheet.title || sheetKey);
        $('#solar-preview-sheet-counts').text(Number(sheet.total || 0) + ' baris · ' + Number(sheet.importable || 0) + ' siap · ' + Number(sheet.needs_check || 0) + ' perlu diperiksa');
        $.getJSON(previewRowsUrl(sheetKey)).done(function (response) {
            previewRows = (response.data || []).map(function (item) {
                var excel = item.excel || {};
                return $.extend({}, excel, {
                    id: item.id,
                    ledger_key: String(item.ledger || sheet.ledger || '').toLowerCase(),
                    source_row: item.source_row,
                    source_sheet: item.source_sheet || item.sheet,
                    tanggal: item.date_iso || (/^\d{4}-\d{2}-\d{2}$/.test(String(excel.tanggal || '')) ? excel.tanggal : ''),
                    raw_date: item.raw_date || '',
                    status: item.status,
                    status_label: item.status_label,
                    detail: item.detail,
                    importable: !!item.importable,
                    has_excel: item.excel !== null && typeof item.excel === 'object' && Object.keys(item.excel).length > 0,
                    excel: excel,
                    customer_ref: excel.customer_ref || '',
                    equipment_name: excel.equipment_name || item.equipment_name || '',
                    asset_id: excel.asset_id == null ? '' : String(excel.asset_id),
                    customer_id: excel.customer_id == null ? '' : String(excel.customer_id),
                    quantity_gln: excel.quantity_gln == null && item.raw_value ? Number(item.raw_value) : excel.quantity_gln
                });
            });
            $('#solar-preview-sheet-counts').text(previewRows.length + ' baris ditampilkan · ' + Number(sheet.importable || 0) + ' siap · ' + Number(sheet.needs_check || 0) + ' perlu diperiksa');
            if (!window.agGrid || typeof window.agGrid.createGrid !== 'function') {
                showPreviewFeedback('AG Grid Community belum termuat. Periksa asset JavaScript lokal.', false);
                return;
            }
            if (window.agGrid.ModuleRegistry && window.agGrid.AllCommunityModule) window.agGrid.ModuleRegistry.registerModules([window.agGrid.AllCommunityModule]);
            var gridElement = document.getElementById('solar-preview-grid');
            if (previewGridApi && !keepGrid) previewGridApi.destroy();
            if (!previewGridApi || !keepGrid) {
                previewGridApi = window.agGrid.createGrid(gridElement, {
                    theme: window.agGrid.themeQuartz,
                    rowData: filteredPreviewRows(),
                    columnDefs: previewColumnDefs(String(sheet.ledger || '').toLowerCase()),
                    defaultColDef: { resizable: true, sortable: true, filter: true, minWidth: 110, wrapHeaderText: true, autoHeaderHeight: true },
                    rowHeight: 39,
                    headerHeight: 44,
                    animateRows: false,
                    singleClickEdit: false,
                    getRowId: function (params) { return String(params.data.id); },
                    rowClassRules: { 'solar-preview-row-issue': function (params) { return ['error', 'different', 'only_app'].indexOf(params.data.status) !== -1; } },
                    onCellValueChanged: savePreviewCell
                });
            } else {
                previewGridApi.setGridOption('rowData', filteredPreviewRows());
            }
        }).fail(function () {
            showPreviewFeedback('Data sheet tidak dapat dimuat. Coba pilih sheet kembali.', false);
        });
    }

    function initReconcile() {
        if (!reconcileApp || reconcileApp.getAttribute('data-grid-ready') === '1') return;
        reconcileApp.setAttribute('data-grid-ready', '1');
        var assets = JSON.parse(reconcileApp.getAttribute('data-assets') || '[]');
        var customers = JSON.parse(reconcileApp.getAttribute('data-customers') || '[]');
        previewAssetMap = {};
        assets.forEach(function (asset) { previewAssetMap[String(asset.id)] = [asset.asset_code, asset.name].filter(Boolean).join(' · '); });
        previewCustomerMap = {};
        customers.forEach(function (customer) { previewCustomerMap[String(customer.id)] = customer.supplier_business_name || customer.name; });
        try { previewSheets = JSON.parse(reconcileApp.getAttribute('data-sheets') || '[]'); } catch (error) { previewSheets = []; }
        previewActiveSheet = previewSheets.length ? previewSheets[0].sheet_key : null;
        updatePreviewStats({ sheets: previewSheets, total: previewSheets.reduce(function (sum, sheet) { return sum + Number(sheet.total || 0); }, 0), importable: previewSheets.reduce(function (sum, sheet) { return sum + Number(sheet.importable || 0); }, 0), needs_check: previewSheets.reduce(function (sum, sheet) { return sum + Number(sheet.needs_check || 0); }, 0) });
        if (previewActiveSheet) loadPreviewSheet(previewActiveSheet);
        $('#solar-preview-tabs').off('click.solar').on('click.solar', '.solar-preview-tab', function () { loadPreviewSheet($(this).data('sheet-key')); });
        $('#solar-preview-filter').off('change.solar').on('change.solar', function () { previewFilter = this.value; applyPreviewFilter(); });
        $('#solar-preview-confirm').off('click.solar').on('click.solar', function () {
            var batchId = reconcileApp.getAttribute('data-batch-id');
            var token = $('#solar-preview-csrf').val();
            var importableCount = Number($('#solar-preview-importable-count').text() || 0);
            var skipCount = Number($('#solar-preview-skip-count').text() || 0);
            if (!window.confirm('Antrekan ' + importableCount + ' baris untuk diimpor. ' + skipCount + ' baris akan dilewati atau tidak ikut diimpor. Lanjutkan?')) return;
            $(this).prop('disabled', true);
            $.ajax({
                url: reconcileApp.getAttribute('data-confirm-url-template').replace('__BATCH__', encodeURIComponent(batchId)),
                method: 'POST', data: { _token: token }, headers: { Accept: 'application/json' }
            }).done(function (response) {
                processModal.length && startProcess(response.batch_id, response.status_url, 'Mengimpor Solar');
                $('#solar-preview-modal').modal('hide');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Import tidak dapat diantrekan.';
                showPreviewFeedback(message, false);
                $('#solar-preview-confirm').prop('disabled', false);
            });
        });
    }

    initProcessUI();
    initReconcile();
    initHistoryUI();
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
        var quantityLedger = ledger === 'pool' || ledger === 'mess';
        $('.ledger-quantity').toggle(quantityLedger).find(':input').prop('disabled', !quantityLedger);
        $('.ledger-mess').toggle(ledger === 'mess').find(':input').prop('disabled', ledger !== 'mess');
        $('.ledger-usage').toggle(ledger === 'usage').find(':input').prop('disabled', ledger !== 'usage');
        $('.ledger-not-usage, .solar-not-usage').toggle(ledger !== 'usage').find(':input').prop('disabled', ledger === 'usage');
    }

    function openRecordModal(ledger, record) {
        record = record || {};
        currentLedger = ledger;
        form.reset();
        showLedgerFields(ledger);
        $('#solar-record-title').text((record.id ? 'Edit ' : 'Tambah ') + 'Transaksi ' + ledger.toUpperCase());
        form.action = baseUrl + '/' + ledger + (record.id ? '/' + record.id : '');
        setValue('solar-form-method', record.id ? 'PUT' : 'POST');
        setValue('solar-tanggal', record.tanggal || record.usage_date || new Date().toISOString().slice(0, 10));
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
        setValue('solar-equipment-name', record.equipment_name);
        setValue('solar-asset-id', record.asset_id);
        setValue('solar-quantity-gln', record.quantity_gln);
        $('#solar-customer').trigger('change');
        $('#solar-asset-id').trigger('change');
        $('#solar-quantity-gln').trigger('input');
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

    $('#solar-asset-id').on('change', function () {
        var selected = $(this).find(':selected');
        var equipmentName = $('#solar-equipment-name');
        if (this.value && !equipmentName.val()) {
            equipmentName.val(selected.data('asset-name') || selected.text());
        }
    });
    $('#solar-quantity-gln').on('input', function () {
        var liters = Number(this.value || 0) * 30;
        $('#solar-quantity-liter').text(liters.toLocaleString('id-ID', { maximumFractionDigits: 2 }));
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

    var initialTab = ['stock', 'pool', 'mess', 'usage'].indexOf(currentLedger) !== -1 ? currentLedger : 'stock';
    $('#solar-tabs a[href="#' + initialTab + '"]').tab('show');
    if ($.fn.select2) {
        $('#solar-customer').select2({
            dropdownParent: $('#solar-record-modal'),
            allowClear: true,
            placeholder: '— Opsional —',
            width: '100%'
        });
        $('#solar-asset-id').select2({
            dropdownParent: $('#solar-record-modal'),
            allowClear: true,
            width: '100%'
        });
    }
})(window.jQuery);
