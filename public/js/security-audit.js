(function ($) {
    'use strict';

    function messageFrom(xhr, fallback) {
        var response = xhr && xhr.responseJSON ? xhr.responseJSON : {};
        if (response.message) {
            return response.message;
        }
        if (response.errors) {
            var keys = Object.keys(response.errors);
            if (keys.length && response.errors[keys[0]].length) {
                return response.errors[keys[0]][0];
            }
        }
        return fallback;
    }


    function setBusy($form, busy, busyText) {
        var $button = $form.find('button[type="submit"]');
        if (!$button.data('original-text')) {
            $button.data('original-text', $button.html());
        }
        $button.prop('disabled', busy).html(busy ? '<i class="fas fa-circle-notch fa-spin"></i> ' + busyText : $button.data('original-text'));
        $form.find('button[data-dismiss="modal"], .close').prop('disabled', busy);
    }

    function notify(type, message) {
        if (window.toastr && typeof window.toastr[type] === 'function') {
            window.toastr[type](message);
        } else {
            window.alert(message);
        }
    }

    $(function () {
        var $page = $('#security-audit-page');
        if (!$page.length) {
            return;
        }

        $('#security-report-selector').on('change', function () {
            if (this.value) {
                window.location.href = this.value;
            }
        });

        var findingsTable = null;
        if ($('#security-findings-table').length && $.fn.DataTable) {
            findingsTable = $('#security-findings-table').DataTable({
                pageLength: 25,
                lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Semua']],
                order: [[0, 'asc'], [4, 'desc']],
                autoWidth: false,
                columnDefs: [{ targets: [5], orderable: false }]
            });
            $('#security-severity-filter').on('change', function () {
                findingsTable.column(0).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
            });
            $('#security-scope-filter').on('change', function () {
                findingsTable.column(1).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
            });
        }

        $('#security-cleanup-form').on('submit', function (event) {
            event.preventDefault();
            var $form = $(this);
            $form.find('.security-form-error').hide();
            setBusy($form, true, 'Memverifikasi & membersihkan…');

            $.ajax({
                url: $form.data('url'),
                method: 'POST',
                data: $form.serialize(),
                headers: { Accept: 'application/json' }
            }).done(function (response) {
                notify('success', response.message);
                window.location.reload();
            }).fail(function (xhr) {
                $form.find('.security-form-error').text(messageFrom(xhr, 'Pembersihan gagal. Tidak ada file yang dihapus tanpa verifikasi.')).show();
                setBusy($form, false);
            });
        });
    });
})(window.jQuery);
