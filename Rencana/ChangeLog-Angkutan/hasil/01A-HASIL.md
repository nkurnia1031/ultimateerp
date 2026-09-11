# Hasil 01A — Audit master unit, sopir, dan tarif

## Ringkasan handoff

- Status bagian: **LULUS — audit read-only selesai**.
- Status perilaku aplikasi: **BELUM DIUJI**; bagian ini tidak menjalankan transaksi browser.
- Lingkungan yang diaudit: `D:\Laragon\www\2025\ultimateerp`, source lokal.
- Workbook: [LIST UANG JALAN PT BMT 2026.xlsx](../../../ref/LIST%20UANG%20JALAN%20PT%20BMT%202026.xlsx).
- Fakta workbook: 1 sheet, 91 baris tarif, 29 rute, 135 token unit, 14 label unit unik, 41 baris multi-unit, 29 baris tanpa `Harga/Ton`, dan 6 keterangan berisi tanggal/perubahan.
- Unit fisik saat ini menggunakan model `assets`; tidak ada master `heavy_equipment_units` terpisah.
- Sopir saat ini menggunakan `users` dengan `business_id` dan `user_type = user`; belum ada penanda/role sopir khusus yang terlihat di jalur tarif.
- Tarif dan rute dibuat oleh proses import. Menu tarif memiliki draft, pemetaan unit, konfirmasi aktif, batal, histori, dan export.
- Gap utama: upload langsung membuat batch draft di database; “preview” belum merupakan preview aman yang terpisah dari pembuatan draft.
- Gap utama: belum ada CRUD UI khusus untuk rute atau baris tarif; rute dibuat `firstOrCreate` ketika import.
- Gap utama: upload workbook yang sama tidak dicegah berdasarkan `source_hash` sebelum batch baru dibuat.
- Gap utama: selector pemetaan menampilkan seluruh asset business, belum membatasi kategori/tipe alat berat secara eksplisit.
- Prasyarat bagian berikut: hasil ini dipakai oleh `01B` dan `02A`; jangan menganggap angka atau gap sebagai hasil pengujian runtime.

## Lingkup dan bukti source

Audit membaca source berikut tanpa menulis database atau memanggil transaksi aplikasi:

| Area | Bukti | Temuan aktual |
| --- | --- | --- |
| Struktur master asset | [Asset.php](../../../Modules/AssetManagement/Entities/Asset.php), [create_assets migration](../../../Modules/AssetManagement/Database/Migrations/2020_08_20_114339_create_assets_table.php) | Asset memiliki `business_id`, `asset_code`, `name`, `category_id`, `location_id`, `quantity`, dan `is_allocatable`. Relasi baru ke tarif/trip/Bonus sudah ada pada model. |
| CRUD asset | [AssetController.php](../../../Modules/AssetManagement/Http/Controllers/AssetController.php) | Index, create, store, edit, update, destroy tersedia dan query index/edit memakai business aktif; permission asset diterapkan per operasi. |
| Master user/sopir | [User.php](../../../app/User.php) | User memiliki relasi business dan relasi trip/settlement/Bonus/Kasbon. Dropdown umum memakai `business_id`, scope `user`, dan dapat menyaring commission agent; belum ada driver-specific master yang diaudit di bagian ini. |
| Struktur tarif | [2026_09_09_000001_create_heavy_equipment_tracking_tables.php](../../../Modules/AssetManagement/Database/Migrations/2026_09_09_000001_create_heavy_equipment_tracking_tables.php) | Rute, batch, route rate, unit mapping, dan periode tersimpan. `route_rates` memiliki `business_id`; `rate_units` dirunut ke rate dan asset. |
| Import/aktivasi tarif | [HeavyEquipmentRateService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentRateService.php) | Import membaca XLSX/XLS/CSV, memetakan header, memecah unit berdasarkan `;`, mencocokkan asset berdasarkan nama/asset_code, dan membuat batch/rate/unit dalam transaksi. Confirm memeriksa unmatched/duplikat dan menutup tarif aktif sebelumnya. |
| Endpoint tarif | [HeavyEquipmentRateController.php](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentRateController.php) dan [routes](../../../Modules/AssetManagement/Routes/web.php) | Index, upload, confirm, cancel, map unit, export tersedia di prefix `asset/fleet/rates`; semua selector asset dan batch dibatasi business aktif. |
| UI tarif | [rates/index.blade.php](../../../Modules/AssetManagement/Resources/views/fleet/rates/index.blade.php) | Form upload + tanggal berlaku, daftar batch, inline map unit, confirm/cancel, dan export. Tidak ada preview step/route terpisah, edit nilai rate, atau hapus rate individual. |
| Menu/permission | [DataController.php](../../../Modules/AssetManagement/Http/Controllers/DataController.php) dan [nav](../../../Modules/AssetManagement/Resources/views/layouts/nav.blade.php) | Permission `asset.fleet_rates.manage` dan menu `Tarif Angkutan` tersedia. Asset menu menggunakan `asset.view/create/update/delete`; submenu tarif tampil untuk view atau manage. |

