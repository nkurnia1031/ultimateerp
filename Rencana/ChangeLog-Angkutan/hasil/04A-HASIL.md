# Hasil 04A — Penyimpanan Status Baca Change Log

## Status

**LULUS untuk kontrak teknis dan keamanan endpoint; journey UI normal belum dinilai pada tahap ini.** Migration, model, scope user/business/release, validasi eligible release, CSRF middleware, dan operasi idempotent sudah dibuat. Uji klik ikon/navbar dan penyimpanan status melalui UI sengaja ditunda ke 04B.

## Perubahan yang dibuat

- Migration [2026_09_10_000002_create_change_log_reads.php](../../../database/migrations/2026_09_10_000002_create_change_log_reads.php) membuat tabel `change_log_reads` secara additive.
- Model [ChangeLogRead.php](../../../app/ChangeLogRead.php) menyediakan relasi ke user dan business serta cast `read_at`.
- [User.php](../../../app/User.php) memiliki relasi `changeLogReads`.
- [ChangeLogCatalog.php](../../../app/Support/ChangeLogCatalog.php) menyediakan daftar `release_id` yang sudah published dan eligible untuk user aktif.
- [ChangeLogController.php](../../../app/Http/Controllers/ChangeLogController.php) memiliki `markRead` untuk menyimpan status secara transactional dan idempotent dengan `upsert`.
- [routes/web.php](../../../routes/web.php) memiliki `POST /change-log/read` bernama `change-log.read` di dalam middleware `auth`, session business, dan web/CSRF.

## Kontrak data

Tabel menyimpan:

| Kolom | Makna |
| --- | --- |
| `user_id` | user yang membaca |
| `business_id` | business yang aktif saat membaca |
| `release_id` | ID rilis dari katalog kode, bukan input bebas yang menjadi foreign key |
| `read_at` | waktu terakhir ditandai sudah dibaca |
| unique gabungan | satu status per user + business + release |

Foreign key user dan business menggunakan tipe `unsignedInteger` yang sesuai tabel aktual, dengan cascade delete. `release_id` sengaja tidak menjadi FK database karena sumber rilis adalah katalog konfigurasi terkurasi.

## Fixture dan scope

- Business aktif pada sesi uji: `CV BM TRANSPORT` (`business_id=2`).
- User admin: sesi `changelog02i`.
- Release eligible yang dipakai untuk kontrak: `release-2026-09-10-angkutan`.
- Uji negatif tidak membuat row status baca dan tidak mengubah transaksi operasional.

## Uji teknis tambahan

### 1. Request tanpa CSRF

Request dikirim dari browser session admin ke endpoint dengan method POST, tetapi tanpa token CSRF.

Expected: request ditolak oleh middleware sebelum controller menyimpan data.

Actual:

```text
HTTP 419
```

Body yang diterima adalah halaman error 419. Tidak ada row `change_log_reads` yang dibuat dari request ini.

### 2. Release ID tidak eligible

Request dikirim dengan token CSRF dari halaman aktif, tetapi body berisi `release_ids: ["release-not-eligible"]`.

Expected: controller menolak ID yang tidak berasal dari daftar published dan eligible untuk user/business aktif.

Actual:

```text
HTTP 422
Satu atau beberapa rilis tidak tersedia untuk akun atau business aktif ini.
```

Tidak ada row status baca yang dibuat.

### 3. Migration dan cache

Perintah `migrate --pretend` pertama sempat gagal karena dijalankan bersamaan dengan `route:cache` dan cache route sedang diganti. Setelah dijalankan berurutan dengan extension PHP `mbstring` lokal, hasilnya lulus dan menampilkan SQL tabel/constraint yang diharapkan.

Migration lokal kemudian dijalankan:

```text
2026_09_10_000002_create_change_log_reads ... DONE
```

`mbstring` dipakai sebagai flag runtime karena PHP CLI lokal tidak memuat extension tersebut secara default; file aplikasi tidak diubah untuk menyembunyikan masalah environment.

## Validasi teknis dan hasil nyata

- `php -l database/migrations/2026_09_10_000002_create_change_log_reads.php` — lulus.
- `php -l app/ChangeLogRead.php` — lulus.
- `php -l app/Support/ChangeLogCatalog.php` — lulus.
- `php -l app/Http/Controllers/ChangeLogController.php` — lulus.
- `php -d extension=mbstring artisan migrate --pretend --no-interaction` — lulus; SQL migration sesuai kontrak.
- `php -d extension=mbstring artisan migrate --no-interaction` — lulus; tabel dibuat lokal.
- `php artisan route:cache --no-interaction` — lulus.
- `php artisan view:cache --no-interaction` — lulus.
- `python -m graphify update .` — lulus dengan exit code 0; peringatan `tree_sitter_sql` tetap dicatat sebagai dependency Graphify yang tidak terpasang.
- Tidak ada JavaScript module yang berubah; publish module tidak diperlukan pada tahap ini.

## Batas pengujian

- Belum diuji melalui klik ikon karena ikon belum dibuat.
- Belum dibuktikan bahwa GET overview/detail menandai baca; GET tetap tidak memiliki efek write pada controller.
- Belum diuji idempotensi valid melalui dua klik UI; itu adalah journey 04B/04C.
- Belum diuji pergantian business pada browser; endpoint selalu memakai `session('user.business_id')`, dan skenario pergantian business dilakukan setelah navbar tersedia.

## Cleanup dan handoff

- Tidak ada data transaksi baru.
- Tidak ada row status baca dari request negatif.
- Migration `change_log_reads` dipertahankan sebagai prasyarat 04B.
- Jangan menghapus tabel atau mereset migration sebelum 04B selesai.

## Tahap berikutnya

Jalankan [04B — Bangun ikon dan alur baca](../04B-BANGUN-IKON-DAN-ALUR-BACA.md) secara terpisah. Tahap tersebut wajib membuat POST valid melalui interaksi user, memeriksa pulse sebelum/sesudah baca, dan memastikan status tersimpan tanpa mengubah notifikasi lama.
