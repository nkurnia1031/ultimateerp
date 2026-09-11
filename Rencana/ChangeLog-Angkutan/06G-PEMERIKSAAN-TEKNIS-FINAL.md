# 06G — Pemeriksaan teknis final

## Tujuan dan lingkup

Selesaikan hanya pemeriksaan teknis final. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/06A-HASIL.md, hasil/06B-HASIL.md, hasil/06C-HASIL.md, hasil/06D-HASIL.md, hasil/06E-HASIL.md, hasil/06F-HASIL.md. Peta pemilik: [06A](06A-VERIFIKASI-NAVIGASI-DAN-ASET-PANDUAN.md), [06B](06B-REGRESI-INVOICE-PROJECT-LAMA.md), [06C](06C-REGRESI-SELL-BIASA.md), [06D](06D-REGRESI-PAYROLL-BULANAN.md), [06E](06E-REGRESI-MAINTENANCE-DAN-NOTIFIKASI.md), [06F](06F-REKONSILIASI-AKHIR-ANGKUTAN.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

Gate tambahan wajib: baca ringkasan hasil/02K-HASIL.md, hasil/02L-HASIL.md dan hasil/02M-HASIL.md untuk [isolasi akses](02K-UJI-ISOLASI-AKSES.md), [konkurensi billing](02L-UJI-KONKURENSI-SUMBER-BILLING.md), dan [konkurensi Bonus/Payroll](02M-UJI-KONKURENSI-BONUS-DAN-PAYROLL.md). Ketiganya harus lulus; jangan melewatkannya karena bukan alur artikel. Jangan menjalankan ulang otomatis jika hasil belum tersedia.

1. Dari root lokal jalankan php -l/node --check file berubah, migrate --pretend --no-interaction, view:cache dan route:cache --no-interaction serta test relevan.
2. Untuk migration baru tinjau SQL dan verifikasi apply lokal; migration schema bukan jalan pintas membuat fixture UI. Gunakan PHP/extension tepat; bila subprocess runner bermasalah gunakan phpunit langsung.
3. Publish setiap module JS berubah memakai php scripts/publish-module-js.php <Module> [file.js], pastikan byte-identical; graphify update .; git diff --check dan cek file sementara.
4. Pisahkan kegagalan lama dari regresi dengan bukti; jangan memperbaiki bagian lain sekadar meluluskan cache/test.

## Bukti dan hasil wajib

Simpan hasil/06G-HASIL.md sesuai format 00: ringkasan handoff, file/kontrak berubah, perintah, exit code dan hasil teknis nyata; bedakan dari journey UI yang belum dilakukan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Exit code dan hasil nyata dilaporkan; pemeriksaan teknis tidak menggantikan browser.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
