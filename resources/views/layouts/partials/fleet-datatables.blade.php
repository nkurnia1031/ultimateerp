@once
<style>
    /* Shared presentation for the transport tables. Keep this scoped so legacy
       tables in the rest of the application keep their existing appearance. */
    .fleet-datatable-wrap {
        width: 100%;
    }

    .fleet-datatable-wrap .dataTables_wrapper {
        width: 100%;
    }

    .fleet-datatable-wrap .dataTables_length,
    .fleet-datatable-wrap .dataTables_filter {
        margin-bottom: 10px;
    }

    .fleet-datatable-wrap .dataTables_filter input {
        max-width: 260px;
    }

    .fleet-datatable-wrap table.fleet-datatable {
        width: 100% !important;
    }

    .fleet-datatable-wrap table.fleet-datatable th,
    .fleet-datatable-wrap table.fleet-datatable td {
        vertical-align: middle;
    }

    .fleet-datatable-wrap table.fleet-datatable th {
        white-space: nowrap;
    }

    .fleet-datatable-wrap .dataTables_info {
        color: #60717b;
    }

    .fleet-datatable-wrap .dataTables_paginate {
        margin-top: 4px;
    }

    .fleet-table-detail-toggle {
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        margin-top: 6px;
        padding: 5px 9px;
        border: 1px solid #b8cbd0;
        border-radius: 4px;
        background: #f7fafb;
        color: #334e5b;
        font-size: 12px;
        cursor: pointer;
    }

    .fleet-table-detail-toggle:hover,
    .fleet-table-detail-toggle:focus {
        background: #e8f0f1;
        border-color: #8faeb5;
    }

    .fleet-table-detail-toggle:focus-visible {
        outline: 3px solid #f0b85b;
        outline-offset: 2px;
    }

    .fleet-table-detail {
        max-width: 420px;
        margin-top: 8px;
        padding: 9px 10px;
        border-left: 3px solid #d9534f;
        border-radius: 3px;
        background: #fff5f5;
        color: #9d2626;
        font-size: 12px;
        line-height: 1.5;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .fleet-datatable-scroll {
        max-height: 360px;
        overflow-y: auto;
    }

    .fleet-datatable-wrap .dtr-details {
        width: 100%;
    }

    .fleet-datatable-wrap .dtr-details li {
        padding: 6px 0;
    }

    @media (max-width: 767px) {
        .fleet-datatable-wrap .dataTables_length,
        .fleet-datatable-wrap .dataTables_filter,
        .fleet-datatable-wrap .dataTables_info,
        .fleet-datatable-wrap .dataTables_paginate {
            float: none;
            width: 100%;
            text-align: left;
        }

        .fleet-datatable-wrap .dataTables_filter input {
            width: calc(100% - 54px);
            max-width: none;
        }

        .fleet-datatable-wrap .dataTables_paginate {
            margin-top: 8px;
        }

        .fleet-table-detail {
            max-width: none;
        }
    }
</style>

@push('scripts')
<script>
(function ($) {
    'use strict';

    window.initFleetDataTable = function (selector, options) {
        if (!window.jQuery || !$.fn || !$.fn.DataTable) {
            return null;
        }

        var firstTable = null;
        var defaults = {
            responsive: true,
            autoWidth: false,
            order: [],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
            language: {
                search: 'Cari:',
                searchPlaceholder: 'Ketik untuk mencari',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_–_END_ dari _TOTAL_ data',
                infoEmpty: 'Belum ada data',
                infoFiltered: '(disaring dari _MAX_ data)',
                zeroRecords: 'Data tidak ditemukan',
                emptyTable: 'Belum ada data',
                paginate: {
                    first: 'Awal',
                    last: 'Akhir',
                    next: 'Berikutnya',
                    previous: 'Sebelumnya'
                }
            }
        };

        $(selector).each(function () {
            var table = this;
            var $table = $(table);

            if ($.fn.DataTable.isDataTable(table)) {
                firstTable = firstTable || $table.DataTable();
                return;
            }

            // DataTables expects a regular row. The old views used a colspan row
            // for the empty state, so let DataTables render that message instead.
            $table.find('tbody tr').filter(function () {
                return $(this).children('td, th').length === 1
                    && $(this).children('td, th').first().is('[colspan]');
            }).remove();

            var dataTable = $table.DataTable($.extend(true, {}, defaults, options || {}));
            $table.data('fleetDataTable', dataTable);
            firstTable = firstTable || dataTable;
        });

        return firstTable;
    };

    $(document).on('click', '.fleet-table-detail-toggle', function () {
        var button = this;
        var detail = document.getElementById(button.getAttribute('aria-controls'));
        if (!detail) {
            return;
        }

        var isOpen = !detail.hasAttribute('hidden');
        detail.toggleAttribute('hidden', isOpen);
        button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        button.textContent = isOpen ? 'Lihat detail' : 'Sembunyikan detail';
    });
}(window.jQuery));
</script>
@endpush
@endonce
