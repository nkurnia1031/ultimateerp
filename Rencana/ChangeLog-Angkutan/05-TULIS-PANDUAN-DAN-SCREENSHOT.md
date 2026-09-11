# 05-TULIS-PANDUAN-DAN-SCREENSHOT — Indeks bagian kecil

## Cara menggunakan

Baca [petunjuk bersama](00-PETUNJUK-PELAKSANAAN.md), kemudian pilih tepat satu dokumen. Indeks ini bukan instruksi menjalankan semua bagian atau membaca semua dokumen anak.

- [05A — Panduan master unit dan tarif](05A-PANDUAN-MASTER-UNIT-DAN-TARIF.md) — hasil prasyarat: 02B, 03C.
- [05B — Panduan input dan verifikasi SPB](05B-PANDUAN-INPUT-DAN-VERIFIKASI-SPB.md) — hasil prasyarat: 02C, 03C.
- [05C — Panduan import SPB](05C-PANDUAN-IMPORT-SPB.md) — hasil prasyarat: 02D, 03C.
- [05D — Panduan SPB dan invoice Project](05D-PANDUAN-SPB-DAN-INVOICE-PROJECT.md) — hasil prasyarat: 02E, 03C.
- [05E — Panduan Sell angkutan](05E-PANDUAN-SELL-ANGKUTAN.md) — hasil prasyarat: 02F, 03C.
- [05F — Panduan Bonus Ayak](05F-PANDUAN-BONUS-AYAK.md) — hasil prasyarat: 02G, 03C.
- [05G — Panduan Kasbon](05G-PANDUAN-KASBON.md) — hasil prasyarat: 02H, 03C.
- [05H — Panduan draft dan batal settlement](05H-PANDUAN-DRAFT-DAN-BATAL-SETTLEMENT.md) — hasil prasyarat: 02I, 03C.
- [05I — Panduan posting Payroll](05I-PANDUAN-POSTING-PAYROLL.md) — hasil prasyarat: 02J, 03C.
- [05J — Panduan rekap dan ledger](05J-PANDUAN-REKAP-DAN-LEDGER.md) — hasil prasyarat: 02N, 03C.
- [05K — Tulis overview dan panduan ikon](05K-TULIS-OVERVIEW-DAN-PANDUAN-IKON.md) — hasil prasyarat: 04C, 05A, 05B, 05C, 05D, 05E, 05F, 05G, 05H, 05I, 05J.

## Hasil dan batas

Setiap bagian menyimpan hasil/<ID>-HASIL.md sendiri: bukti, gap, fixture dan checkpoint. Tidak wajib membuat laporan gabungan besar. Jangan melanjutkan tanpa permintaan pengguna. Hasil yang belum ada/gagal tidak boleh diasumsikan lulus.

Kelompok selesai hanya jika bagian terkait punya hasil wajib dan bukti kelulusan. Indeks ini tidak menyatakan fitur aplikasi sudah selesai.

Standar gambar untuk seluruh bagian: screenshot raw harus dicapai lewat `agent-browser`, kemudian anotasi diproses ulang dari raw PNG menggunakan `scripts/annotate_changelog_screenshots.py`. Panduan hanya menyematkan PNG hasil Python; SVG, overlay HTML, dan lapisan DOM bukan format anotasi yang boleh dipakai.
