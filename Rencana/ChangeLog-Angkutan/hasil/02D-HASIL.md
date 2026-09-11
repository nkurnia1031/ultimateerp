# Hasil 02D - Uji Import SPB

## Status

**LULUS dengan catatan.** Import workbook, pencocokan unit dan sopir, pemisahan baris siap dan bermasalah, perlindungan fingerprint, pencegahan duplikat antar-sheet, detail hasil import, dan rekap berhasil diuji melalui browser. Penyimpanan file export tidak dapat diselesaikan oleh tooling `agent-browser`, walaupun endpoint download mengembalikan HTTP 200 dengan header attachment yang benar.

## Run dan business

- Run ID: `RUN02D-20260910`.
- Business aktif: `CV BM TRANSPORT`.
- Semua workbook diunggah dari halaman aplikasi `/asset/fleet/trips/import` memakai akun demo lokal, bukan melalui SQL, controller, service, HTTP manual, atau JavaScript API.
- Pemeriksaan database di bawah ini hanya read-only untuk rekonsiliasi bukti.

## Handoff data

| Sumber | Sheet | Baris valid dibuat | ID SPB | Status awal |
|---|---|---:|---:|---|
| `05. PT. JATIM PROPERTINDO JAYA CANGKANG (MV. QUEEN CORAL).xls` | `BMT` | 38 | 3 - 40 | draft, unbilled, unsettled |
| `02. CV. PUTRA SANJAYA, CANGKANG (PT. BMT).xlsx` | `Sheet1` dan `TAGIHAN  PT.BMT` | 78 | 41 - 118 | draft, unbilled, unsettled |

SPB ID 1 dan 2 dari tahap 02C tetap dipertahankan untuk tahap Project invoice dan Sell. SPB hasil import ID 3 - 118 juga tetap draft untuk handoff; belum ada yang ditagihkan, disettle, atau diposting payroll.

## Journey browser dan hasil aktual

### A. Workbook Jatim

1. Membuka menu **Asset Management -> Import SPB Angkutan**.
2. Memilih workbook Jatim melalui tombol file browser.
3. Menekan **Preview Workbook**.
4. Membaca preview sebelum menekan impor.

Hasil preview aktual: **32 sheet, 828 baris sumber, 38 baris siap, 790 baris bermasalah**. Contoh baris sumber `BMT:11` terbaca dengan tiket `No : 492556035`, tanggal `2026-08-23`, unit `BM 9600 RE`, sopir `ZAINUDDIN`, ton `26.39`, dan tarif sumber `33500`.

Workbook tidak selalu menyediakan asal/tujuan yang dapat dipakai untuk membuat route. User memilih route default `SSP2 - EUP/GBE` pada dropdown preview, kemudian menekan **Import Baris Siap**.

Hasil aktual setelah konfirmasi: **38 SPB berhasil diimpor**. Baris dengan unit atau sopir yang belum cocok tetap dilaporkan sebagai peringatan per baris dan tidak menghentikan baris siap lainnya.

### B. Workbook Putra Sanjaya

1. Mengunggah workbook melalui form yang sama.
2. Menekan **Preview Workbook** dan memeriksa daftar sheet serta catatan baris.
3. Memilih route default bila baris tidak memiliki route yang dapat dipetakan.
4. Menekan **Import Baris Siap**.

Hasil preview aktual: **2 sheet, 180 baris sumber, 78 baris siap, 102 baris bermasalah**. Preview menampilkan sumber dalam format `TAGIHAN PT.BMT:8` dan `Sheet1:8`, nomor kendaraan, nama sopir, tanggal, tonase, tarif, status, serta catatan pencocokan.

Hasil aktual setelah konfirmasi: **78 SPB berhasil diimpor**. 102 baris bermasalah tidak menggagalkan 78 baris yang siap.

Contoh hasil yang kemudian dibuka dari daftar adalah SPB ID 96, sumber `TAGIHAN PT.BMT` baris 97, ton `26.23`, snapshot harga per ton `115000`, status `draft`, `unbilled`, dan `unsettled`. Detail dapat dibuka dari daftar setelah import dan tetap dapat dimuat ulang.

### C. Duplikat fingerprint dan Sheet1

1. Mengunggah ulang workbook Jatim melalui UI.
2. Membuka preview, tanpa menekan tombol impor.
3. Mengulangi upload untuk workbook Putra.
4. Memeriksa catatan duplikat dan status tombol.

Hasil aktual untuk Jatim: preview menjadi **828 baris bermasalah, 0 baris siap** dan tombol impor nonaktif. Baris diberi catatan bahwa fingerprint sumber sudah pernah diimpor.

Hasil aktual untuk Putra: preview menjadi **180 baris bermasalah, 0 baris siap** dan tombol impor nonaktif. Preview juga menampilkan duplikat logis antar-sheet, misalnya `Sheet1:8` diberi catatan bahwa ia duplikat dari `TAGIHAN PT.BMT` baris 8 dan tidak akan diimpor dua kali.

Preview final mempunyai ringkasan **Duplikat terdeteksi**, kolom **Catatan**, fingerprint sumber, dan catatan duplikat logis. Tidak ada SPB baru yang dibuat saat upload ulang.

Rekonsiliasi read-only database menunjukkan:

- Jatim: 38 record pada 1 sheet, sumber baris 11 - 116.
- Putra: 78 record pada 2 sheet; `Sheet1` 22 record dari baris 22 - 83, `TAGIHAN  PT.BMT` 56 record dari baris 8 - 97.
- Jumlah fingerprint sumber ganda setelah import: **0**.

### D. Rekap dan export

