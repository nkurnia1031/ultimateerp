# Hasil 03B — Overview Change Log

## Status

**LULUS.** Overview Change Log sudah dibangun dan diuji melalui browser pada akun admin dan akun terbatas. Rilis yang boleh dilihat tampil sebagai kartu ringkas, akun tanpa permission melihat empty state yang aman, dan reload tidak memicu request POST atau memuat transaksi pelanggan.

Tahap ini hanya mencakup overview. Navbar, pulse, status baca di server, dan isi panduan lengkap tetap menjadi scope 04 dan 03C.

## Perubahan yang dibuat

- `config/change_log.php` memakai katalog rilis terkurasi dengan permission `asset.view`.
- `app/Support/ChangeLogCatalog.php` menyaring rilis berdasarkan status published, module, permission, dan subscription permission jika diisi.
- `resources/views/change-log/index.blade.php` menampilkan judul, tanggal, kategori, ringkasan manfaat, serta tombol `Lihat penjelasan dan cara pakai`.
- Overview diberi label area, focusable link, `aria-labelledby`, ukuran tombol yang nyaman, dan aturan responsive untuk layar kecil.
- Rilis awal tetap memakai ID `release-2026-09-10-angkutan` dan slug `angkutan-spb-dan-bonus-ayak`.

Tidak ada CMS, editor konten, atau data invoice/customer yang dimasukkan ke katalog.

## Fixture dan business

- Akun admin: sesi `changelog02i`, business aktif `CV BM TRANSPORT` (`business_id=2`).
- Akun terbatas: akun demonstrasi sementara `run03b_limited`, role `Driver`, business aktif `CV BM TRANSPORT` (`business_id=2`). Akun dibuat melalui halaman User Management hanya untuk uji lalu dihapus kembali melalui tombol Delete dan konfirmasi UI.
- Password akun uji tidak dicatat pada dokumen.
- Tidak ada transaksi operasional baru yang dibuat pada tahap ini.

## Uji browser dan hasil

### 1. Overview admin — desktop, reload, dan tautan lanjutan

Tindakan pengguna pada sesi `changelog02i`:

1. Membuka aplikasi lokal dan login sebagai admin.
2. Membuka `GET /change-log` melalui navigasi browser.
3. Memastikan kartu rilis angkutan menampilkan tanggal `10-09-2026`, judul, ringkasan, manfaat, dan tombol `Lihat penjelasan dan cara pakai`.
4. Reload halaman.
5. Sebagai pemeriksaan lanjutan dari hasil 03A, menekan tombol detail dan memeriksa URL slug tetap.

Expected:

- Overview hanya menampilkan rilis eligible dari katalog.
- Kartu tetap ada setelah reload.
- Tombol membawa user ke `/change-log/angkutan-spb-dan-bonus-ayak`.
- Isi kartu tidak menampilkan nama customer, nomor invoice, nominal transaksi, sopir, atau data pelanggan.

Actual:

- Semua elemen tampil dan bertahan setelah reload.
- URL detail menggunakan slug tetap dan halaman merespons HTTP 200, sesuai bukti 03A.
- Isi overview hanya berisi penjelasan fitur umum.

Bukti: [overview desktop setelah reload](../../../hasil/screenshots/03B-overview-desktop-after-reload.png).

### 2. Overview admin — layar ponsel

Pada sesi admin yang sama, viewport diubah melalui perintah browser menjadi `390 × 844`, lalu overview diambil ulang.

Expected:

- Judul dan ringkasan tidak terpotong secara tidak terbaca.
- Kartu tidak melebar keluar layar.
- Tombol detail tetap dapat dibaca dan mudah ditekan.

Actual:

- Kartu berada dalam lebar layar, teks membungkus dengan baik, dan tombol memenuhi lebar area kartu.
- Navbar dan footer tetap terlihat tanpa menutupi isi overview.
- Tidak ada horizontal overflow yang terlihat pada screenshot.

Bukti: [overview layar ponsel](../../../hasil/screenshots/03B-overview-mobile-final.png).

### 3. Akun tanpa permission — empty state

Untuk menguji penyaringan akses, admin membuat akun demonstrasi melalui UI:

