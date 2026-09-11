# Hasil 05D — Project dan invoice SPB

## Status

**LULUS dengan catatan.** Tab Project, ringkasan perjalanan, tombol pembuatan SPB, dan wizard pemilihan SPB untuk invoice berhasil diperiksa melalui interaksi browser. Journey lengkap pembuatan invoice Project—termasuk preview, simpan invoice, reload, sumber hilang dari chooser, dan penolakan edit sumber terkunci—sudah lulus pada hasil browser 02E dan direkonsiliasi di sini. Tahap ini tidak membuat invoice kedua dari fixture yang sudah memiliki riwayat billing/payroll.

## Run, business, dan data uji

- Run screenshot: `RUN05D-20260911`.
- Run journey invoice: `RUN02E-20260910`.
- Business aktif: `CV BM TRANSPORT`, business ID `2`.
- Project: `RUN02A Project Angkutan`, ID `11`.
- Customer: `RUN02A Customer Demo Revised`, ID `1802`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- Trip yang tampil pada Project: `TRIP-202609-0004` 20 ton, `TRIP-202609-0003` 22 ton, dan `TRIP-202609-0001` 21 ton; semuanya `VERIFIED`.
- Invoice yang sudah diuji pada 02E: transaction ID `54289`, nomor `DR2026/0006`, status `draft`. ID ini dipertahankan sebagai handoff dan tidak dihapus melalui database.

Semua pembuatan, verifikasi, pemilihan sumber, dan penyimpanan invoice pada journey 02E dilakukan melalui UI browser. Pemeriksaan database hanya read-only untuk rekonsiliasi.

## Panduan untuk user awam

### Apa gunanya?

Project mengumpulkan perjalanan yang memang terkait dengan pekerjaan tersebut. Dari tab **Trip Angkutan**, user dapat melihat total tonase, pendapatan pelanggan, upah sopir, dan kontribusi. Tombol **Pilih SPB untuk Invoice** membantu membuat tagihan dari perjalanan yang sudah selesai diperiksa.

SPB pada tahap ini dipakai sebagai catatan satu perjalanan angkutan. `Verified` berarti data perjalanan sudah diperiksa dan boleh dipakai untuk tagihan. Invoice berarti tagihan yang dikirim atau dicatat untuk pelanggan.

### Cara membuat SPB dari Project

1. Buka **Project**, lalu buka project yang akan dikerjakan.
2. Pilih tab **Trip Angkutan**.
3. Tekan **Buat SPB**.
4. Pastikan Project dan customer sudah terisi otomatis.
5. Isi tiket, tanggal, rute, unit, sopir, dan tonase perjalanan.
6. Tekan **Simpan**, lalu buka detail SPB untuk memeriksa hasilnya.
7. Tekan **Selesaikan**, kemudian **Verify** setelah data disetujui.

Jika berhasil, SPB muncul di daftar Project dengan status `VERIFIED` dan dapat dipakai untuk invoice atau settlement payroll.

### Cara memilih SPB untuk invoice Project

1. Pada tab **Trip Angkutan**, tekan **Pilih SPB untuk Invoice**.
2. Pastikan nama Project dan customer pada halaman pemilihan sudah benar.
3. Centang SPB yang akan ditagihkan. Daftar ini hanya menampilkan SPB Project yang sudah `verified`, belum pernah ditagihkan, dan tidak dibatalkan.
4. Tekan **Lanjutkan**.
5. Pilih lokasi bisnis invoice.
6. Periksa preview: gunakan **Ton Tagihan**, **Harga/Ton**, dan **Nilai Akhir** sebagai acuan tagihan. Ton aktual tetap ditampilkan sebagai pembanding.
7. Tekan **Buat Invoice Project**.
8. Buka nomor invoice, lalu reload halaman Project untuk memastikan invoice tetap tercatat.

Setelah berhasil, SPB tersebut tidak lagi muncul pada chooser. Ini mencegah satu perjalanan ditagihkan dua kali.

### Angka contoh yang sudah diuji

Pada SPB Project dengan gross 24 ton dan tare 2 ton:

