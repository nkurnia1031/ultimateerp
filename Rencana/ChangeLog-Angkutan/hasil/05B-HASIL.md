# Hasil 05B — Panduan Input dan Verifikasi SPB

## Status

**LULUS.** Panduan input dan verifikasi SPB disusun menggunakan slug Change Log produksi `angkutan-spb-dan-bonus-ayak` dan hanya menjelaskan field serta perilaku yang sudah dibuktikan. Bukti baru diambil melalui `agent-browser` pada form, daftar, dan detail SPB. Tidak ada transaksi operasional baru yang dibuat pada tahap penulisan panduan.

## Artikel dan konten yang diperbarui

File konten: [config/change_log.php](../../../config/change_log.php).

Langkah artikel yang diperjelas:

- **Buat satu perjalanan** sekarang menyebut nomor tiket, tanggal, rute, unit/kendaraan, sopir, Project, customer, Gross, Tare, Netto, Diterima, Ton Tagihan, Selisih, Toleransi, Tarif Klaim/Kg, dan Potongan Lain.
- **Selesaikan dan verifikasi** menjelaskan pemeriksaan nilai dasar, penyesuaian klaim, nilai akhir, dan status sebelum SPB dipakai untuk tagihan pelanggan atau rekap upah.
- Contoh angka menggunakan SPB yang sudah diuji, bukan angka rekaan baru.

Panduan memakai istilah awam:

- **SPB** adalah Surat Perintah Bongkar; dalam aplikasi satu SPB mewakili satu perjalanan.
- **Verifikasi** adalah pemeriksaan akhir sebelum SPB boleh dipakai pada proses berikutnya.
- **Settlement** adalah rekap pembayaran sopir per periode.
- Pendapatan pelanggan dan upah sopir dihitung serta ditampilkan sebagai komponen berbeda.
- Bonus Ayak adalah biaya/penghasilan sopir untuk payroll dan margin Project, bukan baris tagihan pelanggan.

## Fixture yang digunakan

| Data | Nilai |
|---|---|
| Run uji panduan | `RUN05B-20260911` |
| Sesi browser | `changelog02i` |
| Business | `CV BM TRANSPORT`, `business_id=2` |
| SPB | ID `1`, `TRIP-202609-0001`, tiket `RUN02C-20260910-MAIN` |
| Status | `VERIFIED`, sudah billed dan settled dari tahap sebelumnya |
| Rute | `SSP2 - EUP/GBE` |
| Asset/unit | ID `39`, `RUN02A Unit Angkutan Revised` |
| Sopir | user ID `93`, `RUN02A Driver Revised` |
| Project/customer | Project ID `11`, customer ID `1802` |
| Tarif snapshot | Harga/Ton Rp115.000; Supir/Ton Rp65.000; Uang jalan Rp1.300.000 |

SPB ID 1 tidak diedit atau dihapus karena sudah menjadi sumber invoice Project dan settlement payroll. SPB ID 2, ID 119/3, dan ID 120 juga tetap dipertahankan sesuai handoff tahap sebelumnya.

## Panduan yang diserahkan untuk user

### Siapa yang memakai

Dispatcher atau admin operasional membuat SPB. Pemeriksa memeriksa dan memverifikasi. Bagian penagihan memakai SPB verified untuk tagihan pelanggan; bagian payroll memakai SPB verified untuk rekap upah sopir.

### Cara membuka menu

1. Pastikan business aktif di navbar sudah benar.
2. Buka **Asset Management**.
3. Pilih **SPB Angkutan**.
4. Tekan **Buat Trip**.

### Mengisi SPB

1. Isi **Nomor Tiket** jika dokumen fisik memiliki nomor tiket.
2. Isi **Tanggal SPB**. Tanggal Muat dan Tanggal Bongkar dapat diisi jika tersedia.
3. Pilih **Rute**, **Unit/kendaraan**, dan **Sopir** dari pilihan yang tersedia. Pilihan tersebut berasal dari master business aktif.
4. Pilih **Project** dan **Customer** bila perjalanan terkait pekerjaan atau tagihan tertentu.
5. Isi tonase sesuai dokumen timbang:
   - Gross = berat sebelum dikurangi tare;
   - Tare = berat kendaraan kosong;
   - Netto = tonase aktual;
   - Diterima = tonase yang diterima, bila berbeda;
   - Ton Tagihan = tonase yang akan menjadi dasar tagihan.
6. Isi **Selisih**, **Toleransi**, **Tarif Klaim/Kg**, dan **Potongan Lain** bila berlaku. Jangan mengisi angka perkiraan hanya agar form tersimpan.
7. Periksa kembali pilihan dan angka, lalu tekan **Simpan SPB**.
8. Buka detail SPB dari daftar dan pastikan data yang tersimpan benar.

### Contoh angka yang sudah diuji

Pada SPB `TRIP-202609-0001`:

