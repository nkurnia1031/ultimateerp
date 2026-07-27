<?php

namespace Modules\Project\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProjectImportTemplate
{
    public const VERSION = '1.0';

    private const DATA_FIRST_ROW = 5;

    private const DATA_LAST_ROW = 204;

    private const PRIMARY = '1F4E78';

    private const SECONDARY = '0F6B78';

    private const INPUT = 'FFF7D6';

    private const OPTIONAL = 'EAF3F8';

    private const BORDER = 'CBD5E1';

    public function build(array $references): Spreadsheet
    {
        $workbook = new Spreadsheet();
        $workbook->removeSheetByIndex(0);

        $this->buildInstructions($workbook);
        $this->buildProjectSheet($workbook, $references);
        $this->buildTasksSheet($workbook);
        $this->buildTimeLogsSheet($workbook);
        $this->buildInvoiceSheet($workbook, $references);
        $this->buildInvoiceLinesSheet($workbook);
        $this->buildReferencesSheet($workbook, $references);

        $workbook->setActiveSheetIndexByName('Petunjuk');

        return $workbook;
    }

    private function buildInstructions(Spreadsheet $workbook): void
    {
        $sheet = $workbook->createSheet();
        $sheet->setTitle('Petunjuk');
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:H2');
        $sheet->setCellValue('A1', 'TEMPLATE IMPORT PROJECT TERPADU');
        $sheet->getStyle('A1:H2')->applyFromArray($this->titleStyle());
        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->getRowDimension(2)->setRowHeight(25);

        $sheet->mergeCells('A4:H4');
        $sheet->setCellValue('A4', 'Satu file untuk membuat Project, Task, Time Log dua sesi, dan Draft/Final Invoice.');
        $sheet->getStyle('A4:H4')->applyFromArray($this->sectionStyle());

        $instructions = [
            ['1', 'Project', 'Wajib', 'Isi nilai pada kolom kuning. Lead kosong akan menggunakan user yang melakukan import.'],
            ['2', 'Tasks', 'Opsional', 'Gunakan task_key unik agar Time Logs dan Invoice Lines dapat merujuk task yang benar.'],
            ['3', 'Time Logs', 'Opsional', 'Jam kerja dapat dibagi menjadi sesi 1 dan sesi 2; jeda antar-sesi tidak dihitung.'],
            ['4', 'Invoice', 'Opsional', 'Ubah generate_invoice menjadi ya. Default status adalah draft agar aman ditinjau.'],
            ['5', 'Invoice Lines', 'Wajib bila invoice dibuat', 'Kuantitas bisa manual, total jam task, atau total jam seluruh project.'],
            ['6', 'Referensi', 'Bantuan', 'Berisi customer, pekerja, lokasi, pajak, kategori, tag, dan pilihan dropdown saat template diunduh.'],
        ];

        $sheet->fromArray([['Urutan', 'Sheet', 'Sifat', 'Cara penggunaan']], null, 'A6');
        $sheet->fromArray($instructions, null, 'A7');
        $sheet->mergeCells('D6:H6');
        foreach (range(7, 12) as $row) {
            $sheet->mergeCells("D{$row}:H{$row}");
        }
        $sheet->getStyle('A6:H6')->applyFromArray($this->headerStyle());
        $sheet->getStyle('A7:H12')->applyFromArray($this->bodyStyle());
        $sheet->getStyle('D7:H12')->getAlignment()->setWrapText(true);

        $sheet->mergeCells('A15:H15');
        $sheet->setCellValue('A15', 'Aturan penting');
        $sheet->getStyle('A15:H15')->applyFromArray($this->sectionStyle());
        $rules = [
            'Jangan mengubah nama sheet, key tersembunyi, atau susunan kolom.',
            'Tanggal gunakan format YYYY-MM-DD. Jam gunakan HH:MM; untuk tengah malam gunakan 00:00.',
            'Nilai jam yang melewati tengah malam otomatis diteruskan ke hari berikutnya.',
            'Beberapa pekerja, kategori, atau tag dipisahkan dengan titik koma (;).',
            'Baris kosong dan seluruh sheet opsional yang tidak diisi akan dilewati.',
            'Import bersifat atomik: bila ada satu kesalahan, tidak ada data yang disimpan.',
        ];
        foreach ($rules as $index => $rule) {
            $row = 17 + $index;
            $sheet->setCellValue("A{$row}", '•');
            $sheet->mergeCells("B{$row}:H{$row}");
            $sheet->setCellValue("B{$row}", $rule);
        }
        $sheet->getStyle('A17:H22')->applyFromArray($this->bodyStyle());

        $sheet->mergeCells('A25:B26');
        $sheet->setCellValue('A25', 'KUNING');
        $sheet->getStyle('A25:B26')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::INPUT);
        $sheet->mergeCells('C25:H26');
        $sheet->setCellValue('C25', 'Kolom utama / perlu diperhatikan');
        $sheet->mergeCells('A28:B29');
        $sheet->setCellValue('A28', 'BIRU MUDA');
        $sheet->getStyle('A28:B29')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::OPTIONAL);
        $sheet->mergeCells('C28:H29');
        $sheet->setCellValue('C28', 'Kolom opsional');
        $sheet->getStyle('A25:H29')->applyFromArray($this->bodyStyle());
        $sheet->getStyle('A25:H29')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->mergeCells('A32:H32');
        $sheet->setCellValue('A32', 'Contoh pengisian berdasarkan format operasional alat berat');
        $sheet->getStyle('A32:H32')->applyFromArray($this->sectionStyle());
        $sheet->fromArray([
            ['Tanggal', 'Shift', 'Mulai 1', 'Selesai 1', 'Mulai 2', 'Selesai 2', 'Total', 'Pekerja'],
            ['2026-06-04', 'malam', '21:00', '00:00', '01:00', '07:00', '9.00 jam', 'Pilih dari Referensi'],
            ['2026-06-05', 'pagi', '08:00', '12:00', '13:00', '17:00', '8.00 jam', 'Pilih dari Referensi'],
        ], null, 'A34');
        $sheet->getStyle('A34:H34')->applyFromArray($this->headerStyle());
        $sheet->getStyle('A35:H36')->applyFromArray($this->bodyStyle());
        $sheet->mergeCells('A39:H39');
        $sheet->setCellValue('A39', 'Untuk invoice berbasis jam, isi task_key yang sama lalu pilih quantity_source = task_hours. Tarif tetap diisi pada Invoice Lines.');
        $sheet->getStyle('A39:H39')->applyFromArray($this->bodyStyle());
        $sheet->getStyle('A39:H39')->getAlignment()->setWrapText(true);

        $sheet->getColumnDimension('A')->setWidth(11);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(24);
        foreach (range('D', 'H') as $column) {
            $sheet->getColumnDimension($column)->setWidth(16);
        }
        $sheet->freezePane('A6');
    }

    private function buildProjectSheet(Spreadsheet $workbook, array $references): void
    {
        $sheet = $workbook->createSheet();
        $sheet->setTitle('Project');
        $this->prepareFormSheet($sheet, 'DATA UTAMA PROJECT', 'Isi Value. Hanya nama project yang mutlak wajib; nilai lain memiliki default atau boleh kosong.');

        $currentUser = $references['current_user'] ?? '';
        $fields = [
            ['Nama Project', '', 'WAJIB', 'Nama pekerjaan/project.', 'project_name', true],
            ['Customer', '', 'Opsional', 'Pilih customer dari dropdown. Dapat dikosongkan.', 'customer_ref', false],
            ['Status', 'not_started', 'Default tersedia', 'not_started, in_progress, on_hold, cancelled, completed.', 'status', true],
            ['Project Lead', $currentUser, 'Default tersedia', 'Kosong = user yang melakukan import.', 'lead_ref', true],
            ['Anggota Project', '', 'Opsional', 'Pisahkan beberapa referensi pekerja dengan titik koma (;). Lead otomatis menjadi anggota.', 'member_refs', false],
            ['Tanggal Mulai', '', 'Opsional', 'Format YYYY-MM-DD.', 'start_date', false],
            ['Tanggal Selesai', '', 'Opsional', 'Format YYYY-MM-DD dan tidak boleh sebelum tanggal mulai.', 'end_date', false],
            ['Deskripsi', '', 'Opsional', 'Ruang lingkup pekerjaan, kapal, alat, lokasi, atau catatan lain.', 'description', false],
            ['Kategori', '', 'Opsional', 'Nama kategori yang sudah ada; pisahkan dengan titik koma (;).', 'category_names', false],
            ['Aktifkan Time Log', 'ya', 'Default tersedia', 'ya / tidak.', 'enable_timelog', false],
            ['Aktifkan Invoice', 'ya', 'Default tersedia', 'ya / tidak.', 'enable_invoice', false],
            ['Aktifkan Dokumen', 'ya', 'Default tersedia', 'ya / tidak.', 'enable_notes_documents', false],
            ['Anggota Kelola Task', 'tidak', 'Default tersedia', 'ya / tidak.', 'members_crud_task', false],
            ['Anggota Kelola Catatan', 'tidak', 'Default tersedia', 'ya / tidak.', 'members_crud_note', false],
            ['Anggota Kelola Time Log', 'tidak', 'Default tersedia', 'ya / tidak.', 'members_crud_timelog', false],
            ['Tampilan Task', 'list_view', 'Default tersedia', 'list_view / kanban.', 'task_view', false],
            ['Prefix Task ID', '#', 'Default tersedia', 'Contoh: # atau JOB-.', 'task_id_prefix', false],
        ];

        $sheet->fromArray([['Field', 'Value', 'Kebutuhan', 'Penjelasan', 'key']], null, 'A4');
        foreach ($fields as $index => $field) {
            $row = self::DATA_FIRST_ROW + $index;
            $sheet->fromArray([array_slice($field, 0, 5)], null, "A{$row}");
            $sheet->getStyle("B{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($field[5] ? self::INPUT : self::OPTIONAL);
            $this->addComment(
                $sheet,
                "B{$row}",
                "Cara pengisian: {$field[3]}\nKebutuhan: {$field[2]}"
            );
        }

        $lastRow = self::DATA_FIRST_ROW + count($fields) - 1;
        $sheet->getStyle("A4:E4")->applyFromArray($this->headerStyle());
        $sheet->getStyle("A5:E{$lastRow}")->applyFromArray($this->bodyStyle());
        $sheet->getStyle("D5:D{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(58);
        $sheet->getColumnDimension('E')->setVisible(false);
        $sheet->freezePane('A5');

        $this->addListValidation($sheet, 'B6', 'Customers');
        $this->addListValidation($sheet, 'B7', 'ProjectStatuses');
        $this->addListValidation($sheet, 'B8', 'Users');
        foreach (['B14', 'B15', 'B16', 'B17', 'B18', 'B19'] as $cell) {
            $this->addListValidation($sheet, $cell, 'YesNo');
        }
        $this->addListValidation($sheet, 'B20', 'TaskViews');
        $sheet->getStyle('B10:B11')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    }

    private function buildTasksSheet(Spreadsheet $workbook): void
    {
        $headers = [
            ['task_key', 'subject', 'description', 'start_date', 'due_date', 'priority', 'status', 'member_refs', 'tag_names', 'custom_field_1', 'custom_field_2', 'custom_field_3', 'custom_field_4'],
            ['Task Key*', 'Judul Task*', 'Deskripsi', 'Mulai', 'Jatuh Tempo', 'Prioritas', 'Status', 'Pekerja', 'Tag', 'Custom 1', 'Custom 2', 'Custom 3', 'Custom 4'],
        ];
        $sheet = $this->buildTableSheet(
            $workbook,
            'Tasks',
            'DAFTAR TASK',
            'Opsional. task_key harus unik dalam workbook dan digunakan untuk menghubungkan Time Logs serta Invoice Lines.',
            $headers,
            [
                'Wajib bila baris task diisi. Buat kode unik dalam file ini, misalnya TASK-LOADER-01. Kode yang sama dipakai pada Time Logs dan Invoice Lines.',
                'Wajib bila baris task diisi. Isi nama pekerjaan yang mudah dikenali, misalnya Operasional Wheel Loader.',
                'Opsional. Isi rincian ruang lingkup atau hasil yang diharapkan.',
                'Opsional. Gunakan format YYYY-MM-DD, misalnya 2026-06-04.',
                'Opsional. Gunakan format YYYY-MM-DD dan jangan lebih awal dari tanggal mulai.',
                'Opsional. Pilih low, medium, high, atau urgent.',
                'Opsional. Pilih status dari dropdown; bila kosong sistem memakai status awal.',
                'Opsional. Salin/pilih pekerja dari sheet Referensi. Untuk beberapa pekerja, pisahkan dengan titik koma (;).',
                'Opsional. Gunakan nama tag dari sheet Referensi. Untuk beberapa tag, pisahkan dengan titik koma (;).',
                'Opsional. Nilai custom field task pertama bila fitur ini digunakan.',
                'Opsional. Nilai custom field task kedua bila fitur ini digunakan.',
                'Opsional. Nilai custom field task ketiga bila fitur ini digunakan.',
                'Opsional. Nilai custom field task keempat bila fitur ini digunakan.',
            ]
        );
        $this->addListValidation($sheet, 'F5:F204', 'Priorities');
        $this->addListValidation($sheet, 'G5:G204', 'TaskStatuses');
        $sheet->getStyle('D5:E204')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $this->setWidths($sheet, [16, 28, 42, 14, 14, 14, 16, 34, 24, 18, 18, 18, 18]);
    }

    private function buildTimeLogsSheet(Spreadsheet $workbook): void
    {
        $headers = [
            ['task_key', 'worker_ref', 'work_date', 'shift', 'start_1', 'end_1', 'start_2', 'end_2', 'note', 'total_hours', 'tag_names'],
            ['Task Key', 'Pekerja*', 'Tanggal*', 'Shift*', 'Mulai 1*', 'Selesai 1*', 'Mulai 2', 'Selesai 2', 'Keterangan', 'Total Jam', 'Tag'],
        ];
        $sheet = $this->buildTableSheet(
            $workbook,
            'Time Logs',
            'TIME LOG DUA SESI',
            'Opsional. Sesi kedua untuk jam setelah istirahat; jeda antar-sesi tidak dihitung. Task Key boleh kosong agar log hanya terkait ke Project.',
            $headers,
            [
                'Opsional. Isi task_key persis seperti pada sheet Tasks. Kosongkan bila time log hanya terkait langsung ke project.',
                'Pilih pekerja dari sheet Referensi. Bila kosong, sistem menggunakan user yang menjalankan import.',
                'Wajib bila baris time log diisi. Gunakan format YYYY-MM-DD, misalnya 2026-06-04.',
                'Pilih pagi atau malam. Bila kosong, shift ditentukan otomatis dari jam Mulai 1.',
                'Wajib. Jam mulai sesi pertama dalam format HH:MM, misalnya 21:00.',
                'Wajib. Jam selesai sesi pertama dalam format HH:MM. Gunakan 00:00 untuk tengah malam.',
                'Opsional. Jam mulai sesi kedua setelah istirahat, misalnya 01:00. Harus diisi berpasangan dengan Selesai 2.',
                'Opsional. Jam selesai sesi kedua, misalnya 07:00. Harus diisi berpasangan dengan Mulai 2.',
                'Opsional. Isi keterangan pekerjaan atau kondisi lapangan.',
                'Otomatis dan tidak perlu diubah. Total = durasi sesi 1 + sesi 2; waktu istirahat di antara sesi tidak dihitung.',
                'Opsional. Gunakan nama tag dari sheet Referensi. Untuk beberapa tag, pisahkan dengan titik koma (;).',
            ]
        );

        $this->addListValidation($sheet, 'B5:B204', 'Users');
        $this->addListValidation($sheet, 'D5:D204', 'Shifts');
        $sheet->getStyle('C5:C204')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('E5:H204')->getNumberFormat()->setFormatCode('hh:mm');
        $sheet->getStyle('J5:J204')->getNumberFormat()->setFormatCode('0.00');

        for ($row = self::DATA_FIRST_ROW; $row <= self::DATA_LAST_ROW; $row++) {
            $sheet->setCellValue(
                "J{$row}",
                "=IF(COUNTA(C{$row},E{$row},F{$row})<3,\"\",24*(MOD(F{$row}-E{$row},1)+IF(COUNTA(G{$row},H{$row})=2,MOD(H{$row}-G{$row},1),0)))"
            );
        }
        $sheet->getStyle('J5:J204')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2F0D9');
        $this->setWidths($sheet, [16, 36, 14, 12, 12, 12, 12, 12, 40, 14, 24]);
    }

    private function buildInvoiceSheet(Spreadsheet $workbook, array $references): void
    {
        $sheet = $workbook->createSheet();
        $sheet->setTitle('Invoice');
        $this->prepareFormSheet($sheet, 'PENGATURAN INVOICE PROJECT', 'Opsional. Invoice hanya dibuat bila generate_invoice = ya. Gunakan draft untuk pemeriksaan sebelum finalisasi.');

        $fields = [
            ['Generate Invoice', 'tidak', 'Pengaman', 'ya / tidak. Default tidak.', 'generate_invoice', true],
            ['Judul Invoice', '', 'Wajib bila dibuat', 'Contoh: Sewa Alat Berat - Pupuk MOP.', 'invoice_title', true],
            ['Customer Invoice', '', 'Default dari Project', 'Kosong = customer pada Project.', 'customer_ref', false],
            ['Lokasi Bisnis', $references['default_location'] ?? '', 'Wajib bila dibuat', 'Pilih lokasi bisnis penerbit invoice.', 'location_ref', true],
            ['Tanggal Invoice', '', 'Default hari import', 'Format YYYY-MM-DD.', 'invoice_date', false],
            ['Status Invoice', 'draft', 'Default aman', 'draft / final.', 'status', true],
            ['Skema Invoice', $references['default_invoice_scheme'] ?? '', 'Default tersedia', 'Kosong = skema default bisnis.', 'invoice_scheme_ref', false],
            ['Jangka Bayar', '', 'Opsional', 'Angka, misalnya 14.', 'pay_term_number', false],
            ['Satuan Jangka Bayar', 'days', 'Opsional', 'days / months.', 'pay_term_type', false],
            ['Tipe Diskon', 'fixed', 'Default tersedia', 'fixed / percentage.', 'discount_type', false],
            ['Nilai Diskon', 0, 'Default tersedia', 'Nilai tetap atau persen sesuai tipe diskon.', 'discount_amount', false],
            ['Ketentuan', '', 'Opsional', 'Ketentuan pembayaran.', 'staff_note', false],
            ['Catatan Tambahan', '', 'Opsional', 'Catatan yang ingin ditampilkan pada invoice.', 'additional_notes', false],
            ['Subtotal Preview', "=SUM('Invoice Lines'!I5:I204)", 'Otomatis', 'Perkiraan total termasuk pajak sebelum diskon.', 'subtotal_preview', false],
            ['Final Total Preview', '=IF(B14="percentage",B18*(1-B15/100),B18-B15)', 'Otomatis', 'Perkiraan total setelah diskon.', 'final_total_preview', false],
        ];

        $sheet->fromArray([['Field', 'Value', 'Kebutuhan', 'Penjelasan', 'key']], null, 'A4');
        foreach ($fields as $index => $field) {
            $row = self::DATA_FIRST_ROW + $index;
            $sheet->fromArray([array_slice($field, 0, 5)], null, "A{$row}");
            $fill = in_array($field[4], ['subtotal_preview', 'final_total_preview'], true)
                ? 'E2F0D9'
                : ($field[5] ? self::INPUT : self::OPTIONAL);
            $sheet->getStyle("B{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $instruction = in_array($field[4], ['subtotal_preview', 'final_total_preview'], true)
                ? "Otomatis dan tidak perlu diubah. {$field[3]}"
                : "Cara pengisian: {$field[3]}\nKebutuhan: {$field[2]}";
            $this->addComment($sheet, "B{$row}", $instruction);
        }
        $lastRow = self::DATA_FIRST_ROW + count($fields) - 1;
        $sheet->getStyle('A4:E4')->applyFromArray($this->headerStyle());
        $sheet->getStyle("A5:E{$lastRow}")->applyFromArray($this->bodyStyle());
        $sheet->getStyle("D5:D{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(58);
        $sheet->getColumnDimension('E')->setVisible(false);
        $sheet->getStyle('B9')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('B15:B19')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->freezePane('A5');

        $this->addListValidation($sheet, 'B5', 'YesNo');
        $this->addListValidation($sheet, 'B7', 'Customers');
        $this->addListValidation($sheet, 'B8', 'Locations');
        $this->addListValidation($sheet, 'B10', 'InvoiceStatuses');
        $this->addListValidation($sheet, 'B11', 'InvoiceSchemes');
        $this->addListValidation($sheet, 'B13', 'PayTermTypes');
        $this->addListValidation($sheet, 'B14', 'DiscountTypes');
    }

    private function buildInvoiceLinesSheet(Spreadsheet $workbook): void
    {
        $headers = [
            ['line_no', 'task_key', 'item_name', 'quantity_source', 'manual_quantity', 'calculated_quantity', 'rate', 'tax_ref', 'estimated_total', 'description'],
            ['No.', 'Task Key', 'Nama Item/Jasa', 'Sumber Kuantitas*', 'Qty Manual', 'Qty Dipakai', 'Tarif*', 'Pajak', 'Estimasi Total', 'Deskripsi'],
        ];
        $sheet = $this->buildTableSheet(
            $workbook,
            'Invoice Lines',
            'BARIS INVOICE',
            'Diisi bila invoice dibuat. task_hours mengambil total jam task; project_hours mengambil seluruh jam project; manual memakai Qty Manual.',
            $headers,
            [
                'Otomatis dan tidak perlu diubah. Nomor baris muncul setelah Nama Item/Jasa diisi.',
                'Opsional untuk manual/project_hours, tetapi wajib untuk task_hours. Isi task_key persis seperti pada sheet Tasks agar task tampil pada invoice.',
                'Wajib bila baris invoice diisi, kecuali Task Key sudah diisi; bila kosong, nama task dipakai sebagai nama item.',
                'Wajib. Pilih manual, task_hours, atau project_hours. task_hours mengambil total jam task terkait; project_hours mengambil seluruh jam project.',
                'Isi angka hanya bila Sumber Kuantitas = manual. Contoh: 2 atau 8.5.',
                'Otomatis dan tidak perlu diubah. Nilai dihitung dari sumber kuantitas yang dipilih.',
                'Wajib. Isi tarif per unit/jam sebagai angka tanpa simbol mata uang, misalnya 350000.',
                'Opsional. Pilih pajak dari sheet Referensi; kosong berarti tanpa pajak.',
                'Otomatis dan tidak perlu diubah. Perkiraan = Qty Dipakai x Tarif, termasuk pajak bila dipilih.',
                'Opsional. Isi uraian tambahan yang perlu tampil pada baris invoice.',
            ]
        );
        $this->addListValidation($sheet, 'D5:D204', 'QuantitySources');
        $this->addListValidation($sheet, 'H5:H204', 'Taxes');

        for ($row = self::DATA_FIRST_ROW; $row <= self::DATA_LAST_ROW; $row++) {
            $sheet->setCellValue("A{$row}", "=IF(C{$row}=\"\",\"\",ROW()-4)");
            $sheet->setCellValue(
                "F{$row}",
                "=IF(D{$row}=\"manual\",E{$row},IF(D{$row}=\"task_hours\",SUMIF('Time Logs'!\$A\$5:\$A\$204,B{$row},'Time Logs'!\$J\$5:\$J\$204),IF(D{$row}=\"project_hours\",SUM('Time Logs'!\$J\$5:\$J\$204),\"\")))"
            );
            $sheet->setCellValue(
                "I{$row}",
                "=IF(OR(F{$row}=\"\",G{$row}=\"\"),\"\",F{$row}*G{$row}*(1+IFERROR(VLOOKUP(H{$row},Referensi!\$D\$2:\$E\$204,2,FALSE),0)/100))"
            );
        }

        $sheet->getStyle('A5:A204')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2F0D9');
        $sheet->getStyle('F5:F204')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2F0D9');
        $sheet->getStyle('I5:I204')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2F0D9');
        $sheet->getStyle('E5:G204')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('I5:I204')->getNumberFormat()->setFormatCode('#,##0.00');
        $this->setWidths($sheet, [8, 16, 30, 21, 14, 14, 16, 28, 18, 42]);
    }

    private function buildReferencesSheet(Spreadsheet $workbook, array $references): void
    {
        $sheet = $workbook->createSheet();
        $sheet->setTitle('Referensi');
        $sheet->setShowGridlines(false);

        $columns = [
            'A' => ['Customer', $references['customers'] ?? [], 'Customers'],
            'B' => ['Pekerja/User', $references['users'] ?? [], 'Users'],
            'C' => ['Lokasi Bisnis', $references['locations'] ?? [], 'Locations'],
            'D' => ['Pajak', array_column($references['taxes'] ?? [], 'label'), 'Taxes'],
            'E' => ['Tarif Pajak (%)', array_column($references['taxes'] ?? [], 'rate'), null],
            'F' => ['Kategori Project', $references['categories'] ?? [], 'ProjectCategories'],
            'G' => ['Tag', $references['tags'] ?? [], 'Tags'],
            'H' => ['Status Project', ['not_started', 'in_progress', 'on_hold', 'cancelled', 'completed'], 'ProjectStatuses'],
            'I' => ['Status Task', ['not_started', 'in_progress', 'on_hold', 'cancelled', 'completed'], 'TaskStatuses'],
            'J' => ['Prioritas', ['low', 'medium', 'high', 'urgent'], 'Priorities'],
            'K' => ['Shift', ['pagi', 'malam'], 'Shifts'],
            'L' => ['Ya/Tidak', ['ya', 'tidak'], 'YesNo'],
            'M' => ['Tampilan Task', ['list_view', 'kanban'], 'TaskViews'],
            'N' => ['Status Invoice', ['draft', 'final'], 'InvoiceStatuses'],
            'O' => ['Satuan Tempo', ['days', 'months'], 'PayTermTypes'],
            'P' => ['Tipe Diskon', ['fixed', 'percentage'], 'DiscountTypes'],
            'Q' => ['Sumber Qty', ['manual', 'task_hours', 'project_hours'], 'QuantitySources'],
            'R' => ['Skema Invoice', $references['invoice_schemes'] ?? [], 'InvoiceSchemes'],
        ];

        foreach ($columns as $column => [$heading, $values, $rangeName]) {
            $sheet->setCellValue("{$column}1", $heading);
            $this->addComment(
                $sheet,
                "{$column}1",
                $rangeName === null
                    ? 'Kolom bantuan yang digunakan oleh rumus template. Jangan diubah.'
                    : 'Daftar referensi untuk dropdown. Pilih atau salin nilai persis seperti yang tersedia; jangan mengubah daftar pada sheet ini.'
            );
            $values = array_values($values);
            foreach ($values as $index => $value) {
                $sheet->setCellValue($column . ($index + 2), $value);
            }
            $lastRow = max(2, count($values) + 1);
            if ($rangeName !== null) {
                $workbook->addNamedRange(new NamedRange($rangeName, $sheet, "\${$column}\$2:\${$column}\${$lastRow}"));
            }
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['A', 'B', 'C', 'D', 'R'], true) ? 42 : 22);
        }

        $sheet->setCellValue('T1', 'template_version');
        $sheet->setCellValueExplicit('T2', self::VERSION, DataType::TYPE_STRING);
        $sheet->getColumnDimension('T')->setVisible(false);
        $sheet->getStyle('A1:R1')->applyFromArray($this->headerStyle());
        $sheet->getStyle('A2:R204')->applyFromArray($this->bodyStyle());
        $sheet->getStyle('E2:E204')->getNumberFormat()->setFormatCode('0.00');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:R204');
    }

    private function buildTableSheet(
        Spreadsheet $workbook,
        string $name,
        string $title,
        string $subtitle,
        array $headers,
        array $comments
    ): Worksheet
    {
        $sheet = $workbook->createSheet();
        $sheet->setTitle($name);
        $sheet->setShowGridlines(false);
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers[0]));
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray($this->titleStyle());
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray($this->sectionStyle());
        $sheet->fromArray([$headers[0]], null, 'A3');
        $sheet->fromArray([$headers[1]], null, 'A4');
        foreach ($comments as $index => $comment) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $this->addComment($sheet, "{$column}4", $comment);
        }
        $sheet->getRowDimension(3)->setVisible(false);
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray($this->headerStyle());
        $sheet->getStyle("A5:{$lastColumn}204")->applyFromArray($this->bodyStyle());
        $sheet->getStyle("A5:{$lastColumn}204")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::OPTIONAL);
        $sheet->setAutoFilter("A4:{$lastColumn}204");
        $sheet->freezePane('A5');

        return $sheet;
    }

    private function addComment(Worksheet $sheet, string $coordinate, string $text): void
    {
        $comment = $sheet->getComment($coordinate);
        $comment->setAuthor('Ultimate ERP');
        $comment->getText()->createTextRun($text);
        $comment->setWidth('360px');
        $comment->setHeight('150px');
    }

    private function prepareFormSheet(Worksheet $sheet, string $title, string $subtitle): void
    {
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1:E1')->applyFromArray($this->titleStyle());
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getStyle('A2:E2')->applyFromArray($this->sectionStyle());
    }

    private function addListValidation(Worksheet $sheet, string $range, string $name): void
    {
        $validation = $sheet->getCell(explode(':', $range)[0])->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle('Nilai tidak valid');
        $validation->setError('Pilih nilai dari daftar yang tersedia.');
        $validation->setShowInputMessage(true);
        $validation->setPromptTitle('Pilih dari daftar');
        $validation->setPrompt('Gunakan pilihan yang tersedia agar import dapat diproses.');
        $validation->setFormula1("={$name}");
        $validation->setSqref($range);
    }

    private function setWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $index => $width) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function titleStyle(): array
    {
        return [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => self::PRIMARY]],
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
    }

    private function sectionStyle(): array
    {
        return [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'D9EAF0']],
            'font' => ['bold' => true, 'color' => ['rgb' => self::SECONDARY]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    private function headerStyle(): array
    {
        return [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => self::SECONDARY]],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER]]],
        ];
    }

    private function bodyStyle(): array
    {
        return [
            'font' => ['size' => 10, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER]]],
        ];
    }
}
