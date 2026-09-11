# Hasil 05C — Panduan Import SPB

## Status

**LULUS TERARAH dengan catatan tooling download.** Panduan import SPB mencakup pemilihan workbook, preview sheet/baris, pencocokan unit dan sopir, route default, baris bermasalah, fingerprint, duplikat antar-sheet, dan konfirmasi. Journey browser memakai preview duplicate dari workbook yang sudah pernah diimpor sehingga tidak membuat SPB kedua. Isi panduan tidak menjanjikan penyimpanan file export karena keterbatasan `agent-browser download` sudah tercatat pada hasil 02D.

## Artikel dan konten yang diperbarui

File konten: [config/change_log.php](../../../config/change_log.php).

Langkah **Siapkan tarif dan master** kini juga membedakan dua jenis workbook:

- workbook tarif masuk melalui **Tarif Angkutan** dan membutuhkan tanggal berlaku;
- workbook perjalanan masuk melalui **Import SPB Angkutan** agar sheet dan nomor baris asal dapat diperiksa sebelum impor.

Panduan tetap menggunakan istilah awam:

- **SPB** berarti Surat Perintah Bongkar dan satu baris perjalanan yang berhasil diimpor akan menjadi satu data perjalanan;
- **verifikasi** adalah pemeriksaan lanjutan setelah data SPB lengkap;
- **settlement** adalah rekap pembayaran sopir per periode;
- Bonus Ayak masuk biaya/payroll, bukan baris tagihan pelanggan.

## Fixture dan sumber bukti

| Data | Nilai |
|---|---|
| Run panduan | `RUN05C-20260911` |
| Sesi browser | `changelog02i` |
| Business | `CV BM TRANSPORT`, `business_id=2` |
| Workbook browser | `ref/05. PT. JATIM PROPERTINDO JAYA CANGKANG (MV. QUEEN CORAL).xls` |
| Status workbook | Sudah pernah diimpor pada 02D; preview ulang aman dan tidak menghasilkan SPB baru |
| Hasil import awal | 32 sheet, 828 baris sumber, 38 baris dibuat, 790 bermasalah |
| Preview ulang | 32 sheet, 828 bermasalah, 0 siap; 38 baris ditolak karena fingerprint/tiket sudah pernah dipakai |
| Contoh sumber | `BMT:11`, tiket `No : 492556035`, tanggal `2026-08-23`, unit `BM 9600 RE`, sopir `ZAINUDDIN`, ton `26.39`, tarif `33500` |

Baris contoh dan nama pada workbook adalah data sumber operasional. Screenshot yang diterbitkan untuk panduan hanya mengambil area form/ringkasan/peringatan dan tidak menampilkan tabel baris yang memuat nama sopir. Screenshot detail baris hanya disimpan sebagai bukti internal pada hasil 02D, bukan asset publik.

## Panduan yang diserahkan untuk user

### Siapa yang memakai

Dispatcher atau admin operasional memakai Import SPB untuk memasukkan banyak perjalanan dari workbook rekap. Pemeriksa memeriksa baris siap dan bermasalah sebelum impor. Bagian penagihan dan payroll tetap memakai SPB yang sudah diverifikasi, bukan langsung memakai baris yang baru preview.

### Cara membuka menu

1. Pastikan business aktif di navbar sudah benar.
2. Buka **Asset Management**.
3. Pilih **Import SPB Angkutan**.

### Mengunggah dan membaca preview

1. Tekan **Choose File** lalu pilih workbook `.xlsx`, `.xls`, atau `.csv`.
2. Tekan **Preview Workbook**.
3. Periksa ringkasan **Sheet**, **Baris**, **Siap**, dan **Bermasalah**.
4. Baca tabel preview. Kolom **Sumber** menunjukkan sheet dan nomor baris, misalnya `BMT:11`; kolom lain menunjukkan tiket, unit, sopir, tanggal, ton, dan tarif.
5. Baca kolom **Catatan** untuk menemukan unit/sopir yang belum cocok, fingerprint yang sudah pernah diimpor, tiket yang sudah digunakan, atau duplikat antar-sheet.
6. Jika workbook tidak memiliki asal/tujuan yang dapat dipetakan, pilih **Rute default untuk baris tanpa asal/tujuan** hanya jika rute tersebut memang disetujui.
7. Hanya baris berstatus **Siap** yang boleh diimpor. Baris **Perlu koreksi** tidak ikut dibuat.
8. Tekan **Import Baris Siap** setelah ringkasan dan catatan diperiksa.
9. Buka daftar **SPB Angkutan**, cari hasil import, dan buka detailnya untuk memeriksa relasi serta status awal `Draft`.
10. Lengkapi dan verifikasi SPB sebelum dipakai untuk tagihan pelanggan atau settlement payroll.

