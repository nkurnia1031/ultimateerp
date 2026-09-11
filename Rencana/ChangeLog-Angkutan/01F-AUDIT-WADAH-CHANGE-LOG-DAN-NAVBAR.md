# 01F — Audit wadah Change Log dan navbar

## Tujuan dan lingkup

Selesaikan hanya audit wadah change log dan navbar. Read-only: jangan mengubah aplikasi atau menjalankan transaksi browser.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Tidak memerlukan hasil bagian lain; pastikan lingkungan/source aktual tersedia.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Periksa implementasi Change Log yang sudah ada, auth/business, layout dan semua navbar yang memuat notifikasi.
2. Petakan permission/module/subscription serta route dan layanan navbar yang dapat dipakai ulang.
3. Rancang kontrak katalog release_id/slug/tanggal terbit dan status baca tanpa membangun aplikasi.

## Bukti dan hasil wajib

Simpan hasil/01F-HASIL.md sesuai format 00: ringkasan handoff, source/route/schema, menu/permission, perbedaan rancangan-versus-aktual, gap dan calon artikel/skenario; tandai perilaku belum diuji.

Jangan menandai perilaku aplikasi lulus dari pembacaan kode saja.

## Kriteria lulus dan berhenti

Peta file/akses navbar dan kontrak katalog tersedia; tanggal belum diketahui tetap belum ditentukan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
