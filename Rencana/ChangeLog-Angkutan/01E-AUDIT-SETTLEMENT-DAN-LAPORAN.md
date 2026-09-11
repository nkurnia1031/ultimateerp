# 01E — Audit settlement dan laporan

## Tujuan dan lingkup

Selesaikan hanya audit settlement dan laporan. Read-only: jangan mengubah aplikasi atau menjalankan transaksi browser.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01C-HASIL.md, hasil/01D-HASIL.md. Peta pemilik: [01C](01C-AUDIT-BILLING-PROJECT-DAN-SELL.md), [01D](01D-AUDIT-BONUS-AYAK-DAN-KASBON.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Telusuri pemilihan trip/bonus/kasbon, sumber dikeluarkan, reservasi draft, batal dan posting Payroll.
2. Audit bruto/netto, pembulatan/periode, nol/negatif, unique constraint, transaksi bersamaan dan kunci edit/hapus semua pintu masuk.
3. Petakan rekap/ledger asset dan Project: revenue, upah, bonus, kontribusi tanpa hitung ganda.

## Bukti dan hasil wajib

Simpan hasil/01E-HASIL.md sesuai format 00: ringkasan handoff, source/route/schema, menu/permission, perbedaan rancangan-versus-aktual, gap dan calon artikel/skenario; tandai perilaku belum diuji.

Jangan menandai perilaku aplikasi lulus dari pembacaan kode saja.

## Kriteria lulus dan berhenti

Kontrak settlement dan rekonsiliasi tersedia; celah diberi skenario reproduksi.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
