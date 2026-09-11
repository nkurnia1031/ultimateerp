# Hasil 05I - Panduan posting Payroll

## Status

**LULUS.** Settlement gabungan yang sudah direview diposting melalui tombol aplikasi ke Payroll Essentials. Daftar settlement, detail Payroll, dan detail SPB diperiksa kembali melalui browser. Sumber yang sudah posted tetap terkunci untuk mencegah pembayaran ganda.

## Handoff

- Run browser: `RUN05I-20260911`.
- Sesi: `changelog05i`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Driver: `RUN02A Driver Revised`, user ID `93`.
- SPB: ID `121`, `TRIP-202609-0005`.
- Bonus Ayak: ID `885` dan `886`.
- Kasbon: ID `19`.
- Settlement posted: `SET-202609-0007`.
- Payroll transaction: ID `54295`.

## Journey browser dan expected/actual

| Tindakan user melalui browser | Expected | Actual setelah reload | Status |
|---|---|---|---|
| Pilih sumber yang sudah direview | SPB/Bonus/Kasbon yang dipilih tampil pada preview | SPB 121, Bonus 885/886, dan Kasbon 19 terpilih | LULUS |
| Periksa preview | Bruto dan netto cocok dengan sumber | Upah trip Rp1.950.000, Bonus Rp50.000, bruto Rp2.000.000, Kasbon Rp300.037, netto Rp1.699.963 | LULUS |
| Tekan `Buat Settlement Draft` | Draft tersimpan sebelum posting | Settlement `SET-202609-0007` muncul pada daftar | LULUS |
| Tekan `Post Payroll` dan konfirmasi dialog | Payroll dibuat dan sumber dikunci | Daftar menampilkan `POSTED`, 1 SPB, Bonus Rp50.000, Kasbon Rp300.037, bruto Rp2.000.000, netto Rp1.699.963 | LULUS |
| Buka `Lihat Payroll` | Allowance/deduction dapat direkonsiliasi | Payroll ID 54295 menampilkan Basic salary Rp650.000, Uang Jalan Rp1.300.000, Bonus Ayak Rp50.000, Kasbon Bonus Ayak Rp300.037; earnings Rp2.000.000, deductions Rp300.037, net Rp1.699.963 | LULUS |
| Buka kembali SPB | Snapshot tarif dan status tidak berubah | SPB 121 tetap VERIFIED dan menampilkan `Sudah masuk settlement SET-202609-0007` | LULUS |
| Reload halaman | Posted tidak kembali eligible | Settlement tetap POSTED dan detail SPB tetap terkunci | LULUS |

Rumus yang terbukti:

```text
Basic salary dari 10 ton       = 10 x Rp65.000  = Rp650.000
Uang Jalan dari 1 trip         = 1 x Rp1.300.000 = Rp1.300.000
Bonus Ayak                     = Rp50.000
Total earnings/bruto           = Rp2.000.000
Kasbon                         = Rp300.037
Net payroll                    = Rp1.699.963
```

Pendapatan pelanggan SPB Rp1.150.000 tidak dimasukkan ke Payroll. Pendapatan tersebut tetap milik alur invoice/Sell; Payroll hanya memuat hak sopir dan potongan.

## Bukti screenshot

Raw internal:

- `storage/app/changelog-evidence/RUN05I-20260911/raw/05I-settlement-selected-for-post.png`
- `storage/app/changelog-evidence/RUN05I-20260911/raw/05I-settlement-posted-list.png`
- `storage/app/changelog-evidence/RUN05I-20260911/raw/05I-settlement-posted-row.png`
- `storage/app/changelog-evidence/RUN05I-20260911/raw/05I-payroll-detail.png`
- `storage/app/changelog-evidence/RUN05I-20260911/raw/05I-spb-after-payroll.png`

Screenshot daftar posted diambil ulang setelah scroll melalui browser agar row `SET-202609-0007` dan tombol `Lihat Payroll` terlihat. Semua gambar panduan di bawah diproses dengan Pillow dari raw PNG:

- [Settlement berhasil diposting](../../../hasil/screenshots/05I-settlement-posted-list-annotated.png)
- [Row settlement POSTED](../../../hasil/screenshots/05I-settlement-posted-row-annotated.png)
- [Detail Payroll](../../../hasil/screenshots/05I-payroll-detail-annotated.png)
- [SPB setelah Payroll](../../../hasil/screenshots/05I-spb-after-payroll-annotated.png)

Contoh perintah anotasi:

```text
python scripts/annotate_changelog_screenshots.py --preset 05I-settlement-posted-row --input storage/app/changelog-evidence/RUN05I-20260911/raw/05I-settlement-posted-row.png --output hasil/screenshots/05I-settlement-posted-row-annotated.png
```

Hasil anotasi juga dicadangkan di `storage/app/changelog-evidence/RUN05I-20260911/annotations/`, `Rencana/ChangeLog-Angkutan/hasil/screenshots/`, dan `public/images/change-log/release-2026-09-10-angkutan/`. Metode final hanya Python/Pillow raster compositing; tidak ada SVG atau overlay HTML.

## Penguncian dan cleanup

Setelah posting, SPB 121, Bonus 885/886, dan Kasbon 19 tidak dihapus atau dibuka kuncinya. Ini adalah fixture handoff yang diperlukan untuk rekap dan ledger. Kasbon ID20 dan ID21 belum diposting dan tetap menjadi kandidat cleanup UI pada tahap 06H.

Lanjutkan ke [05J - Panduan rekap dan ledger](../05J-PANDUAN-REKAP-DAN-LEDGER.md).
