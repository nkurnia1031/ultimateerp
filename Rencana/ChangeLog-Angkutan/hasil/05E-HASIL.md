# Hasil 05E — Sell angkutan dari SPB

## Status

**LULUS dengan catatan.** Journey Sell dari SPB dilakukan ulang melalui browser: memilih SPB, mengisi customer/produk jasa/lokasi melalui dropdown, membuat Sell, membuka **Sell → List Drafts**, membuka detail, dan reload. Guard tanpa pilihan serta pencegahan pemakaian ulang SPB yang sudah billed juga lulus. Sell yang dibuat berstatus `Draft`, sehingga pencariannya memang melalui **List Drafts**, bukan **All sales**.

## Run, business, dan data uji

- Run ID: `RUN05E-20260911`.
- Business aktif: `CV BM TRANSPORT`, business ID `2`.
- SPB: ID `120`, `TRIP-202609-0004`, tiket `RUN02M-20260910-CONCURRENT`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- Customer: `RUN02A Customer Demo Revised`, ID `1802`.
- Produk jasa: `RUN02A Jasa Angkutan SPB Revised (RUN02A-SPB)`, variation/product ID `3069`.
- Lokasi: `CV BM TRANSPORT (BL0001)`, ID `2`.
- Sell baru: `DR2026/0009`, status `Draft`.

Pembuatan Sell dan navigasi dilakukan melalui `agent-browser`. Database hanya dibaca untuk rekonsiliasi, bukan untuk membuat atau mengubah transaksi.

## Panduan untuk user awam

### Apa gunanya?

Sell dari SPB membuat tagihan pelanggan dari satu atau beberapa perjalanan yang sudah diverifikasi. SPB adalah catatan satu perjalanan; `Verified` berarti perjalanan telah diperiksa dan boleh ditagihkan. Produk jasa yang dipilih harus berupa jasa atau produk tanpa stok.

Biaya/upah sopir tetap berasal dari SPB dan settlement payroll. Bonus Ayak tidak menjadi baris tagihan pelanggan.

### Cara membuat Sell dari SPB

1. Buka **Asset Management → SPB Angkutan**.
2. Centang SPB berstatus **Verified unbilled** yang akan ditagihkan.
3. Tekan **Sell dari yang dipilih**.
4. Pastikan trip yang terpilih benar.
5. Pilih **Customer**.
6. Pilih **Produk jasa angkutan**. Gunakan produk jasa/tanpa stok yang sudah disiapkan di modul Produk.
7. Pilih **Lokasi** bisnis.
8. Periksa tanggal, status `Draft`, rute, tonase, Harga/Ton, dan Total pada preview.
9. Tekan **Buat Sell**.
10. Buka **Sell → List Drafts**, cari nomor Sell, lalu buka barisnya untuk memeriksa detail.

Jika berhasil, aplikasi menampilkan pesan nomor Sell berhasil dibuat, SPB berubah menjadi `Verified billed`, dan checkbox SPB tersebut tidak lagi tersedia untuk Sell berikutnya.

### Contoh angka yang sudah diuji

SPB `TRIP-202609-0004` memiliki tonase tagihan 20 ton dan Harga/Ton Rp115.000.

```text
Nilai Sell = 20 x Rp115.000 = Rp2.300.000
```

Preview Sell, detail Sell, dan total transaction menampilkan Rp2.300.000. Detail juga mencatat rute `SSP2 - EUP/GBE`, unit, sopir, dan tonase 20 ton.

### Cara membedakan pendapatan dan biaya

- Sell adalah tagihan pelanggan: quantity dan harga memakai tonase tagihan serta snapshot nilai SPB.
- Upah sopir dan Bonus Ayak diproses dalam settlement payroll, bukan ditambahkan ke Sell.
- Rekap Project dapat menampilkan keduanya untuk menghitung kontribusi, tetapi nominalnya tidak boleh dicampur menjadi satu line invoice.

## Hasil uji browser

### A. Form Sell dari SPB

Tindakan pengguna:

1. Membuka menu SPB Angkutan.
2. Memeriksa status SPB ID 120 sebagai verified dan unbilled.
3. Mencentang baris SPB melalui checkbox.
4. Menekan **Sell dari yang dipilih**.
5. Memastikan customer sudah berisi data demo yang benar.
6. Memilih produk jasa ID 3069 melalui dropdown.
7. Memilih lokasi bisnis ID 2 melalui dropdown.
8. Mengambil screenshot desktop dan ponsel.

Expected: form memuat trip yang dipilih dan tidak meminta user mengetik ulang unit/sopir/rute/tonase.

Actual: form menampilkan `TRIP-202609-0004`, customer, produk jasa, lokasi, tanggal Sell, status Draft, rute, 20 ton, Harga/Ton Rp115.000, dan total Rp2.300.000.

Bukti:

- [form Sell desktop](../../../hasil/screenshots/05E-sell-form-filled-desktop.png);
- [form Sell ponsel](../../../hasil/screenshots/05E-sell-form-filled-mobile.png);
- [anotasi form desktop](../../../hasil/screenshots/05E-sell-form-desktop-annotated.png);
- [anotasi form ponsel](../../../hasil/screenshots/05E-sell-form-mobile-annotated.png).

### B. Membuat Sell dan membuka detail

Tindakan pengguna:

1. Menekan **Buat Sell**.
2. Membaca pesan `Sell DR2026/0009 berhasil dibuat.`.
3. Membuka menu **Sell**.
4. Memilih **List Drafts**.
5. Membuka baris `DR2026/0009`.

