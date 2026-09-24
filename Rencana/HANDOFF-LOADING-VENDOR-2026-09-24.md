# Handoff Loading Vendor — 24 September 2026

## Status akhir

Implementasi utama Loading Vendor sudah masuk ke commit `78c49d0` (`update`). Setelah commit tersebut, alias import `Tiket` diperbaiki dan dokumen handoff ini dibuat; perubahan tersebut sengaja belum di-commit agar sesi berikutnya dapat meninjau dan menggabungkannya.

Fitur ini adalah transaksi vendor yang terpisah dari SPB internal. Loading Vendor tidak otomatis masuk invoice, Sell, payroll, settlement internal, asset ledger, atau Expense.

## Yang sudah tersedia

- Menu `Asset Management → Loading Vendor`.
- Tombol `Tambah Loading Vendor` dan `Import Loading Vendor` pada halaman daftar.
- Menu navigasi `Import SPB Angkutan` terpisah sudah dihapus. Import SPB tetap tersedia dari halaman SPB.
- DataTables server-side dengan responsive dan filter tanggal, supplier, status, serta status Expense.
- Form create/edit dengan perhitungan otomatis:

  ```text
  Netto          = Gross - Tare
  Tonase Tagihan = Tonase Diterima jika ada, selain itu Netto
  Nilai Dasar    = Tonase Tagihan × Tarif Vendor/Ton
  Nilai Akhir    = Nilai Dasar - Potongan + Penyesuaian Klaim
  ```

- Unit dan sopir vendor menggunakan Select2 tagging. Nilai baru disimpan sebagai teks snapshot dan tidak membuat Asset/User internal.
- Pilihan unit dan sopir sebelumnya diambil dari Loading Vendor lain dalam business aktif. Saat ini belum disaring berdasarkan supplier.
- Supplier/Bendera menggunakan Contact bertipe `supplier` atau `both`, dibatasi business aktif, dengan pencarian Select2 AJAX.
- Supplier minimal dapat dibuat dari form atau dari preview import jika user memiliki `supplier.create`.
- Route, Project, Customer, dan Expense semuanya nullable.
- Status `draft`, `loading`, `in_transit`, `completed`, `verified`, dan `cancelled`.
- Loading Vendor verified/cancelled atau yang sudah dikaitkan Expense terkunci dari edit dan hapus.
- Permission baru:

  - `asset.fleet_vendor_loadings.view`
  - `asset.fleet_vendor_loadings.manage`
  - `asset.fleet_vendor_loadings.import`
  - `asset.fleet_vendor_loadings.verify`
  - `asset.fleet_vendor_loadings.expense`

## File utama

- Migration: `Modules/AssetManagement/Database/Migrations/2026_09_23_000001_create_heavy_equipment_vendor_loadings_table.php`
- Model: `Modules/AssetManagement/Entities/HeavyEquipmentVendorLoading.php`
- CRUD/status controller: `Modules/AssetManagement/Http/Controllers/HeavyEquipmentVendorLoadingController.php`
- Business logic: `Modules/AssetManagement/Services/HeavyEquipmentVendorLoadingService.php`
- Import parser/preview: `Modules/AssetManagement/Services/HeavyEquipmentVendorLoadingImportService.php`
- Views: `Modules/AssetManagement/Resources/views/fleet/vendor_loadings/`
- Routes: `Modules/AssetManagement/Routes/web.php`
- Permission registry: `Modules/AssetManagement/Http/Controllers/DataController.php`
- Navigation: `Modules/AssetManagement/Resources/views/layouts/nav.blade.php`

Route prefix:

```text
/asset/fleet/vendor-loadings
```

Route names menggunakan prefix `asset.fleet.vendor-loadings.*`.

## Hasil pengujian browser

Pengujian dilakukan dengan `agent-browser` melalui UI lokal, bukan SQL write atau pemanggilan controller langsung.

Skenario yang lulus:

