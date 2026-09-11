# Hasil 03C — Detail Artikel dan Panduan Ringkas

## Status

**LULUS terarah.** Detail artikel sudah menampilkan penjelasan untuk user awam, istilah penting, persiapan, langkah bernomor, contoh angka yang benar-benar berasal dari data uji, hasil yang diharapkan, masalah umum, dan tautan fitur yang sudah disaring berdasarkan akses. Alur overview → detail → fitur → kembali berhasil lewat browser.

Ini masih merupakan draft panduan di dalam aplikasi. Pengambilan screenshot beranotasi dan penulisan panduan per fitur secara terpisah menjadi tahap 05.

## Perubahan yang dibuat

- `config/change_log.php` sekarang menyimpan konten kurasi artikel:
  - siapa yang memakai;
  - definisi SPB, verifikasi, dan settlement;
  - persiapan;
  - enam langkah alur angkutan;
  - hasil yang diharapkan;
  - contoh nominal yang diuji;
  - masalah umum dan penyelesaiannya;
  - penjelasan pemisahan pendapatan pelanggan dan biaya/upah sopir.
- `resources/views/change-log/show.blade.php` merender konten tersebut sebagai bagian yang berlabel, daftar istilah, langkah bernomor, callout contoh, dan tautan kembali.
- CSS detail dibuat responsive; tombol kembali menjadi lebar penuh pada layar kecil dan langkah memiliki nomor visual yang tetap dapat dibaca.
- Semua isi dirender sebagai teks escaped dari katalog kode. Tidak ada HTML/path bebas dari input user.

## Fixture dan business

- Admin sesi `changelog02i`, business `CV BM TRANSPORT` (`business_id=2`).
- Contoh yang ditampilkan: SPB `TRIP-202609-0004` (ID `120`), unit ID `39`, sopir ID `93`, project ID `11`.
- Nilai yang ditampilkan berasal dari bukti 02M/02N: 20 ton, pendapatan pelanggan Rp2.300.000, total upah sopir Rp2.600.000.
- Tidak ada transaksi operasional baru pada tahap ini.
- Akun terbatas sementara `run03c_limited` dengan role Driver dan business 2 dibuat melalui UI untuk uji permission, lalu dihapus melalui UI setelah screenshot selesai. Password tidak dicatat.

## Journey browser

### 1. Overview → detail

Tindakan user:

1. Membuka `/change-log` sebagai admin.
2. Memfokuskan tombol `Lihat penjelasan dan cara pakai` melalui browser lalu menekan `Enter` dengan keyboard.
3. Memastikan URL menjadi `/change-log/angkutan-spb-dan-bonus-ayak`.
4. Reload detail dan memeriksa judul serta bagian panduan.

Expected:

- Keyboard dapat menjalankan link yang sama seperti klik mouse.
- Detail menampilkan judul, tanggal, manfaat, istilah, langkah, contoh, hasil, masalah, dan fitur terkait.

Actual:

- Link aktif melalui `focus` + `press Enter` dan URL detail benar.
- `get text body` menemukan `SPB`, `Verifikasi`, `Settlement`, `Bonus Ayak`, `tagihan pelanggan`, `Contoh yang sudah diuji`, dan `Masalah yang sering terjadi`.
- Reload tidak menghilangkan isi artikel.

Bukti visual: [detail desktop](../../../hasil/screenshots/03C-detail-desktop.png).

### 2. Detail → fitur SPB → kembali

Tindakan user:

1. Dari detail menekan `Buka SPB Angkutan`.
2. Memastikan browser membuka `/asset/fleet/trips`.
3. Memastikan halaman menampilkan `SPB Angkutan`, `Buat Trip`, dan `Import SPB`.
4. Menekan tombol browser Back untuk kembali ke detail.
5. Menekan `Kembali ke Change Log`.
6. Memastikan browser kembali ke `/change-log`.

Actual:

- Tautan SPB bekerja dan endpoint tujuan menampilkan halaman fitur yang benar.
- Browser Back mengembalikan detail tanpa error.
- Tombol kembali mengembalikan overview.

### 3. Detail → fitur rekap → kembali

Tindakan user:

1. Dari overview membuka detail lagi.
2. Menekan `Buka Rekap Alat & Sopir`.
3. Memastikan browser membuka `/asset/fleet/reports`.
4. Memastikan halaman menampilkan heading `Rekap Alat & Sopir`, filter, `Per Unit Alat`, Pendapatan, Upah Sopir, dan Kontribusi.
5. Menggunakan browser Back ke detail dan tombol `Kembali ke Change Log` ke overview.

Actual:

- Tautan rekap bekerja pada business aktif.
- Endpoint tujuan tetap melakukan kontrol aksesnya sendiri; Change Log hanya menyediakan tautan setelah katalog menyaring permission.
- Navigasi kembali berhasil.

