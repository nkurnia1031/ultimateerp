(function ($, window, document) {
    'use strict';

    var config = window.vendorLoadingDocumentCreateConfig || {};
    var nextIndex = 0;
    var $form = $('#vendor-document-create-form');
    var $lines = $('#vendor-create-lines');
    var $feedback = $('#vendor-document-create-feedback');

    function notify(success, text) {
        var message = text || 'Terjadi kesalahan.';
        if (window.toastr && typeof window.toastr[success ? 'success' : 'error'] === 'function') {
            window.toastr[success ? 'success' : 'error'](message);
            return;
        }
        $feedback.removeAttr('hidden').removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function number(value) {
        var parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function quantity(value) {
        return Number(value || 0).toLocaleString('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
    }

    function money(value) {
        return 'Rp ' + Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    function field(index, name, type, classes, extra, value) {
        var initial = value == null ? '' : String(value);
        return '<input id="vendor-line-' + index + '-' + name + '" type="' + type + '" name="lines[' + index + '][' + name + ']" class="form-control ' + classes + '" value="' + initial + '" ' + (extra || '') + '>';
    }

    function labeledField(index, name, label, type, classes, extra, value) {
        return '<div class="vendor-line-field"><label for="vendor-line-' + index + '-' + name + '">' + label + '</label>' + field(index, name, type, classes, extra, value) + '</div>';
    }

    function calculatedField(index, name, label, className) {
        return '<div class="vendor-line-field"><label for="vendor-line-' + index + '-' + name + '">' + label + '</label><input id="vendor-line-' + index + '-' + name + '" type="text" class="form-control ' + className + ' text-right" readonly tabindex="-1"></div>';
    }

    function projectOptions(index) {
        var projects = config.projects || {};
        var html = '<select id="vendor-line-' + index + '-project_id" name="lines[' + index + '][project_id]" class="form-control"><option value="">Tidak terkait project</option>';
        Object.keys(projects).forEach(function (id) {
            html += '<option value="' + id + '">' + $('<div>').text(projects[id]).html() + '</option>';
        });
        return html + '</select>';
    }

    function appendLine() {
        var index = nextIndex++;
        var card = $('<article class="vendor-line-card" data-index="' + index + '">' +
            '<div class="vendor-line-main" data-index="' + index + '">' +
            '<div class="vendor-line-card-header"><h4 class="vendor-line-card-title">Line <span class="vendor-line-number"></span></h4>' +
            '<div class="vendor-line-card-actions"><button type="button" class="btn btn-default vendor-line-toggle" aria-expanded="false">Tampilkan rincian</button><button type="button" class="btn btn-danger vendor-line-remove" aria-label="Hapus line"><i class="fas fa-trash" aria-hidden="true"></i> Hapus</button></div></div>' +
            '<div class="vendor-line-fields">' +
            labeledField(index, 'loading_date', 'Tanggal loading *', 'date', 'vendor-loading-date', 'required', config.today) +
            labeledField(index, 'ticket_no', 'Nomor tiket', 'text', '', 'maxlength="100"') +
            labeledField(index, 'vendor_unit_name', 'No. polisi / unit', 'text', '', 'maxlength="191"') +
            labeledField(index, 'vendor_driver_name', 'Nama sopir', 'text', '', 'maxlength="191"') +
            '</div><div class="vendor-line-fields vendor-line-fields--three">' +
            labeledField(index, 'gross_tons', 'Gross (ton)', 'number', 'vendor-gross', 'step="0.0001" min="0" placeholder="0,0000"') +
            labeledField(index, 'tare_tons', 'Tare (ton)', 'number', 'vendor-tare', 'step="0.0001" min="0" placeholder="0,0000"') +
            labeledField(index, 'net_tons', 'Netto (ton)', 'number', 'vendor-net', 'step="0.0001" readonly tabindex="-1"') +
            labeledField(index, 'billable_tons', 'Tonase tagihan', 'number', 'vendor-billable', 'step="0.0001" min="0" placeholder="Ikuti netto"') +
            labeledField(index, 'vendor_rate_per_ton', 'Tarif per ton (Rp)', 'number', 'vendor-rate', 'step="0.01" min="0" placeholder="0"') +
            calculatedField(index, 'line_total', 'Total biaya (Rp)', 'vendor-line-total') +
            '</div></div>' +
            '<div class="vendor-line-details" data-index="' + index + '" hidden><h5 class="vendor-line-details-title">Rincian tambahan</h5><div class="vendor-line-detail-grid">' +
            labeledField(index, 'load_date', 'Tanggal muat', 'date', '', '') +
            labeledField(index, 'unload_date', 'Tanggal bongkar', 'date', '', '') +
            labeledField(index, 'received_tons', 'Tonase diterima', 'number', 'vendor-received', 'step="0.0001" min="0"') +
            '<div class="vendor-line-field"><label for="vendor-line-' + index + '-project_id">Project</label>' + projectOptions(index) + '</div>' +
            labeledField(index, 'vendor_unit_code', 'Kode unit', 'text', '', 'maxlength="100"') +
            labeledField(index, 'vendor_driver_code', 'Kode sopir', 'text', '', 'maxlength="100"') +
            labeledField(index, 'claim_adjustment', 'Penyesuaian klaim (Rp)', 'number', 'vendor-claim', 'step="0.01"') +
            labeledField(index, 'other_deduction', 'Potongan lain (Rp)', 'number', 'vendor-deduction', 'step="0.01" min="0"') +
            '<div class="vendor-line-field vendor-line-field--full"><label for="vendor-line-' + index + '-notes">Catatan line</label>' + field(index, 'notes', 'text', '', 'maxlength="2000"') + '</div>' +
            '</div></div></article>');

        $lines.append(card);
        updateLineNumbers();
        calculateLine(card.find('.vendor-line-main').first());
    }

    function updateLineNumbers() {
        $lines.find('.vendor-line-main').each(function (index) {
            $(this).find('.vendor-line-number').text(index + 1);
        });
        $lines.find('.vendor-line-remove').prop('disabled', $lines.find('.vendor-line-main').length <= 1);
        calculateTotals();
    }

    function lineField(row, selector) {
        var input = row.find(selector);
        if (input.length) return input;
        return $lines.find('.vendor-line-details[data-index="' + row.data('index') + '"]').find(selector);
    }

    function mainLineFor(element) {
        return $(element).closest('.vendor-line-card').find('.vendor-line-main').first();
    }

    function calculateLine(row) {
        var grossInput = row.find('.vendor-gross'), tareInput = row.find('.vendor-tare');
        var grossRaw = grossInput.val(), tareRaw = tareInput.val();
        var gross = number(grossRaw), tare = number(tareRaw);
        var net = grossRaw !== '' && tareRaw !== '' && gross >= tare ? Math.round((gross - tare) * 10000) / 10000 : 0;
        var netInput = row.find('.vendor-net');
        netInput.val(net > 0 ? net.toFixed(4) : '');

        var received = number(lineField(row, '.vendor-received').val());
        var billableInput = row.find('.vendor-billable');
        if (billableInput.attr('data-manual') !== '1') {
            var defaultBillable = received > 0 ? received : net;
            billableInput.val(defaultBillable > 0 ? defaultBillable.toFixed(4) : '');
        }

        var billable = number(billableInput.val());
        var rateRaw = row.find('.vendor-rate').val();
        var total = rateRaw === '' ? null : Math.round((billable * number(rateRaw) - number(lineField(row, '.vendor-deduction').val()) + number(lineField(row, '.vendor-claim').val())) * 100) / 100;
        row.find('.vendor-line-total').val(total === null ? '' : Number(total).toLocaleString('id-ID', { maximumFractionDigits: 0 }));
        calculateTotals();
    }

    function calculateTotals() {
        var totals = { gross: 0, tare: 0, net: 0, billable: 0, amount: 0 };
        $lines.find('.vendor-line-main').each(function () {
            var row = $(this);
            totals.gross += number(row.find('.vendor-gross').val());
            totals.tare += number(row.find('.vendor-tare').val());
            totals.net += number(row.find('.vendor-net').val());
            totals.billable += number(row.find('.vendor-billable').val());
            var rate = row.find('.vendor-rate').val();
            if (rate !== '') totals.amount += number(row.find('.vendor-billable').val()) * number(rate) - number(lineField(row, '.vendor-deduction').val()) + number(lineField(row, '.vendor-claim').val());
        });
        $('#vendor-total-gross').text(quantity(totals.gross) + ' ton');
        $('#vendor-total-tare').text(quantity(totals.tare) + ' ton');
        $('#vendor-total-net').text(quantity(totals.net) + ' ton');
        $('#vendor-total-billable').text(quantity(totals.billable) + ' ton');
        $('#vendor-total-amount').text(money(totals.amount));
    }

    function initContactSelect(selector, url, placeholder) {
        if (!$.fn.select2 || !$(selector).length) return;
        $(selector).select2({ width: '100%', placeholder: placeholder, allowClear: true, ajax: {
            url: url,
            dataType: 'json',
            delay: 250,
            data: function (params) { return { term: params.term || '' }; },
            processResults: function (data) { return data; }
        }});
    }

    function selectCreatedContact(selector, contact) {
        if (!contact) return;
        $(selector).append(new Option(contact.text, contact.id, true, true)).trigger('change');
    }

    function createMinimal(buttonSelector, inputSelector, feedbackSelector, url, selectSelector, entityName) {
        $(buttonSelector).on('click', function () {
            var $button = $(this), $input = $(inputSelector), $message = $(feedbackSelector), name = $.trim($input.val() || '');
            if (!name) { notify(false, 'Nama ' + entityName + ' wajib diisi.'); return; }
            $button.prop('disabled', true);
            $.ajax({ url: url, type: 'POST', data: { _token: config.csrf, name: name }, headers: { Accept: 'application/json' } })
                .done(function (response) {
                    selectCreatedContact(selectSelector, response[entityName]);
                    $input.val('');
                    $message.attr('hidden', true);
                    $(buttonSelector).closest('.modal').modal('hide');
                    notify(true, (entityName === 'supplier' ? 'Supplier' : 'Customer') + ' berhasil dibuat.');
                })
                .fail(function (xhr) {
                    var body = xhr.responseJSON || {};
                    notify(false, body.msg || body.message || 'Data belum dapat dibuat.');
                })
                .always(function () { $button.prop('disabled', false); });
        });
    }

    $('#vendor-add-line').on('click', appendLine);
    $lines.on('input change', '.vendor-gross, .vendor-tare, .vendor-billable, .vendor-rate, .vendor-received, .vendor-deduction, .vendor-claim', function () {
        if ($(this).hasClass('vendor-billable')) $(this).attr('data-manual', '1');
        calculateLine(mainLineFor(this));
    });
    $lines.on('click', '.vendor-line-toggle', function () {
        var $button = $(this), $details = $button.closest('.vendor-line-card').find('.vendor-line-details').first(), isOpen = !$details.prop('hidden');
        $details.prop('hidden', isOpen);
        $button.attr('aria-expanded', isOpen ? 'false' : 'true').text(isOpen ? 'Tampilkan rincian' : 'Tutup rincian');
    });
    $lines.on('click', '.vendor-line-remove', function () {
        if ($lines.find('.vendor-line-main').length <= 1) return;
        $(this).closest('.vendor-line-card').remove();
        updateLineNumbers();
    });

    initContactSelect('#vendor-supplier-id', config.supplierUrl, 'Pilih bendera / supplier');
    initContactSelect('#vendor-customer-id', config.customerUrl, 'Pilih customer');
    if (config.canCreateSupplier) createMinimal('#vendor-minimal-supplier-save', '#vendor-minimal-supplier-name', '#vendor-minimal-supplier-feedback', config.createSupplierUrl, '#vendor-supplier-id', 'supplier');
    if (config.canCreateCustomer) createMinimal('#vendor-minimal-customer-save', '#vendor-minimal-customer-name', '#vendor-minimal-customer-feedback', config.createCustomerUrl, '#vendor-customer-id', 'customer');

    $form.on('submit', function (event) {
        event.preventDefault();
        var missing = [];
        [['goods_name', 'Nama Barang'], ['vessel_name', 'Nama Kapal'], ['destination', 'Tujuan']].forEach(function (item) {
            if (!$.trim($form.find('[name="' + item[0] + '"]').val() || '')) missing.push(item[1]);
        });
        if (!$('#vendor-customer-id').val()) missing.push('Customer');
        if (!$('#vendor-supplier-id').val()) missing.push('Bendera / Supplier');
        var invalidLine = null;
        $lines.find('.vendor-line-main').each(function (index) {
            var row = $(this), label = 'Line ' + (index + 1), gross = row.find('.vendor-gross').val(), tare = row.find('.vendor-tare').val();
            if (!row.find('.vendor-loading-date').val()) invalidLine = label + ': tanggal loading wajib diisi.';
            else if (gross !== '' && tare !== '' && number(gross) < number(tare)) invalidLine = label + ': Gross tidak boleh lebih kecil dari Tare.';
            else if (number(row.find('.vendor-net').val()) <= 0 && number(row.find('.vendor-billable').val()) <= 0) invalidLine = label + ': isi Gross/Tare atau tonase tagihan lebih dari nol.';
        });
        if (missing.length || invalidLine) {
            showFeedback(false, missing.length ? 'Lengkapi field wajib: ' + missing.join(', ') + '.' : invalidLine);
            return;
        }

        var $button = $form.find('[type="submit"]');
        $button.prop('disabled', true);
        $.ajax({ url: $form.attr('action'), type: 'POST', data: $form.serialize(), headers: { Accept: 'application/json' } })
            .done(function (response) {
                showFeedback(true, response.msg || 'Dokumen tersimpan.');
                window.setTimeout(function () { window.location.href = response.redirect; }, 500);
            })
            .fail(function (xhr) {
                var body = xhr.responseJSON || {}, messages = [];
                if (body.errors) Object.keys(body.errors).forEach(function (key) { messages = messages.concat(body.errors[key]); });
                showFeedback(false, body.msg || (messages.length ? messages.join(' ') : body.message) || 'Data belum tersimpan.');
                $button.prop('disabled', false);
            });
    });

    function showFeedback(success, text) {
        notify(success, text);
    }

    appendLine();
})(window.jQuery, window, document);
