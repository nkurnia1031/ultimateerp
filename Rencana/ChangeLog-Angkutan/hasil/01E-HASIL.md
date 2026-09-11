# Hasil 01E — Audit Settlement, Payroll, Rekap, dan Ledger

## Status

**LULUS untuk audit kode read-only.** Settlement, Payroll, laporan, dan ledger belum dijalankan melalui browser pada bagian ini. Tidak ada transaksi yang dibuat atau diposting.

## Kesimpulan

Alur settlement gabungan sudah diimplementasikan:

```text
SPB verified + Bonus Ayak + Kasbon
        ↓ pilih pada review settlement
Bruto = Upah Trip + Bonus Ayak
Netto = Bruto - Kasbon
        ↓ buat draft
Payroll Essentials saat posting
```

Pembuatan draft menyimpan snapshot total dan memasang tiga pivot sumber. Posting membuat satu transaksi Payroll Essentials dengan allowance upah perjalanan, allowance Bonus Ayak, dan deduction Kasbon. Draft dapat dibatalkan sehingga pivot sumber dilepas.

## Matriks alur settlement

| Tahap | Implementasi | Bukti kode | Status/risko |
|---|---|---|---|
| Pilih driver/periode | Form memilih sopir dan tanggal mulai/sampai | [`HeavyEquipmentSettlementController.php`](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentSettlementController.php:55) | Ada; uji dropdown business |
| Kandidat SPB | Hanya business aktif, driver sama, tanggal sesuai, `verified`, `payroll_status=unsettled`, dan belum punya settlement | [`HeavyEquipmentTripService.php`](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:654) | Ada; uji status/duplikasi |
| Kandidat Bonus Ayak | Business aktif, driver sama, tanggal sesuai, belum settlement | [`HeavyEquipmentTripService.php`](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:665) | Ada; uji Bonus manual dan bersumber |
| Kandidat Kasbon | Business aktif, driver sama, tanggal sesuai, belum settlement | [`HeavyEquipmentTripService.php`](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:671) | Ada; uji potongan |
| Pilih/keluarkan item | Checkbox trip, bonus, kasbon; angka review dihitung ulang di browser | [`preview.blade.php`](../../../Modules/AssetManagement/Resources/views/fleet/settlements/preview.blade.php:27) | Ada; server tetap harus menjadi sumber kebenaran |
| Buat draft | Menghitung upah trip + bonus − kasbon dalam transaksi DB dan attach semua pivot | [`HeavyEquipmentTripService.php`](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:505) | Ada; perlu uji sumber ganda/dua tab |
| Batalkan draft | Detach tiga pivot lalu status menjadi `cancelled` | [`HeavyEquipmentSettlementController.php`](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentSettlementController.php:98) | Ada; uji sumber kembali tersedia |
| Posting | Mengunci row settlement, membuat transaksi payroll final, mengubah SPB menjadi settled | [`HeavyEquipmentTripService.php`](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:556) | Ada; uji klik ganda dan koreksi |
| Lock after post | Settlement bukan draft tidak dapat diposting ulang; SPB verified/settled tidak dapat diedit | [`HeavyEquipmentTripService.php`](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:558), [`HeavyEquipmentTripController.php`](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentTripController.php:73) | Ada; uji browser |

## Rumus dan Payroll

Kode menghitung:

```text
Upah Trip     = SUM(driver_pay_total SPB terpilih)
Bonus Ayak    = SUM(bonus Bonus Ayak terpilih)
Bruto         = Upah Trip + Bonus Ayak
Kasbon        = SUM(jumlah_kasbon terpilih)
Netto         = Bruto - Kasbon
```

Posting menyimpan `final_total` dan `total_before_tax` sebesar netto. Rincian Payroll Essentials dikirim sebagai:

- allowance `Uang Jalan` untuk total uang jalan;
- allowance `Bonus Ayak` bila nilainya lebih dari nol;
- deduction `Kasbon Bonus Ayak` bila nilainya lebih dari nol;
- komponen upah variabel sebagai duration ton dan amount per unit.

Hal yang harus direkonsiliasi pada `02I`:

- jumlah allowance yang tampil di Payroll Essentials dengan `driver_pay_total` SPB;
- Bonus Ayak satu SPB versus rumus minimal 50 trip;
- jumlah item (`trip_count`, jumlah Bonus, jumlah Kasbon) versus nominal;
- apakah Payroll Essentials menjumlahkan field JSON dan `final_total` seperti yang diharapkan.

