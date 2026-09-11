# 02H — Uji Kasbon

## Tujuan dan lingkup

Selesaikan hanya uji kasbon. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01D-HASIL.md, hasil/02A-HASIL.md. Peta pemilik: [01D](01D-AUDIT-BONUS-AYAK-DAN-KASBON.md), [02A](02A-SIAPKAN-SESI-DAN-MASTER-UJI.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buat Kasbon sopir uji melalui UI, buka daftar/detail.
2. Edit nominal lalu reload, batal dialog hapus, hapus fixture terpisah dan cek rekap.
3. Uji validasi/selector business dan siapkan Kasbon untuk settlement tanpa memotongnya dulu.

## Bukti dan hasil wajib

Simpan hasil/02H-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

CRUD dan nominal Kasbon terbukti; ID sumber tersedia untuk payroll.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
