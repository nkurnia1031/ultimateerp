# 04A — Bangun penyimpanan status baca

## Tujuan dan lingkup

Selesaikan hanya bangun penyimpanan status baca. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/03C-HASIL.md. Peta pemilik: [03C](03C-BANGUN-DAN-UJI-DETAIL-ARTIKEL.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buat migration additive user_id/business_id/release_id/read_at dengan unique gabungan dan tipe FK aktual.
2. POST /change-log/read memakai CSRF, user/business session, hanya release_ids terbit eligible, simpan idempotent secara transaksional.
3. Uji teknis terarah termasuk invalid ID/CSRF; catat bahwa journey browser belum diuji sampai 04B.

## Bukti dan hasil wajib

Simpan hasil/04A-HASIL.md sesuai format 00: ringkasan handoff, file/kontrak berubah, perintah, exit code dan hasil teknis nyata; bedakan dari journey UI yang belum dilakukan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Status baca scoped dan GET tetap read-only; tidak memalsukan kelulusan UX dari test backend.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
