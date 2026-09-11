# 02J — Uji posting Payroll dan kunci sumber

## Tujuan dan lingkup

Selesaikan hanya uji posting payroll dan kunci sumber. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01E-HASIL.md, hasil/02I-HASIL.md. Peta pemilik: [01E](01E-AUDIT-SETTLEMENT-DAN-LAPORAN.md), [02I](02I-UJI-DRAFT-SETTLEMENT-DAN-PEMBATALAN.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buat draft baru lewat UI memakai sumber yang tersedia; review lalu tekan posting.
2. Buka Payroll: allowance upah SPB dan Bonus, deduction Kasbon, final_total = netto; reload.
3. Coba posting ulang, settlement kedua atas sumber sama, edit/hapus sumber dan posted settlement; coba pintu payroll terkait.

## Bukti dan hasil wajib

Simpan hasil/02J-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Tepat satu payroll, nominal cocok dan sumber terkunci; posted tidak dibuka paksa untuk cleanup.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
