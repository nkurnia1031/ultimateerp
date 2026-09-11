# 06B — Regresi invoice Project lama

## Tujuan dan lingkup

Selesaikan hanya regresi invoice project lama. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02E-HASIL.md, hasil/03C-HASIL.md. Peta pemilik: [02E](02E-UJI-SPB-DAN-INVOICE-DARI-PROJECT.md), [03C](03C-BANGUN-DAN-UJI-DETAIL-ARTIKEL.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Lewat UI buat invoice Project non-angkutan dengan fixture; simpan, buka ulang dan cocokkan line/total.
2. Uji edit/batal hapus/hapus yang tersedia pada draft terpisah; jangan ubah invoice operasional.
3. Ulangi jalur angkutan Project hanya bila perubahan terbaru menyentuhnya.

## Bukti dan hasil wajib

Simpan hasil/06B-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Invoice lama tetap bekerja; hasil simpan dibuktikan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
