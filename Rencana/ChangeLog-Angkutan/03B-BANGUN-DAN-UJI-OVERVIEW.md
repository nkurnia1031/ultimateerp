# 03B — Bangun dan uji overview

## Tujuan dan lingkup

Selesaikan hanya bangun dan uji overview. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/03A-HASIL.md. Peta pemilik: [03A](03A-BANGUN-KATALOG-DAN-AKSES-CHANGE-LOG.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Bangun halaman overview per rilis terbaru dengan manfaat dan tautan Lihat penjelasan dan cara pakai.
2. Pakai agent-browser untuk login, navigasi daftar, empty state akun terbatas dan reload pada desktop/ponsel.
3. Pastikan tidak ada transaksi pelanggan sebagai konten dan GET tidak menandai baca.

## Bukti dan hasil wajib

Simpan hasil/03B-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Overview responsif dan hanya artikel eligible; jangan membangun navbar pada bagian ini.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