```text
Gross              = 23 ton
Tare               = 2 ton
Netto              = 21 ton
Diterima/Tagihan   = 21 ton
Harga/Ton          = Rp115.000
Nilai dasar        = 21 × Rp115.000 = Rp2.415.000
Penyesuaian klaim  = Rp150.000
Nilai akhir        = Rp2.515.000
Supir/Ton          = Rp65.000
Uang jalan         = Rp1.300.000
Total upah         = Rp2.665.000
```

`Nilai akhir` adalah pendapatan pelanggan setelah penyesuaian yang tampil pada SPB. `Total upah` adalah hak sopir dan tidak boleh diperlakukan sebagai nominal invoice pelanggan.

### Menyelesaikan dan memverifikasi

1. Dari daftar SPB, cari perjalanan yang baru disimpan dengan status `Draft`.
2. Tekan **Selesaikan** setelah muat/bongkar dan data timbang sudah lengkap.
3. Pastikan status berubah menjadi **Selesai**.
4. Tekan **Verify** setelah pemeriksa menyetujui data.
5. Reload daftar dan pastikan status menjadi **Verified**.
6. Setelah verified, SPB dapat dipilih untuk invoice Project/Sell atau settlement payroll sesuai hak akses.

### Cara mengenali proses berhasil

- nomor SPB/tiket muncul di daftar;
- detail menampilkan rute, unit, sopir, Project/customer, tonase, nilai, dan status;
- status berubah berurutan dari Draft → Selesai → Verified;
- tarif dan nominal tetap sama setelah reload;
- SPB verified tampil sebagai sumber yang dapat dipilih untuk proses berikutnya, selama belum billed/settled.

### Masalah umum

| Masalah | Solusi |
|---|---|
| Netto atau tonase negatif | Browser menolak nilai kurang dari nol. Masukkan angka dokumen yang benar. |
| Tarif tidak tersedia untuk rute/unit/tanggal | Periksa master Tarif Angkutan dan pemetaan unit. Jangan mengarang tarif. Override hanya dengan alasan dan kewenangan yang sesuai. |
| Unit atau sopir tidak ada di pilihan | Pastikan master dibuat pada business aktif dan relasi sudah tersedia. Jangan mengetik identitas bebas. |
| SPB tidak bisa diedit | SPB yang sudah verified, billed, settled, atau dibatalkan dikunci untuk menjaga histori. Gunakan prosedur koreksi resmi. |
| Nilai Bonus Ayak tidak terlihat di invoice | Itu benar. Bonus Ayak masuk biaya/payroll dan margin Project, bukan line tagihan pelanggan. |
| Status belum Verified | Lengkapi data, lakukan Selesaikan, lalu minta pemeriksa menekan Verify. |

## Uji browser untuk memastikan panduan sesuai tampilan

Semua tindakan journey dilakukan melalui interaksi browser. Tidak ada SQL write, pemanggilan controller/service langsung, atau JavaScript yang memanggil API untuk membuat/mengubah SPB.

### 1. Form input

Tindakan pengguna pada sesi `changelog02i`:

1. Membuka `/asset/fleet/trips/create` melalui browser.
2. Mengisi nomor tiket demonstrasi `RUN05B-20260911-GUIDE`.
3. Memilih rute value `1` (`SSP2 - EUP/GBE`), asset value `39`, sopir value `93`, Project value `11`, dan customer value `1802` melalui dropdown.
4. Mengisi Gross `23`, Tare `2`, Netto `21`, Diterima `21`, Ton Tagihan `21`, Selisih `100`, Toleransi `50`, Tarif Klaim/Kg `1000`, dan Potongan Lain `50000`.
5. Tidak menekan Simpan SPB; form hanya dipakai untuk bukti visual dan fixture baru tidak dibuat.

Expected:

- field memiliki label yang dapat dipahami;
- unit, sopir, rute, Project, dan customer dipilih dari relasi yang tersedia;
- input numerik menolak nilai negatif;
- user dapat membatalkan dengan **Kembali** tanpa membuat data.

Actual:

- form berada pada `/asset/fleet/trips/create`;
- semua field relasi dan angka dapat diisi melalui browser;
- snapshot menampilkan `RUN02A Unit Angkutan Revised`, `RUN02A Driver Revised`, `RUN02A Project Angkutan`, dan customer demonstrasi;
- tombol `Simpan SPB`, `Kembali`, dan `Import Excel SPB` terlihat;
- form ditinggalkan tanpa POST penyimpanan.

Bukti: [form SPB terisi desktop](../../../hasil/screenshots/05B-spb-form-filled-desktop.png) dan [form anotasi](../../../hasil/screenshots/05B-spb-form-desktop-annotated.png).

### 2. Daftar SPB verified

Tindakan pengguna:

1. Membuka **SPB Angkutan**.
2. Memilih status `Verified` pada filter.
3. Menekan **Filter**.
4. Memeriksa daftar dan reload halaman.

