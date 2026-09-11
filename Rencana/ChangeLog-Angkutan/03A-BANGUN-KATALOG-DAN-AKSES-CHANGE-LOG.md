# 03A — Bangun katalog dan akses Change Log

## Tujuan dan lingkup

Selesaikan hanya bangun katalog dan akses change log. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01F-HASIL.md. Peta pemilik: [01F](01F-AUDIT-WADAH-CHANGE-LOG-DAN-NAVBAR.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Periksa implementasi aktual; buat/pakai katalog kurasi release_id, slug tetap, judul, manfaat, kategori, urutan, permission, view dan tanggal terbit sah.
2. Sediakan route login GET /change-log dan /change-log/{slug}; filter permission/module/subscription terpusat; slug tidak dikenal/tidak berhak 404.
3. GET read-only, tanpa CMS/transaksi pelanggan, tanpa path view dari input. Rilis belum sah tetap draft internal.

## Bukti dan hasil wajib

Simpan hasil/03A-HASIL.md sesuai format 00: ringkasan handoff, file/kontrak berubah, perintah, exit code dan hasil teknis nyata; bedakan dari journey UI yang belum dilakukan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Kontrak katalog/akses teruji terarah; konten belum dinyatakan final.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
