# 06E — Regresi maintenance dan notifikasi

## Tujuan dan lingkup

Selesaikan hanya regresi maintenance dan notifikasi. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/04C-HASIL.md, hasil/02A-HASIL.md. Peta pemilik: [04C](04C-UJI-VARIASI-NAVBAR-DAN-KEGAGALAN.md), [02A](02A-SIAPKAN-SESI-DAN-MASTER-UJI.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Lewat UI buat/edit maintenance asset uji, simpan/reload dan uji hapus/batal sesuai izin.
2. Buka notifikasi lama: dropdown/count/navigasi tetap berjalan pada desktop/ponsel tanpa mengirim notifikasi nyata.
3. Catat fixture maintenance dan perubahan status baca notifikasi uji.

## Bukti dan hasil wajib

Simpan hasil/06E-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Maintenance dan notifikasi tidak mengalami regresi.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
