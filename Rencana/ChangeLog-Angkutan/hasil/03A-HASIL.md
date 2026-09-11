# Hasil 03A — Katalog dan Akses Change Log

## Status

**LULUS terarah.** Kontrak katalog, login, permission/module filter terpusat, route overview, route detail, dan 404 slug sudah dibuat serta diuji. Penataan isi panduan lengkap menjadi scope 03B–03C.

## Perubahan yang dibuat

- `config/change_log.php`: katalog kurasi release yang memuat `release_id`, slug tetap, judul, ringkasan manfaat, kategori, urutan, `published_at`, status, module, permission, dan tautan fitur terkait.
- `app/Support/ChangeLogCatalog.php`: satu tempat untuk menyaring status published, module terpasang, permission, subscription, dan tautan tindakan yang boleh dilihat user.
- `app/Http/Controllers/ChangeLogController.php`: endpoint overview dan detail tanpa CMS, transaksi pelanggan, atau path view dari input.
- `routes/web.php`: route authenticated `change-log.index` dan `change-log.show` dengan pembatas slug `[a-z0-9-]+`.
- `resources/views/change-log/index.blade.php`: daftar rilis.
- `resources/views/change-log/show.blade.php`: detail kontrak awal dan tautan fitur terkait.

Rilis awal yang dikurasi:

- ID: `release-2026-09-10-angkutan`.
- Slug: `angkutan-spb-dan-bonus-ayak`.
- Status: `published`.
- Tanggal terbit yang ditampilkan: `10-09-2026`.
- Kategori: `Angkutan`.

## Uji browser

### User authenticated

Sesi `agent-browser` yang sudah login pada business `CV BM TRANSPORT` membuka:

`/change-log`

Hasil aktual:

- halaman berhasil dibuka;
- satu rilis angkutan tampil;
- judul, ringkasan, manfaat, tanggal, dan tombol `Lihat penjelasan dan cara pakai` tampil;
- tidak ada transaksi pelanggan yang dimuat sebagai isi katalog.

Tombol tersebut kemudian diklik melalui browser. URL berubah menjadi:

`/change-log/angkutan-spb-dan-bonus-ayak`

Hasil aktual detail:

- judul dan metadata rilis tampil;
- tautan `Buka SPB Angkutan` dan `Buka Rekap Alat & Sopir` tampil untuk admin business aktif;
- tidak ada tautan ke route yang berasal dari input user.

Bukti:

- [Overview katalog](../../../hasil/screenshots/03A-change-log-overview.png)
- [Detail katalog](../../../hasil/screenshots/03A-change-log-detail-contract.png)

### User belum login

Sesi browser baru membuka `/change-log` tanpa autentikasi. Aplikasi mengarahkan browser ke `/login` dan menampilkan form `Username`, `Password`, dan `Login`.

Hasil ini membuktikan GET Change Log tidak terbuka untuk user anonim.

### Slug tidak dikenal

Sesi admin membuka `/change-log/slug-tidak-ada`. Hasil aktual:

- HTTP/browser page `404`;
- halaman menampilkan `Not Found` dan `404 NOT FOUND`;
- tidak ada fallback ke artikel lain.

## Validasi teknis

- `php -l app/Support/ChangeLogCatalog.php` — lulus.
- `php -l app/Http/Controllers/ChangeLogController.php` — lulus.
- `php artisan view:cache --no-interaction` — lulus.
- `php artisan route:cache --no-interaction` — lulus.
- `php artisan migrate --pretend --no-interaction` — lulus, tidak ada migrasi tertunda.
- `python -m graphify update .` — lulus; Graphify memperbarui graph menjadi 9.469 node dan 19.707 edge. Ada peringatan dependency `tree_sitter_sql` yang tidak terpasang.

## Batas pengujian tahap ini

- Layout overview belum diperlakukan sebagai tampilan final panduan; perbaikan copy, langkah pemakaian, manfaat, dan struktur artikel dilakukan pada 03B–03C.
- Filtering berdasarkan user tanpa permission asset dan filtering subscription belum dibuatkan fixture baru; jalur filter terpusat sudah berada di `ChangeLogCatalog`, sedangkan pengujian cross-role/subscription tetap perlu dilakukan bila fixture tersedia.
- Status baca, ikon navbar, pulse, screenshot anotasi, dan verifikasi akhir belum dikerjakan pada tahap ini.

## Handoff

03B boleh memakai route dan release ID yang sudah ada untuk menyempurnakan overview. 03C harus mempertahankan slug `angkutan-spb-dan-bonus-ayak` dan menambahkan detail panduan tanpa mengubah kontrak akses atau memuat data transaksi nyata.
