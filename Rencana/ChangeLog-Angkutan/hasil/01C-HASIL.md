# Hasil 01C — Audit billing Project dan Sell

## Ringkasan handoff

- Status bagian: **LULUS — audit source/read-only selesai**.
- Status journey UI invoice/Sell: **BELUM DIUJI melalui agent-browser**.
- Jalur kontekstual sudah tersedia pada Project: tab Trip Angkutan, Buat SPB, Pilih SPB untuk Invoice, Tambah Bonus Ayak, dan Kelola semua SPB Project.
- Jalur kontekstual sudah tersedia pada Sell: Angkutan dari SPB dan Buat SPB.
- SPB untuk billing disaring business aktif, status `verified`, dan `invoice_status = unbilled`.
- Project invoice hanya menerima trip dalam satu Project; customer berbeda ditolak oleh service.
- Billing source memakai tabel `heavy_equipment_trip_billings` dengan unique `trip_id`, dan SPB ditandai `invoice_status = billed` dalam transaksi billing.
- **Risiko P0:** Project invoice menetapkan total transaksi dari `revenue_total`, tetapi line rate tetap snapshot harga/ton; tampilan line dapat berbeda dari total jika ada klaim/potongan.
- **Risiko P0:** Sell menghitung `final_total` dari quantity × snapshot harga melalui `calculateInvoiceTotal`, bukan dari `trip.revenue_total`; klaim/potongan dapat hilang dari tagihan Sell.
- **Risiko P1:** Sell dapat memilih customer override dan beberapa trip tanpa validasi semua customer sama; ini perlu keputusan dan uji UI.
- **Risiko P1:** chooser Project menampilkan SPB Project verified/unbilled tetapi tidak menampilkan status payroll; tabel Project juga belum menampilkan billing/payroll secara terpisah.
- **Risiko P1:** pencegahan billing ganda mengandalkan unique database dan pemeriksaan sebelum transaksi, tanpa row lock eksplisit; uji dua tab wajib dilakukan.
- Handoff: `02E` menguji invoice Project, `02F` menguji Sell, `02L` menguji kompetisi billing, dan `02N` merekonsiliasi laporan.

## Bukti source