## Hasil workbook

Pembacaan dengan PhpSpreadsheet, `data_only=false`, pada sheet `List Uang Jalan` menghasilkan:

| Pemeriksaan | Hasil |
| --- | ---: |
| Baris sumber setelah header | 91 |
| Rute unik case-insensitive | 29 |
| Token unit setelah pemisahan `;` | 135 |
| Label unit unik case-insensitive | 14 |
| Baris dengan lebih dari satu unit | 41 |
| Baris `Harga/Ton` kosong | 29 |
| Duplikasi rute–unit di workbook | 0 |
| Keterangan dengan tanggal/perubahan | 6 |

Label unit yang ditemukan: `DT`, `DT 01`, `DT 05`, `DT 11`, `TK 01`, `TK 02`, `TK 06`, `TK 03`, `TK 05`, `DT01`, `DT05`, `DT11`, `TK`, dan `DT 10`. Perbedaan seperti `DT 01` versus `DT01` harus dikonfirmasi saat pemetaan; jangan dinormalisasi diam-diam.

Contoh baris awal yang menjadi expected value untuk bagian pengujian:

| Baris workbook | Rute | Unit | Borongan Supir/Ton | Uang Jalan | Harga/Ton | Keterangan |
| ---: | --- | --- | ---: | ---: | ---: | --- |
| 2 | SSP2 - EUP/GBE | DT | Rp65.000 | Rp1.300.000 | Rp115.000 | Acc Bu Lily 06/08/26 |
| 3 | SSP2 - EUP/GBE | DT 01 ; DT 05 ; DT 11 | Rp65.000 | Rp1.400.000 | Rp115.000 | kosong |
| 4 | SSP2 - EUP | TK 01 ; TK 02 | Rp65.000 | Rp2.000.000 | Rp115.000 | kosong |

Tanggal pada keterangan hanya catatan sumber pada tahap ini. `effective_from` wajib berasal dari input tanggal aplikasi pada saat import; tidak boleh disimpulkan otomatis dari teks keterangan.

## Matriks menu dan akses awal

| Kebutuhan | Menu/route aktual | Permission yang terlihat | Status audit |
| --- | --- | --- | --- |
| Kelola unit fisik | Asset / Alat Berat; resource `asset/assets` | `asset.view`, `asset.create`, `asset.update`, `asset.delete`, subscription asset management | Ada CRUD asset umum; klasifikasi khusus alat berat belum dipastikan. |
| Pilih sopir | Belum ada menu sopir khusus; selector user pada fitur trip/settlement | `business_id` dan `user_type = user` pada query controller | Ada sumber user; kelayakan sebagai sopir perlu diuji/ditentukan lewat data UI. |
| Upload master tarif | `asset/fleet/rates` | `asset.view` atau `asset.fleet_rates.manage` untuk lihat; manage/create/update untuk mutasi | Ada; upload langsung membuat draft. |
| Cocokkan unit tarif | `POST asset/fleet/rates/units/{unitId}/map` | `asset.fleet_rates.manage` atau permission manage yang dipakai controller | Ada inline select; perlu uji browser, unmatched, dan asset lintas business. |
| Aktifkan tarif | `POST asset/fleet/rates/{id}/confirm` | `authorizeManage` | Ada; error disimpan pada batch lalu dikembalikan ke halaman. |
| Batalkan batch | `POST asset/fleet/rates/{id}/cancel` | `authorizeManage` | Ada; aturan tarif yang sudah dipakai trip perlu diuji pada bagian berikut. |
| Export tarif aktif | `GET asset/fleet/rates/export` | view atau manage | Ada; hanya status aktif dan business aktif yang diambil. |

