# 04B — Bangun ikon dan alur baca

## Tujuan dan lingkup

Selesaikan hanya bangun ikon dan alur baca. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/04A-HASIL.md. Peta pemilik: [04A](04A-BANGUN-PENYIMPANAN-STATUS-BACA.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Pasang partial ! tepat di sebelah notifikasi pada navbar aktual; link overview, tooltip/aria-label/fokus/target nyaman sekitar 44px.
2. Pulse lingkaran lembut tanpa layout shift/pointer blocking; hormati reduced motion. Gunakan asset helper; jangan mengubah count/dropdown notif.
3. Setelah overview berhasil render, JS aplikasi POST rilis ditampilkan; padamkan pulse hanya sukses.
4. Agent-browser: user unread klik ikon → overview → reload; ikon tetap ada dan pulse berhenti. Jangan memanggil API manual untuk menandai baca.

## Bukti dan hasil wajib

Simpan hasil/04B-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Alur normal tersimpan server dan notifikasi tetap bekerja.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
