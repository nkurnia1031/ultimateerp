# Hasil 05K - Overview Change Log dan ikon navbar

## Status

**LULUS dengan catatan publikasi.** Ikon `!` di sebelah notifikasi dapat diklik melalui browser, membuka overview Change Log, meneruskan ke detail panduan, dan kembali ke overview. Isi yang tampil memakai Bahasa Indonesia sederhana serta menjelaskan alur master tarif, perjalanan/SPB, invoice, Bonus Ayak, Payroll, dan rekap.

## Journey browser

- Run: `RUN05K-20260911`, sesi `changelog05j`, business `CV BM TRANSPORT` ID 2.
- Dari halaman Settlement Payroll, ikon `!` terlihat tepat di sebelah tombol notifikasi.
- Klik ikon `!` melalui UI membuka `GET /change-log`.
- Overview menampilkan rilis `Angkutan · 10-09-2026`, judul, manfaat, dan link `Lihat penjelasan dan cara pakai`.
- Klik link tersebut membuka detail `angkutan-spb-dan-bonus-ayak`.
- Detail memuat arti SPB, Verifikasi, Settlement, persiapan, langkah, contoh angka, hasil yang diharapkan, dan masalah umum.
- Klik `Kembali ke Change Log` mengembalikan user ke overview.

## Expected/actual

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Ikon navbar | Ikon `!` tidak mengubah notifikasi lama dan mudah ditemukan | Ikon tampil di samping notifikasi dan link berlabel `Perubahan terbaru aplikasi` | LULUS |
| Overview | Ringkasan rilis dengan manfaat dan link detail | Rilis 10-09-2026 tampil dengan ringkasan alur angkutan | LULUS |
| Detail | Bahasa awam dan langkah yang dapat diikuti | Detail menjelaskan SPB, verifikasi, settlement, invoice, Bonus Ayak, Payroll, dan rekap | LULUS |
| Kembali | User dapat kembali tanpa menebak navigasi | `Kembali ke Change Log` mengembalikan overview | LULUS |
| Pemisahan nominal | Bonus Ayak tidak dijanjikan sebagai line invoice | Detail menyebut Bonus sebagai biaya/payroll dan margin, bukan tagihan pelanggan | LULUS |

## Artikel dan release

- `release_id`: `release-2026-09-10-angkutan`.
- Slug: `angkutan-spb-dan-bonus-ayak`.
- Tanggal tampil: `10-09-2026`, mengikuti katalog aplikasi dan bukti fitur.
- Konten terkurasi tersimpan di `config/change_log.php`; tidak ada CMS/editor yang dibuat.
- Status artikel tetap `published` pada local demo sesuai konfigurasi yang sedang diuji; gate kesiapan deployment final tetap berada di tahap 06.

## Bukti screenshot

Raw browser:

- `storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-overview.png`
- `storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-detail.png`

PNG anotasi diproses ulang dengan Pillow:

- [Overview Change Log dan ikon](../../../hasil/screenshots/05K-change-log-overview-annotated.png)
- [Detail panduan Change Log](../../../hasil/screenshots/05K-change-log-detail-annotated.png)

Contoh perintah:

```text
python scripts/annotate_changelog_screenshots.py --preset 05K-change-log-overview --input storage/app/changelog-evidence/RUN05K-20260911/raw/05K-change-log-overview.png --output hasil/screenshots/05K-change-log-overview-annotated.png
```

Anotasi final adalah PNG raster dengan legenda di pita atas yang dibuat Python/Pillow. Tidak digunakan SVG, overlay HTML, atau gambar pengganti UI. Salinan tersedia pada folder annotations internal, `Rencana/ChangeLog-Angkutan/hasil/screenshots/`, dan `public/images/change-log/release-2026-09-10-angkutan/`.

## Privasi dan catatan gate

Screenshot hanya menampilkan nama business demo, judul rilis, dan istilah fitur; tidak ada password, rekening, atau data pribadi pelanggan/sopir. Artikel tidak memuat transaksi pelanggan. Variasi permission, pulse unread/read, business lain, dan reduced-motion sudah memiliki bukti pada tahap 04C/hasil terkait dan harus tetap diregresikan pada tahap 06.

Lanjutkan ke [06A - Verifikasi navigasi dan aset panduan](../06A-VERIFIKASI-NAVIGASI-DAN-ASET-PANDUAN.md).
