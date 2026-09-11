# 01C — Audit billing Project dan Sell

## Tujuan dan lingkup

Selesaikan hanya audit billing project dan sell. Read-only: jangan mengubah aplikasi atau menjalankan transaksi browser.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01B-HASIL.md. Peta pemilik: [01B](01B-AUDIT-SPB-DAN-WORKBOOK.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Telusuri Project → SPB → invoice serta Sell angkutan; pertahankan invoice non-angkutan.
2. Periksa verified/unbilled, scope customer/project/business dan permission.
3. Bandingkan nilai akhir SPB termasuk klaim/potongan dengan invoice lines; periksa penguncian dan sumber billing ganda lintas kedua jalur.

## Bukti dan hasil wajib

Simpan hasil/01C-HASIL.md sesuai format 00: ringkasan handoff, source/route/schema, menu/permission, perbedaan rancangan-versus-aktual, gap dan calon artikel/skenario; tandai perilaku belum diuji.

Jangan menandai perilaku aplikasi lulus dari pembacaan kode saja.

## Kriteria lulus dan berhenti

Kontrak billing dan risiko nominal/ganda didukung source aktual.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