1. Membuka Asset Management dan memastikan menu Import SPB terpisah tidak tampil.
2. Membuka halaman SPB dan memastikan tombol Import SPB tetap tersedia.
3. Membuat Loading Vendor melalui form.
4. Memilih supplier dari Select2 AJAX.
5. Mengetik unit dan sopir vendor baru melalui Select2 tagging.
6. Menyimpan, membuka detail, mengedit, reload, dan memastikan perubahan bertahan.
7. Membatalkan edit dan memastikan data tidak berubah.
8. Membatalkan dialog hapus dan memastikan data tetap ada.
9. Menghapus data draft melalui tombol aplikasi.
10. Upload file, membuka preview, melihat baris supplier cocok dan supplier belum cocok.
11. Memilih supplier pada preview dan menggunakan checkbox pembuatan supplier minimal.
12. Mengonfirmasi import melalui UI.
13. Mengimport ulang file dan mendapatkan penanda duplikat.
14. Mengubah status bertahap sampai Terverifikasi.
15. Memastikan baris verified hanya menampilkan `Detail` dan `Terkunci`.
16. Membuka URL edit record verified dan mendapatkan penolakan yang jelas.
17. Memeriksa desktop dan viewport mobile 390px.

Post-fix preview juga memastikan header `Tiket` terbaca. Alias ini diperbaiki setelah uji awal menemukan nomor tiket kosong.

## Data uji yang tersisa

Data berikut sengaja dipertahankan karena sudah berstatus final dan tidak boleh dihapus sembarangan:

| Field | Nilai |
|---|---|
| Business | `2` |
| Loading Vendor ID | `3` |
| Loading No | `LV-202609-0002` |
| Supplier ID | `1806` |
| Supplier snapshot | `SUPPLIER-BARU-UI-20260923` |
| Unit vendor | `VENDOR-IMPORT-UNIT-02` |
| Sopir vendor | `Vendor Driver Import 02` |
| Billable tons | `20.0000` |
| Vendor total | `1.395.000` |
| Status | `verified` |
| Expense status | `not_linked` |
| Source | `vendor-loading-ui-test.csv` |

Draft test rows lain sudah dihapus melalui UI. File uji dan screenshot browser berada di `storage/app/`, diabaikan oleh Git, dan bukan aset production.

## Validasi teknis yang sudah dijalankan

- `php artisan migrate --pretend --no-interaction`
- `php artisan view:cache --no-interaction`
- `php artisan route:cache --no-interaction`
- PHP lint untuk migration, model, service, importer, controller, route, dan DataController.
- `php artisan test --filter=ContactSupplierLabelTest` — 8 test lulus.
- `git diff --check`
- `graphify update .`

Tidak ada JavaScript module canonical yang diubah. JavaScript Loading Vendor berada inline pada Blade, sehingga tidak ada publish module JavaScript yang harus dijalankan.

## Catatan penting untuk sesi berikutnya

### Pekerjaan lanjutan yang belum dikerjakan

- Penyaringan saran unit dan sopir berdasarkan supplier terkait. Saat ini sumbernya global dalam business aktif.
- Integrasi pembuatan Expense dari Loading Vendor. Kolom `expense_transaction_id` dan `expense_status` sudah tersedia, tetapi belum ada transaksi Expense otomatis.
- Automated test khusus service/import Loading Vendor belum dibuat.
- Belum ada integrasi Loading Vendor ke invoice, Sell, payroll, settlement SPB, atau asset ledger; ini sesuai keputusan scope saat ini.

### Cara memulai sesi berikutnya

```powershell
Set-Location D:\Laragon\www\2025\ultimateerp
Get-Content AGENTS.md
Get-Content Rencana\HANDOFF-LOADING-VENDOR-2026-09-24.md
git status --short
```

Untuk uji UI gunakan:

```text
http://localhost/2025/ultimateerp/public/
```

Login demo lokal tercatat di `AGENTS.md`; jangan menyalin kredensial lokal ke production atau dokumentasi publik.

Sebelum mengubah kode, jalankan query Graphify yang fokus pada Loading Vendor. Setelah perubahan kode, jalankan pemeriksaan relevan dan `graphify update .`.

Jangan menjalankan import workbook uji ke production. Jangan menghapus Loading Vendor ID `3` tanpa keputusan eksplisit karena statusnya sudah `verified`.
