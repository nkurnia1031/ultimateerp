# 04C — Uji variasi navbar dan kegagalan

## Tujuan dan lingkup

Selesaikan hanya uji variasi navbar dan kegagalan. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/04B-HASIL.md. Peta pemilik: [04B](04B-BANGUN-IKON-DAN-ALUR-BACA.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Uji user/business lain, akun tanpa artikel, dua sesi, desktop/ponsel, keyboard/reduced motion.
2. Buat rilis demonstrasi baru melalui katalog kode, bukan SQL status; buktikan pulse kembali dan urutan rilis lama tidak memicu unread baru.
3. Ganggu jaringan sesi uji saat klik normal; artikel tetap terbaca, status belum read; pulihkan dan ulangi.
4. Tes request CSRF/invalid/idempotensi hanya tambahan; kembalikan katalog demonstrasi dan catat status baca fixture.

## Bukti dan hasil wajib

Simpan hasil/04C-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Semua variasi terbukti; tidak mengubah notifikasi atau membuka kunci data.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
