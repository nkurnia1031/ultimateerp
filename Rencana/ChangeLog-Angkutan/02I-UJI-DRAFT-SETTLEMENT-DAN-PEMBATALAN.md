# 02I — Uji draft settlement dan pembatalan

## Tujuan dan lingkup

Selesaikan hanya uji draft settlement dan pembatalan. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01E-HASIL.md, hasil/02C-HASIL.md, hasil/02G-HASIL.md, hasil/02H-HASIL.md. Peta pemilik: [01E](01E-AUDIT-SETTLEMENT-DAN-LAPORAN.md), [02C](02C-UJI-FORM-DAN-VERIFIKASI-SPB.md), [02G](02G-UJI-BONUS-AYAK-MANUAL-DAN-SUMBER-SPB.md), [02H](02H-UJI-KASBON.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Pilih sopir/periode; centang upah SPB, Bonus, Kasbon dan keluarkan sebagian item termasuk seluruh kategori.
2. Cocokkan bruto/netto: contoh upah 1.400.000 + Bonus valid 50.000 − Kasbon 100.000 = 1.350.000; jangan memaksa nominal lewat DB.
3. Simpan draft, buka ulang, uji edit jika tersedia. Batalkan draft memakai tombol; cek semua sumber yang dilepas tersedia lagi.
4. Uji netto nol dan Kasbon melebihi hak sesuai keputusan bisnis audit, bukan asumsi.

## Bukti dan hasil wajib

Simpan hasil/02I-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Pilihan sumber bertahan dan batal melepas reservasi; belum posting Payroll pada bagian ini.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
