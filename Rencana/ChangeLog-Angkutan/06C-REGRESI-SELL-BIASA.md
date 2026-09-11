# 06C — Regresi Sell biasa

## Tujuan dan lingkup

Selesaikan hanya regresi sell biasa. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02F-HASIL.md, hasil/03C-HASIL.md. Peta pemilik: [02F](02F-UJI-SELL-ANGKUTAN.md), [03C](03C-BANGUN-DAN-UJI-DETAIL-ARTIKEL.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Lewat UI buat Sell biasa tanpa SPB dengan fixture; simpan, reload dan cocokkan total/line.
2. Uji CRUD draft yang tersedia dan penolakan perubahan transaksi terkunci.
3. Ulangi angkutan Sell hanya bila terdampak perubahan terbaru.

## Bukti dan hasil wajib

Simpan hasil/06C-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Sell non-angkutan tetap bekerja; tidak memicu pembayaran eksternal.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
