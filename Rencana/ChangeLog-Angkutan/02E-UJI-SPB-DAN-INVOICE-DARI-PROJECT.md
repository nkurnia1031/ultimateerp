# 02E — Uji SPB dan invoice dari Project

## Tujuan dan lingkup

Selesaikan hanya uji spb dan invoice dari project. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01C-HASIL.md, hasil/02C-HASIL.md. Peta pemilik: [01C](01C-AUDIT-BILLING-PROJECT-DAN-SELL.md), [02C](02C-UJI-FORM-DAN-VERIFIKASI-SPB.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buka Project uji → Buat SPB; pastikan Project/customer terisi; input dan verifikasi melalui UI.
2. Pilih SPB untuk invoice: hanya Project ini, verified dan belum billed; uji SPB dengan klaim/potongan.
3. Konfirmasi, buka detail invoice, reload dan cocokkan nilai akhir/billing link. Coba memilih ulang dan mengedit/menghapus sumber terpakai.

## Bukti dan hasil wajib

Simpan hasil/02E-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Invoice sama nilai akhir SPB dan sumber terkunci; jangan membuat Sell pada bagian ini.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
