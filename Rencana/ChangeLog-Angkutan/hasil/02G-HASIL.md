# Hasil 02G — Bonus Ayak manual dan sumber SPB

## Status

**LULUS dengan catatan.** Relasi Bonus Ayak ke sopir, unit, Project, dan beberapa SPB berhasil dipakai melalui browser. Perhitungan bonus, edit, reload, pembatalan hapus, penghapusan, validasi sumber yang tidak cocok, pencegahan pilihan SPB yang sudah dipakai, import XLSX, dan export Excel sudah diuji. Ada dua catatan yang harus dibawa ke tahap perbaikan atau dokumentasi: import Bonus Ayak belum memiliki preview dan mengizinkan import duplikat; endpoint export PDF merespons HTTP 204 pada sesi ini sehingga file PDF belum terbukti tersedia. Data legacy/unmatched terdeteksi melalui pemeriksaan baca database, tetapi belum ditampilkan melalui filter UI karena berada di luar bulan default.

## Run, business, dan data uji

- Run ID: `RUN02G-20260910`.
- Browser: `agent-browser`, session `changelog02e`.
- URL lokal: `http://localhost/2025/ultimateerp/public/`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Project: `RUN02A Project Angkutan`, project ID `11`.
- SPB sumber: ID `1` (`TRIP-202609-0001`) dan ID `119` (`TRIP-202609-0003`), keduanya verified, sopir 93, unit 39, dan Project 11.
- SPB mismatch: ID `2` (`TRIP-202609-0002`), verified tetapi memakai asset 38 (`B 0004`), sehingga sengaja tidak boleh menjadi sumber Bonus Ayak untuk unit 39.

Semua create, edit, delete, import, konfirmasi dialog, dan navigasi dilakukan melalui tampilan aplikasi. Pemeriksaan database hanya `SELECT` untuk rekonsiliasi setelah tindakan browser.

## Journey browser dan hasil aktual

### A. Bonus manual: buat, edit, reload, batal hapus, dan hapus

1. Membuka **Pool -> Bonus Ayak** melalui menu aplikasi.
2. Mengisi tanggal `10-09-2026`, sopir ID 93, unit ID 39, Project ID 11, dan `Trip=60`.
3. Setelah layout menampilkan `@stack('scripts')`, preview otomatis menjadi `Rp 70.000`.
4. Menekan **Simpan**.

Hasil aktual: Bonus Ayak ID `879` muncul pada daftar dengan sopir, unit, Project, `Trip=60`, dan `Bonus=70.000`. Identitas ditampilkan dari relasi, dengan snapshot nama/unit tetap disimpan.

5. Membuka **Edit** untuk ID 879.
6. Mengubah Trip `60` menjadi `61`, lalu menekan **Update**.
7. Membuka ulang halaman Bonus Ayak.

Hasil aktual setelah reload: row ID 879 tetap ada dengan `Trip=61` dan `Bonus=72.000`.

8. Menekan **Hapus**, kemudian memilih **Dismiss** pada dialog `Hapus data ini?`.

Hasil aktual: row tetap ada.

9. Membuka ulang daftar, menekan **Hapus**, lalu memilih **Accept** pada dialog browser.

Hasil aktual: ID 879 hilang dari daftar dan total kembali ke data yang tersisa. Tidak ada data manual ini yang dipertahankan.

Bukti:

- [Form manual terisi](../../../hasil/screenshots/02G-manual-form-filled.png)
- [Manual tersimpan](../../../hasil/screenshots/02G-manual-saved.png)
- [Manual setelah edit dan reload](../../../hasil/screenshots/02G-manual-edited-reloaded.png)
- [Hapus dibatalkan](../../../hasil/screenshots/02G-delete-cancelled.png)
- [Manual sudah dihapus](../../../hasil/screenshots/02G-manual-deleted.png)

### B. Bonus dengan beberapa SPB sumber