Actual:

- URL `/asset/fleet/trips` terbuka;
- daftar menampilkan SPB `TRIP-202609-0001`, status `Verified billed`, dan sumber verified lain;
- filter status tetap `Verified` setelah submit.

Bukti: [daftar SPB verified](../../../hasil/screenshots/05B-spb-list-verified-filtered.png).

### 3. Detail dan perhitungan

Tindakan pengguna:

1. Membuka detail SPB ID 1 dari URL fitur melalui browser.
2. Memeriksa field relasi, tonase, harga, nilai dasar, klaim, nilai akhir, upah, status, invoice, dan settlement.
3. Reload detail.
4. Mengubah viewport ke `390 × 844` dan reload.
5. Menggulir ke bagian bawah pada desktop untuk memeriksa total upah dan status.

Actual setelah reload:

- tanggal `10-09-2026`, tiket `RUN02C-20260910-MAIN`, rute `SSP2 - EUP/GBE`;
- unit/sopir `RUN02A Unit Angkutan Revised / RUN02A Driver Revised`;
- Gross `23,0000`, Tare `2,0000`, Netto `21,0000`, Diterima `21,0000`, Tagihan `21,0000`;
- Harga/Ton `Rp115.000,00`, Nilai dasar `Rp2.415.000,00`, klaim `Rp150.000,00`, Nilai akhir `Rp2.515.000,00`;
- Supir/Ton `Rp65.000,00`, Uang jalan `Rp1.300.000,00`, Total upah `Rp2.665.000,00`, status `VERIFIED`;
- detail menyatakan sudah ditagihkan ke transaksi project invoice dan sudah masuk settlement.

Bukti:

- [detail desktop](../../../hasil/screenshots/05B-spb-detail-desktop.png);
- [detail ponsel](../../../hasil/screenshots/05B-spb-detail-mobile.png);
- [detail bagian nominal/status](../../../hasil/screenshots/05B-spb-detail-desktop-lower.png);
- [detail desktop beranotasi](../../../hasil/screenshots/05B-spb-detail-desktop-annotated.png);
- [nominal dan status beranotasi](../../../hasil/screenshots/05B-spb-detail-lower-annotated.png);
- [detail ponsel beranotasi](../../../hasil/screenshots/05B-spb-detail-mobile-annotated.png).

## Inventaris raw, anotasi, dan versi panduan

| Jenis | Lokasi |
|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05B-20260911/raw/` |
| PNG anotasi internal | `storage/app/changelog-evidence/RUN05B-20260911/annotations/` |
| Asset panduan | `public/images/change-log/release-2026-09-10-angkutan/` |
| Bukti hasil browser | `hasil/screenshots/05B-*.png` |

PNG anotasi dibuat dengan `scripts/annotate_changelog_screenshots.py` dari raw browser capture. PNG menggunakan nomor, kotak merah, panah, dan caption; teks panduan tetap menyediakan makna yang sama tanpa gambar. Field penting tidak ditutup pada screenshot desktop maupun ponsel.

## Review privasi

- Screenshot tidak memuat password, email, nomor telepon, atau data pelanggan/sopir yang tidak diperlukan.
- Nama business, unit, dan sopir yang terlihat merupakan fixture demonstrasi lokal dan ID-nya dicatat di atas.
- Screenshot detail memperlihatkan nomor transaksi Project dan settlement karena itu bagian dari bukti relasi; asset dipakai untuk panduan internal dan tidak dipromosikan sebagai data customer.
- Raw dan PNG anotasi disimpan terpisah; anotasi bukan pengganti pemeriksaan data sensitif.

## Pemeriksaan tambahan

- `php -d extension=mbstring -l config/change_log.php` — lulus.
- `php -d extension=mbstring artisan view:cache --no-interaction` — lulus.
- `php -d extension=mbstring artisan route:cache --no-interaction` — lulus.
- `php -d extension=mbstring artisan migrate --pretend --no-interaction` — lulus; tidak ada migration tertunda.
- `node --check public/js/app.js` — perlu dijalankan pada handoff.
- `python -m graphify update .` — perlu dijalankan setelah konten dan asset disimpan.
- Tidak ada module JavaScript yang berubah; publish module tidak diperlukan.

## Cleanup dan handoff

- Form demonstrasi tidak disimpan, sehingga tidak ada SPB tambahan untuk dihapus.
- SPB posted dan batch tarif aktif tidak disentuh.
- Asset, sopir, Project, customer, invoice, settlement, dan payroll fixture tetap dipertahankan untuk tahap panduan/integrasi berikut.

Lanjutkan [05C — Panduan import SPB](../05C-PANDUAN-IMPORT-SPB.md). Tahap tersebut hanya membahas upload workbook SPB, preview, mapping, duplikasi, dan hasil import.
