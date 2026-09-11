# 02D — Uji import SPB

## Tujuan dan lingkup

Selesaikan hanya uji import spb. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01B-HASIL.md, hasil/02B-HASIL.md. Peta pemilik: [01B](01B-AUDIT-SPB-DAN-WORKBOOK.md), [02B](02B-UJI-IMPORT-DAN-HISTORI-TARIF.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Upload dua workbook Cangkang lewat UI menggunakan salinan demonstrasi; periksa sheet dan nomor baris.
2. Cocokkan nomor polisi/sopir; pastikan unmatched terlihat dan baris error tidak menggagalkan semua file.
3. Konfirmasi hanya baris valid; reload daftar/detail. Upload ulang dan uji Sheet1 duplikat/fingerprint serta export rekap.

## Bukti dan hasil wajib

Simpan hasil/02D-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Tiket/tanggal/tonase/tarif/sopir sesuai sumber dan tidak ada duplikasi tersembunyi.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