### Contoh angka yang sudah diuji

Pada workbook Jatim yang sudah diuji:

- 32 sheet terbaca;
- 828 baris sumber terbaca;
- 38 baris valid berhasil dibuat pada import pertama;
- 790 baris bermasalah tidak menghentikan 38 baris siap;
- saat file yang sama diunggah ulang, 0 baris siap dan 828 baris bermasalah karena perlindungan fingerprint/tiket.

Pada workbook Putra Sanjaya di hasil 02D, sistem membaca 2 sheet, 180 baris, 78 baris siap, dan 102 bermasalah. Duplikat logis antar-sheet tidak dihitung dua kali.

### Cara mengenali proses berhasil

- preview menunjukkan jumlah sheet dan baris yang masuk akal;
- tiap baris menyimpan sumber dalam format sheet:baris;
- hasil import menambah SPB hanya untuk baris siap;
- detail SPB dapat dibuka dari daftar setelah reload;
- baris yang duplikat tidak menambah SPB baru;
- SPB hasil import masih harus melalui proses Selesaikan dan Verify.

### Masalah umum

| Masalah | Solusi |
|---|---|
| Banyak baris `Perlu koreksi` | Baca Catatan per baris. Perbaiki unit, sopir, tiket, tanggal, atau sumber workbook; jangan memaksa semua menjadi siap. |
| Unit/sopir tidak ditemukan | Pastikan nama atau nomor kendaraan sudah memiliki relasi asset/user pada business aktif. Pemetaan data master harus dilakukan melalui UI yang disediakan. |
| Asal/tujuan kosong | Pilih rute default hanya jika memang benar. Jika tidak, biarkan baris bermasalah dan koreksi sumber. |
| `fingerprint sumber sudah pernah diimpor` | Itu perlindungan dari import ganda. Jangan menekan impor berulang untuk file yang sama. |
| `Nomor tiket sudah pernah digunakan` | Cari SPB yang sudah ada. Pastikan ini bukan perjalanan yang sama sebelum memakai tiket baru yang sah. |
| `Sheet1` tampak sama dengan sheet lain | Baca Catatan duplikat logis. Sistem tidak boleh membuat SPB kedua dari baris yang sama. |
| Hasil import belum dapat ditagihkan | Status awal hasil import adalah Draft. Lengkapi lalu Selesaikan dan Verify terlebih dahulu. |

## Uji browser untuk mencocokkan panduan

Semua langkah utama dilakukan dengan `agent-browser`: upload file, menekan Preview Workbook, memilih route default bila diperlukan, membaca tabel dan peringatan, serta tidak menekan impor pada preview duplicate. Pemeriksaan hasil import awal berasal dari journey browser lulus pada hasil 02D.

### 1. Halaman kosong

Tindakan pengguna:

1. Membuka `/asset/fleet/trips/import`.
2. Memeriksa heading, keterangan, `Choose File`, `Preview Workbook`, dan `Kembali`.
3. Mengambil screenshot desktop.

Actual: halaman tampil tanpa preview lama, dan tombol sesuai dengan instruksi.

Bukti: [halaman import kosong](../../../hasil/screenshots/05C-import-empty-desktop.png).

### 2. Preview workbook yang sudah pernah diimpor

Tindakan pengguna:

1. Memilih workbook Jatim melalui `Choose File`.
2. Menekan `Preview Workbook`.
3. Menunggu halaman `/asset/fleet/trips/import/preview`.
4. Membaca ringkasan dan beberapa baris awal.
5. Membaca dropdown route default.
6. Tidak menekan `Import Baris Siap` karena semua baris memang duplicate.
7. Reload preview melalui browser dan memeriksa state tetap dapat dibaca.

Expected:

- file dibaca tanpa membuat transaksi duplicate;
- sheet/baris, siap/bermasalah, sumber, dan catatan terlihat;
- tombol impor tidak dapat digunakan bila tidak ada baris siap.

Actual:

