# 02K — Uji isolasi akses

## Tujuan dan lingkup

Selesaikan hanya uji isolasi akses. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01C-HASIL.md, hasil/01D-HASIL.md, hasil/01E-HASIL.md, hasil/02A-HASIL.md. Peta pemilik: [01C](01C-AUDIT-BILLING-PROJECT-DAN-SELL.md), [01D](01D-AUDIT-BONUS-AYAK-DAN-KASBON.md), [01E](01E-AUDIT-SETTLEMENT-DAN-LAPORAN.md), [02A](02A-SIAPKAN-SESI-DAN-MASTER-UJI.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Dengan sesi business berbeda uji selector/link/detail SPB, Project, Sell, Bonus, Kasbon dan settlement menggunakan ID fixture tercatat.
2. Dengan akun input-only, billing-only dan payroll-only coba navigasi serta tombol terkait.
3. Tambahkan request ID dimodifikasi sebagai tes keamanan terpisah; DB read-only/log hanya bukti pendukung.

## Bukti dan hasil wajib

Simpan hasil/02K-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Tidak ada baca/tulis lintas business atau bypass permission; laporkan per endpoint tanpa mengklaim dari dropdown saja.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
