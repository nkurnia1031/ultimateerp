# Hasil 06A — Verifikasi navigasi dan aset panduan

## Status

**LULUS.** Alur ikon `!` → overview Change Log → detail artikel → fitur terkait → kembali berhasil diuji melalui `agent-browser` pada desktop dan ponsel.

## Fixture dan sesi

- Run browser: `RUN06A-20260911`, sesi `changelog05j`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Artikel: `angkutan-spb-dan-bonus-ayak`, rilis 10-09-2026.
- Tidak ada transaksi baru dibuat pada tahap ini.

## Journey browser dan hasil

| Langkah user | Expected | Actual | Status |
|---|---|---|---|
| Klik ikon `!` di navbar desktop | Overview Change Log terbuka | Overview tampil dengan rilis Angkutan dan link detail | LULUS |
| Klik `Lihat penjelasan dan cara pakai` | Detail artikel terbuka | Slug detail terbuka, istilah SPB/Verifikasi/Settlement dan langkah penggunaan tampil | LULUS |
| Klik `Buka SPB Angkutan` | Fitur terkait terbuka | Browser menuju daftar SPB Angkutan | LULUS |
| Kembali lalu klik `Buka Rekap Alat & Sopir` | Link terkait kedua terbuka | Browser menuju Rekap Alat & Sopir | LULUS |
| Klik `Kembali ke Change Log` | Overview kembali | Overview tampil lagi | LULUS |
| Ulangi pada viewport 390×844 | Navigasi dan artikel tetap terbaca | Overview dan detail tampil tanpa tombol terhalang | LULUS |

## Pemeriksaan aset

- Overview dan detail memakai gambar PNG beranotasi hasil raster Pillow.
- Gambar panduan memiliki alt text dan keterangan teks.
- Tidak ada SVG/HTML overlay yang dijadikan aset final.
- Ikon `!` tetap berdampingan dengan area notifikasi; notifikasi lama tidak diganti.
- Screenshot berisi data demonstrasi lokal dan hanya untuk bukti internal.

## Bukti

Raw browser:

- `storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-overview.png`
- `storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-detail.png`
- `storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-overview-mobile.png`
- `storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-detail-mobile.png`

PNG anotasi:

- [Overview desktop](screenshots/05K-change-log-overview-annotated.png)
- [Detail desktop](screenshots/05K-change-log-detail-annotated.png)
- [Overview ponsel](screenshots/05K-change-log-overview-mobile-annotated.png)
- [Detail ponsel](screenshots/05K-change-log-detail-mobile-annotated.png)

Semua anotasi diproses ulang dengan `python scripts/annotate_changelog_screenshots.py` dan Pillow dari raw capture.

## Masalah tersisa

Tidak ada blocker link atau gambar. Sebelum publikasi eksternal, gunakan fixture anonim atau redaksi tambahan.

## Tahap berikutnya

Lanjutkan ke [06B — regresi invoice Project lama](../06B-REGRESI-INVOICE-PROJECT-LAMA.md).