- URL menjadi `/asset/fleet/trips/import/preview`;
- ringkasan menampilkan `Sheet: 32`, `Baris: 828`, `Siap: 0`, `Bermasalah: 828`;
- alert menampilkan `Duplikat terdeteksi` dan 38 fingerprint yang sudah pernah diimpor;
- baris contoh menampilkan `BMT:11`, tiket, unit, sopir, tanggal, ton, tarif, dan `Perlu koreksi`;
- dropdown route default tersedia;
- tidak ada SPB baru dibuat.

Bukti:

- [preview duplicate desktop](../../../hasil/screenshots/05C-import-preview-duplicate-desktop.png);
- [preview duplicate mobile](../../../hasil/screenshots/05C-import-preview-duplicate-mobile.png);
- [preview anotasi desktop](../../../hasil/screenshots/05C-import-preview-desktop-annotated.png);
- [preview anotasi mobile](../../../hasil/screenshots/05C-import-preview-mobile-annotated.png).

### 3. Pemeriksaan hasil import awal

Hasil 02D membuktikan melalui browser bahwa workbook Jatim pertama kali diimpor menghasilkan 38 SPB dari 38 baris siap, sedangkan workbook Putra menghasilkan 78 SPB dari 78 baris siap. Salah satu hasil dibuka dari daftar dan direload; detail tetap menampilkan sumber sheet/baris, unit, sopir, tanggal, tonase, tarif snapshot, status `Draft`, `unbilled`, dan `unsettled`.

Panduan hanya menjadikan angka ini contoh karena tahap 05C tidak mengulang impor yang berisiko menggandakan data.

## Inventaris raw, anotasi, dan versi publik

| Jenis | Lokasi | Catatan |
|---|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05C-20260911/raw/` | Screenshot halaman import dan preview yang benar-benar dibuka browser. |
| PNG anotasi internal | `storage/app/changelog-evidence/RUN05C-20260911/annotations/` | PNG hasil Python bernomor untuk form/ringkasan/peringatan. |
| Asset publik | `public/images/change-log/release-2026-09-10-angkutan/` | Hanya area aman: form, ringkasan, dan peringatan tanpa nama sopir pada tabel. |
| Bukti hasil | `hasil/screenshots/05C-*.png` | Bukti internal untuk review tahap ini. |

Anotasi memakai nomor, kotak merah, panah, dan caption:

- desktop `1` = pilih workbook + Preview Workbook, `2` = ringkasan sheet/baris;
- mobile `1` = pilih file + preview, `2` = ringkasan, `3` = peringatan duplikat.

PNG hasil Python memiliki metadata judul/deskripsi. Teks panduan tidak bergantung pada gambar.

## Review privasi

- Raw internal dapat memuat data sumber uji dan tidak dipublikasikan.
- Asset publik hanya menampilkan area yang sudah diperiksa: tidak ada password, email, nomor telepon, nama customer, atau nama sopir pada tabel.
- Screenshot hasil 02D yang memuat baris sumber tetap menjadi bukti internal dan tidak dirujuk sebagai gambar publik di Change Log.
- Tidak ada transaksi baru, invoice, payroll, Bonus Ayak, atau settlement dibuat pada 05C.

## Pemeriksaan tambahan

- `php -d extension=mbstring -l config/change_log.php` — wajib lulus setelah konten.
- `php -d extension=mbstring artisan view:cache --no-interaction` — wajib lulus.
- `php -d extension=mbstring artisan route:cache --no-interaction` — wajib lulus.
- `php -d extension=mbstring artisan migrate --pretend --no-interaction` — wajib lulus.
- `node --check public/js/app.js` — tidak berubah pada tahap ini, tetap diperiksa.
- `python -m graphify update .` — wajib dijalankan setelah asset dan hasil disimpan.
- Tidak ada module JavaScript yang berubah; publish module tidak diperlukan.

## Cleanup dan handoff

- Preview duplicate tidak dikonfirmasi, sehingga tidak ada SPB tambahan yang harus dihapus.
- SPB ID 3–118 hasil import 02D tetap draft untuk handoff; jangan hapus langsung dengan database.
- SPB verified, invoice, payroll, dan settlement tahap sebelumnya tidak disentuh.

Lanjutkan [05D — Panduan SPB dan invoice Project](../05D-PANDUAN-SPB-DAN-INVOICE-PROJECT.md). Tahap berikut hanya membahas memilih SPB verified dari Project dan membuat tagihan pelanggan.