## Matriks laporan dan ledger

| Laporan | Yang tersedia | Gap terhadap kebutuhan |
|---|---|---|
| Rekap semua SPB | Filter asset, driver, project, periode; total trip, ton, revenue, upah, kontribusi | Query hanya mengecualikan cancelled, sehingga draft/loading/unverified ikut dihitung. Tulis aturan tampilan yang jelas atau perbaiki setelah dibuktikan pada `02J`. |
| Rekap per unit | Trip, ton, pendapatan, upah sopir, kontribusi; link ke ledger unit | Bonus Ayak belum diringkas sebagai biaya unit. |
| Rekap per sopir | Trip, ton, upah sopir | Tidak memuat pendapatan, Bonus Ayak, Kasbon, bruto/netto, atau margin driver. |
| Export rekap | CSV dengan nomor SPB, tiket, tanggal, rute, unit, sopir, project, ton, revenue, upah, kontribusi, status | Export memakai `actual_tons` untuk Ton Netto, bukan selalu `billable_tons`; Bonus/Kasbon tidak ikut. Perlu dipastikan istilah dan angka tidak menyesatkan. |
| Ledger asset | Riwayat SPB, rute, sopir, project, billing, settlement; ringkasan unit | Belum menampilkan Bonus Ayak, total Kasbon, atau status payroll secara eksplisit pada ringkasan. |
| Ledger project | SPB dan invoice Project tersedia dari integrasi Project yang diaudit di `01C` | Rekonsiliasi Bonus Ayak sebagai cost Project perlu diuji di `02J`; jangan dianggap sudah tampil hanya karena relasi ada. |

Bukti utama laporan: [`HeavyEquipmentReportController.php`](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentReportController.php:14), [`reports/index.blade.php`](../../../Modules/AssetManagement/Resources/views/fleet/reports/index.blade.php:1), dan [`asset_ledger.blade.php`](../../../Modules/AssetManagement/Resources/views/fleet/reports/asset_ledger.blade.php:1).

## Risiko transaksi ganda dan penguncian

- Pivot `heavy_equipment_settlement_trips` memiliki unique `trip_id`.
- Pivot `heavy_equipment_settlement_bonus_ayak` memiliki unique `bonus_ayak_id`.
- Pivot `heavy_equipment_settlement_kasbons` memiliki unique `kasbon_id`.
- Kandidat menggunakan `whereDoesntHave('settlements')`, sehingga draft pun membuat sumber tidak muncul pada settlement berikutnya.
- Posting mengunci row settlement, tetapi pembuatan draft kandidat tidak mengunci seluruh sumber terlebih dahulu. Dua tab yang membuat draft bersamaan perlu diuji pada `02L`; kegagalan constraint harus menghasilkan pesan yang dapat dipahami dan tidak meninggalkan partial data.
- Cancel hanya melepas pivot dan mengubah status settlement; SPB tetap `unsettled`, sebagaimana yang diharapkan. Bonus dan Kasbon tidak mempunyai flag settlement sendiri sehingga ketersediaannya ditentukan oleh pivot.

## Permission dan business scope

Controller index/preview memakai permission lihat settlement atau asset/trip terkait. Store, cancel, dan post memakai permission pengelolaan settlement/payroll atau beberapa permission asset. Semua kandidat difilter dengan `business_id` dan driver dari business aktif.

Yang belum terbukti:

- user dengan permission hanya lihat tidak dapat membuat, membatalkan, atau posting;
- user dari business lain tidak muncul atau tidak dapat dipaksa lewat form;
- transaksi payroll yang dibuat memiliki business yang sama;
- dua tab tidak dapat memposting settlement yang sama dua kali.

## Handoff

- `02H`: uji Kasbon sebagai sumber settlement.
- `02I`: uji review, keluarkan sebagian item, buat draft, posting Payroll, dan rekonsiliasi angka.
- `02J`: uji laporan unit/sopir/project serta ledger.
- `02K`: uji permission dan business scope.
- `02L`: uji concurrency/duplikasi.
- `02M`: uji transaksi terkunci setelah settlement/posting.
- `06G`: gunakan hasil ini sebagai gate sebelum menulis panduan final.

Bagian berikutnya yang dapat dijalankan tanpa input tambahan adalah [`01F-AUDIT-WADAH-CHANGE-LOG-DAN-NAVBAR.md`](../01F-AUDIT-WADAH-CHANGE-LOG-DAN-NAVBAR.md).
