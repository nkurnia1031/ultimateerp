# 06D — Regresi payroll bulanan

## Tujuan dan lingkup

Selesaikan hanya regresi payroll bulanan. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02J-HASIL.md. Peta pemilik: [02J](02J-UJI-POSTING-PAYROLL-DAN-KUNCI-SUMBER.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Lewat UI jalankan payroll bulanan biasa dengan fixture tanpa settlement angkutan.
2. Simpan/buka ulang, cocokkan allowance/deduction/netto dan kunci transaksi sesuai status.
3. Pastikan perubahan settlement tidak menduplikasi sumber/pembayaran; catat sumber dan hasil.

## Bukti dan hasil wajib

Simpan hasil/06D-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Payroll bulanan terbukti tersimpan, bukan sekadar halaman terbuka.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
