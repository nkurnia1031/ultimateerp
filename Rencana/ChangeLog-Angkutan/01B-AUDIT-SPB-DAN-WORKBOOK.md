# 01B — Audit SPB dan workbook

## Tujuan dan lingkup

Selesaikan hanya audit spb dan workbook. Read-only: jangan mengubah aplikasi atau menjalankan transaksi browser.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01A-HASIL.md. Peta pemilik: [01A](01A-AUDIT-MASTER-DAN-TARIF.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Petakan input/import/preview/verifikasi HeavyEquipmentTrip dan status aktual.
2. Bandingkan dua workbook Cangkang di ref dengan parser: tiket, polisi, sopir, sheet/baris, fingerprint, sheet duplikat, unmatched.
3. Telusuri gross/tare/netto, ton diterima/tagihan, toleransi, klaim, potongan, nilai akhir dan snapshot; catat ambiguitas tanpa menebak.

## Bukti dan hasil wajib

Simpan hasil/01B-HASIL.md sesuai format 00: ringkasan handoff, source/route/schema, menu/permission, perbedaan rancangan-versus-aktual, gap dan calon artikel/skenario; tandai perilaku belum diuji.

Jangan menandai perilaku aplikasi lulus dari pembacaan kode saja.

## Kriteria lulus dan berhenti

Kontrak perhitungan, mapping dan jalur CRUD/verifikasi terpetakan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
