# 02A — Siapkan sesi dan master uji

## Tujuan dan lingkup

Selesaikan hanya siapkan sesi dan master uji. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01A-HASIL.md. Peta pemilik: [01A](01A-AUDIT-MASTER-DAN-TARIF.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Verifikasi lokal, baca bantuan agent-browser, login dengan sesi khusus dan catat viewport.
2. Buat melalui UI fixture run-id: unit, sopir, customer, Project, lokasi dan produk jasa; buat akun terbatas/business pembanding bila jalur tersedia.
3. Jalankan CRUD master yang tersedia termasuk batal hapus; simpan ID dan business untuk bagian berikutnya.

## Bukti dan hasil wajib

Simpan hasil/02A-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Fixture tersedia lewat UI; gap persiapan dilaporkan tanpa seed/backend bypass.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