## Gap dan risiko prioritas

1. **P0 — Ambiguitas preview vs mutasi:** `import()` membuat batch, route, rate, dan unit sebelum user meninjau. Jika validasi gagal sebagian, draft tetap dibuat dan error disimpan. Bagian `02B` harus membuktikan apakah UI memberi pemahaman dan cleanup yang aman; usulan perbaikan adalah preview server-side sebelum commit data tarif.
2. **P0 — Duplikasi upload:** `source_hash` disimpan, tetapi tidak dipakai untuk menolak upload identik. Bagian `02B` wajib menguji upload ulang; bila terjadi duplikasi, perbaiki dalam lingkup import tarif.
3. **P1 — Identitas unit:** lookup hanya cocok exact case-insensitive pada `assets.name` atau `asset_code`. Format label ber-spasi berbeda dapat unmatched. Bagian `02A/02B` perlu fixture asset yang jelas dan bukti pemetaan manual.
4. **P1 — Selektor asset terlalu luas:** `$allAssets` mengambil seluruh asset business, bukan hanya alat berat atau kategori tertentu. Perlu keputusan/validasi UI agar user tidak salah memilih asset non-armada.
5. **P1 — Driver belum master khusus:** selector driver memakai user business bertipe `user`; belum ada atribut driver yang diaudit pada source ini. Bagian `02A` harus membuat/memakai user melalui UI sesuai jalur yang tersedia dan mencatat jika tidak ada cara aman menandai sopir.
6. **P1 — Route CRUD belum tersedia:** `HeavyEquipmentRoute` dibuat melalui `firstOrCreate` saat import dan belum memiliki route resource UI. Jika user perlu memperbaiki rute tanpa upload ulang, catat sebagai gap implementasi.
7. **P1 — Periode dan duplikasi histori:** confirm menutup tarif aktif sebelumnya berdasarkan asset/rute, tetapi pemeriksaan periode overlap dan batch draft lama perlu diuji. Jangan mengubah histori yang sudah dipakai trip.
8. **P2 — Auditability:** batch menyimpan nama file, hash, tanggal input, jumlah baris, dan error; route rate menyimpan source row/unit text/notes. Sheet name belum terlihat pada tabel tarif yang diaudit.

## Skenario yang diserahkan

- `01B`: verifikasi import workbook lain dan struktur source SPB; gunakan fakta unit/route dari bagian ini bila relevan.
- `02A`: buat fixture unit/sopir melalui browser dan catat ID/business.
- `02B`: upload workbook melalui browser, preview/mapping/confirm, duplikasi, rollback, dan histori tarif.
- `05A`: artikel master/tarif hanya boleh menyebut preview yang benar-benar terbukti; jangan menjanjikan preview non-mutating sebelum gap P0 selesai.

## Pemeriksaan yang dijalankan

- Graphify CLI `graphify query ...` dicoba, tetapi executable `graphify` tidak tersedia pada PATH dan interpreter tersimpan di `graphify-out/.graphify_python` juga tidak ada pada path tersebut. Sebagai fallback sesuai instruksi Graphify, graph existing dibaca read-only dengan NetworkX; ditemukan node `HeavyEquipmentRateBatch`, `HeavyEquipmentRouteRate`, `HeavyEquipmentTrip`, `HeavyEquipmentRateService`, `HeavyEquipmentRateController`, `Asset`, `User`, `BonusAyak`, dan `HeavyEquipmentDriverSettlement`.
- PhpSpreadsheet dipakai read-only untuk membaca workbook; tidak ada macro dijalankan dan tidak ada file sumber ditulis.
- Tidak ada browser, SQL write, migration, service/controller transaksi, atau perubahan source aplikasi dijalankan pada bagian 01A.

## Cleanup dan handoff

Tidak ada data uji dibuat, sehingga tidak ada cleanup. Lanjutkan hanya ke bagian yang diminta pengguna. Bagian berikutnya yang paling tepat adalah [01B](../01B-AUDIT-SPB-DAN-WORKBOOK.md) atau [02A](../02A-SIAPKAN-SESI-DAN-MASTER-UJI.md) setelah audit lanjutan yang diperlukan.
