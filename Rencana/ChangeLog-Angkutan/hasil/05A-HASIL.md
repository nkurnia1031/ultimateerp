# Hasil 05A — Panduan Master Unit dan Tarif

## Status

**LULUS.** Panduan master unit dan tarif disusun dari tampilan serta hasil uji yang sudah ada. Artikel menggunakan katalog Change Log produksi `release-2026-09-10-angkutan` dengan slug tetap `angkutan-spb-dan-bonus-ayak`; bagian yang relevan adalah langkah **Siapkan tarif dan master**. Tidak dibuat release baru agar ikon navbar tidak memunculkan pengumuman duplikat.

## Artikel dan bahasa pengguna

File konten: [config/change_log.php](../../../config/change_log.php).

Panduan menjelaskan dengan bahasa awam:

- master unit adalah daftar alat fisik yang dipilih dari `Asset / Alat Berat`;
- sopir dipilih dari user business aktif, bukan diketik sebagai identitas bebas;
- tarif angkutan adalah aturan rute/unit yang dipakai menghitung pendapatan pelanggan dan upah sopir;
- `SPB` berarti Surat Perintah Bongkar, yaitu satu perjalanan angkutan;
- `verifikasi` berarti pemeriksaan akhir sebelum SPB dipakai untuk tagihan pelanggan atau rekap upah;
- `settlement` berarti rekap pembayaran sopir per periode;
- pendapatan pelanggan dan biaya/upah sopir ditampilkan terpisah;
- Bonus Ayak masuk biaya/payroll dan margin Project, bukan baris tagihan pelanggan.

## Panduan yang diserahkan

### Siapa yang memakai

Admin tarif menyiapkan master dan mengaktifkan tarif. Dispatcher memakai unit, sopir, rute, dan tarif itu ketika membuat SPB. Bagian penagihan dan payroll hanya memakai SPB yang sudah diverifikasi.

### Cara membuka menu

1. Login dan pastikan business yang tampil di navbar adalah business yang benar.
2. Buka **Asset Management**.
3. Pilih **Tarif Angkutan** untuk meninjau batch, tarif aktif, dan histori.
4. Pilih **Asset / Alat Berat** untuk memastikan unit fisik tersedia.

### Cara menyiapkan tarif dari Excel

1. Pada kartu **Upload Workbook Tarif**, tekan **Choose File** dan pilih workbook tarif.
2. Isi **Berlaku mulai** dengan tanggal yang sudah disetujui. Pada contoh uji, tanggalnya `01-01-2026`.
3. Tekan **Upload & Preview**. Upload ini membuat draft hasil import; periksa draft dan peringatan sebelum menekan konfirmasi aktif.
4. Periksa jumlah baris, rute, unit, dan pesan baris yang tidak valid.
5. Untuk unit yang belum cocok, pilih asset yang benar dari dropdown pemetaan. Jangan mengetik identitas baru atau memilih asset dari business lain.
6. Pastikan rute, unit, `Supir/Ton`, `Uang Jalan`, dan `Harga/Ton` sudah benar.
7. Tekan **Konfirmasi/Aktifkan** hanya setelah semua pemetaan wajib selesai.
8. Reload halaman dan pastikan batch berubah menjadi `ACTIVE` serta tarif terlihat pada bagian **Tarif Aktif, Preview, dan Histori**.

### Contoh angka yang sudah diuji

Pada workbook PT BMT, baris rute `SSP2 - EUP/GBE` dan unit `DT` memiliki:

- `Borongan Supir/Ton`: Rp65.000;
- `Uang Jalan`: Rp1.300.000;
- `Harga/Ton`: Rp115.000;
- keterangan sumber: `Acc Bu Lily 06/08/26`.

Tanggal pada keterangan tersebut hanya catatan sumber. Tanggal berlaku harus diisi pada kontrol tanggal aplikasi. Jika 20 ton dipakai pada SPB, pendapatan pelanggan dihitung 20 × Rp115.000 = Rp2.300.000; upah sopir dihitung terpisah sesuai komponen tarif dan settlement.

### Cara mengenali berhasil

- batch muncul pada daftar **Batch Import**;
- statusnya `ACTIVE` setelah konfirmasi;
- unit pada tabel tampil sebagai pasangan label workbook dan nama asset, misalnya `DT → RUN02A Unit Angkutan Revised`;
- tanggal berlaku dan nominal tampil konsisten setelah reload;
- tarif itu dapat dipilih ketika membuat SPB pada tanggal yang sesuai.

### Masalah umum

| Masalah | Langkah penyelesaian |
|---|---|
| Sistem meminta tanggal | Isi **Berlaku mulai** secara manual. Jangan mengandalkan tanggal di kolom keterangan Excel. |
| Unit belum cocok | Pilih asset business aktif melalui pemetaan. Bedakan `DT`, `DT 01`, dan `DT01`; jangan menormalisasi diam-diam. |
| `Harga/Ton` atau komponen biaya kosong | Jangan menganggap kosong sebagai nol. Minta admin memperbaiki sumber atau mengikuti prosedur override yang disetujui. |
| File identik ditolak | Itu perlindungan dari batch ganda. Periksa batch aktif atau gunakan file versi resmi dengan tanggal berlaku yang benar. |
| Tarif lama terlihat berubah | Periksa tanggal berlaku. SPB yang sudah tersimpan memakai snapshot tarifnya sendiri; jangan mengubah transaksi yang sudah verified. |
| Tombol edit/hapus tarif individual tidak ditemukan | UI saat ini mengelola tarif melalui batch, aktivasi, dan pembatalan; jangan menghapus baris dengan database. |

