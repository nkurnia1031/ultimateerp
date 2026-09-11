# Hasil 05J - Panduan rekap dan ledger

## Status

**LULUS setelah perbaikan.** Rekap terfilter, ledger unit, dan tab Trip Angkutan pada Project sudah diperiksa melalui `agent-browser`. Bonus Ayak sekarang ikut mengurangi kontribusi biaya, tetapi tetap ditampilkan terpisah dari pendapatan pelanggan.

## Handoff

- Run browser: `RUN05J-20260911`.
- Sesi: `changelog05j`.
- Business: `CV BM TRANSPORT`, ID `2`.
- Asset: ID `39`, `RUN02A Unit Angkutan Revised`.
- Driver: ID `93`, `RUN02A Driver Revised`.
- Project: ID `11`, `RUN02A Project Angkutan`.
- Sumber utama: SPB 121 dan Bonus Ayak 885/886; semua sudah berstatus settlement posted pada handoff 05I.

## Journey browser dan hasil

| Area | Tindakan user | Expected | Actual setelah filter/reload | Status |
|---|---|---|---|---|
| Rekap Alat & Sopir | Buka menu, isi tanggal 11-09-2026, pilih unit, sopir, Project, tekan Filter | Hanya data fixture sesuai konteks aktif | 1 trip, 10 ton, pendapatan Rp1.150.000, upah Rp1.950.000, Bonus Ayak Rp50.000, kontribusi setelah Bonus -Rp850.000 | LULUS |
| Ledger unit | Buka unit dari menu Asset Management | Detail trip dan status billing/payroll dapat ditelusuri | 4 trip, 73 ton, pendapatan Rp8.480.000, upah Rp9.945.000, Bonus Ayak Rp50.000, kontribusi setelah Bonus -Rp1.515.000 | LULUS |
| Bonus manual pada ledger | Periksa tabel Bonus Ayak yang belum terikat ke satu SPB | Bonus tidak hilang dari total unit | Bonus manual 50 trip/Rp50.000 terlihat sebagai baris terpisah dan diberi status settlement | LULUS |
| Project | Buka Project lalu tab `Trip Angkutan (4)` | Ringkasan Project sama dengan sumber dan Bonus bukan line invoice | Total 73 ton, pendapatan Rp8.480.000, upah Rp9.945.000, Bonus Rp50.000, kontribusi setelah Bonus -Rp1.515.000 | LULUS |
| Reload | Muat ulang report/Project | Nilai dan filter tetap dapat diperiksa | Nilai tetap sama; status SPB tetap VERIFIED | LULUS |

Rekonsiliasi ledger:

```text
Pendapatan trip      = Rp8.480.000
Upah sopir           = Rp9.945.000
Kontribusi sebelum Bonus = Rp8.480.000 - Rp9.945.000 = -Rp1.465.000
Bonus Ayak           = Rp50.000
Kontribusi setelah Bonus = -Rp1.465.000 - Rp50.000 = -Rp1.515.000
```

Bonus Ayak manual tetap masuk biaya unit dan payroll settlement. Bonus tersebut tidak ditambahkan ke invoice pelanggan.

## Perbaikan yang dilakukan

Pemeriksaan awal menemukan ringkasan report dan Project menampilkan Bonus Ayak sebagai catatan terpisah, tetapi kontribusi belum menguranginya. Selain itu, ledger menampilkan total Bonus namun belum menampilkan Bonus pada rincian baris. Perbaikan dalam lingkup tahap ini:

- `HeavyEquipmentReportController` mengambil Bonus Ayak yang business/date/filter-nya sesuai.
- Rekap, tabel unit, tabel sopir, export, dan ledger menghitung kontribusi setelah Bonus.
- Ledger memisahkan Bonus Ayak manual yang tidak punya SPB sumber agar total dan detail dapat direkonsiliasi.
- Tab Project menampilkan metrik Bonus Ayak dan `Kontribusi setelah Bonus`.

File yang berubah:

- `Modules/AssetManagement/Http/Controllers/HeavyEquipmentReportController.php`
- `Modules/AssetManagement/Resources/views/fleet/reports/index.blade.php`
- `Modules/AssetManagement/Resources/views/fleet/reports/asset_ledger.blade.php`
- `Modules/Project/Http/Controllers/ProjectController.php`
- `Modules/Project/Resources/views/project/show.blade.php`

## Bukti screenshot

Raw internal dari browser:

- `storage/app/changelog-evidence/RUN05J-20260911/raw/05J-rekap-with-bonus.png`
- `storage/app/changelog-evidence/RUN05J-20260911/raw/05J-asset-ledger-with-manual-bonus.png`
- `storage/app/changelog-evidence/RUN05J-20260911/raw/05J-project-trip-tab-with-bonus-contribution.png`

PNG beranotasi dibuat ulang dari raw dengan Pillow, bukan SVG/HTML overlay:

- [Rekap terfilter](../../../hasil/screenshots/05J-rekap-with-bonus-annotated.png)
- [Ledger unit dan Bonus manual](../../../hasil/screenshots/05J-asset-ledger-with-manual-bonus-annotated.png)
- [Trip Angkutan pada Project](../../../hasil/screenshots/05J-project-trip-tab-with-bonus-contribution-annotated.png)

Perintah contoh:

```text
python scripts/annotate_changelog_screenshots.py --preset 05J-rekap-with-bonus --input storage/app/changelog-evidence/RUN05J-20260911/raw/05J-rekap-with-bonus.png --output hasil/screenshots/05J-rekap-with-bonus-annotated.png
```

Salinan tersedia pada `storage/app/changelog-evidence/RUN05J-20260911/annotations/`, `Rencana/ChangeLog-Angkutan/hasil/screenshots/`, dan `public/images/change-log/release-2026-09-10-angkutan/`. Gambar sudah diperiksa agar marker berada pada area kosong/tepi dan tidak menutupi nilai utama. Tidak ada kredensial atau data rekening pada gambar.

## Catatan scope

Export report tidak diubah melalui browser pada run ini karena fokus bukti adalah filter dan rekonsiliasi tampilan. Endpoint export tetap ikut diperbarui agar kolom Bonus Ayak dan kontribusi setelah Bonus tersedia; verifikasi export terpisah dapat dilakukan pada regression final bila dibutuhkan.

Lanjutkan ke [05K - Tulis overview dan panduan ikon](../05K-TULIS-OVERVIEW-DAN-PANDUAN-IKON.md).
