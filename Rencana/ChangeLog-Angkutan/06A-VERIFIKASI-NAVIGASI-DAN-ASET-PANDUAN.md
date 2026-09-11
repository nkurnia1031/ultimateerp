# 06A — Verifikasi navigasi dan aset panduan

## Tujuan dan lingkup

Selesaikan hanya verifikasi navigasi dan aset panduan. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/05K-HASIL.md. Peta pemilik: [05K](05K-TULIS-OVERVIEW-DAN-PANDUAN-IKON.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Agent-browser desktop/ponsel: ikon → overview → setiap artikel → fitur → kembali; catat hasil per tautan.
2. Periksa gambar/alt/caption/label, permission, data sensitif dan siklus baca normal; ulangi variasi 04C jika kode navbar berubah.
3. Jika artikel/UI gagal, tandai artikel terdampak dan bagian pemilik untuk uji ulang.

## Bukti dan hasil wajib

Simpan hasil/06A-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Semua link/asset dan akses sesuai; daftar temuan spesifik, bukan audit ulang seluruh kode.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