```text
Netto             = 24 - 2 = 22 ton
Tonase tagihan    = 21 ton
Nilai dasar       = 21 x Rp115.000 = Rp2.415.000
Penyesuaian klaim = (100 + 50) kg x Rp1.000 = Rp150.000
Potongan lain     = Rp50.000
Nilai akhir       = Rp2.415.000 - Rp50.000 + Rp150.000 = Rp2.515.000
```

Invoice Project hasil uji menyimpan quantity 21 ton, rate Rp115.000, dan total Rp2.515.000. Nilai akhir SPB dan total invoice sama.

Pada ringkasan Project yang diperiksa melalui browser:

- total tonase: `63,00` ton;
- pendapatan pelanggan: `Rp7.330.000`;
- upah sopir: `Rp7.995.000`;
- kontribusi: `-Rp665.000`;
- Bonus Ayak Project: `Rp0` dan diberi keterangan bukan baris invoice pelanggan.

Pendapatan pelanggan dan biaya/upah sopir tetap dua hal terpisah. Bonus Ayak masuk biaya/payroll dan margin Project, bukan line invoice pelanggan.

## Hasil uji browser

### A. Tab Project dan ringkasan

Tindakan pengguna melalui `agent-browser`:

1. Login ke aplikasi lokal.
2. Membuka Project ID 11 melalui menu Project.
3. Membuka tab **Trip Angkutan**.
4. Membaca tombol **Buat SPB**, **Pilih SPB untuk Invoice**, dan **Tambah Bonus Ayak**.
5. Membaca daftar trip serta ringkasan tonase, pendapatan, upah, dan kontribusi.
6. Mengambil screenshot desktop dan ponsel.

Expected: Project menampilkan hanya relasi trip/bonus dalam business dan project yang sedang aktif, dengan nominal yang terpisah.

Actual: tiga trip verified tampil, seluruhnya memakai fixture unit/sopir yang sama; ringkasan menampilkan 63 ton, pendapatan Rp7.330.000, upah Rp7.995.000, kontribusi -Rp665.000, serta Bonus Ayak Rp0 bukan line invoice.

Bukti:

- [tab Trip Angkutan Project — desktop](../../../hasil/screenshots/05D-project-trips-desktop.png);
- [tab Trip Angkutan Project — ponsel](../../../hasil/screenshots/05D-project-trips-mobile.png);
- [anotasi tab Project](../../../hasil/screenshots/05D-project-trips-desktop-annotated.png).

### B. Wizard chooser invoice

Tindakan pengguna melalui `agent-browser`:

1. Menekan **Pilih SPB untuk Invoice** dari tab Project.
2. Membaca heading dan keterangan bahwa daftar hanya berisi SPB verified dan belum masuk invoice.
3. Membaca konteks Project/customer.
4. Memeriksa satu SPB yang tersedia, tanpa menekan submit invoice tambahan.
5. Mengambil screenshot desktop dan ponsel.

Expected: SPB dari Project lain, SPB draft, SPB dibatalkan, dan SPB yang sudah billed tidak tersedia.

Actual: URL wizard terbuka melalui tombol UI dan hanya satu fixture unbilled tampil untuk Project aktif. Kolom SPB, tanggal, rute, unit/sopir, dan Ton Tagihan tersedia. Pada ponsel tombol **Lanjutkan** terbaca dan dapat menjadi tindakan berikutnya.

Bukti:

- [chooser invoice Project — desktop](../../../hasil/screenshots/05D-project-invoice-chooser-desktop.png);
- [chooser invoice Project — ponsel](../../../hasil/screenshots/05D-project-invoice-chooser-mobile.png);
- [anotasi chooser desktop](../../../hasil/screenshots/05D-project-invoice-chooser-desktop-annotated.png);
- [anotasi chooser ponsel](../../../hasil/screenshots/05D-project-invoice-chooser-mobile-annotated.png).

### C. Pembuatan invoice dan reload

Bukti journey lengkap berasal dari 02E:

1. Memilih satu SPB verified melalui chooser.
2. Memilih lokasi bisnis.
3. Membaca preview dengan label **Ton Aktual**, **Ton Tagihan**, **Harga/Ton**, dan **Nilai Akhir**.
4. Menekan **Buat Invoice Project**.
5. Membuka detail invoice dan melakukan reload.
6. Membuka chooser kembali.

Actual: invoice `DR2026/0006` tersimpan sebagai draft dengan total Rp2.515.000; detail tetap sama setelah reload; SPB sumber tidak lagi tersedia pada chooser. Edit SPB yang sudah billed/verified ditolak oleh aplikasi. Bukti detail dan rekonsiliasi lengkap tersedia pada [hasil 02E](02E-HASIL.md).

### D. Batasan perubahan data dan cleanup

Tidak ada invoice tambahan yang dibuat pada 05D. Invoice draft dan SPB billed dari 02E dipertahankan sebagai handoff karena UI belum menyediakan prosedur unlink/koreksi yang aman. Tidak ada posted transaction yang dibuka kuncinya. SPB yang sudah billed tidak dihapus.

## Masalah umum

| Masalah | Yang harus dilakukan |
|---|---|
| Tombol Pilih SPB untuk Invoice tidak menampilkan perjalanan | Pastikan project benar, status SPB `Verified`, belum billed, dan business aktif benar. |
| Customer pada invoice kosong | Periksa customer pada Project dan gunakan data customer yang memiliki label yang benar; jangan meneruskan invoice sebelum konteksnya jelas. |
| Nominal invoice berbeda dari ton aktual | Cocokkan Ton Tagihan, Harga/Ton, klaim, potongan, dan Nilai Akhir. Invoice memakai nilai yang sudah ditentukan pada preview. |
| SPB yang sudah billed ingin diedit | Ini dikunci untuk menjaga audit. Gunakan prosedur koreksi yang disediakan admin, bukan mengubah database. |
| Bonus Ayak terlihat pada Project tetapi bukan line invoice | Itu benar. Bonus Ayak dipakai untuk biaya/payroll dan margin, bukan tagihan pelanggan. |

## Inventaris screenshot dan privasi

| Jenis | Lokasi | Catatan |
|---|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05D-20260911/raw/` | Screenshot keadaan browser yang dibuka melalui UI. |
| PNG anotasi | `storage/app/changelog-evidence/RUN05D-20260911/annotations/` | PNG raster hasil Python dengan nomor, kotak, panah, dan caption. |
| Asset publik | `public/images/change-log/release-2026-09-10-angkutan/` | Raw Project/chooser dan PNG anotasi hasil Python untuk panduan. |
| Bukti tahap | `hasil/screenshots/05D-*.png` | Bukti desktop, ponsel, dan hasil render anotasi. |

Screenshot memakai data demo sintetis yang dapat dikenali sebagai fixture, bukan kredensial atau data pelanggan/sopir nyata. Tidak ada password, email, atau nomor telepon pada asset publik.

## Pemeriksaan tambahan

| Pemeriksaan | Hasil |
|---|---|
| Interaksi browser Project dan chooser | Lulus |
| Screenshot desktop/ponsel dan proses PNG melalui Python | Lulus |
| `php -d extension=mbstring -l config/change_log.php` | Lulus |
| `php -d extension=mbstring artisan view:cache --no-interaction` | Lulus |
| `php -d extension=mbstring artisan route:cache --no-interaction` | Lulus |
| `php -d extension=mbstring artisan migrate --pretend --no-interaction` | Lulus; tidak ada migration pending |
| `node --check public/js/app.js` | Lulus; tidak berubah pada tahap ini |
| `python -m graphify update .` | Lulus; warning `tree_sitter_sql` tetap ada |
| Publish JavaScript module | Tidak diperlukan; perubahan tahap ini hanya konten/anotasi |

## Handoff

Perilaku Project/invoice sudah dapat dijelaskan pada Change Log. Jangan memakai SPB yang sudah billed untuk tahap Sell atau membuat invoice kedua. Lanjutkan ke [05E — Panduan Sell angkutan](../05E-PANDUAN-SELL-ANGKUTAN.md) dengan fixture SPB yang memang masih unbilled dan belum dipakai untuk settlement, sesuai catatan hasil 02F.