1. Mengisi form dengan sopir ID 93, unit ID 39, Project ID 11.
2. Pada **Sumber SPB**, memilih dua pilihan melalui multiselect: ID 1 dan ID 119.
3. Mengisi Trip `2` dan memeriksa preview `Rp 0` sesuai rumus aplikasi untuk kurang dari 50 trip.
4. Menekan **Simpan**.

Hasil aktual: Bonus Ayak ID `880` muncul sebagai:

```text
RUN02A Driver Revised | RUN02A Unit Angkutan Revised | RUN02A Project Angkutan
TRIP-202609-0001, TRIP-202609-0003 | Trip 2 | Bonus Rp0
```

5. Membuka **Edit** ID 880.
6. Mengubah field Trip menjadi `5`, lalu menekan **Update**.
7. Membuka ulang daftar.

Hasil aktual: sistem memakai jumlah sumber yang dipilih dan mengembalikan Trip menjadi `2`, Bonus tetap `0`. Ini membuktikan jumlah sumber tidak dapat diganti diam-diam menjadi angka manual ketika SPB terhubung.

Pemeriksaan database baca saja mengonfirmasi relasi berikut:

```text
bonus_ayak 880 | business_id 2 | driver_id 93 | asset_id 39 | project_id 11
bonus_ayak_trip: (bonus_ayak_id 880, trip_id 1, business_id 2)
bonus_ayak_trip: (bonus_ayak_id 880, trip_id 119, business_id 2)
```

Bukti: [Form beberapa sumber](../../../hasil/screenshots/02G-multi-source-form.png), [tersimpan](../../../hasil/screenshots/02G-multi-source-saved.png), [setelah edit dan reload](../../../hasil/screenshots/02G-multi-source-edited-reloaded.png).

### C. Sumber tidak cocok dan sumber ganda

1. Pada form Bonus baru, memilih sopir 93, unit 39, Project 11, lalu memilih SPB ID 2 yang memakai asset 38.
2. Menekan **Simpan**.

Hasil pertama sebelum perbaikan: aplikasi menampilkan halaman debug Ignition HTTP 422 dengan pesan teknis yang sebenarnya benar.

Perbaikan dalam lingkup fitur: `BonusAyakController` sekarang memakai `ValidationException` untuk penolakan sumber dan view Bonus menampilkan error validasi yang mudah dipahami.

Hasil setelah perbaikan dan uji ulang: halaman kembali ke Bonus Ayak dengan pesan:

`SPB sumber harus menggunakan sopir dan unit yang sama dengan Bonus Ayak.`

Tidak ada row Bonus baru yang dibuat. Form kembali berada pada business aktif. Bukti: [form mismatch](../../../hasil/screenshots/02G-mismatch-form.png), [pesan validasi ramah](../../../hasil/screenshots/02G-mismatch-friendly.png), dan [hasil setelah penolakan](../../../hasil/screenshots/02G-mismatch-result.png).

Uji sumber ganda dilakukan melalui UI dengan memeriksa pilihan yang tersedia setelah ID 880 tersimpan. SPB ID 1 dan ID 119 tidak lagi muncul pada daftar pilihan; yang tersisa pada fixture ini adalah SPB ID 2. Artinya jalur UI mencegah pemilihan ulang. Tidak dilakukan pemalsuan request untuk melewati selector.

Bukti: [guard sumber ganda](../../../hasil/screenshots/02G-duplicate-source-guard.png).

### D. Import XLSX dan uji duplikasi

Fixture aman dibuat khusus untuk run ini: [02G-bonus-ayak.xlsx](../../../hasil/fixtures/02G-bonus-ayak.xlsx). Isinya satu baris demonstrasi dengan sopir ID 93 melalui nama yang cocok, unit ID 39 melalui nama yang cocok, tanggal `10-09-2026`, dan Trip `50`.

1. Memilih file melalui tombol **Choose File**.
2. Menekan **Upload**.

