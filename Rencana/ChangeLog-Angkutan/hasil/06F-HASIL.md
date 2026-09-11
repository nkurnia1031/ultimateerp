# Hasil 06F — Rekonsiliasi akhir angkutan

## Status

**LULUS dengan catatan.** SPB, invoice Project, Sell, Bonus Ayak, Kasbon, settlement Payroll, Project ledger, dan rekap unit berhasil dibuka kembali melalui browser dan nilai utama konsisten. Bukti export CSV dari tahap 02N tetap berstatus response HTTP terverifikasi, bukan file yang dibaca sel per sel.

## Fixture dan business

- Run browser: `RUN06F-20260911`, sesi `changelog06d`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- Project: `RUN02A Project Angkutan`, project ID `11`.
- Customer: ID `1802`.
- SPB: ID `121`, `TRIP-202609-0005`, tiket `RUN05H-20260911-SETTLE`.
- Sell: `DR2026/0007`, transaction ID `54290`, dari SPB ID `2`.
- Invoice Project: `DR2026/0006`, transaction ID `54289`, dari SPB ID `119`.
- Bonus Ayak: ID `885`/`886`.
- Settlement posted: `SET-202609-0007`, Payroll transaction ID `54295`.

## Hasil UI dan rekonsiliasi

| Sumber | Expected | Actual | Status |
|---|---|---|---|
| SPB ID121 | Snapshot relasi, tonase, harga, nilai, status | 10 ton × Rp115.000 = Rp1.150.000; upah Rp1.950.000; VERIFIED; settlement `SET-202609-0007` | LULUS |
| Sell `DR2026/0007` | Line jasa sesuai SPB Sell | 10 Ton × Rp115.000 = Rp1.150.000; total payable sama | LULUS |
| Project `RUN02A` | Semua trip tampil satu kali | 4 trip, 73 ton, revenue Rp8.480.000, upah Rp9.945.000, Bonus Rp50.000, kontribusi setelah Bonus -Rp1.515.000 | LULUS |
| Bonus Ayak | Terpisah dari invoice pelanggan | ID 885/886 tampil pada Project; Bonus masuk cost/payroll, bukan line invoice | LULUS |
| Kasbon | Hanya menjadi deduction settlement yang dipilih | Settlement posted memakai Rp300.037; Payroll memakai angka sama | LULUS |
| Settlement `SET-0007` | Bruto trip+bonus, netto setelah Kasbon | Bruto Rp2.000.000, Kasbon Rp300.037, netto Rp1.699.963 | LULUS |
| Payroll `54295` | Allowance/deduction dapat ditelusuri | Earnings trip Rp1.950.000 + Bonus Rp50.000; deduction Rp300.037; net Rp1.699.963 | LULUS |
| Ledger unit | Rincian dan status sama | Rekap unit memuat trip, tonase, revenue, upah, Bonus, kontribusi setelah Bonus | LULUS |

## Rumus utama

```text
SPB ID121 revenue = 10 × Rp115.000 = Rp1.150.000
SPB ID121 upah    = (10 × Rp65.000) + Rp1.300.000 = Rp1.950.000
Settlement bruto  = Rp1.950.000 + Rp50.000 = Rp2.000.000
Settlement netto  = Rp2.000.000 - Rp300.037 = Rp1.699.963

Project contribution sebelum Bonus = Rp8.480.000 - Rp9.945.000 = -Rp1.465.000
Project contribution setelah Bonus = -Rp1.465.000 - Rp50.000 = -Rp1.515.000
```

## Bukti browser

- [SPB detail dan settlement](screenshots/06F-spb-detail-bottom-annotated.png)
- [Daftar SPB](screenshots/06F-spb-list-annotated.png)
- [Project trip tab](screenshots/06F-project-trip-tab-annotated.png)
- [Sell detail](screenshots/06F-sell-detail-annotated.png)
- [Bonus Ayak](screenshots/06F-bonus-list-annotated.png)
- [Kasbon](screenshots/06F-kasbon-list-annotated.png)
- [Settlement Payroll](screenshots/06F-settlement-payroll-detail-annotated.png)
- Raw SPB list, Bonus, Kasbon, dan seluruh capture ada di `storage/app/changelog-evidence/RUN06F-20260911/raw/`.

Gambar anotasi dibuat ulang dengan Python/Pillow dari raw screenshot UI.

## Catatan export dan cleanup

Tidak ada transaksi baru dibuat pada 06F. Seluruh sumber billed/settled/posted dipertahankan sebagai handoff dan tidak dibuka kuncinya. Export rekap sebelumnya menghasilkan HTTP 200 attachment CSV, tetapi tool browser belum menyimpan file sehingga isi CSV belum diverifikasi per sel.

## Masalah tersisa

Tidak ada ketidaksesuaian nominal pada sumber yang dibuka ulang. Verifikasi artifact export CSV masih perlu dilakukan bila dokumentasi final wajib menyertakan isi file.

## Tahap berikutnya

Lanjutkan ke [06G — pemeriksaan teknis final](../06G-PEMERIKSAAN-TEKNIS-FINAL.md).
