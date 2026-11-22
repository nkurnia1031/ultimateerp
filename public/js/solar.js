(function ($) {
    'use strict';

    var app = document.getElementById('solar-app');
    var reconcileApp = document.getElementById('solar-reconcile-app');
    var historyApp = document.getElementById('solar-import-history');
    var importDetailApp = document.getElementById('solar-import-detail');
    if (!app && !reconcileApp && !historyApp && !importDetailApp) {
        return;
    }

    function escapeHtml(value) {
        return $('<div>').text(value === null || typeof value === 'undefined' ? '' : value).html();
    }

    var processModal = $('#solar-process-modal');
    var processTimer = null;
    var processDelay = 1000;
    var processStatusUrl = null;
    var processEventsUrl = null;
    var processBatchId = null;
    var processOperation = null;
    var processLastHeartbeat = null;
    var processEventCursor = 0;
    var processClockTimer = null;
    var processNetworkFailures = 0;
    var processStartedAt = null;
    var processCreatedAt = null;

    function processText(stage) {
        return {
            uploading: 'Mengunggah file',
            queued: 'Menunggu worker queue Solar',
            file_check: 'Memeriksa file',
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
        $('#solar-process-activity').removeClass('alert-warning alert-danger').addClass('alert-info').text('Menunggu worker mengambil batch.');
        $('#solar-process-log').html('<li class="text-muted">Mengambil log aktivitas...</li>');
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
        if (processClockTimer) {
            window.clearInterval(processClockTimer);
            processClockTimer = null;
        }
    }

    function processEventsUrlFor(batchId) {
        var template = $('#solar-import-form').attr('data-events-url-template')
            || (reconcileApp && $(reconcileApp).attr('data-events-url-template'));
        return template ? template.replace('__BATCH__', encodeURIComponent(batchId)) : null;
    }

    function fetchProcessEvents(done) {
        if (!processEventsUrl) {
            if (done) done();
            return;
        }
        $.getJSON(processEventsUrl, { after_id: processEventCursor }).done(function (response) {
            var events = response.events || [];
            if (!events.length) return;
            var list = $('#solar-process-log');
            list.find('.text-muted').remove();
            events.forEach(function (event) {
                var context = event.context || {};
                var details = [];
                if (context.sheet) details.push('Sheet ' + context.sheet);
                if (context.row) details.push('baris ' + context.row + (context.row_total ? '/' + context.row_total : ''));
                if (context.issues != null) details.push(context.issues + ' isu');
                if (event.current != null && event.total != null) details.push(event.current + '/' + event.total);
                var timestamp = event.created_at ? new Date(event.created_at).toLocaleTimeString() : '';
                var line = '<li class="' + (event.level === 'error' ? 'text-danger' : '') + '"><small class="text-muted">' + escapeHtml(timestamp) + (event.percent == null ? '' : ' · ' + Number(event.percent) + '%') + '</small> <strong>' + escapeHtml(processText(event.stage)) + ':</strong> ' + escapeHtml(event.message || '') + (details.length ? ' <span class="text-muted">(' + escapeHtml(details.join(' · ')) + ')</span>' : '') + '</li>';
                list.append(line);
                processEventCursor = Math.max(processEventCursor, Number(event.id || 0));
            });
            list.find('li').slice(0, -30).remove();
            if (list[0]) list.scrollTop(list[0].scrollHeight);
        }).always(function () { if (done) done(); });
    }

    function updateProcessElapsed(startedAt, createdAt) {
        if (startedAt) processStartedAt = startedAt;
        if (createdAt) processCreatedAt = createdAt;
        $('#solar-process-elapsed').text(formatElapsed(processStartedAt || processCreatedAt));
    }

    function openPreviewPage(batchId) {
        var template = $('#solar-import-form').attr('data-reconcile-url-template')
            || (reconcileApp && $(reconcileApp).attr('data-reconcile-url-template'));
        if (!template) {
            $('#solar-process-message').text('Preview selesai. Buka batch dari Riwayat Import untuk meninjaunya.');
            return;
        }
        window.location.href = template.replace('__BATCH__', encodeURIComponent(batchId));
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
                openPreviewPage(data.batch_id || processBatchId);
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
        updateProcessElapsed(data.started_at, data.created_at);
        $('#solar-process-heartbeat').text(data.heartbeat_at ? new Date(data.heartbeat_at).toLocaleTimeString() : '—');
        processLastHeartbeat = data.heartbeat_at || processLastHeartbeat;
        var age = Number(data.activity_age_seconds || 0);
        var ageText = age < 60 ? age + ' detik lalu' : Math.floor(age / 60) + ' menit ' + (age % 60) + ' detik lalu';
        var activity;
        var activityClass = 'alert-info';
        if (data.state === 'queued') {
            activity = data.stale
                ? 'Batch masih di antrean. Worker belum mengambilnya selama ' + ageText + '; periksa bahwa queue worker menjalankan antrean solar.'
                : 'Menunggu worker queue solar mengambil batch. File sudah diterima server.';
            if (data.stale) activityClass = 'alert-warning';
        } else if (data.stage === 'reading') {
            activity = 'Server sedang membuka atau membaca workbook. Checkpoint terakhir ' + ageText + '. Workbook dengan banyak sheet atau baris dapat memerlukan waktu; halaman tetap memeriksa status otomatis.';
            if (data.stale) activityClass = 'alert-warning';
        } else if (data.stale) {
            activity = 'Belum ada checkpoint baru selama ' + ageText + ' pada tahap ' + processText(data.stage) + '. Worker mungkin lambat atau berhenti; pemantauan akan terus mencoba kembali.';
            activityClass = 'alert-warning';
        } else {
            activity = 'Checkpoint diterima ' + ageText + '. Tahap aktif: ' + processText(data.stage) + '.';
        }
        $('#solar-process-activity').removeClass('alert-info alert-warning alert-danger').addClass(activityClass).text(activity);
        $('#solar-process-stale').toggle(!!data.stale).text(activity);
        $('#solar-process-retry').toggle(!!data.stale && ['failed', 'cancelled'].indexOf(data.state) === -1).text('Cek status lagi');
        if (['completed', 'failed', 'cancelled'].indexOf(data.state) !== -1) {
            finishProcess(data);
        }
    }

    function pollProcess() {
        if (!processStatusUrl) {
            return;
        }
        $.getJSON(processStatusUrl).done(function (data) {
            processNetworkFailures = 0;
            processDelay = Math.min(5000, Math.max(1500, processDelay + 250));
            updateProcess(data);
            fetchProcessEvents();
            if (['completed', 'failed', 'cancelled'].indexOf(data.state) === -1) processTimer = window.setTimeout(pollProcess, processDelay);
        }).fail(function () {
            processNetworkFailures++;
            var retrySeconds = Math.max(1, Math.ceil(processDelay / 1000));
            var connectionMessage = 'Koneksi status ke server gagal sementara. Percobaan ulang otomatis sekitar ' + retrySeconds + ' detik lagi; batch tetap diproses di server.';
            $('#solar-process-activity').removeClass('alert-info alert-warning').addClass('alert-danger').text(connectionMessage);
            $('#solar-process-stale').text(connectionMessage).show();
            $('#solar-process-retry').text('Coba cek sekarang').show();
            processDelay = Math.min(15000, Math.ceil(processDelay * (processNetworkFailures < 3 ? 1.6 : 1.35)));
            processTimer = window.setTimeout(pollProcess, processDelay);
        });
    }

    function startProcess(batchId, statusUrl, title) {
        stopProcessPolling();
        processBatchId = batchId;
        processStatusUrl = statusUrl;
        processEventsUrl = processEventsUrlFor(batchId);
        processEventCursor = 0;
        processNetworkFailures = 0;
        processStartedAt = null;
        processCreatedAt = new Date().toISOString();
        processDelay = 1000;
        window.localStorage.setItem('solar.activeBatch', batchId);
        resetProcessModal(title);
        processClockTimer = window.setInterval(function () { updateProcessElapsed(); }, 1000);
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
        var tableElement = $('#solar-import-history-table');
        if (!$.fn.DataTable || !tableElement.length) return;
        var timer = null;
        var stopped = false;
        var statusUrlTemplate = history.attr('data-status-url-template');
        var csrf = $('meta[name="csrf-token"]').attr('content') || history.find('input[name="_token"]').val();

        function statusLabel(status) {
            var labels = {
                queued_preview: 'Menunggu preview', analyzing: 'Menganalisis', preview_ready: 'Preview siap',
                queued_import: 'Menunggu import', importing: 'Mengimpor', imported: 'Selesai',
                queued: 'Menunggu', processing: 'Memproses', completed: 'Selesai',
                failed: 'Gagal', cancelled: 'Dibatalkan', reverted: 'Rollback'
            };
            return labels[status] || status || '—';
        }

        function statusClass(status) {
            if (status === 'imported' || status === 'preview_ready') return 'success';
            if (status === 'failed') return 'danger';
            if (status === 'reverted' || status === 'cancelled') return 'default';
            return 'warning';
        }

        function actionLink(url, label, icon, className) {
            return url ? '<a class="btn btn-xs ' + className + '" href="' + escapeHtml(url) + '"><i class="fa ' + icon + '"></i> ' + label + '</a>' : '';
        }

        function actionPost(url, label, icon, className, action) {
            return url ? '<button type="button" class="btn btn-xs ' + className + ' solar-history-post" data-url="' + escapeHtml(url) + '" data-action="' + action + '"><i class="fa ' + icon + '"></i> ' + label + '</button>' : '';
        }

        function processCell(row) {
            var active = !!row.active;
            var state = row.process_state || 'completed';
            var color = state === 'failed' ? 'danger' : (state === 'completed' ? 'success' : (state === 'cancelled' ? 'default' : 'info'));
            var percent = Math.max(0, Math.min(100, Number(row.progress_percent || 0)));
            return '<span class="label label-' + color + '">' + escapeHtml(statusLabel(state)) + '</span>' +
                (active ? '<div class="progress progress-xs" style="margin:5px 0 0"><div class="progress-bar" style="width:' + percent + '%"></div></div>' : '') +
                '<small class="text-muted">' + (active ? percent + '% · ' : '') + window.importHistoryMessageCell(row.error_message || row.last_message || '', 'display') + '</small>';
        }

        function rowActions(row) {
            var actions = [
                actionLink(row.preview_url, 'Tinjau', 'fa-eye', 'btn-primary'),
                actionLink(row.detail_url, 'Detail', 'fa-list-alt', 'btn-default'),
                actionLink(row.restore_url, 'Unduh Excel', 'fa-download', 'btn-success'),
                actionPost(row.retry_url, 'Ulangi', 'fa-repeat', 'btn-warning', 'retry'),
                actionPost(row.cancel_url, 'Batalkan', 'fa-times', 'btn-default', 'cancel'),
                actionPost(row.rollback_url, 'Rollback', 'fa-undo', 'btn-danger', 'rollback')
            ].filter(Boolean);
            return '<div class="btn-group-vertical">' + (actions.join(' ') || '<span class="text-muted">—</span>') + '</div>';
        }

        var table = tableElement.DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 400,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            order: [[1, 'desc']],
            ajax: {
                url: history.attr('data-table-url'),
                data: function (data) {
                    data.status = $('#solar-import-history-status').val() || '';
                    data.start_date = $('#solar-import-history-start-date').val() || '';
                    data.end_date = $('#solar-import-history-end-date').val() || '';
                },
                error: function () {
                    $('#solar-history-feedback').removeClass('alert-success').addClass('alert-danger').text('Riwayat batch Solar tidak dapat dimuat.').removeAttr('hidden');
                }
            },
            columns: [
                { data: 'file_name', render: function (value, type, row) { return type === 'display' ? actionLink(row.detail_url, escapeHtml(value || 'Workbook Solar'), 'fa-file-excel-o', 'btn-link') : value; } },
                { data: 'created_at', render: function (value) { return value ? escapeHtml(new Date(value.replace(' ', 'T')).toLocaleString()) : '—'; } },
                { data: 'status', render: function (value) { return '<span class="label label-' + statusClass(value) + '">' + escapeHtml(statusLabel(value)) + '</span>'; } },
                { data: null, orderable: false, searchable: false, render: function (value, type, row) { return type === 'display' ? processCell(row) : (row.last_message || ''); } },
                { data: 'counts.stock', defaultContent: 0, searchable: false },
                { data: 'counts.pool', defaultContent: 0, searchable: false },
                { data: 'counts.mess', defaultContent: 0, searchable: false },
                { data: 'counts.usage', defaultContent: 0, searchable: false },
                { data: null, orderable: false, searchable: false, render: function (value, type, row) { return type === 'display' ? rowActions(row) : ''; } }
            ],
            createdRow: function (row, data) {
                if (data.domain_batch_id) row.id = 'solar-import-batch-' + data.domain_batch_id;
            },
            language: {
                emptyTable: 'Belum ada riwayat import.',
                processing: 'Memuat riwayat…',
                search: 'Cari:',
                lengthMenu: 'Tampilkan _MENU_ batch',
                info: 'Menampilkan _START_–_END_ dari _TOTAL_ batch',
                infoEmpty: 'Tidak ada batch',
                zeroRecords: 'Tidak ada batch yang cocok.',
                paginate: { previous: 'Sebelumnya', next: 'Berikutnya' }
            }
        });

        function pollHistory() {
            if (stopped) return;
            var requests = [];
            var reachedTerminal = false;
            table.rows({ page: 'current' }).every(function () {
                var rowData = this.data();
                if (!rowData || !rowData.active || !rowData.domain_batch_id) return;
                var currentRow = this;
                var statusUrl = statusUrlTemplate.replace('__BATCH__', encodeURIComponent(rowData.domain_batch_id));
                requests.push($.getJSON(statusUrl).done(function (data) {
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
                    var updated = $.extend({}, rowData, {
                        process_state: data.state,
                        progress_percent: data.percent,
                        last_message: data.message || data.error || '',
                        active: ['queued', 'processing'].indexOf(data.state) !== -1,
                        domain_status: data.domain_status,
                        counts: counts
                    });
                    if (rowData.active && !updated.active) reachedTerminal = true;
                    if (data.domain_status === 'imported') updated.status = 'imported';
                    else if (data.domain_status === 'rolled_back') updated.status = 'reverted';
                    currentRow.data(updated).invalidate();
                }));
            });
            if (!requests.length) return;
            $.when.apply($, requests).always(function () {
                if (reachedTerminal) table.ajax.reload(null, false);
                else table.draw(false);
            });
        }

        table.on('draw.dt', function () {
            if (timer) window.clearTimeout(timer);
            timer = window.setTimeout(pollHistory, 250);
        });
        $('#solar-import-history-status').on('change', function () { table.ajax.reload(); });
        $('#solar-import-history-start-date, #solar-import-history-end-date').on('change', function () { table.ajax.reload(); });
        history.on('click', '.solar-history-post', function () {
            var button = $(this);
            var action = button.data('action');
            var prompts = {
                cancel: 'Batalkan proses batch ini?',
                rollback: 'Rollback batch ini? Data ledger yang dibuat batch akan dihapus dan saldo dihitung ulang.'
            };
            if (prompts[action] && !window.confirm(prompts[action])) return;
            button.prop('disabled', true);
            $.ajax({ url: button.data('url'), method: 'POST', data: { _token: csrf }, headers: { Accept: 'application/json' } })
                .done(function (response) {
                    $('#solar-history-feedback').removeClass('alert-danger').addClass('alert-success').text(response.message || 'Permintaan selesai.').removeAttr('hidden');
                    table.ajax.reload(null, false);
                }).fail(function (xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Permintaan gagal.';
                    $('#solar-history-feedback').removeClass('alert-success').addClass('alert-danger').text(message).removeAttr('hidden');
                    table.ajax.reload(null, false);
                }).always(function () { button.prop('disabled', false); });
        });
        $(window).on('beforeunload.solar-import-history', function () {
            stopped = true;
            if (timer) window.clearTimeout(timer);
        });
    }
    function initImportDetailUI() {
        if (!importDetailApp) return;
        var root = $(importDetailApp);
        var statusUrl = root.attr('data-status-url');
        var eventsUrl = root.attr('data-events-url');
        var cursor = Number(root.attr('data-last-event-id') || 0);
        var timer = null;
        var stopped = false;

        var sourceRowsTable = $('#solar-import-source-rows');
        if ($.fn.DataTable && sourceRowsTable.length) {
            function payloadCell(value) {
                if (!value || typeof value !== 'object' || !Object.keys(value).length) return '<span class="text-muted">—</span>';
                return '<details><summary>Lihat</summary><pre style="max-width:360px;max-height:220px;overflow:auto;white-space:pre-wrap">' + escapeHtml(JSON.stringify(value, null, 2)) + '</pre></details>';
            }
            sourceRowsTable.DataTable({
                processing: true,
                serverSide: true,
                searchDelay: 350,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                order: [[0, 'asc'], [1, 'asc']],
                ajax: { url: root.attr('data-rows-url'), data: function (data) { data.length = Math.min(Number(data.length || 25), 100); } },
                columns: [
                    { data: 'sheet', render: function (value) { return escapeHtml(value || '—'); } },
                    { data: 'source_row', render: function (value) { return escapeHtml(value || '—'); } },
                    { data: 'ledger', render: function (value) { return escapeHtml(value || '—'); } },
                    { data: 'date', render: function (value) { return escapeHtml(value || '—'); } },
                    { data: 'status_label', render: function (value, type, row) { return '<span class="label label-' + (row.status === 'error' ? 'danger' : (row.status === 'match' ? 'success' : 'warning')) + '">' + escapeHtml(value || row.status || '—') + '</span>'; } },
                    { data: 'matched_by', render: function (value) { return escapeHtml(value || '—'); } },
                    { data: 'detail', render: function (value) { return escapeHtml(value || '—'); } },
                    { data: 'excel', orderable: false, searchable: false, render: payloadCell },
                    { data: 'app', orderable: false, searchable: false, render: payloadCell },
                    { data: 'importable', orderable: false, searchable: false, render: function (value) { return value ? '<span class="label label-success">Siap</span>' : '<span class="label label-default">Tidak</span>'; } }
                ],
                language: {
                    emptyTable: 'Tidak ada baris sumber untuk batch ini.',
                    processing: 'Memuat rincian baris…',
                    search: 'Cari:',
                    lengthMenu: 'Tampilkan _MENU_ baris',
                    info: 'Menampilkan _START_–_END_ dari _TOTAL_ baris',
                    infoEmpty: 'Tidak ada baris',
                    zeroRecords: 'Tidak ada baris yang cocok.',
                    paginate: { previous: 'Sebelumnya', next: 'Berikutnya' }
                }
            });
        }

        function poll() {
            if (stopped) return;
            $.getJSON(statusUrl).done(function (data) {
                var active = ['queued', 'processing'].indexOf(data.state) !== -1;
                var percent = Math.max(0, Math.min(100, Number(data.percent || 0)));
                $('#solar-import-detail-progress').toggleClass('alert-warning', !!data.stale).toggleClass('alert-info', !data.stale).html(
                    '<strong>' + escapeHtml(data.state || '') + '</strong> · ' + percent + '% · ' + escapeHtml(data.message || '') +
                    (data.stale ? '<br><small>Checkpoint terakhir ' + escapeHtml(String(data.activity_age_seconds || 0)) + ' detik lalu. ' + (data.state === 'queued' ? 'Periksa worker queue solar.' : 'Worker belum mengirim checkpoint terbaru.') + '</small>' : '') +
                    '<div class="progress progress-xs" style="margin:7px 0 0"><div class="progress-bar" style="width:' + percent + '%"></div></div>'
                );
                $('#solar-import-detail-connection').text('Checkpoint: ' + (data.heartbeat_at ? new Date(data.heartbeat_at).toLocaleString() : 'belum tersedia'));
                $.getJSON(eventsUrl, { after_id: cursor }).done(function (response) {
                    var list = $('#solar-import-detail-events');
                    (response.events || []).forEach(function (event) {
                        var context = event.context || {};
                        var detail = context.sheet ? ' (Sheet ' + context.sheet + (context.row ? ' · baris ' + context.row : '') + ')' : '';
                        list.append('<li><small class="text-muted">' + escapeHtml(event.created_at ? new Date(event.created_at).toLocaleTimeString() : '') + (event.percent == null ? '' : ' · ' + Number(event.percent) + '% · ') + '</small><strong>' + escapeHtml(processText(event.stage)) + ':</strong> ' + escapeHtml(event.message || '') + '<span class="text-muted">' + escapeHtml(detail) + '</span></li>');
                        cursor = Math.max(cursor, Number(event.id || 0));
                    });
                    list.find('li').slice(0, -300).remove();
                });
                if (active) timer = window.setTimeout(poll, 4000);
            }).fail(function () {
                $('#solar-import-detail-connection').text('Koneksi status terputus sementara. Riwayat log tetap tersimpan; halaman mencoba kembali otomatis.');
                timer = window.setTimeout(poll, 7000);
            });
        }

        poll();
        $(window).on('beforeunload.solar-import-detail', function () {
            stopped = true;
            if (timer) window.clearTimeout(timer);
        });
    }

    var previewGridApi = null;
    var previewSheets = [];
    var previewRows = [];
    var previewActiveSheet = null;
    var previewFilter = 'all';
    var previewAssetMap = {};
    var previewDriverMap = {};
    var previewCustomerMap = {};
    var previewMappingGroups = [];
    var previewMappingQuery = '';
    var previewMappingLimit = 12;

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

    function previewMappingsUrl(sheet) {
        return reconcileApp.getAttribute('data-mappings-url-template')
            .replace('__BATCH__', encodeURIComponent(reconcileApp.getAttribute('data-batch-id')))
            + (sheet ? '?sheet=' + encodeURIComponent(sheet) : '');
    }

    function previewMappingUpdateUrl() {
        return reconcileApp.getAttribute('data-mapping-update-url-template')
            .replace('__BATCH__', encodeURIComponent(reconcileApp.getAttribute('data-batch-id')));
    }

    function previewReferenceOptionsUrl() {
        return reconcileApp.getAttribute('data-reference-options-url-template')
            .replace('__BATCH__', encodeURIComponent(reconcileApp.getAttribute('data-batch-id')));
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
        var warnings = Array.isArray(summary.warnings) ? summary.warnings : [];
        var blocking = warnings.some(function (warning) { return !!warning.blocking; });
        if (warnings.length) {
            var warningHtml = warnings.map(function (warning) {
                return '<li>' + escapeHtml(warning.message || 'Periksa kembali nama sheet workbook.') + '</li>';
            }).join('');
            $('#solar-preview-warnings').removeClass('alert-warning alert-danger').addClass(blocking ? 'alert-danger' : 'alert-warning').html('<strong>' + (blocking ? 'Import diblokir:' : 'Catatan workbook:') + '</strong><ul style="margin:5px 0 0;padding-left:20px">' + warningHtml + '</ul>').removeAttr('hidden');
        } else {
            $('#solar-preview-warnings').attr('hidden', true).empty();
        }
        $('#solar-preview-confirm').prop('disabled', importable < 1 || blocking).text(blocking ? 'Perbaiki nama sheet lalu unggah ulang' : 'Konfirmasi & Antrekan Import (' + importable + ')');
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
        ['tanggal', 'stock_in', 'out_actual', 'out_meteran', 'qty_in', 'qty_out', 'keterangan', 'unit', 'operator', 'customer_id', 'nama_kapal', 'lokasi', 'equipment_name', 'asset_id', 'driver_id', 'quantity_gln'].forEach(function (field) {
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
            asset_id: original.asset_id || '',
            driver_id: original.driver_id || '',
            nama_kapal: original.nama_kapal,
            lokasi: original.lokasi,
            equipment_name: original.equipment_name || '',
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

    function formatReferenceSuggestions(value) {
        if (!Array.isArray(value) || value.length === 0) return '—';
        return value.map(function (candidate) {
            return candidate && candidate.label ? candidate.label : '';
        }).filter(Boolean).join(' / ') || '—';
    }

    function SolarRemoteSelectEditor() {}

    SolarRemoteSelectEditor.prototype.init = function (params) {
        this.params = params;
        this.type = params.colDef.cellEditorParams && params.colDef.cellEditorParams.type || 'asset';
        this.element = document.createElement('select');
        this.element.className = 'form-control input-sm';
        this.element.style.width = Math.max(220, Number(params.column && params.column.getActualWidth ? params.column.getActualWidth() : 0)) + 'px';
        this.element.appendChild(new Option('', '', false, false));
        this.$element = $(this.element);
        var currentId = params.value == null ? '' : String(params.value);
        if (currentId) {
            var sourceMap = this.type === 'asset' ? previewAssetMap : (this.type === 'driver' ? previewDriverMap : previewCustomerMap);
            var label = sourceMap[currentId]
                || (this.type === 'customer' ? (params.data.customer_name || params.data.customer_ref || 'Customer #' + currentId) : this.type + ' #' + currentId);
            this.element.appendChild(new Option(label, currentId, true, true));
        }
    };

    SolarRemoteSelectEditor.prototype.getGui = function () { return this.element; };
    SolarRemoteSelectEditor.prototype.afterGuiAttached = function () {
        if ($.fn.select2) {
            var self = this;
            if (!this.$element.data('select2')) {
                this.$element.select2({
                    placeholder: this.type === 'asset' ? 'Cari Asset / nomor polisi' : (this.type === 'driver' ? 'Cari supir/operator' : 'Cari Customer'),
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('body'),
                    ajax: {
                        url: previewReferenceOptionsUrl(),
                        dataType: 'json',
                        delay: 250,
                        data: function (query) { return { type: self.type, q: query.term || '' }; },
                        processResults: function (response) { return { results: response.results || [] }; }
                    }
                });
                this.$element.on('select2:select.solar-grid select2:clear.solar-grid', function () {
                    window.setTimeout(function () { self.params.stopEditing(); }, 0);
                });
            }
            this.$element.select2('open');
            window.setTimeout(function () { $('.select2-container--open .select2-search__field').last().trigger('focus'); }, 30);
        } else {
            this.element.focus();
        }
    };
    SolarRemoteSelectEditor.prototype.getValue = function () { return this.$element.val() || ''; };
    SolarRemoteSelectEditor.prototype.destroy = function () {
        if (this.$element && this.$element.data('select2')) this.$element.select2('destroy');
    };
    SolarRemoteSelectEditor.prototype.isPopup = function () { return true; };

    function previewColumnDefs(ledger) {
        var defs = [
            { headerName: 'Baris Excel', field: 'source_row', width: 100, pinned: 'left', editable: false },
            { headerName: 'Tanggal ✎', field: 'tanggal', width: 140, editable: true, cellEditor: 'agTextCellEditor', cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return p.value || 'Isi tanggal'; } }
        ];
        if (ledger === 'pool' || ledger === 'mess') {
            defs.push({ headerName: 'Customer ✎', field: 'customer_id', minWidth: 200, editable: true, cellEditor: SolarRemoteSelectEditor, cellEditorPopup: true, cellEditorParams: { type: 'customer' }, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewCustomerMap, p.value, p.data.customer_name || (p.data.customer_ref ? 'Belum cocok · ' + p.data.customer_ref : 'Pilih Customer')); } });
        }
        if (ledger === 'usage') {
            defs.push(
                { headerName: 'Nama Asset / alat', field: 'equipment_name', minWidth: 180, editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Asset ✎', field: 'asset_id', minWidth: 190, editable: true, cellEditor: SolarRemoteSelectEditor, cellEditorPopup: true, cellEditorParams: { type: 'asset' }, refData: previewAssetMap, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewAssetMap, p.value, 'Belum dipetakan · ' + (p.data.equipment_name || '')); } },
                { headerName: 'Saran Asset', field: 'asset_suggestions', minWidth: 220, editable: false, valueFormatter: function (p) { return formatReferenceSuggestions(p.value); } },
                { headerName: 'Jumlah (Gln) ✎', field: 'quantity_gln', minWidth: 145, editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell', valueParser: function (p) { return Number(p.newValue); }, valueFormatter: function (p) { return p.value === '' || p.value == null ? '' : Number(p.value).toLocaleString('id-ID', { maximumFractionDigits: 2 }); } },
                { headerName: 'Liter (Gln × 30)', valueGetter: function (p) { return Number(p.data.quantity_gln || 0) * 30; }, minWidth: 150, valueFormatter: function (p) { return Number(p.value || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }); } }
            );
        } else if (ledger === 'stock') {
            defs.push(
                { headerName: 'Unit / Asset ✎', field: 'unit', minWidth: 170, editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Relasi Asset ✎', field: 'asset_id', minWidth: 185, editable: true, cellEditor: SolarRemoteSelectEditor, cellEditorPopup: true, cellEditorParams: { type: 'asset' }, refData: previewAssetMap, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewAssetMap, p.value, 'Belum dipetakan'); } },
                { headerName: 'Saran Asset', field: 'asset_suggestions', minWidth: 220, editable: false, valueFormatter: function (p) { return formatReferenceSuggestions(p.value); } },
                { headerName: 'Nama supir ✎', field: 'operator', minWidth: 145, editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Relasi supir ✎', field: 'driver_id', minWidth: 185, editable: true, cellEditor: SolarRemoteSelectEditor, cellEditorPopup: true, cellEditorParams: { type: 'driver' }, refData: previewDriverMap, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewDriverMap, p.value, 'Belum dipetakan'); } },
                { headerName: 'Saran supir', field: 'driver_suggestions', minWidth: 220, editable: false, valueFormatter: function (p) { return formatReferenceSuggestions(p.value); } },
                { headerName: 'Masuk (L) ✎', field: 'stock_in', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keluar Aktual (L) ✎', field: 'out_actual', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keluar Meteran (L) ✎', field: 'out_meteran', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keterangan ✎', field: 'keterangan', editable: true, cellClass: 'solar-preview-editable-cell', minWidth: 180 }
            );
        } else {
            defs.push(
                { headerName: 'Masuk (Gln) ✎', field: 'qty_in', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keluar (Gln) ✎', field: 'qty_out', editable: true, cellEditor: 'agNumberCellEditor', cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Keterangan ✎', field: 'keterangan', minWidth: 180, editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Unit / Asset ✎', field: 'unit', minWidth: 170, editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Relasi Asset ✎', field: 'asset_id', minWidth: 185, editable: true, cellEditor: SolarRemoteSelectEditor, cellEditorPopup: true, cellEditorParams: { type: 'asset' }, refData: previewAssetMap, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewAssetMap, p.value, 'Belum dipetakan'); } },
                { headerName: 'Saran Asset', field: 'asset_suggestions', minWidth: 220, editable: false, valueFormatter: function (p) { return formatReferenceSuggestions(p.value); } },
                { headerName: 'Nama supir ✎', field: 'operator', editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Relasi supir ✎', field: 'driver_id', minWidth: 185, editable: true, cellEditor: SolarRemoteSelectEditor, cellEditorPopup: true, cellEditorParams: { type: 'driver' }, refData: previewDriverMap, cellClass: 'solar-preview-editable-cell', valueFormatter: function (p) { return formatSelectValue(previewDriverMap, p.value, 'Belum dipetakan'); } },
                { headerName: 'Saran supir', field: 'driver_suggestions', minWidth: 220, editable: false, valueFormatter: function (p) { return formatReferenceSuggestions(p.value); } },
            );
            if (ledger === 'mess') defs.push(
                { headerName: 'Nama Kapal ✎', field: 'nama_kapal', editable: true, cellClass: 'solar-preview-editable-cell' },
                { headerName: 'Lokasi ✎', field: 'lokasi', editable: true, cellClass: 'solar-preview-editable-cell' }
            );
        }
        defs.push(
            { headerName: 'Hasil pencocokan', field: 'status_label', minWidth: 145, editable: false },
            {
                headerName: 'Keterangan / alasan',
                field: 'detail',
                minWidth: 280,
                maxWidth: 420,
                flex: 1,
                editable: false,
                cellRenderer: function (params) {
                    var cell = document.createElement('div');
                    cell.innerHTML = window.importHistoryMessageCell(params.value || '', 'display');
                    return cell;
                }
            }
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
        if (event.colDef.field === 'asset_id' || event.colDef.field === 'driver_id' || event.colDef.field === 'customer_id') {
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
        loadPreviewMappings(sheetKey);
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
                    driver_id: excel.driver_id == null ? '' : String(excel.driver_id),
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

    function loadPreviewMappings(sheetKey) {
        $('#solar-preview-reference-mappings').html('<span class="text-muted">Memuat daftar nama…</span>');
        $.getJSON(previewMappingsUrl(sheetKey)).done(function (response) {
            renderPreviewMappings(response.mappings || {});
        }).fail(function () {
            $('#solar-preview-reference-mappings').html('<span class="text-danger">Daftar pemetaan tidak dapat dimuat.</span>');
        });
    }

    function renderPreviewMappings(mappings) {
        previewMappingGroups = (mappings.asset || []).concat(mappings.driver || []);
        previewMappingLimit = previewMappingPageSize();
        renderPreviewMappingGroups();
    }

    function previewMappingPageSize() {
        return window.matchMedia && window.matchMedia('(max-width: 767px)').matches ? 6 : 12;
    }

    function renderPreviewMappingGroups() {
        var query = previewMappingQuery.toLocaleLowerCase();
        var groups = previewMappingGroups.filter(function (item) {
            return !query || String(item.source_value || '').toLocaleLowerCase().indexOf(query) !== -1;
        });
        var shownGroups = groups.slice(0, previewMappingLimit);
        $('#solar-reference-mapping-count').text(groups.length + ' nama unik' + (groups.length > shownGroups.length ? ' · tampil ' + shownGroups.length : ''));
        if (!groups.length) {
            $('#solar-preview-reference-mappings').html('<span class="text-muted">Tidak ada nama yang cocok.</span>');
            $('#solar-reference-mapping-more').prop('hidden', true);
            return;
        }

        var html = shownGroups.map(function (item) {
            var isAsset = item.type === 'asset';
            var targets = isAsset ? previewAssetMap : previewDriverMap;
            var selectedId = item.has_conflict ? '' : (item.target_id == null ? '' : String(item.target_id));
            var selectedOption = selectedId && targets[selectedId]
                ? '<option value="' + escapeHtml(selectedId) + '" selected>' + escapeHtml(targets[selectedId]) + '</option>'
                : '';
            var sheetSummary = Object.keys(item.sheets || {}).map(function (name) {
                return escapeHtml(name) + ': ' + Number(item.sheets[name]);
            }).join(' · ');
            var suggestions = (item.suggestions || []).map(function (candidate) { return candidate.label || ''; }).filter(Boolean);
            var conflict = item.has_conflict ? ' · Pilihan baris berbeda' : '';
            return '<label class="solar-reference-map-item">' +
                '<span class="solar-reference-map-source"><strong>' + escapeHtml(item.source_value) + '</strong><small>' + escapeHtml(isAsset ? 'Unit Asset' : 'Supir') + ' · ' + Number(item.row_count) + ' baris' + conflict + '</small><small>' + sheetSummary + '</small>' + (suggestions.length ? '<small>Saran (pilih untuk menerapkan): ' + escapeHtml(suggestions.join(' / ')) + '</small>' : '') + '</span>' +
                '<select class="form-control input-sm" data-solar-reference-mapping data-type="' + escapeHtml(item.type) + '" data-source-value="' + escapeHtml(item.source_value) + '"><option value=""></option>' + selectedOption + '</select>' +
                '</label>';
        }).join('');
        $('#solar-preview-reference-mappings').html(html);
        initPreviewMappingSelects();

        var remaining = groups.length - shownGroups.length;
        $('#solar-reference-mapping-more')
            .text('Muat ' + Math.min(50, remaining) + ' nama lagi · ' + remaining + ' tersisa')
            .prop('hidden', remaining <= 0);
    }

    function initPreviewMappingSelects() {
        if (!$.fn.select2) return;
        $('#solar-preview-reference-mappings [data-solar-reference-mapping]').each(function () {
            var mappingType = $(this).data('type');
            $(this).select2({
                placeholder: 'Cari padanan di database…',
                allowClear: true,
                width: '100%',
                ajax: {
                    url: previewReferenceOptionsUrl(),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { type: mappingType, q: params.term || '' };
                    },
                    processResults: function (response) {
                        return { results: response.results || [] };
                    }
                }
            });
        });
    }

    function savePreviewMapping(select) {
        var $select = $(select);
        var data = {
            _token: $('#solar-preview-csrf').val(),
            type: $select.data('type'),
            source_value: $select.attr('data-source-value'),
            target_id: $select.val() || '',
            sheet: previewActiveSheet || ''
        };
        $select.prop('disabled', true);
        $.ajax({ url: previewMappingUpdateUrl(), method: 'PATCH', data: data, headers: { Accept: 'application/json' } })
            .done(function (response) {
                updatePreviewStats(response.summary || {});
                renderPreviewMappings(response.mappings || {});
                loadPreviewSheet(previewActiveSheet, true);
                $('#solar-preview-feedback').attr('hidden', true);
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Pemetaan tidak tersimpan.';
                if (xhr.responseJSON && xhr.responseJSON.errors) message = Object.values(xhr.responseJSON.errors).flat()[0] || message;
                showPreviewFeedback(message, false);
                loadPreviewMappings(previewActiveSheet);
            }).always(function () { $select.prop('disabled', false); });
    }

    function initReconcile() {
        if (!reconcileApp || reconcileApp.getAttribute('data-grid-ready') === '1') return;
        reconcileApp.setAttribute('data-grid-ready', '1');
        var assets = JSON.parse(reconcileApp.getAttribute('data-assets') || '[]');
        var drivers = JSON.parse(reconcileApp.getAttribute('data-drivers') || '[]');
        var customers = JSON.parse(reconcileApp.getAttribute('data-customers') || '[]');
        previewAssetMap = {};
        assets.forEach(function (asset) { previewAssetMap[String(asset.id)] = [asset.asset_code, asset.name].filter(Boolean).join(' · '); });
        previewDriverMap = {};
        drivers.forEach(function (driver) { previewDriverMap[String(driver.id)] = driver.full_name || ''; });
        previewCustomerMap = {};
        customers.forEach(function (customer) {
            var customerLabel = [customer.display_name, customer.supplier_business_name, customer.name, customer.contact_id, customer.mobile, customer.email]
                .map(function (value) { return String(value || '').trim(); })
                .find(function (value) { return value !== ''; });
            previewCustomerMap[String(customer.id)] = customerLabel || ('Customer #' + customer.id);
        });
        try { previewSheets = JSON.parse(reconcileApp.getAttribute('data-sheets') || '[]'); } catch (error) { previewSheets = []; }
        previewActiveSheet = previewSheets.length ? previewSheets[0].sheet_key : null;
        var initialWarnings = [];
        try { initialWarnings = JSON.parse(reconcileApp.getAttribute('data-preview-warnings') || '[]'); } catch (error) { initialWarnings = []; }
        updatePreviewStats({ sheets: previewSheets, warnings: initialWarnings, total: previewSheets.reduce(function (sum, sheet) { return sum + Number(sheet.total || 0); }, 0), importable: previewSheets.reduce(function (sum, sheet) { return sum + Number(sheet.importable || 0); }, 0), needs_check: previewSheets.reduce(function (sum, sheet) { return sum + Number(sheet.needs_check || 0); }, 0) });
        if (previewActiveSheet) loadPreviewSheet(previewActiveSheet);
        $('#solar-preview-tabs').off('click.solar').on('click.solar', '.solar-preview-tab', function () { loadPreviewSheet($(this).data('sheet-key')); });
        $('#solar-preview-reference-mappings').off('change.solar').on('change.solar', '[data-solar-reference-mapping]', function () { savePreviewMapping(this); });
        $('#solar-reference-mapping-search').off('input.solar').on('input.solar', function () {
            previewMappingQuery = String(this.value || '').trim();
            previewMappingLimit = previewMappingPageSize();
            renderPreviewMappingGroups();
        });
        $('#solar-reference-mapping-more').off('click.solar').on('click.solar', function () {
            previewMappingLimit += previewMappingPageSize();
            renderPreviewMappingGroups();
        });
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
    initImportDetailUI();
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
        setValue('solar-record-asset-id', record.asset_id);
        setValue('solar-record-driver-id', record.driver_id);
        setValue('solar-quantity-gln', record.quantity_gln);
        $('#solar-customer').trigger('change');
        $('#solar-asset-id').trigger('change');
        $('#solar-record-asset-id, #solar-record-driver-id').trigger('change');
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
    $('#solar-record-asset-id').on('change', function () {
        var selected = $(this).find(':selected');
        if (this.value && !$('#solar-unit').val()) {
            $('#solar-unit').val(selected.data('asset-name') || selected.text());
        }
    });
    $('#solar-record-driver-id').on('change', function () {
        var selected = $(this).find(':selected');
        if (this.value && !$('#solar-operator').val()) {
            $('#solar-operator').val(selected.data('driver-name') || selected.text());
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
        $('#solar-record-asset-id, #solar-record-driver-id').select2({
            dropdownParent: $('#solar-record-modal'),
            allowClear: true,
            width: '100%'
        });
    }
})(window.jQuery);
