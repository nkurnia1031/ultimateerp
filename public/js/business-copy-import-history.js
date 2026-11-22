(function ($) {
    'use strict';

    $(function () {
        window.businessCopyImportHistories = window.businessCopyImportHistories || {};

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        $('.import-copy-history').each(function () {
            var panel = $(this);
            var type = panel.data('import-copy-type');
            var table = panel.find('.import-copy-history-table');
            if ($.fn.DataTable.isDataTable(table[0])) return;

            var history = table.DataTable({
                processing: true,
                serverSide: true,
                searchDelay: 350,
                pageLength: 10,
                order: [[0, 'desc']],
                ajax: {
                    url: panel.data('history-url'),
                    data: function (data) { data.status = panel.find('.import-copy-status').val(); }
                },
                columns: [
                    {data: 'id', name: 'id'},
                    {data: 'file_name', name: 'file_name', render: escapeHtml},
                    {data: 'status', name: 'status'},
                    {data: 'progress_percent', name: 'progress_percent', orderable: false, render: function (value, kind, row) {
                        return escapeHtml(value || 0) + '% (' + escapeHtml(row.progress_current || 0) + '/' + escapeHtml(row.progress_total || 0) + ')';
                    }},
                    {data: 'created_at', name: 'created_at'},
                    {data: null, orderable: false, searchable: false, render: function (row) {
                        var actions = '<button type="button" class="btn btn-xs btn-info import-copy-detail-button" data-url="' + escapeHtml(row.detail_url) + '">Detail</button> ';
                        if (row.can_retry) actions += '<button type="button" class="btn btn-xs btn-warning import-copy-retry-button" data-url="' + escapeHtml(row.retry_url) + '">Coba ulang</button> ';
                        if (row.can_revert) actions += '<button type="button" class="btn btn-xs btn-danger import-copy-revert-button" data-url="' + escapeHtml(row.revert_url) + '">Revert</button>';
                        return actions;
                    }}
                ]
            });
            window.businessCopyImportHistories[type] = history;
            panel.find('.import-copy-status').on('change', function () { history.ajax.reload(); });

            window.setInterval(function () {
                var active = history.rows().data().toArray().some(function (row) { return row.active; });
                if (active) history.ajax.reload(null, false);
            }, 5000);

            panel.on('click', '.import-copy-detail-button', function () {
                var detailPanel = panel.find('.import-copy-detail');
                $.get($(this).data('url')).done(function (payload) {
                    var summary = payload.results.summary || {};
                    var options = payload.batch.options || {};
                    detailPanel.find('.import-copy-summary').text(
                        'Batch #' + payload.batch.id + ' · sumber bisnis #' + (options.source_business_id || '—') +
                        ' · tujuan bisnis #' + (options.target_business_id || '—') +
                        ' · dibuat ' + (summary.created_rows || 0) + ', dilewati ' + (summary.skipped_rows || 0) +
                        ', gagal ' + (summary.failed_rows || 0)
                    );
                    var body = detailPanel.find('tbody').empty();
                    (payload.results.row_outcomes || []).forEach(function (row) {
                        var target = escapeHtml(row.error || row.target_label || row.status);
                        if (row.record_url) target = '<a href="' + escapeHtml(row.record_url) + '">' + target + '</a>';
                        $('<tr>').append(
                            $('<td>').text(row.source_id || '—'),
                            $('<td>').text(row.source_label || '—'),
                            $('<td>').text(row.status || '—'),
                            $('<td>').html(target)
                        ).appendTo(body);
                    });
                    detailPanel.removeClass('hidden');
                }).fail(function (xhr) {
                    if (window.toastr) toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Detail batch tidak dapat dimuat.');
                });
            });

            panel.on('click', '.import-copy-retry-button, .import-copy-revert-button', function () {
                var button = $(this);
                var revert = button.hasClass('import-copy-revert-button');
                if (revert && !window.confirm('Revert hanya dilakukan jika record belum berubah atau memiliki dependensi. Lanjutkan?')) return;
                $.ajax({
                    url: button.data('url'),
                    method: 'POST',
                    data: {_token: $('meta[name="csrf-token"]').attr('content')}
                }).done(function () {
                    if (window.toastr) toastr.success(revert ? 'Batch salin berhasil direvert.' : 'Record gagal masuk antrean ulang.');
                    history.ajax.reload(null, false);
                }).fail(function (xhr) {
                    if (window.toastr) toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Aksi batch gagal.');
                });
            });
        });
    });
})(window.jQuery);
