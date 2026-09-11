# 02C — Uji form dan verifikasi SPB

## Tujuan dan lingkup

Selesaikan hanya uji form dan verifikasi spb. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01B-HASIL.md, hasil/02B-HASIL.md. Peta pemilik: [01B](01B-AUDIT-SPB-DAN-WORKBOOK.md), [02B](02B-UJI-IMPORT-DAN-HISTORI-TARIF.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buat SPB manual: isi tiket, tanggal, rute, unit, sopir, tonase; uji CRUD draft dan batal hapus.
2. Uji gross-tare, ton tagihan dan klaim/potongan nonnol dari kontrak audit; reload perhitungan. Contoh dasar 20 ton × 115.000 = 2.300.000; upah 20 × 65.000 + 100.000 = 1.400.000 jika input tarif tersebut disiapkan lewat UI.
3. Uji unit tidak sesuai/tarif kosong/input negatif/tanggal salah; selesaikan dan verifikasi memakai tombol.
4. Pilih tarif lama/baru berdasarkan tanggal, ubah master uji kemudian pastikan snapshot SPB selesai tidak berubah.

## Bukti dan hasil wajib

Simpan hasil/02C-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Perhitungan dan snapshot benar; input salah ditolak; fixture verified diserahkan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
