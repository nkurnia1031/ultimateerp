# 02M — Uji konkurensi Bonus dan Payroll

## Tujuan dan lingkup

Selesaikan hanya uji konkurensi bonus dan payroll. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02G-HASIL.md, hasil/02J-HASIL.md. Peta pemilik: [02G](02G-UJI-BONUS-AYAK-MANUAL-DAN-SUMBER-SPB.md), [02J](02J-UJI-POSTING-PAYROLL-DAN-KUNCI-SUMBER.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Dengan dua tab/sesi coba simpan dua Bonus memakai SPB sama.
2. Pada fixture lain coba dua reservasi/settlement memakai sumber sama serta dua posting draft sama; gunakan tombol UI.
3. Reload sumber, Bonus, settlement dan Payroll; catat waktu/hasil dan cek read-only jumlah transaksi/pivot.

## Bukti dan hasil wajib

Simpan hasil/02M-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Sumber tidak dibayar ganda dan tidak ada orphan; keterbatasan overlap ditulis.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