## Uji browser untuk mencocokkan panduan

### Fixture

- Sesi: `changelog02i`.
- Business: `CV BM TRANSPORT`, `business_id=2`.
- Asset contoh: ID `39`, `RUN02A Unit Angkutan Revised`.
- Batch aktif: ID `3`, berlaku mulai `01-01-2026`, hasil `88/91` baris valid dan `14` unit.
- Tidak ada transaksi baru pada tahap panduan. Batch aktif dan asset dipertahankan untuk tahap SPB berikutnya.

### Tindakan pengguna dan hasil

1. Melalui navbar aplikasi, membuka **Asset Management → Tarif Angkutan**.
2. Memeriksa form **Upload Workbook Tarif**, label file, tanggal berlaku, tombol **Upload & Preview**, dan **Export Aktif**.
3. Memeriksa daftar batch yang memuat batch aktif ID 3 dan peringatan tiga baris invalid.
4. Memeriksa tabel tarif aktif dan mencocokkan rute/unit/nominal dengan expected workbook.
5. Reload melalui browser.
6. Mengubah viewport ke layar ponsel dan reload.

Expected:

- label mudah ditemukan;
- status batch serta tarif aktif tetap ada setelah reload;
- form tidak keluar dari kartu pada ponsel;
- user mendapat petunjuk bahwa tanggal harus diisi manual.

Actual:

- halaman berada di `/asset/fleet/rates`;
- heading `Tarif Angkutan`, kartu `Upload Workbook Tarif`, `Choose File`, `Berlaku mulai`, `Upload & Preview`, dan `Export Aktif` tampil;
- batch aktif ID 3 terlihat dengan `88/91`, `28` rute valid, dan `14` unit;
- baris pertama menampilkan `SSP2 - EUP/GBE`, `DT → RUN02A Unit Angkutan Revised`, `Rp65.000`, `Rp1.300.000`, dan `Rp115.000`;
- setelah reload nilai tetap sama;
- pada ponsel form dan tombol tetap dapat dibaca tanpa menutupi field penting.

Bukti baru:

- [master tarif desktop](../../../hasil/screenshots/05A-master-tarif-desktop.png)
- [form master tarif mobile](../../../hasil/screenshots/05A-master-tarif-mobile.png)
- [master tarif dan form upload desktop](../../../hasil/screenshots/05A-master-tarif-desktop.png)
- [versi anotasi mobile](../../../hasil/screenshots/05A-master-tarif-mobile-annotated.png)

Uji upload workbook yang sama dan tanggal yang sama sudah dilakukan melalui UI pada hasil 02B. Sistem menolak batch identik setelah perbaikan tanpa membuat batch baru; panduan tidak mengulang konfirmasi agar batch aktif tidak terganggu.

## Inventaris screenshot dan anotasi

| Jenis | Lokasi | Isi |
|---|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05A-20260911/raw/` | Screenshot desktop dan mobile dari halaman yang benar-benar dibuka browser. |
| Raw internal | `storage/app/changelog-evidence/RUN05A-20260911/raw/` | PNG screenshot desktop/ponsel yang diambil dari UI melalui agent-browser. |
| Anotasi internal | `storage/app/changelog-evidence/RUN05A-20260911/annotations/` | PNG hasil proses Python bernomor dengan kotak, panah, dan caption. |
| Versi panduan | `public/images/change-log/release-2026-09-10-angkutan/` | PNG raw dan PNG anotasi hasil Python yang dapat dirujuk dengan asset helper. |

Anotasi memakai nomor, kotak merah, panah, dan caption:

- desktop: `1` upload + tanggal, `2` batch import, `3` tabel tarif aktif;
- mobile: `1` pilih workbook, `2` isi tanggal, `3` tekan Upload & Preview.

PNG diberi metadata judul/deskripsi oleh utilitas Python. Teks panduan di atas dan alt text tetap menjadi penjelasan utama; gambar bukan satu-satunya sumber informasi.

## Review privasi dan keamanan

- Screenshot tidak menampilkan password, email, nomor telepon, nama customer, sopir, invoice, atau payroll.
- Nama business dan asset pada screenshot adalah data demonstrasi lokal yang sudah dicatat sebagai fixture.
- Anotasi raster tidak dianggap sebagai sanitasi; raw dan public tetap diperiksa terpisah.
- Link artikel tetap memakai slug produksi `angkutan-spb-dan-bonus-ayak`; tidak ada path dari input user.
- Akses menu dan endpoint tetap bergantung pada business aktif serta permission aplikasi.

## Pemeriksaan tambahan

- `php -l config/change_log.php` — wajib dijalankan setelah perubahan konten.
- `php artisan view:cache --no-interaction` — wajib dijalankan pada handoff tahap ini.
- Tidak ada JavaScript module yang berubah; publish module tidak diperlukan.
- Graphify perlu diperbarui setelah hasil ini tersimpan.

## Cleanup dan handoff

Tidak ada data operasional baru. Batch tarif ID 3, asset ID 39, driver ID 93, project ID 11, dan transaksi posted dari tahap sebelumnya dipertahankan sesuai dependensi penerus. Jangan membatalkan batch aktif atau menghapus asset yang sudah direferensikan.

Lanjutkan [05B — Panduan input dan verifikasi SPB](../05B-PANDUAN-INPUT-DAN-VERIFIKASI-SPB.md). Tahap berikut wajib memakai istilah dan contoh yang konsisten dengan artikel ini, tetapi hanya menjelaskan form SPB dan verifikasi.
