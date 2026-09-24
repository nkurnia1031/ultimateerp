(function ($, window, document) {
    'use strict';

    var config = window.vendorLoadingImportConfig || {};
    var preview = null;
    var batchId = null;
    var watchTimer = null;
    var activeSheetKey = null;
    var gridApi = null;
    var sheetOverrides = {};
    var rowOverrides = {};
    var sheetMissingDates = {};
    var activeFilter = 'all';
    var feedback = $('#vendor-import-feedback');

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function message(success, text, level) {
        var notice = text || 'Terjadi kesalahan.';
        var type = level || (success ? 'success' : 'error');
        if (window.toastr && typeof window.toastr[type] === 'function') {
            window.toastr[type](notice);
            return;
        }
        feedback.removeAttr('hidden').removeClass('alert-success alert-danger alert-warning')
            .addClass(type === 'success' ? 'alert-success' : (type === 'warning' ? 'alert-warning' : 'alert-danger')).text(notice);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function dateIsValid(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false;
        var date = new Date(value + 'T00:00:00Z');
        return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
    }

    function selectedSupplier(sheet) {
        var value = (sheetOverrides[sheet.sheet_key] || {}).supplier_id;
        return value !== undefined ? value : (sheet.metadata || {}).supplier_id;
    }

    function selectedCustomer(sheet) {
        var value = (sheetOverrides[sheet.sheet_key] || {}).customer_id;
        return value !== undefined ? value : (sheet.metadata || {}).customer_id;
    }

    function selectedRoute(sheet) {
        var values = sheetOverrides[sheet.sheet_key] || {};
        if (Object.prototype.hasOwnProperty.call(values, 'route_id')) return values.route_id;
        var metadata = sheet.metadata || {};
        if (metadata.route_match_status === 'matched') return String(metadata.route_id);
        if (metadata.route_match_status === 'not_set') return 'none';
        return '';
    }

    function creatingSupplier(sheet) {
        return !!((sheetOverrides[sheet.sheet_key] || {}).create_supplier);
    }

    function supplierReady(sheet) {
        return !!selectedSupplier(sheet) || creatingSupplier(sheet);
    }

    function customerReady(sheet) {
        if (selectedCustomer(sheet)) return true;
        var metadata = sheet.metadata || {};
        return metadata.customer_match_status === 'will_create' && !!config.canCreateCustomer && !!metadata.customer_label;
    }

    function routeReady(sheet) {
        var metadata = sheet.metadata || {};
        return metadata.route_match_status === 'not_set' || !!selectedRoute(sheet);
    }

    function rowMessages(row, sheet) {
        var messages = (row.row_errors || []).filter(function (item) {
            var text = String(item || '').toLowerCase();
            if (text.indexOf('bendera/supplier') !== -1 && supplierReady(sheet)) return false;
            if (text.indexOf('customer ') !== -1 && customerReady(sheet)) return false;
            if (text.indexOf('sheet memiliki lebih dari satu nama bendera/supplier') === 0 && supplierReady(sheet)) return false;
            if (text.indexOf('tanggal loading tidak terbaca') !== -1 && dateIsValid(row.loading_date)) return false;
            return true;
        });
        if (!row.loading_date) messages.push('Tanggal loading belum diisi.');
        else if (!dateIsValid(row.loading_date)) messages.push('Tanggal loading wajib berformat YYYY-MM-DD.');
        if (!supplierReady(sheet)) messages.push('Pilih supplier/bendera pada header sheet.');
        if (!customerReady(sheet)) messages.push('Pilih customer pada header sheet.');
        if (!routeReady(sheet)) messages.push('Tentukan satu rute dokumen atau pilih tanpa rute untuk sheet ini.');
        return messages.concat(row.row_warnings || []);
    }

    function rowReady(row, sheet) {
        return rowMessages(row, sheet).filter(function (text) {
            return !/tarif vendor belum diisi/i.test(text);
        }).length === 0;
    }

    function rowCounts(sheet) {
        var ready = 0, issues = 0;
        (sheet.rows || []).forEach(function (row) { if (rowReady(row, sheet)) ready++; else issues++; });
        return { ready: ready, issues: issues };
    }

    function visibleRows(sheet) {
        var rows = sheet.rows || [];
        if (activeFilter === 'ready') return rows.filter(function (row) { return rowReady(row, sheet); });
        if (activeFilter === 'issues') return rows.filter(function (row) { return !rowReady(row, sheet); });
        return rows;
    }

    function refreshCounts() {
        if (!preview) return;
        var allReady = 0, allIssues = 0, allMissingDates = 0;
        preview.sheets.forEach(function (sheet) {
            var counts = rowCounts(sheet);
            allReady += counts.ready;
            allIssues += counts.issues;
            allMissingDates += (sheet.rows || []).filter(function (row) { return !row.loading_date || !dateIsValid(row.loading_date); }).length;
            var tab = $('#vendor-import-tabs [data-sheet-key="' + sheet.sheet_key + '"]');
            tab.find('.vendor-import-ready-badge').text(counts.ready);
            tab.find('.vendor-import-issue-badge').text(counts.issues);
        });
        $('#vendor-import-ready-total').text(allReady);
        $('#vendor-import-issue-total').text(allIssues);
        $('#vendor-import-missing-date-total').text(allMissingDates);
        if (activeSheetKey) {
            var active = getSheet(activeSheetKey), counts = active ? rowCounts(active) : { ready: 0, issues: 0 };
            $('#vendor-import-sheet-ready').text(counts.ready);
            $('#vendor-import-sheet-issues').text(counts.issues);
            if (gridApi) {
                if (gridApi.setGridOption) gridApi.setGridOption('rowData', visibleRows(active));
                else if (gridApi.setRowData) gridApi.setRowData(visibleRows(active));
                gridApi.refreshCells({ force: true });
            }
        }
        $('#vendor-import-confirm').prop('disabled', allReady === 0);
    }

    function getSheet(key) {
        return preview && preview.sheets.find(function (sheet) { return sheet.sheet_key === key; });
    }

    function renderMetadata(sheet) {
        var meta = sheet.metadata || {};
        var html = '<div class="row"><div class="col-md-6"><dl class="vendor-import-metadata">' +
            '<dt>Barang</dt><dd>' + escapeHtml(meta.goods_name || '—') + '</dd>' +
            '<dt>Jenis barang</dt><dd>' + escapeHtml(meta.goods_type || '—') + '</dd>' +
            '<dt>Nama kapal</dt><dd>' + escapeHtml(meta.vessel_name || '—') + '</dd>' +
            '<dt>Tujuan</dt><dd>' + escapeHtml(meta.destination || '—') + '</dd>' +
            '</dl></div><div class="col-md-3"><label>Bendera / Supplier</label>' +
            '<select id="vendor-import-supplier" class="form-control"><option value="">Pilih supplier</option>' +
            (selectedSupplier(sheet) ? '<option selected value="' + escapeHtml(selectedSupplier(sheet)) + '">' + escapeHtml(meta.supplier_label || 'Supplier terpilih') + '</option>' : '') +
            '</select>';
        if (config.canCreateSupplier && meta.supplier_label) {
            html += '<label class="vendor-import-create-supplier"><input id="vendor-import-create-supplier" type="checkbox" ' + (creatingSupplier(sheet) ? 'checked' : '') + '> Buat supplier baru dari label “' + escapeHtml(meta.supplier_label) + '”</label>';
        }
        html += '</div><div class="col-md-3"><label>Customer</label><select id="vendor-import-customer" class="form-control"><option value="">Pilih customer</option>' +
            (selectedCustomer(sheet) ? '<option selected value="' + escapeHtml(selectedCustomer(sheet)) + '">' + escapeHtml(meta.customer_label || 'Customer terpilih') + '</option>' : '') +
            '</select>';
        if (meta.customer_match_status === 'will_create' && config.canCreateCustomer) {
            html += '<small class="text-success">“' + escapeHtml(meta.customer_label) + '” akan dibuat saat konfirmasi, kecuali dipilih customer lain.</small>';
        } else if (meta.customer_match_status === 'permission_required') {
            html += '<small class="text-warning">Customer belum terdaftar. Pilih customer aktif atau minta izin customer.create.</small>';
        }
        html += '</div><div class="col-md-12" style="margin-top:12px"><label>Rute Dokumen untuk seluruh sheet</label><select id="vendor-import-route" class="form-control" style="max-width:520px">';
        var routeValue = selectedRoute(sheet);
        html += '<option value="" ' + (!routeValue ? 'selected' : '') + '>Pilih rute atau keputusan</option>';
        html += '<option value="none" ' + (routeValue === 'none' ? 'selected' : '') + '>Tanpa rute</option>';
        Object.keys(config.routes || {}).forEach(function (id) {
            html += '<option value="' + escapeHtml(id) + '" ' + (String(routeValue) === String(id) ? 'selected' : '') + '>' + escapeHtml(config.routes[id]) + '</option>';
        });
        html += '</select>';
        if (meta.route_match_status === 'conflict') {
            html += '<div class="text-warning"><strong>Rute sumber berbeda:</strong> ' + escapeHtml((meta.route_source_labels || []).join(', ')) + '. Pilih satu rute master untuk dokumen atau tanpa rute.</div>';
        } else if (meta.route_match_status === 'unmatched') {
            html += '<div class="text-warning">Rute sumber "' + escapeHtml(meta.route_label || '') + '" belum cocok dengan rute aktif. Pilih rute master atau tanpa rute.</div>';
        } else if (meta.route_match_status === 'matched') {
            html += '<small class="text-success">Rute "' + escapeHtml(meta.route_label || '') + '" cocok dan akan diterapkan ke seluruh dokumen.</small>';
        } else {
            html += '<small class="text-muted">Workbook tidak menyebutkan rute. Pilihan awal: tanpa rute.</small>';
        }
        html += '</div></div>';
        $('#vendor-import-sheet-header').html(html);
        initHeaderSelects(sheet);
    }

    function initHeaderSelects(sheet) {
        var supplier = $('#vendor-import-supplier'), customer = $('#vendor-import-customer');
        supplier.select2({ width: '100%', placeholder: 'Pilih supplier/bendera', allowClear: true, ajax: {
            url: config.supplierUrl, dataType: 'json', delay: 250,
            data: function (params) { return { term: params.term || '' }; },
            processResults: function (data) { return data; }
        }}).on('change', function () {
            var values = sheetOverrides[sheet.sheet_key] || {};
            values.supplier_id = $(this).val() || null;
            values.create_supplier = false;
            sheetOverrides[sheet.sheet_key] = values;
            $('#vendor-import-create-supplier').prop('checked', false);
            refreshCounts();
        });
        customer.select2({ width: '100%', placeholder: 'Pilih customer', allowClear: true, ajax: {
            url: config.customerUrl, dataType: 'json', delay: 250,
            data: function (params) { return { term: params.term || '' }; },
            processResults: function (data) { return data; }
        }}).on('change', function () {
            var values = sheetOverrides[sheet.sheet_key] || {};
            values.customer_id = $(this).val() || null;
            sheetOverrides[sheet.sheet_key] = values;
            refreshCounts();
        });
        $('#vendor-import-route').on('change', function () {
            var values = sheetOverrides[sheet.sheet_key] || {};
            values.route_id = $(this).val() || '';
            sheetOverrides[sheet.sheet_key] = values;
            refreshCounts();
        });
        $('#vendor-import-create-supplier').on('change', function () {
            var values = sheetOverrides[sheet.sheet_key] || {};
            values.create_supplier = this.checked;
            if (this.checked) {
                values.supplier_id = null;
                supplier.val(null).trigger('change.select2');
            }
            sheetOverrides[sheet.sheet_key] = values;
            supplier.prop('disabled', this.checked);
            refreshCounts();
        });
        supplier.prop('disabled', creatingSupplier(sheet));
    }

    function formatNumber(value, decimals) {
        if (value === null || value === undefined || value === '') return '—';
        return Number(value).toLocaleString('id-ID', { maximumFractionDigits: decimals || 2 });
    }

    function issueText(row) {
        return rowMessages(row, getSheet(activeSheetKey)).join(' · ');
    }

    function destroyGrid() {
        if (gridApi) {
            gridApi.destroy();
            gridApi = null;
        }
    }

    function createGrid(sheet) {
        destroyGrid();
        if (!window.agGrid || typeof window.agGrid.createGrid !== 'function') {
            message(false, 'AG Grid Community belum termuat. Muat ulang halaman atau periksa asset JavaScript module.');
            return;
        }
        if (window.agGrid.ModuleRegistry && window.agGrid.AllCommunityModule) {
            window.agGrid.ModuleRegistry.registerModules([window.agGrid.AllCommunityModule]);
        }
        var gridElement = document.getElementById('vendor-import-grid');
        var options = {
            theme: window.agGrid.themeQuartz,
            rowData: visibleRows(sheet),
            rowHeight: 39,
            headerHeight: 42,
            animateRows: false,
            suppressCellFocus: false,
            singleClickEdit: false,
            getRowId: function (params) { return params.data.row_key; },
            rowClassRules: {
                'vendor-import-row-issue': function (params) { return !rowReady(params.data, sheet); },
                'vendor-import-row-warning': function (params) { return rowReady(params.data, sheet) && (params.data.row_warnings || []).length > 0; }
            },
            defaultColDef: { resizable: true, sortable: true, filter: true, minWidth: 100, wrapHeaderText: true, autoHeaderHeight: true },
            columnDefs: [
                { headerName: 'Baris', field: 'source_row', width: 82, pinned: 'left' },
                { headerName: 'No. Tiket', field: 'ticket_no', width: 138, pinned: 'left' },
                { headerName: 'Tanggal ✎', field: 'loading_date', width: 145, editable: true, cellClass: 'vendor-import-editable-cell', cellEditor: 'agDateStringCellEditor', cellEditorParams: { min: '1900-01-01', max: '2100-12-31' }, valueFormatter: function (p) { return p.value && dateIsValid(p.value) ? new Date(p.value + 'T00:00:00').toLocaleDateString('id-ID') : (p.value || 'Isi tanggal'); },
                    valueSetter: function (p) {
                        var rawValue = p.newValue;
                        var value = rawValue instanceof Date && !Number.isNaN(rawValue.getTime())
                            ? [rawValue.getFullYear(), String(rawValue.getMonth() + 1).padStart(2, '0'), String(rawValue.getDate()).padStart(2, '0')].join('-')
                            : String(rawValue || '').trim();
                        if (!dateIsValid(value)) { message(false, 'Tanggal wajib valid dengan format YYYY-MM-DD.'); return false; }
                        p.data.loading_date = value;
                        p.data.date_missing = false;
                        p.data.loading_date_invalid = false;
                        rowOverrides[p.data.row_key] = { loading_date: value };
                        return true;
                    } },
                { headerName: 'No. Polisi / Unit', field: 'vendor_unit_name', width: 145 },
                { headerName: 'Nama Sopir', field: 'vendor_driver_name', width: 155 },
                { headerName: 'Gross', field: 'gross_tons', width: 105, valueFormatter: function (p) { return formatNumber(p.value, 4); } },
                { headerName: 'Tare', field: 'tare_tons', width: 105, valueFormatter: function (p) { return formatNumber(p.value, 4); } },
                { headerName: 'Nett', field: 'net_tons', width: 105, valueFormatter: function (p) { return formatNumber(p.value, 4); } },
                { headerName: 'Harga / Ton (Rp)', field: 'vendor_rate_per_ton', width: 135, valueFormatter: function (p) { return p.value == null || p.value === '' ? '-' : 'Rp ' + formatNumber(p.value, 0); } },
                { headerName: 'Total (Rp)', field: 'vendor_total_amount', width: 140, valueFormatter: function (p) { return p.value == null || p.value === '' ? '-' : 'Rp ' + formatNumber(p.value, 0); } },
                { headerName: 'Kondisi baris', colId: 'row_issue', minWidth: 260, flex: 1, sortable: false, filter: false, valueGetter: function (p) { return issueText(p.data); }, cellStyle: function (p) { return rowReady(p.data, sheet) ? { color: '#247345' } : { color: '#a33c28', whiteSpace: 'normal' }; } }
            ],
            onCellValueChanged: function (params) { feedback.attr('hidden', true); refreshCounts(); }
        };
        gridApi = window.agGrid.createGrid(gridElement, options);
        $('#vendor-import-missing-date').val(sheetMissingDates[sheet.sheet_key] || '');
        var missing = (sheet.rows || []).filter(function (row) { return !!row.date_missing && !row.loading_date; }).length;
        $('#vendor-import-missing-date').closest('.vendor-import-bulk-date').toggle(missing > 0);
        refreshCounts();
    }

    function activateSheet(key) {
        var sheet = getSheet(key);
        if (!sheet) return;
        activeSheetKey = key;
        $('#vendor-import-tabs .vendor-import-tab').removeClass('active').attr('aria-selected', 'false');
        $('#vendor-import-tabs [data-sheet-key="' + key + '"]').addClass('active').attr('aria-selected', 'true');
        renderMetadata(sheet);
        createGrid(sheet);
    }

    function renderPreview(data) {
        preview = data;
        sheetOverrides = {};
        rowOverrides = {};
        sheetMissingDates = {};
        var summary = data.summary || {};
        $('#vendor-import-summary').html(
            '<div class="col-sm-2 col-xs-6"><div class="vendor-import-stat"><strong>Sheet</strong><br>' + escapeHtml(summary.sheets || data.sheets.length) + '</div></div>' +
            '<div class="col-sm-2 col-xs-6"><div class="vendor-import-stat"><strong>Tiket</strong><br>' + escapeHtml(summary.source_rows || 0) + '</div></div>' +
            '<div class="col-sm-2 col-xs-6"><div class="vendor-import-stat"><strong>Siap</strong><br><span id="vendor-import-ready-total">0</span></div></div>' +
            '<div class="col-sm-2 col-xs-6"><div class="vendor-import-stat"><strong>Perlu diperiksa</strong><br><span id="vendor-import-issue-total">0</span></div></div>' +
            '<div class="col-sm-2 col-xs-6"><div class="vendor-import-stat"><strong>Tanggal kosong</strong><br><span id="vendor-import-missing-date-total">' + escapeHtml(summary.date_missing_rows || 0) + '</span></div></div>');
        var tabs = data.sheets.map(function (sheet, index) {
            return '<button type="button" class="vendor-import-tab' + (index === 0 ? ' active' : '') + '" role="tab" aria-selected="' + (index === 0 ? 'true' : 'false') + '" data-sheet-key="' + escapeHtml(sheet.sheet_key) + '">' + escapeHtml(sheet.title) + ' <small>(' + escapeHtml(sheet.rows.length) + ')</small> <small>Siap <span class="badge vendor-import-ready-badge">0</span> · Isu <span class="badge vendor-import-issue-badge">0</span></small></button>';
        }).join('');
        $('#vendor-import-tabs').html(tabs).off('click', '.vendor-import-tab').on('click', '.vendor-import-tab', function () { activateSheet($(this).data('sheet-key')); });
        $('#vendor-import-preview-box').removeAttr('hidden');
        $('#vendor-import-filter').val('all');
        activeFilter = 'all';
        if (data.sheets.length) activateSheet(data.sheets[0].sheet_key);
    }

    function updateProgressBar($bar, percent, active) {
        var value = Math.max(0, Math.min(100, Number(percent) || 0));
        $bar.css('width', value + '%').text(value + '%').toggleClass('active', !!active);
    }

    function statusLabel(status) {
        return ({ queued_preview: 'Menunggu analisis', analyzing: 'Menganalisis', preview_ready: 'Preview siap', queued_import: 'Menunggu import', importing: 'Mengimpor', imported: 'Selesai', partial: 'Selesai sebagian', failed: 'Gagal', expired: 'Preview kedaluwarsa', reversed: 'Data impor dihapus' })[status] || status;
    }

    function renderBatchStatus(batch) {
        $('#vendor-import-batch-progress').removeAttr('hidden');
        $('#vendor-import-batch-title').text(batch.file_name || ('Batch #' + batch.id));
        $('#vendor-import-batch-status').removeClass('label-info label-success label-warning label-danger').addClass(
            batch.status === 'failed' ? 'label-danger' : (batch.status === 'partial' || batch.status === 'expired' ? 'label-warning' : (batch.status === 'imported' || batch.status === 'preview_ready' ? 'label-success' : 'label-info'))
        ).text(statusLabel(batch.status));
        updateProgressBar($('#vendor-import-batch-bar'), batch.progress, ['queued_preview', 'analyzing', 'queued_import', 'importing'].indexOf(batch.status) !== -1);
        $('#vendor-import-batch-message').text(batch.message || 'Memproses batch…');
        var counters = [];
        if (batch.status === 'analyzing' || batch.status === 'queued_preview') {
            counters.push('Sheet ' + (batch.processed_sheets || 0) + '/' + (batch.total_sheets || 0));
            counters.push('Baris dianalisis ' + (batch.processed_rows || 0) + '/' + (batch.total_rows || 0));
        } else if (batch.status === 'preview_ready') {
            counters.push((batch.ready_rows || 0) + ' tiket siap');
            counters.push((batch.issue_rows || 0) + ' perlu diperiksa');
            counters.push((batch.missing_date_rows || 0) + ' tanggal kosong');
        } else if (batch.status === 'reversed') {
            counters.push((batch.reversed_documents || 0) + ' dokumen dihapus');
            counters.push((batch.reversed_lines || 0) + ' line dihapus');
            if (batch.preserved_expense_transactions) counters.push(batch.preserved_expense_transactions + ' transaksi Expense tetap disimpan');
        } else {
            counters.push('Sheet ' + (batch.processed_sheets || 0) + '/' + (batch.total_sheets || 0));
            counters.push((batch.imported_documents || 0) + ' dokumen');
            counters.push((batch.imported_lines || 0) + ' line diimpor');
            counters.push((batch.skipped_rows || 0) + ' tiket dilewati');
        }
        $('#vendor-import-batch-counters').html(counters.map(function (item) { return '<span>' + escapeHtml(item) + '</span>'; }).join(''));
        if (batch.error || (batch.errors && batch.errors.length)) {
            var items = batch.errors || [];
            var html = batch.error ? '<p><strong>' + escapeHtml(batch.error) + '</strong></p>' : '';
            if (items.length) html += '<ul>' + items.slice(0, 30).map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('') + '</ul>';
            $('#vendor-import-batch-errors').removeAttr('hidden').html(html);
        }
        var historyRow = $('[data-import-batch-row="' + batch.id + '"]');
        if (historyRow.length) {
            historyRow.find('td').eq(1).text(statusLabel(batch.status));
            historyRow.find('td').eq(2).text((batch.processed_sheets || 0) + '/' + (batch.total_sheets || 0) + ' sheet · ' + (batch.processed_rows || 0) + '/' + (batch.total_rows || 0) + ' baris');
            historyRow.find('td').eq(3).text(batch.status === 'reversed'
                ? (batch.reversed_documents || 0) + ' dokumen · ' + (batch.reversed_lines || 0) + ' line dihapus'
                : (batch.imported_lines || 0) + ' line · ' + (batch.skipped_rows || 0) + ' dilewati');
            historyRow.find('td').eq(4).text(batch.updated_at || 'baru saja');
            historyRow.find('.vendor-import-reverse').toggle(config.canReverseImports && ['imported', 'partial'].indexOf(batch.status) !== -1);
        }
    }

    function batchUrl(template, id) { return String(template || '').replace('__ID__', encodeURIComponent(id)); }

    function fetchPreview(id) {
        $.getJSON(batchUrl(config.batchPreviewUrlTemplate, id)).done(function (response) {
            batchId = response.batch_id || id;
            renderPreview(response.preview || {});
            $('#vendor-import-upload-title').text('Preview siap — batch #' + batchId);
        }).fail(function (xhr) {
            var body = xhr.responseJSON || {};
            message(false, body.msg || 'Preview batch belum dapat dibuka.');
        });
    }

    function watchBatch(id) {
        batchId = id;
        if (watchTimer) window.clearTimeout(watchTimer);
        $('#vendor-import-batch-progress').removeAttr('hidden');
        function poll() {
            $.getJSON(batchUrl(config.statusUrlTemplate, id)).done(function (response) {
                var batch = response.batch || {};
                renderBatchStatus(batch);
                if (batch.status === 'preview_ready') {
                    $('#vendor-import-upload-title').text('Workbook sudah dianalisis.');
                    message(true, 'Preview import Loading Vendor sudah siap diperiksa.');
                    fetchPreview(id);
                    return;
                }
                if (['imported', 'partial', 'failed', 'expired', 'reversed'].indexOf(batch.status) !== -1) {
                    if (batch.status === 'imported') {
                        message(true, batch.message || 'Import Loading Vendor selesai.');
                        $('#vendor-import-confirm').prop('disabled', true).html('<i class="fas fa-check"></i> Import selesai');
                    } else if (batch.status === 'partial') {
                        message(false, batch.message || 'Import selesai dengan beberapa baris yang dilewati.', 'warning');
                        $('#vendor-import-confirm').prop('disabled', true).html('<i class="fas fa-check"></i> Import selesai sebagian');
                    } else if (batch.status === 'failed') {
                        message(false, batch.error || batch.message || 'Import Loading Vendor gagal.');
                        $('#vendor-import-confirm').prop('disabled', true).html('<i class="fas fa-times"></i> Import gagal');
                    } else if (batch.status === 'expired') {
                        message(false, batch.message || 'Preview import sudah kedaluwarsa.', 'warning');
                        $('#vendor-import-confirm').prop('disabled', true).html('<i class="fas fa-clock"></i> Preview kedaluwarsa');
                    } else {
                        message(true, batch.message || 'Data hasil import sudah dihapus.');
                        $('#vendor-import-confirm').prop('disabled', true).html('<i class="fas fa-check"></i> Import di-reverse');
                    }
                    return;
                }
                watchTimer = window.setTimeout(poll, 1800);
            }).fail(function () {
                $('#vendor-import-batch-message').text('Status sementara tidak dapat dimuat. Mencoba kembali…');
                watchTimer = window.setTimeout(poll, 3500);
            });
        }
        poll();
    }

    $('#vendor-import-upload').on('submit', function (event) {
        event.preventDefault();
        var form = this, button = $(form).find('[type="submit"]');
        button.prop('disabled', true);
        feedback.attr('hidden', true);
        $('#vendor-import-upload-progress').removeAttr('hidden');
        $('#vendor-import-upload-title').text('Mengunggah file…');
        $('#vendor-import-upload-message').text('Upload berlangsung di halaman ini; analisis akan berlanjut di queue setelah file diterima.');
        updateProgressBar($('#vendor-import-upload-bar'), 0, true);
        $.ajax({
            url: config.previewUrl, type: 'POST', data: new FormData(form), processData: false, contentType: false,
            headers: { Accept: 'application/json' },
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) xhr.upload.addEventListener('progress', function (event) {
                    if (!event.lengthComputable) return;
                    var percent = Math.round((event.loaded / event.total) * 100);
                    updateProgressBar($('#vendor-import-upload-bar'), percent, percent < 100);
                    $('#vendor-import-upload-message').text('File terkirim ' + percent + '% (' + event.loaded.toLocaleString('id-ID') + ' / ' + event.total.toLocaleString('id-ID') + ' byte).');
                });
                return xhr;
            }
        })
            .done(function (response) {
                batchId = response.batch_id;
                updateProgressBar($('#vendor-import-upload-bar'), 100, false);
                $('#vendor-import-upload-title').text('Upload selesai');
                $('#vendor-import-upload-message').text(response.msg || 'Menunggu analisis workbook.');
                message(true, response.msg || 'Upload diterima. Analisis berjalan di latar belakang.');
                watchBatch(batchId);
            })
            .fail(function (xhr) { var body = xhr.responseJSON || {}; message(false, body.msg || body.message || 'File belum dapat dipreview.'); })
            .always(function () { button.prop('disabled', false); });
    });

    $('#vendor-import-filter').on('change', function () {
        activeFilter = $(this).val() || 'all';
        var sheet = getSheet(activeSheetKey);
        if (gridApi && sheet) {
            if (gridApi.setGridOption) gridApi.setGridOption('rowData', visibleRows(sheet));
            else if (gridApi.setRowData) gridApi.setRowData(visibleRows(sheet));
        }
    });

    $('#vendor-import-history').on('click', '.vendor-import-reverse', function () {
        var button = $(this), id = button.data('batch-id');
        if (!config.canReverseImports || !id) return;
        if (!window.confirm('Reverse batch #' + id + '? Semua dokumen dan line Loading Vendor yang dibuat oleh batch ini akan dihapus. Transaksi Expense yang sudah ada tidak ikut dihapus.')) return;

        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memproses');
        $.ajax({
            url: batchUrl(config.reverseUrlTemplate, id),
            type: 'POST',
            data: { _token: config.csrf },
            headers: { Accept: 'application/json' }
        }).done(function (response) {
            var batch = response.batch || {};
            renderBatchStatus(batch);
            button.hide().prop('disabled', false).html('<i class="fas fa-undo"></i> Reverse import');
            message(true, response.msg || 'Data batch berhasil dihapus.');
        }).fail(function (xhr) {
            var body = xhr.responseJSON || {};
            button.prop('disabled', false).html('<i class="fas fa-undo"></i> Reverse import');
            message(false, body.msg || body.message || 'Reverse batch gagal diproses.');
        });
    });

    $('#vendor-import-apply-date').on('click', function () {
        if (!activeSheetKey || !gridApi) return;
        var date = $('#vendor-import-missing-date').val();
        if (!dateIsValid(date)) { message(false, 'Pilih tanggal valid terlebih dahulu.'); return; }
        var sheet = getSheet(activeSheetKey), changed = 0;
        (sheet.rows || []).forEach(function (row) {
            if (row.date_missing && !row.loading_date) {
                row.loading_date = date;
                row.date_missing = false;
                row.loading_date_invalid = false;
                rowOverrides[row.row_key] = { loading_date: date };
                changed++;
            }
        });
        sheetMissingDates[activeSheetKey] = date;
        gridApi.refreshCells({ force: true });
        gridApi.onFilterChanged();
        refreshCounts();
        message(true, changed + ' tanggal kosong pada sheet ini diisi. Tanggal yang sudah valid tidak berubah.');
        $('#vendor-import-missing-date').closest('.vendor-import-bulk-date').toggle((sheet.rows || []).some(function (row) { return !!row.date_missing && !row.loading_date; }));
    });

    $('#vendor-import-confirm').on('click', function () {
        if (!batchId || !preview) return;
        var allCounts = preview.sheets.reduce(function (total, sheet) { var count = rowCounts(sheet); total.ready += count.ready; total.issues += count.issues; return total; }, { ready: 0, issues: 0 });
        if (!allCounts.ready) return;
        var reasons = {};
        preview.sheets.forEach(function (sheet) { (sheet.rows || []).forEach(function (row) {
            if (rowReady(row, sheet)) return;
            var first = rowMessages(row, sheet).filter(function (text) { return !/tarif vendor belum diisi/i.test(text); })[0] || 'Baris perlu diperiksa';
            reasons[first] = (reasons[first] || 0) + 1;
        }); });
        var reasonText = Object.keys(reasons).sort(function (a, b) { return reasons[b] - reasons[a]; }).slice(0, 4).map(function (reason) { return '\n• ' + reasons[reason] + ' — ' + reason; }).join('');
        var warning = allCounts.issues ? '\n' + allCounts.issues + ' tiket akan dilewati dan dicatat dalam hasil import.' + reasonText : '\nTidak ada tiket yang akan dilewati.';
        if (!window.confirm('Antrekan import ' + allCounts.ready + ' tiket siap ke dokumen per sheet?' + warning + '\nProses berlanjut di latar belakang; halaman ini boleh ditinggalkan.')) return;
        var button = $(this).prop('disabled', true);
        $('#vendor-import-confirm-errors').attr('hidden', true).empty();
        $.ajax({ url: config.confirmUrl, type: 'POST', data: {
            _token: config.csrf,
            batch_id: batchId,
            sheet_overrides: JSON.stringify(sheetOverrides),
            row_overrides: JSON.stringify(rowOverrides),
            sheet_missing_dates: JSON.stringify(sheetMissingDates)
        }, headers: { Accept: 'application/json' } }).done(function (response) {
            message(true, response.msg || 'Import sudah masuk antrean.');
            $('#vendor-import-confirm').html('<i class="fas fa-spinner fa-spin"></i> Import diproses di latar belakang…');
            watchBatch(response.batch_id || batchId);
        }).fail(function (xhr) {
            var body = xhr.responseJSON || {}, errors = body.errors || [];
            message(false, body.msg || body.message || 'Import belum berhasil.');
            if (errors.length) $('#vendor-import-confirm-errors').removeAttr('hidden').html('<strong>Rincian:</strong><ul>' + errors.slice(0, 50).map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('') + '</ul>');
            button.prop('disabled', false);
        });
    });

    if (config.resumeBatchId) watchBatch(config.resumeBatchId);

    if ($.fn.select2) {
        // Header selectors are created only after the server has returned sheet metadata.
    }
})(window.jQuery, window, document);