Hasil aktual: aplikasi membuat Bonus Ayak ID `881`, Trip `50`, Bonus `50.000`, sopir relasional ID 93, dan asset relasional ID 39. Tidak ada class error. Import saat ini langsung menyimpan data; layar preview atau pemetaan sebelum simpan belum tersedia.

3. Mengunggah file yang sama sekali lagi melalui form UI.

Hasil aktual: aplikasi membuat row kedua ID `882` dengan isi sama dan menampilkan pesan `Import Bonus Ayak berhasil.`. Tidak ada pencegahan duplikasi pada import Bonus Ayak.

4. Menghapus ID 882 dan ID 881 satu per satu memakai tombol **Hapus** dan dialog **Accept**.

Hasil aktual: kedua row import hilang dari daftar. Bonus Ayak ID 880 tetap ada dan relasi SPB tidak berubah.

Bukti: [hasil import pertama](../../../hasil/screenshots/02G-import-xlsx-result.png), [duplikasi import](../../../hasil/screenshots/02G-import-duplicate.png), [setelah cleanup](../../../hasil/screenshots/02G-import-cleaned.png).

### E. Export

1. Menekan tombol **Excel** pada kartu Export Bonus Ayak.

Hasil aktual: request browser ke `bonus-ayak/export-excel?from=2026-09-01&to=2026-09-30` merespons HTTP `200`. Tombol menjadi disabled sementara sesuai handler submit global. Export tetap memakai business aktif melalui `BonusAyakExport`.

2. Menekan tombol **PDF** pada kartu yang sama.

Hasil aktual: request browser ke `bonus-ayak/export-pdf?from=2026-09-01&to=2026-09-30` tercatat HTTP `204`. File PDF belum terbukti dapat diunduh pada sesi ini, sehingga export PDF berstatus **belum lulus** dan harus diperiksa pada tahap perbaikan/finalisasi.

### F. Legacy/unmatched

Pemeriksaan database baca saja pada business 2 menunjukkan `875` row Bonus Ayak lama, dengan `10` row memiliki `driver_id` kosong dan tidak ada row dengan `asset_id` kosong. Data tersebut berada pada periode lama, sedangkan halaman default hanya menampilkan bulan September 2026. Filter tanggal lama belum berhasil diselesaikan melalui interaksi date picker pada sesi ini; karena itu legacy/unmatched belum dinyatakan lulus melalui tampilan. Tidak ada data lama yang diubah.

## Rumus dan rekonsiliasi

Rumus yang tampil dan tersimpan untuk input manual:

```text
Trip < 50              = Rp0
Trip >= 50             = Rp50.000 + (Trip - 50) x Rp2.000
60 trip                = Rp70.000
61 trip                = Rp72.000
50 trip dari import    = Rp50.000
```

Database baca saja setelah cleanup mengonfirmasi hanya row handoff ID 880 yang tersisa dari run ini pada tanggal uji, dengan business 2, driver 93, asset 39, Project 11, Trip 2, dan Bonus 0. Bonus Ayak tidak membuat invoice pelanggan dan tidak mengubah transaksi Project/Sell; relasinya dipakai untuk biaya dan payroll tahap berikutnya.

## Perbaikan yang dilakukan

1. Menambahkan `@stack('scripts')` pada layout utama agar script Bonus Ayak dan view lain yang memakai `@push('scripts')` benar-benar dijalankan. Dampak terukur: preview form berubah dari `Rp0` menjadi `Rp70.000` saat Trip 60 diisi.
2. Mengubah penolakan sumber Bonus Ayak dari halaman debug HTTP 422 menjadi validasi form dengan pesan Bahasa Indonesia pada `BonusAyakController`, view index, dan view edit.

File utama:

- `resources/views/layouts/app.blade.php`
- `app/Http/Controllers/BonusAyakController.php`
- `resources/views/bonus_ayak/index.blade.php`
- `resources/views/bonus_ayak/edit.blade.php`

