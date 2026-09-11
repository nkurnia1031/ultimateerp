# 07 — Remediasi blocker final

## Tujuan dan batasan

Dokumen ini hanya dijalankan jika blocker pada [06G](06G-PEMERIKSAAN-TEKNIS-FINAL.md) atau [06H](06H-CLEANUP-LOKAL-DAN-SERAH-TERIMA.md) perlu ditutup. Jangan mengulang seluruh journey angkutan dan jangan mengubah data production.

Blocker yang tercatat:

1. PHP CLI belum memiliki ekstensi `mbstring`, sehingga PHPUnit dan `migrate:status` berhenti sebelum assertion/output status.
2. Export rekap sudah mengembalikan HTTP 200 attachment, tetapi artifact CSV belum tersimpan dan dibaca sel per sel.
3. User browser demo tidak memiliki tombol Add maintenance, sehingga create maintenance belum terbukti melalui UI.

## Prasyarat

- Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md), [06G](06G-PEMERIKSAAN-TEKNIS-FINAL.md), dan [06H](06H-CLEANUP-LOKAL-DAN-SERAH-TERIMA.md).
- Pastikan root `D:/Laragon/www/2025/ultimateerp` dan URL lokal benar.
- Gunakan sesi `agent-browser` khusus serta run-id baru, misalnya `RUN07-YYYYMMDD`.
- Jangan memasukkan password, data customer/sopir nyata, atau file export berisi data operasional ke public Change Log.

## A. Perbaiki environment PHP CLI

1. Catat `php --ini` dan `php -v` tanpa membagikan secret.
2. Aktifkan extension `mbstring` pada `php.ini` PHP CLI yang benar-benar dipakai oleh Laragon. Jangan mengubah source aplikasi untuk menggantikan extension.
3. Tutup dan buka ulang shell bila diperlukan.
4. Pastikan `php -m` menampilkan `mbstring`.
5. Jalankan ulang:

   ```text
   php artisan test --no-interaction
   php artisan migrate:status --no-interaction
   ```

6. Catat exit code dan output. Jika test gagal pada assertion, perlakukan sebagai temuan aplikasi baru dan buat perbaikan terpisah; jangan menyebutnya blocker extension lagi.

## B. Verifikasi artifact export melalui UI

1. Login memakai akun lokal yang berwenang melalui `agent-browser`.
2. Buka **Asset Management → Rekap Alat & Sopir**.
3. Isi filter fixture yang sudah ada: business 2, asset 39, driver 93, Project 11, tanggal 11-09-2026. Semua field harus dipilih melalui UI.
4. Tekan **Filter**, lalu tekan **Export Rekap** melalui UI.
5. Simpan attachment ke folder internal `storage/app/changelog-evidence/<run-id>/downloads/` menggunakan mekanisme download `agent-browser`.
6. Baca file CSV secara read-only dengan tool lokal. Cocokkan minimal header dan baris SPB yang tampil pada UI; jangan menulis kembali database.
7. Reload halaman dan pastikan export tidak mengubah status billing/payroll.
8. Jika tool tidak dapat menyimpan attachment, catat tetap sebagai `BELUM DIVERIFIKASI FILE`; response HTTP 200 saja tidak boleh dinaikkan menjadi lulus artifact.

## C. Uji create maintenance dengan permission yang tepat

1. Jangan mengubah role akun operasional. Minta/siapkan akun uji lokal khusus yang memiliki permission create maintenance melalui UI User Management/Role bila mekanisme itu tersedia.
2. Login pada sesi browser baru dengan akun uji tersebut dan pastikan business aktif tetap business fixture lokal.
3. Buka **Asset Management → Asset maintenance**.
4. Jika tombol **Add** tampil, buat satu maintenance bertanda `CL-ANGKUTAN-RUN07`, pilih asset uji, isi status/priority/note, simpan, buka detail, reload, edit note, reload, lalu hapus melalui tombol UI dan konfirmasi dialog.
5. Uji pembatalan dialog hapus pada fixture kedua bila tombol dan permission tersedia.
6. Jika Add tetap tidak tersedia, catat `BELUM DIUJI / GAP PERMISSION` beserta akun/permission yang teramati. Jangan membuat row melalui SQL, HTTP, controller, service, atau JavaScript.
7. Jangan menghapus maintenance existing `2025/0002` atau master asset yang masih dipakai.

## Bukti dan hasil wajib

Simpan `hasil/07-HASIL.md` yang berisi:

- run-id, sesi browser, business, akun/permission, dan tanggal;
- output `php -m`, PHPUnit, dan `migrate:status` setelah perbaikan environment;
- tindakan browser lengkap untuk export dan/atau maintenance;
- nama file CSV, ukuran, header, baris yang direkonsiliasi, atau alasan artifact tidak tersedia;
- ID fixture create/edit/delete maintenance jika berhasil;
- screenshot raw dari browser dan PNG anotasi yang diproses dengan Pillow;
- expected/actual sebelum, sesudah, dan setelah reload;
- cleanup melalui UI serta item yang sengaja dipertahankan;
- blocker yang tetap terbuka dan dampaknya pada Change Log.

## Aturan screenshot

Raw wajib berasal dari keadaan yang dicapai melalui agent-browser. Semua penanda harus diproses ulang dari raw PNG menggunakan `scripts/annotate_changelog_screenshots.py` atau utilitas Python/Pillow setara. Hasil yang disematkan hanya PNG raster; SVG, overlay HTML, dan lapisan DOM tidak boleh menjadi aset final.

## Kriteria berhenti

- Berhenti setelah blocker A, B, dan C masing-masing berstatus LULUS, GAGAL, atau BELUM DIUJI dengan bukti.
- Jika environment sudah pulih tetapi assertion gagal, buat tugas perbaikan terpisah dan jangan mengedit data untuk membuat test lulus.
- Jangan deployment otomatis. Handoff kembali ke review operator setelah `07-HASIL.md` tersimpan.
