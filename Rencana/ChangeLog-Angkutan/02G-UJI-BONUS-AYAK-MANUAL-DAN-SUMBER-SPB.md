# 02G — Uji Bonus Ayak manual dan sumber SPB

## Tujuan dan lingkup

Selesaikan hanya uji bonus ayak manual dan sumber spb. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01D-HASIL.md, hasil/02C-HASIL.md. Peta pemilik: [01D](01D-AUDIT-BONUS-AYAK-DAN-KASBON.md), [02C](02C-UJI-FORM-DAN-VERIFIKASI-SPB.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buat Bonus manual serta Bonus lain dengan beberapa SPB driver/unit/project sesuai; tinjau ringkasan dan simpan.
2. Uji CRUD, batal hapus, selector business dan ketidakcocokan sumber; coba tautkan SPB sama ke Bonus kedua.
3. Uji import preview/export Excel/PDF pada fixture, tampilkan legacy/unmatched tanpa mengubah histori; cek tidak ada class error.

## Bukti dan hasil wajib

Simpan hasil/02G-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Relasi tersimpan, export aman/business-scoped, nominal bukan invoice; sumber tidak ganda.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
