# 05K — Tulis overview dan panduan ikon

## Tujuan dan lingkup

Selesaikan hanya tulis overview dan panduan ikon. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/04C-HASIL.md, hasil/05A-HASIL.md, hasil/05B-HASIL.md, hasil/05C-HASIL.md, hasil/05D-HASIL.md, hasil/05E-HASIL.md, hasil/05F-HASIL.md, hasil/05G-HASIL.md, hasil/05H-HASIL.md, hasil/05I-HASIL.md, hasil/05J-HASIL.md. Peta pemilik: [04C](04C-UJI-VARIASI-NAVBAR-DAN-KEGAGALAN.md), [05A](05A-PANDUAN-MASTER-UNIT-DAN-TARIF.md), [05B](05B-PANDUAN-INPUT-DAN-VERIFIKASI-SPB.md), [05C](05C-PANDUAN-IMPORT-SPB.md), [05D](05D-PANDUAN-SPB-DAN-INVOICE-PROJECT.md), [05E](05E-PANDUAN-SELL-ANGKUTAN.md), [05F](05F-PANDUAN-BONUS-AYAK.md), [05G](05G-PANDUAN-KASBON.md), [05H](05H-PANDUAN-DRAFT-DAN-BATAL-SETTLEMENT.md), [05I](05I-PANDUAN-POSTING-PAYROLL.md), [05J](05J-PANDUAN-REKAP-DAN-LEDGER.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Baca ringkasan handoff artikel saja; tulis overview singkat manfaat setiap perubahan, mulai master tarif, dengan tautan detail.
2. Jelaskan ikon ! dan pulse; ambil screenshot ikon/overview lewat agent-browser. Proses raw screenshot menjadi PNG beranotasi menggunakan `scripts/annotate_changelog_screenshots.py`; gunakan standar gambar aman/alt text yang sama seperti artikel. Jangan memakai SVG atau overlay HTML.
3. Cocokkan release_id dan tanggal publikasi dengan bukti rilis; bila belum terbit tetap draft, jangan mengarang tanggal atau klaim.

## Bukti dan hasil wajib

Simpan hasil/05K-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan. Sertakan file artikel, slug/release_id, contoh angka dan sumber hasil uji, inventaris raw/publik/anotasi serta review privasi.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Overview/artikel tetap draft internal bila kontrol keamanan terkait pada hasil 02K/02L/02M belum lulus. Penulisan bukan izin publikasi atau pengumuman siap rilis; gate akhir ada pada 06G/06H.

Overview hanya menjanjikan fitur yang terbukti; tidak menulis ulang seluruh artikel.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
