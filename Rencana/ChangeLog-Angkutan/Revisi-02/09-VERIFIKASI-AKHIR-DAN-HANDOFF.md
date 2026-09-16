# 09 — Verifikasi akhir dan handoff

## Tujuan

Menutup implementasi relasi kontrak dengan bukti teknis, browser, visual, dan instruksi migrasi bertahap yang dapat dijalankan tim berikutnya.

## Prasyarat

- Hasil 01–08 tersedia dan gap yang memengaruhi pengguna sudah diperbaiki atau dinyatakan terbuka.
- Daftar file, migration, route, permission, fixture, screenshot raw/annotated, dan batch mapping sudah terkumpul.

## Langkah kerja

1. Uji navigasi: Change Log/guide → Surat Kontrak → Project → SPB → invoice/Sell/payroll/report → kembali ke guide.
2. Pastikan seluruh selector dan endpoint membatasi business aktif; status null ditampilkan konsisten sebagai `Belum ditentukan`.
3. Pastikan kontrak legacy, SPB tanpa kontrak, dan data dengan relasi lengkap dapat hidup bersamaan selama masa migrasi.
4. Jalankan pemeriksaan relevan: lint PHP/JS, `php artisan migrate --pretend --no-interaction`, `view:cache`, `route:cache`, test otomatis, `node --check`, publish module JS bila berubah, dan `graphify update .`.
5. Verifikasi tidak ada route lama, invoice Project lama, Sell biasa, payroll bulanan, maintenance asset, Bonus Ayak, atau dokumen yang rusak.
6. Periksa setiap screenshot: berasal dari browser, tidak sensitif, anotasi PNG dari Python/Pillow, alt text tersedia, dan tautan pada panduan benar.
7. Bersihkan fixture lokal melalui UI mulai dari transaksi turunan. Jangan menghapus posted payroll/invoice atau membuka kunci hanya demi cleanup.
8. Tulis rencana deployment manual: backup, migration, cache, queue/restart bila diperlukan, smoke test, monitoring log, dan rollback yang tidak merusak histori. Jangan deploy otomatis.

## Hasil wajib

- `hasil/09-HASIL.md` dengan status final per area.
- Daftar file berubah dan alasan.
- Rekonsiliasi ID dan nominal contoh.
- Daftar masalah tersisa, dampak, pemilik tahap, dan keputusan status null.
- Instruksi deployment dan handoff data yang dipertahankan.

## Kriteria lulus

- Semua relasi target telah tersedia atau gap eksplisit disetujui.
- Data lama tidak dipaksa memiliki status.
- Relasi customer, Project, SPB, billing, payroll, Bonus Ayak, asset, dokumen, dan proposal dapat ditelusuri tanpa mencampur makna.
- Bukti browser dan pemeriksaan teknis konsisten.

## Catatan handoff

Tim operasional dapat mulai dari kontrak baru dengan status kosong, lalu mengisi Project/SPB secara bertahap. Pengisian status dan mapping kontrak lama dilakukan per batch setelah user memverifikasi kandidat; tidak ada kewajiban migrasi satu kali penuh.