### 4. Detail pada layar ponsel

Viewport diubah melalui `agent-browser` menjadi `390 × 844`, lalu detail dibuka dari overview melalui link.

Expected:

- Tombol kembali tidak bertumpuk dengan judul.
- Judul dan paragraf membungkus tanpa keluar dari kartu.
- Nomor langkah tetap terlihat dan tidak menutupi teks.

Actual:

- Tombol `Kembali ke Change Log` menjadi satu baris penuh di atas judul.
- Judul, callout, istilah, dan teks panduan terbaca pada lebar layar kecil.
- Tidak terlihat horizontal overflow pada screenshot.

Bukti visual: [detail ponsel](../../../hasil/screenshots/03C-detail-mobile.png).

### 5. Guest dan slug tidak dikenal

#### Guest

Sesi baru `changelog03a_anon` membuka `/change-log/angkutan-spb-dan-bonus-ayak` tanpa login.

Actual: browser diarahkan ke `/login` dan menampilkan form `Username`, `Password`, dan `Login`. Isi artikel tidak bocor ke user anonim.

#### Slug tidak dikenal

Admin membuka `/change-log/slug-tidak-ada`.

Actual: browser menampilkan title `Not Found`, URL tetap pada slug invalid, dan HTTP/page status 404. Bukti: [slug invalid](../../../hasil/screenshots/03C-detail-invalid-slug.png).

### 6. User tanpa permission

Akun Driver sementara dibuat lewat `User Management → Users → Add`, diberi business 2 tanpa permission `asset.view`, lalu login melalui sesi `changelog03c_limited`.

Actual saat membuka `/change-log/angkutan-spb-dan-bonus-ayak`:

- browser menampilkan halaman 404;
- screenshot menampilkan `404 | NOT FOUND`;
- artikel tidak dapat dibuka dengan menebak slug.

Hasil ini membuktikan detail tidak melewati penyaringan permission overview. Bukti: [detail ditolak untuk user terbatas](../../../hasil/screenshots/03C-detail-limited-404.png).

## Isi panduan yang kini terbukti di halaman

- **SPB** dijelaskan sebagai Surat Perintah Bongkar dan satu perjalanan.
- **Verifikasi** dijelaskan sebagai pemeriksaan sebelum dipakai untuk tagihan pelanggan atau rekap upah.
- **Settlement** dijelaskan sebagai rekap sumber pembayaran per sopir dan periode.
- Langkah memakai label UI yang sudah terlihat: `Buat Trip`, `Selesaikan`, `Verify`, `Pilih SPB untuk Invoice`, `Angkutan dari SPB`, dan `Review Settlement`.
- Pendapatan pelanggan dan upah sopir dipisahkan. Bonus Ayak disebut sebagai biaya/penghasilan sopir untuk payroll dan margin Project, bukan baris invoice pelanggan.
- Contoh nominal tidak menjanjikan angka baru: contoh hanya memakai SPB ID120 yang sudah diuji.

## Pemeriksaan teknis tambahan

- `php -l config/change_log.php` — lulus.
- `php artisan view:cache --no-interaction` — lulus.
- `php artisan migrate --pretend --no-interaction` — lulus, tidak ada migrasi tertunda.
- `php artisan route:cache --no-interaction` — lulus.
- `python -m graphify update .` — perlu dijalankan setelah hasil ini disimpan untuk memperbarui graph terhadap perubahan detail. Peringatan `tree_sitter_sql` boleh dicatat bila tetap muncul.
- Tidak ada JavaScript module yang berubah; publish module tidak diperlukan.

## Cleanup dan handoff

- Akun `run03c_limited` sudah dihapus dari daftar user melalui tombol Delete dan konfirmasi `OK`.
- Tidak ada SPB, Bonus Ayak, invoice, payroll, settlement, asset, atau project baru pada tahap ini.
- Fixture posted dari tahap 02M tetap dipertahankan dan tidak dibuka kuncinya.

## Batas tahap

- Status baca per user/business/release belum ada dan tidak diklaim selesai.
- Ikon `!` dan pulse navbar belum dibuat.
- Screenshot ini belum memiliki anotasi untuk publik; anotasi dan panduan per halaman dibuat pada tahap 05.
- Tautan Project dan Sell dijelaskan dalam teks berdasarkan alur yang sudah diuji di 02E–02F, sedangkan tautan tindakan langsung yang ditampilkan katalog saat ini adalah SPB dan Rekap karena itulah link yang dikonfigurasi dan diuji pada artikel.

## Tahap berikutnya

Jalankan [04A — Bangun penyimpanan status baca](../04A-BANGUN-PENYIMPANAN-STATUS-BACA.md) secara terpisah. Jangan mengubah isi artikel atau menganggap GET detail sebagai aksi baca sebelum endpoint status baca per user/business/release tersedia.