1. Membuka menu **Rekap Alat & Sopir** melalui navigasi aplikasi.
2. Memeriksa total dan tabel per unit/sopir.
3. Menekan **Export Rekap**.

Rekap berhasil dirender melalui browser dengan total aktual **118 trip**, **3.649,98 ton**, pendapatan **Rp375.181.085**, upah sopir **Rp124.844.450**, dan kontribusi **Rp250.336.635**. Tabel menampilkan kolom unit, trip, ton, pendapatan, upah sopir, dan kontribusi; tabel sopir menampilkan trip, ton, dan total upah.

Network browser mencatat request export:

```text
GET /asset/fleet/reports/export
HTTP 200
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename=rekap-spb-20260910-180726.csv
```

Perintah penyimpanan `agent-browser download` dua kali mengembalikan **Download was canceled**, sehingga file CSV tidak dinyatakan tersimpan sebagai bukti lokal. Ini dicatat sebagai keterbatasan tooling; endpoint aplikasi sendiri terbukti merespons download yang benar.

## Bukti screenshot

- [Preview Jatim](screenshots/02D-preview-jatim.png) - jumlah sheet/baris serta contoh unmatched.
- [Preview Jatim dengan route default](screenshots/02D-preview-jatim-default-route.png) - sebelum konfirmasi baris siap.
- [Preview Putra](screenshots/02D-preview-putra.png) - 2 sheet, 180 baris, 78 siap, 102 bermasalah.
- [Detail hasil import](screenshots/02D-imported-detail.png) - detail satu SPB hasil import dan status sumber.
- [Preview upload ulang yang terblokir](screenshots/02D-preview-duplicate-blocked.png) - tombol impor nonaktif karena duplikat.
- [Preview final dengan ringkasan duplikat](screenshots/02D-preview-duplicate-summary.png) - catatan fingerprint/duplikat logis dan kolom Catatan.

Tidak ada screenshot yang memuat password. Workbook sumber berisi nama kendaraan/sopir operasional; screenshot hanya digunakan untuk bukti internal tahap ini dan tidak boleh diterbitkan sebagai panduan publik tanpa penyamaran.

## Temuan dan perbaikan

1. Percobaan awal konfirmasi import dengan route kosong membuat controller mengembalikan redirect POST ke endpoint preview sehingga browser mendapat HTTP 405. Catch di `HeavyEquipmentTripController::importConfirm` diperbaiki agar kembali ke halaman form import dengan pesan error yang dapat dibaca.
2. Preview sebelumnya menilai baris siap walau route kosong. Pada konfirmasi, user sekarang dapat memilih route default dari UI untuk workbook yang tidak memiliki asal/tujuan.
3. Error satu baris pada proses pembuatan SPB sekarang dicatat per baris sehingga baris lain yang valid tetap dapat dibuat.
4. `HeavyEquipmentSpbImportService` sekarang menolak fingerprint sumber yang sudah pernah diimpor dan nomor tiket yang sudah digunakan pada business aktif.
5. View preview diberi `id="spb_import_confirm"`, tipe tombol submit eksplisit, ringkasan duplikat, dan catatan setiap baris. Ini juga membuat alasan tombol nonaktif dapat dipahami user.

## Checklist operasi yang tersedia

- Upload, preview, konfirmasi, daftar, detail, dan export: diuji melalui browser.
- Buat/edit/hapus SPB: pembuatan SPB dan edit diuji pada 02C; implementasi SPB saat ini tidak menyediakan tombol/route hapus, sehingga penghapusan tidak dipaksakan lewat SQL/API.
- Batalkan dialog hapus: **belum tersedia** karena tidak ada operasi hapus pada menu import SPB.
- Transaksi terkunci: sudah diuji pada 02C untuk SPB verified; SPB import tahap ini semuanya masih draft.
- Cleanup: record import dipertahankan sebagai handoff draft. Tidak ada transaksi posted yang dibuka kuncinya dan tidak ada cleanup database manual.

## Pemeriksaan tambahan

| Pemeriksaan | Hasil |
|---|---|
| `php -l Modules/AssetManagement/Http/Controllers/HeavyEquipmentTripController.php` | Lulus |
| `php -l Modules/AssetManagement/Services/HeavyEquipmentSpbImportService.php` | Lulus |
| `git diff --check` pada file perubahan 02D | Lulus; peringatan line ending worktree tetap ada |
| `php artisan view:cache --no-interaction` | Belum lulus karena PHP CLI lokal tidak memiliki ekstensi cURL dan bootstrap berhenti pada `Pusher\\PusherException` |
| `graphify update .` | Belum dapat dijalankan karena executable `graphify` tidak tersedia di PATH |

## Catatan penting untuk penerus

- Hanya SPB verified yang boleh dipakai untuk tahap invoice, Sell, atau payroll. ID 3 - 118 jangan dipakai pada 02E/02F kecuali memang dipilih sebagai data skenario.
- Baris workbook yang sopir/unit-nya unmatched belum boleh dianggap sebagai transaksi operasional lengkap. Pemetaan asset/user perlu dilakukan melalui UI pada tahap perbaikan khusus bila diperlukan.
- Banyak baris import memiliki tarif pendapatan tetapi tidak memiliki tarif upah sopir pada master yang cocok; akibatnya `driver_pay_total` dapat bernilai nol. Jangan menyimpulkan itu sebagai upah nol yang benar tanpa verifikasi master tarif.
- Tahap berikutnya adalah 02E dan harus menguji hanya SPB Project ID 1 dari 02C agar SPB ID 2 tetap tersedia untuk Sell di 02F.
