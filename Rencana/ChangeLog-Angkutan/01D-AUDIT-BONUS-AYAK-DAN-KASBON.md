# 01D — Audit Bonus Ayak dan Kasbon

## Tujuan dan lingkup

Selesaikan hanya audit bonus ayak dan kasbon. Read-only: jangan mengubah aplikasi atau menjalankan transaksi browser.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01B-HASIL.md. Peta pemilik: [01B](01B-AUDIT-SPB-DAN-WORKBOOK.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Petakan driver/asset/project, snapshot legacy, pivot beberapa SPB dan CRUD/import/export.
2. Periksa satu SPB satu sumber Bonus, transaksi atomik bonus/pivot, isolasi business dan unmatched legacy.
3. Periksa model export/import aktual, tipe ID Kasbon sebelum FK, serta penguncian sumber terpakai.

## Bukti dan hasil wajib

Simpan hasil/01D-HASIL.md sesuai format 00: ringkasan handoff, source/route/schema, menu/permission, perbedaan rancangan-versus-aktual, gap dan calon artikel/skenario; tandai perilaku belum diuji.

Jangan menandai perilaku aplikasi lulus dari pembacaan kode saja.

## Kriteria lulus dan berhenti

Peta Bonus/Kasbon memisahkan biaya sopir dari invoice pelanggan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
