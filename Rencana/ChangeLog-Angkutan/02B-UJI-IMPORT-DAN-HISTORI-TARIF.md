# 02B — Uji import dan histori tarif

## Tujuan dan lingkup

Selesaikan hanya uji import dan histori tarif. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01A-HASIL.md, hasil/02A-HASIL.md. Peta pemilik: [01A](01A-AUDIT-MASTER-DAN-TARIF.md), [02A](02A-SIAPKAN-SESI-DAN-MASTER-UJI.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Upload workbook melalui UI, tunggu worker/preview, cocokkan unit, periksa error baris/harga kosong dan aktifkan dengan tanggal eksplisit.
2. Uji pecahan unit, duplikasi rute-unit-periode, import ulang, histori/export/rollback jika tersedia pada batch uji terpisah.
3. Siapkan versi tarif lama/baru dan catat ID serta tanggal untuk SPB; jalankan CRUD yang tersedia.

## Bukti dan hasil wajib

Simpan hasil/02B-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Tarif tersimpan sesuai preview dan periode, error tidak diam-diam diimpor.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