Actual: baris Sell tampil pada List Drafts. Detail menunjukkan status Draft, customer, produk jasa, rute, unit, sopir, ton 20, harga Rp115.000, subtotal/total Rp2.300.000, dan sisa pembayaran Rp2.300.000.

Bukti:

- [detail Sell desktop](../../../hasil/screenshots/05E-sell-detail-desktop.png);
- [detail Sell ponsel](../../../hasil/screenshots/05E-sell-detail-mobile.png);
- [anotasi detail Sell](../../../hasil/screenshots/05E-sell-detail-desktop-annotated.png).

### C. Reload dan sumber setelah billing

Detail ditutup, daftar draft direload melalui browser, dan `DR2026/0009` tetap tampil. Daftar SPB kemudian direload; SPB ID 120 tampil sebagai `Verified billed` dan tidak lagi mempunyai checkbox pilihan. Ini membuktikan relasi billing tersimpan dan mencegah Sell kedua pada jalur normal UI.

### D. Validasi tanpa pilihan

Tindakan pengguna:

1. Kembali ke daftar SPB melalui menu Asset Management.
2. Tidak mencentang baris apa pun.
3. Menekan **Sell dari yang dipilih**.

Expected: aplikasi tidak membuka form kosong dan memberi arahan.

Actual: user dikembalikan ke daftar SPB dengan pesan `Pilih minimal satu trip.`.

Bukti: [guard tanpa pilihan](../../../hasil/screenshots/05E-sell-empty-selection-guard.png).

## Rekonsiliasi dan batasan

Read-only check setelah journey mengonfirmasi business 2, Sell `DR2026/0009`, subtype `heavy_equipment_trip`, line jasa dengan quantity 20 dan rate 115000, serta billing link tunggal ke SPB ID 120 dengan amount 2300000.

Sell draft dan SPB billed dipertahankan sebagai handoff karena source billing sudah tercatat dan tidak ada prosedur unlink aman yang boleh digantikan dengan SQL. Tidak ada transaksi posted yang dibuka kuncinya. Data ini tidak boleh dipakai membuat Sell kedua.

## Masalah umum

| Masalah | Yang harus dilakukan |
|---|---|
| SPB tidak memiliki checkbox | Pastikan status `Verified unbilled`, business aktif benar, dan SPB belum ditagihkan. |
| Tombol dipencet tanpa memilih SPB | Centang minimal satu baris; aplikasi akan menampilkan `Pilih minimal satu trip.`. |
| Produk jasa tidak ada | Buat atau minta admin menyiapkan produk tipe jasa/tanpa stok melalui modul Produk. |
| Sell berhasil tetapi tidak terlihat di All sales | Cari di **Sell → List Drafts** karena status awalnya Draft. |
| Total berbeda | Cocokkan Ton, Harga/Ton, dan Total pada preview. Penyesuaian SPB memakai nilai snapshot yang ditampilkan aplikasi. |
| Ingin memasukkan Bonus Ayak ke invoice | Jangan. Bonus Ayak adalah komponen biaya/payroll dan margin Project, bukan line tagihan pelanggan. |

## Inventaris screenshot dan privasi

| Jenis | Lokasi | Catatan |
|---|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05E-20260911/raw/` | Form, detail, guard, desktop, dan ponsel. |
| PNG anotasi publik | `public/images/change-log/release-2026-09-10-angkutan/` | Form desktop/ponsel berupa PNG hasil Python dengan penanda bernomor. |
| PNG detail internal | `storage/app/changelog-evidence/RUN05E-20260911/annotations/` | Tidak dipasang sebagai asset publik karena detail memuat field kontak demo. |
| Bukti tahap | `hasil/screenshots/05E-*.png` | Screenshot asli dan hasil render anotasi. |

Asset publik form hanya memakai fixture demo sintetis; tidak memuat password, email, atau data pelanggan/sopir nyata. Detail yang memuat nomor kontak tetap internal.

## Pemeriksaan tambahan

| Pemeriksaan | Hasil |
|---|---|
| Journey UI pilih SPB → isi form → Buat Sell | Lulus |
| List Drafts, buka detail, reload | Lulus |
| Guard tanpa pilihan | Lulus |
| Source menjadi billed dan tidak dapat dipilih ulang | Lulus |
| Screenshot desktop/ponsel dan proses anotasi PNG via Python | Lulus |
| `php -d extension=mbstring -l config/change_log.php` | Perlu dijalankan pada pemeriksaan tahap setelah konten ini |
| `php -d extension=mbstring artisan view:cache --no-interaction` | Perlu dijalankan pada pemeriksaan tahap setelah konten ini |
| `php -d extension=mbstring artisan route:cache --no-interaction` | Perlu dijalankan pada pemeriksaan tahap setelah konten ini |
| `php -d extension=mbstring artisan migrate --pretend --no-interaction` | Perlu dijalankan pada pemeriksaan tahap setelah konten ini |
| `python -m graphify update .` | Perlu dijalankan pada pemeriksaan tahap setelah konten ini |
| Publish JavaScript module | Tidak diperlukan; tahap ini tidak mengubah module JavaScript |

## Handoff

Gunakan hasil ini untuk [05F — Panduan Bonus Ayak](../05F-PANDUAN-BONUS-AYAK.md). Sell `DR2026/0009` dan SPB ID 120 jangan disentuh pada tahap Bonus Ayak. Tahap berikutnya harus memakai SPB yang belum dikaitkan ke Bonus Ayak untuk menguji relasi tanpa pembayaran ganda.
