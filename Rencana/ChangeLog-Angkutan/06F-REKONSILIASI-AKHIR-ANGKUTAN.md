# 06F — Rekonsiliasi akhir angkutan

## Tujuan dan lingkup

Selesaikan hanya rekonsiliasi akhir angkutan. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02N-HASIL.md, hasil/05K-HASIL.md. Peta pemilik: [02N](02N-UJI-REKAP-DAN-LEDGER.md), [05K](05K-TULIS-OVERVIEW-DAN-PANDUAN-IKON.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Baca ID dan nilai hasil 02N; buka ulang detail serta laporan lewat agent-browser.
2. Cocokkan SPB/invoice/Sell/upah/Bonus/Kasbon/settlement/ledger dan contoh artikel final.
3. Jika perubahan setelah pengujian memengaruhi nominal, jalankan ulang hanya skenario pemilik yang diminta pada sesi terpisah; tandai belum lulus sampai bukti baru ada.

## Bukti dan hasil wajib

Simpan hasil/06F-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Tidak ada hitung ganda atau contoh artikel yang berbeda dari data final.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