1. Membuka `User Management → Users → Add`.
2. Mengisi nama `RUN03B Limited`, username `run03b_limited`, email demonstrasi, role `Driver`, dan hanya lokasi `CV BM TRANSPORT`.
3. Menyimpan user melalui tombol `Save`.
4. Login melalui sesi browser baru.
5. Membuka `/change-log` dan reload.
6. Kembali ke sesi admin, mencari `RUN03B`, menekan `Delete`, membatalkan/menutup alur bila perlu, kemudian menjalankan Delete dan menekan `OK` pada dialog konfirmasi.
7. Memastikan daftar user menampilkan `No matching records found` untuk pencarian `RUN03B`.

Expected:

- User Driver tanpa `asset.view` tidak melihat kartu rilis angkutan.
- Aplikasi menampilkan pesan yang menjelaskan bahwa belum ada perubahan yang diterbitkan untuk akun tersebut.
- Tidak ada error 500 atau akses tidak sengaja ke isi artikel.

Actual:

- Setelah login, halaman tetap HTTP 200 dan menampilkan `Belum ada perubahan yang diterbitkan untuk akun Anda.`.
- Menu yang tidak relevan tidak muncul pada sidebar akun tersebut.
- Screenshot empty state tersedia sebelum cleanup.
- Setelah cleanup melalui UI, user `run03b_limited` tidak lagi muncul pada pencarian admin.

Bukti: [empty state akun terbatas](../../../hasil/screenshots/03B-overview-limited-empty.png) dan [empty state setelah reload](../../../hasil/screenshots/03B-overview-limited-empty-reload.png).

### 4. GET tidak menandai baca

Pemeriksaan network tambahan dilakukan setelah navigasi dan reload overview pada sesi admin serta setelah reload pada sesi terbatas.

Request Change Log yang teramati berupa GET document, termasuk:

```text
GET /change-log 200
```

Tidak ada POST status baca pada tahap ini. Ini sesuai pembagian tahap: penyimpanan status baca dan tombol navbar baru dibangun pada 04A–04C. Tidak ada klaim bahwa status baca sudah tersedia sebelum tahap tersebut.

## Perbaikan UX yang diterapkan

- Tombol detail dibuat memiliki area klik penuh pada layar kecil.
- Judul kartu dibuat block agar wrapping tidak bertabrakan dengan tombol.
- Struktur heading/region diberi label aksesibilitas untuk pembaca layar.
- Artikel yang belum memenuhi permission tidak ditampilkan sebagai kartu kosong atau tautan yang menyesatkan.

## Pemeriksaan teknis tambahan

- `php -l app/Support/ChangeLogCatalog.php` — lulus.
- `php -l app/Http/Controllers/ChangeLogController.php` — lulus.
- `php artisan migrate --pretend --no-interaction` — lulus, tidak ada migrasi tertunda.
- `php artisan view:cache --no-interaction` — lulus.
- `php artisan route:cache --no-interaction` — lulus.
- `python -m graphify update .` — lulus; Graphify memperbarui graph. Peringatan dependency `tree_sitter_sql` tetap ada dan tidak menghalangi update.
- JavaScript module tidak berubah pada tahap ini, sehingga tidak ada publish module.

## Batas tahap dan handoff

- Isi detail artikel masih kontrak ringkas dari 03A; panduan awam, langkah bernomor, contoh angka, kesalahan umum, dan tautan fitur dikerjakan pada 03C.
- Permission yang diuji adalah permission `asset.view` pada business aktif. Subscription filter belum memiliki fixture terpisah karena rilis ini tidak memakai subscription permission.
- Status baca server belum ada dan sengaja tidak diuji sebagai fitur selesai; 04A harus membangun penyimpanan per user, business, dan release ID.
- Tidak ada data uji yang perlu dipertahankan dari tahap ini. Akun sementara sudah dibersihkan melalui UI.

## Tahap berikutnya

Jalankan [03C — Bangun dan uji detail artikel](../03C-BANGUN-DAN-UJI-DETAIL-ARTIKEL.md) secara terpisah. Pertahankan slug dan permission overview, lalu tulis panduan berdasarkan perilaku UI yang sudah terbukti pada hasil 02A–02N.
