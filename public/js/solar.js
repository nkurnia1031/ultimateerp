(function ($) {
    'use strict';

    var app = document.getElementById('solar-app');
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