## Checklist

| Pemeriksaan | Hasil |
|---|---|
| Buat Bonus manual lewat browser | Lulus; ID 879, kemudian dibersihkan lewat UI |
| Edit, simpan, dan reload | Lulus; 60 menjadi 61 dan Rp70.000 menjadi Rp72.000 |
| Batal dialog hapus | Lulus; row tetap ada |
| Konfirmasi hapus data uji | Lulus; row hilang |
| Relasi driver, asset, dan Project | Lulus; ID 880 memakai 93, 39, dan 11 |
| Beberapa SPB pada satu Bonus | Lulus; SPB 1 dan 119 tersimpan pada pivot |
| Sumber mismatch ditolak | Lulus setelah perbaikan; pesan kembali ke form |
| Sumber SPB ganda tidak ditawarkan | Lulus melalui selector UI |
| Import XLSX dan pencocokan relasi | Lulus dengan catatan import langsung tanpa preview |
| Import file sama dua kali | Terdeteksi sebagai gap; ID 881 dan 882 sempat terbuat lalu dibersihkan |
| Export Excel | Lulus; HTTP 200 |
| Export PDF | Belum lulus; HTTP 204, file belum terbukti |
| Legacy/unmatched melalui tampilan | Belum diuji; 10 row terdeteksi melalui read-only DB di luar filter default |
| Bonus menjadi line invoice pelanggan | Tidak terjadi; tidak ada invoice yang dibuat pada tahap ini |

## Handoff dan cleanup

- Bonus Ayak ID `880` dipertahankan untuk tahap settlement payroll. Statusnya belum masuk settlement.
- SPB ID `1` dan ID `119` tetap tertaut ke Bonus ID 880; jangan dipakai untuk Bonus Ayak lain pada tahap berikutnya.
- Bonus manual ID 879 serta import IDs 881 dan 882 sudah dihapus melalui UI setelah screenshot.
- SPB, invoice Project ID 119, Sell ID 54290, dan fixture dari tahap sebelumnya tidak diubah pada tahap ini.
- Tidak ada transaksi posted yang dibuka kuncinya.

## Pemeriksaan teknis

| Pemeriksaan | Hasil |
|---|---|
| `php -l app/Http/Controllers/BonusAyakController.php` | Lulus; no syntax errors |
| `php -l app/BonusAyak.php` | Lulus; no syntax errors |
| `git diff --check` | Lulus; hanya peringatan line ending yang ada, tidak ada whitespace error |
| `php artisan view:cache --no-interaction` | Pada saat 02G pertama dijalankan terhambat Pusher/cURL; berhasil pada rerun setelah cURL dan pdo_mysql PHP CLI lokal diaktifkan pada tahap 02H |
| `php artisan route:cache --no-interaction` | Pada saat 02G pertama dijalankan terhambat; berhasil pada rerun tahap 02H dan route baru tetap terserialisasi |
| `python -m graphify update .` | Lulus pada rerun tahap 02H; executable wrapper `graphify` tidak ada di PATH, tetapi module Python tersedia |
| Publish JavaScript module | Tidak diperlukan; tidak ada source JavaScript module yang diubah |

## Catatan penerus

- Tahap settlement harus memakai Bonus Ayak ID 880 dan memastikan pivot sumber tidak menyebabkan pembayaran ganda.
- Tahap perbaikan/final harus memeriksa mengapa PDF merespons 204 dan memutuskan apakah import Bonus Ayak perlu preview serta fingerprint duplikasi sebelum dokumentasi user.
- Artikel panduan tidak boleh menjanjikan import preview atau export PDF sampai kedua perilaku tersebut terbukti.
- Lanjutkan ke `02H-UJI-KASBON.md` setelah hasil ini dibaca; jangan mengulang cleanup ID 879/881/882.
