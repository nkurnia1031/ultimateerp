# 01A — Audit master dan tarif

## Tujuan dan lingkup

Selesaikan hanya audit master dan tarif. Read-only: jangan mengubah aplikasi atau menjalankan transaksi browser.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Tidak memerlukan hasil bagian lain; pastikan lingkungan/source aktual tersedia.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Petakan assets/users, selector business dan permission, serta jalur CRUD master.
2. Periksa workbook LIST UANG JALAN PT BMT 2026.xlsx tanpa macro: validasi klaim 91 baris/29 rute, pemisahan unit dengan titik koma, tanggal berlaku, harga kosong, duplikasi dan histori.
3. Catat parser, route, service, menu aktual, gap UI dan calon artikel master/tarif.

## Bukti dan hasil wajib

Simpan hasil/01A-HASIL.md sesuai format 00: ringkasan handoff, source/route/schema, menu/permission, perbedaan rancangan-versus-aktual, gap dan calon artikel/skenario; tandai perilaku belum diuji.

Jangan menandai perilaku aplikasi lulus dari pembacaan kode saja.

## Kriteria lulus dan berhenti

Matriks master/tarif punya rujukan source dan expected value; angka workbook tidak diasumsikan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