| Area | Bukti | Temuan aktual |
| --- | --- | --- |
| Service invoice Project | [HeavyEquipmentTripService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php#L296) | Memilih trip verified/unbilled, satu Project, customer konsisten; membuat `ProjectTransaction`, line invoice, billing link, lalu menandai billed. |
| Service Sell | [HeavyEquipmentTripService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php#L378) | Memilih trip verified/unbilled; validasi customer/location/product jasa; membuat transaction Sell dan line dari snapshot rate. |
| Seleksi aman | [HeavyEquipmentTripService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php#L705) | Query memakai business, ID, verified, dan unbilled; jumlah hasil harus sama dengan ID yang dikirim. |
| Controller chooser | [HeavyEquipmentTripController.php](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentTripController.php#L280) | Project chooser membatasi `project_id`; Sell chooser bisa membatasi project/customer dan limit 500. |
| Project UI | [ProjectController.php](../../../Modules/Project/Http/Controllers/ProjectController.php#L465) dan [project/show.blade.php](../../../Modules/Project/Resources/views/project/show.blade.php#L233) | Query trip/Bonus business-scoped, summary, tombol konteks dan tabel trip/Bonus tersedia. |
| Invoice form | [project_invoice.blade.php](../../../Modules/AssetManagement/Resources/views/fleet/billing/project_invoice.blade.php) | Menampilkan trip, rute, ton, rate, total; user memilih lokasi, tanggal, status draft/final lalu membuat invoice. |
| Sell form | [sell.blade.php](../../../Modules/AssetManagement/Resources/views/fleet/billing/sell.blade.php) | Menampilkan trip dan total, meminta customer, produk jasa tanpa stok, lokasi, tanggal, status. |
| Sell entry point | [sell/create.blade.php](../../../resources/views/sell/create.blade.php#L22) | Menyediakan tombol Angkutan dari SPB dan Buat SPB bila permission sesuai. |
| Billing schema | [tracking migration](../../../Modules/AssetManagement/Database/Migrations/2026_09_09_000001_create_heavy_equipment_tracking_tables.php#L120) | `heavy_equipment_trip_billings.trip_id` unique; transaksi dan line dapat dirunut. |

## Matriks alur dan akses

| Alur | Entry point | Seleksi | Output | Status audit |
| --- | --- | --- | --- | --- |
| Project membuat SPB | Project tab → Buat SPB | `project_id`, `customer_id`, return context di URL | Form SPB, setelah simpan kembali ke Project | Source tersedia; UI belum diuji. |
| Project menagihkan SPB | Project tab → Pilih SPB untuk Invoice | Project aktif, verified, unbilled | Form invoice lalu transaction `project_invoice` | Filter dan route tersedia; nominal penyesuaian perlu diuji. |
| SPB list → Project invoice | Daftar SPB → checkbox → Invoice Project | Service mengulang filter verified/unbilled/same project | Invoice + billing link | Tidak boleh mengandalkan checkbox saja. |
| Sell membuat SPB | Sell create → Buat SPB | return context `sell` | Form SPB, setelah simpan kembali Sell | Source tersedia; UI belum diuji. |
| Sell menagihkan SPB | Sell create atau SPB list → Angkutan dari SPB | verified/unbilled, customer/location/product jasa | Transaction `heavy_equipment_trip` + sell lines + billing link | Source tersedia; nominal klaim berisiko. |
| Invoice non-angkutan | Project invoice lama | Alur `project_invoice` existing | Invoice lama | Harus regresi di 06B, jangan dicampur dengan SPB. |

Permission controller memakai `asset.fleet_billing.manage` atau `asset.fleet_trips.manage`; tombol Project/Sell juga memeriksa permission. Tujuan tahap uji adalah memastikan UI dan endpoint tidak berbeda, bukan hanya dropdown terlihat.

## Rekonsiliasi nominal yang harus dibuktikan

Dengan trip 20 ton, harga Rp115.000/ton, klaim/potongan nol:

```text
expected revenue = 20 × Rp115.000 = Rp2.300.000
```

Dengan klaim atau potongan nonnol, expected billing harus `trip.revenue_total`/nilai akhir, bukan sekadar 20 × Rp115.000. Source menunjukkan:

- Project invoice transaction `total_before_tax`, `final_total`, dan line `total` memakai jumlah `revenue_total`.
- Project invoice line `rate` tetap `snapshot_sale_rate_per_ton` dan `quantity` billable ton. Uji tampilan detail untuk memastikan user tidak melihat subtotal berbeda lalu mengira total salah.
- Sell membuat product line dari quantity × `snapshot_sale_rate_per_ton`, lalu `calculateInvoiceTotal()` menentukan final total. `HeavyEquipmentTripBilling.amount` memakai `revenue_total`, sehingga Billing link dapat berbeda dari total transaction bila klaim/potongan tidak nol.

Ini adalah temuan source, bukan hasil transaksi browser. `02F` harus membuat fixture dengan penyesuaian nonnol, melihat preview dan detail Sell, lalu memperbaiki service dalam scope bila mismatch terbukti. Jangan menyembunyikan selisih dengan mengganti angka pada hasil laporan.

## Isolasi dan penguncian

- Chooser Project mengambil `Project::where('business_id', $businessId)->findOrFail()` dan query trip memakai business + project.
- Service memeriksa customer, location, invoice scheme, variation/product dan semua business terkait.
- Billing link memiliki unique `trip_id`; status SPB ditandai billed setelah link dibuat dalam transaksi.
- Tidak ada delete/update invoice link di alur ini yang diaudit. Perubahan SPB setelah billed ditolak oleh service update dan form edit menyembunyikan tombol ketika status locked.
- Tanpa row lock, dua tab dapat sama-sama lolos query awal. Database unique seharusnya menggagalkan salah satu insert, tetapi pesan dan rollback harus dibuktikan di `02L`.
- Project summary mengambil semua trip non-cancelled, tetapi daftar hanya 10 terbaru dan kolom status belum memisahkan billing/payroll. Ini harus dijelaskan atau diperbaiki sebelum panduan final.

## Gap prioritas untuk tahap uji/perbaikan

1. `02F`: buktikan dan perbaiki mismatch Sell ketika `claim_adjustment_total` atau `other_deduction_total` nonnol. Expected harus konsisten pada preview, transaction, line, billing link dan laporan.
2. `02E`: buktikan line Project invoice ketika rate × quantity berbeda dari final revenue; tentukan tampilan line yang tidak menyesatkan.
3. `02L`: dua tab/sesi menagihkan SPB sama melalui Project vs Sell; tepat satu transaksi harus berhasil dan yang lain gagal/menampilkan sumber sudah billed tanpa orphan.
4. `02K`: uji business/customer/project/location/product/variation lintas business pada UI dan request tambahan.
5. `02N`: cocokkan total Project dengan invoice dan biaya; pastikan revenue final tidak dihitung dua kali.
6. `06B/06C`: regresi invoice Project lama dan Sell biasa setelah service billing diperbaiki.

## Pemeriksaan yang dijalankan

- Graph existing sudah ditelusuri read-only secara fallback karena CLI Graphify dan interpreter tersimpan tidak tersedia pada path lokal; source aktual kemudian diperiksa.
- `rg` dan pembacaan source/view dilakukan read-only. Tidak ada browser, SQL write, invoice, Sell, atau perubahan source aplikasi dijalankan pada bagian 01C.

## Cleanup dan handoff

Tidak ada data uji dibuat. Tidak ada cleanup yang diperlukan. Lanjutkan hanya ke [02E](../02E-UJI-SPB-DAN-INVOICE-DARI-PROJECT.md), [02F](../02F-UJI-SELL-ANGKUTAN.md), atau [02L](../02L-UJI-KONKURENSI-SUMBER-BILLING.md).
