# Hasil 02F - Sell Angkutan dari SPB

## Status

**LULUS dengan catatan.** SPB verified yang belum billed dapat dipilih melalui daftar SPB, customer/lokasi/produk jasa dapat diisi melalui form Sell, line dan nominal benar, lalu transaksi dapat dibuka kembali melalui **Sell -> List Drafts**. Guard billing ganda dan keadaan tanpa pilihan sumber juga diuji melalui browser.

Catatan UX: transaksi dibuat dengan status `Draft`, sehingga memang tidak tampil pada **All sales** yang hanya mengambil transaksi final. Pada awal uji tombol tanpa pilihan membawa ke halaman error 422; controller diperbaiki agar kembali ke daftar SPB dengan pesan yang dapat dipahami, lalu diuji ulang melalui tombol UI.

## Run, business, dan data uji

- Run ID: `RUN02F-20260910`.
- Business aktif: `CV BM TRANSPORT`, business ID `2`.
- SPB yang dipakai: ID `2`, `TRIP-202609-0002`, tiket `RUN02C-20260910-SELL`.
- Status awal: `verified`, `unbilled`, `unsettled`.
- Customer: `RUN02A Customer Demo Revised`, ID `1802`.
- Lokasi: `CV BM TRANSPORT (BL0001)`, ID `2`.
- Produk jasa: `RUN02A Jasa Angkutan SPB Revised (RUN02A-SPB)`, variation/product ID `3069`.
- Sell yang dibuat: transaction ID `54290`, nomor `DR2026/0007`, status `draft`.

SPB ID 119 dari 02E tetap menjadi invoice Project dan tidak dipakai pada Sell. Semua perubahan transaksi dibuat melalui interaksi browser pada aplikasi lokal. Pemeriksaan database hanya read-only untuk rekonsiliasi.

## Journey browser dan hasil aktual

### A. Memilih SPB dari menu SPB

1. Membuka **Asset Management -> SPB Angkutan**.
2. Memilih checkbox SPB ID 2 (`TRIP-202609-0002`).
3. Menekan **Sell dari yang dipilih**.

Hasil: form **Sell dari Trip Angkutan** terbuka dengan trip `TRIP-202609-0002`. SPB yang sudah billed tidak memiliki checkbox pilih.

### B. Mengisi dan membuat Sell

1. Memastikan customer sudah berisi `RUN02A Customer Demo Revised (CO0103)`.
2. Memilih produk jasa `RUN02A Jasa Angkutan SPB Revised (RUN02A-SPB)`.
3. Memilih lokasi `CV BM TRANSPORT (BL0001)`.
4. Memeriksa tanggal Sell `2026-09-10`, status `Draft`, dan tabel preview.
5. Menekan **Buat Sell**.

Preview melalui browser menampilkan:

| Trip | Rute | Ton | Harga/Ton | Total |
|---|---|---:|---:|---:|
| `TRIP-202609-0002` | `SSP2 - EUP/GBE` | 10.0000 | Rp115.000 | Rp1.150.000 |

Hasil: aplikasi menampilkan pesan `Sell DR2026/0007 berhasil dibuat.` SPB kemudian tampil pada daftar dengan status `Verified billed`.

Bukti: [preview Sell](screenshots/02F-sell-preview.png).

### C. Membuka kembali Sell dari jalur yang sesuai

1. Membuka menu **Sell**.
2. Membuka **List Drafts**, bukan **All sales**.
3. Memastikan baris `DR2026/0007` muncul.
4. Membuka menu aksi pada baris tersebut.
5. Menekan **View**.

Hasil daftar draft: `DR2026/0007`, tanggal `10-09-2026`, customer `RUN02A Customer Demo Revised`, nomor kontak `081200000291`, lokasi `CV BM TRANSPORT`, satu item, dan total item `1.00`.

Hasil detail Sell:

- produk `RUN02A Jasa Angkutan SPB Revised (RUN02A-SPB)`;
- deskripsi menyimpan rute `SSP2 - EUP/GBE`, unit `B 0004`, sopir `RUN02A Driver Revised`, dan ton `10.0000`;
- quantity `10.00 Ton`;
- harga `Rp115.000`;
- subtotal dan total payable `Rp1.150.000`;
- payment status belum dibayar, total remaining `Rp1.150.000`.

Bukti: [detail Sell](screenshots/02F-sell-detail.png).

### D. Uji sumber setelah billing

1. Kembali ke **SPB Angkutan**.
2. Memeriksa baris SPB ID 2.
3. Memastikan checkbox untuk SPB ID 2 tidak tersedia.
4. Memeriksa status `Verified billed`.
5. Menekan **Sell dari yang dipilih** tanpa memilih SPB apa pun.

Hasil pertama sebelum perbaikan: aplikasi membuka halaman error 422 `Pilih minimal satu trip.`. Setelah perbaikan, tombol kembali ke daftar SPB dan menampilkan pesan `Pilih minimal satu trip.`.

Uji tambahan melalui browser dengan URL lama SPB ID 2 juga dikembalikan ke daftar dan menampilkan `Trip harus verified dan belum ditagihkan.`. Tidak ada Sell kedua yang dibuat.

Bukti: [guard sumber billed dan pesan tanpa pilihan](screenshots/02F-billed-source-guard.png).

## Rekonsiliasi nominal

SPB ID 2 memiliki tonase tagihan `10` ton dan snapshot harga `Rp115.000/ton`.

```text
Nilai Sell = 10 x Rp115.000 = Rp1.150.000
```

Nilai ini sama pada preview form Sell, transaction final total, sell line, dan billing link. Penyesuaian SPB tidak ditambahkan sebagai baris terpisah pada Sell; apabila ada penyesuaian, line memakai nilai akhir SPB yang sudah dihitung service.

Pemeriksaan database read-only mengonfirmasi:

- transaction `54290`, business `2`, customer `1802`, lokasi `2`, tipe `sell`, subtype `heavy_equipment_trip`, status `draft`, final total `1150000`;
- sell line transaction `54290`, variation `3069`, quantity `10.0000`, unit price `115000`, price inc tax `115000`;
- billing link tunggal untuk trip ID `2`, transaction ID `54290`, billing type `sell`, quantity `10.0000`, amount `1150000`.

## Perbaikan yang dilakukan

1. `HeavyEquipmentTripController::projectInvoiceForm` dan `sellForm` sekarang menangani pilihan kosong atau sumber yang sudah tidak tersedia dengan redirect ke daftar SPB dan pesan validasi, bukan halaman error 422 teknis.

File perubahan:

- `Modules/AssetManagement/Http/Controllers/HeavyEquipmentTripController.php`

Perbaikan customer dan preview invoice Project yang dilakukan pada 02E tetap berlaku dan tidak mengubah Sell biasa.

## Checklist hasil

| Pemeriksaan | Hasil |
|---|---|
| Pilih SPB verified/unbilled melalui checkbox UI | Lulus |
| Customer, produk jasa, dan lokasi dipilih melalui form UI | Lulus |
| Preview menampilkan trip, rute, ton, rate, dan total | Lulus |
| Sell berhasil disimpan melalui tombol UI | Lulus |
| Sell draft ditemukan melalui Sell -> List Drafts | Lulus |
| Detail Sell dapat dibuka dan line dapat direkonsiliasi | Lulus |
| Reload halaman daftar draft mempertahankan transaksi | Lulus pada daftar setelah navigasi ulang |
| SPB billed tidak memiliki checkbox pilih | Lulus |
| Sumber billed tidak dapat dipakai ulang melalui jalur lama | Lulus; pesan validasi ramah |
| Tombol tanpa pilihan sumber | Lulus setelah perbaikan; kembali dengan pesan |
| Edit Sell dan simpan perubahan | Tidak dilakukan; transaksi sudah terhubung ke billing source dan tidak diubah tanpa skenario koreksi |
| Hapus Sell dan batalkan dialog hapus | Tidak dilakukan; cleanup lewat UI tidak aman karena SPB telah billed |
| Invoice Project | Tidak diuji ulang pada tahap ini; sudah diuji pada 02E |
| Payroll/settlement | Tidak diuji pada tahap ini; dilanjutkan 02G-02J |

## Handoff dan cleanup

Sell transaction ID `54290` / `DR2026/0007` dan SPB ID 2 dipertahankan sebagai data handoff untuk audit relasi serta tahap laporan/ledger. Keduanya berstatus draft/billed, belum settle payroll. Tidak ada transaksi posted yang dibuka kuncinya dan tidak ada penghapusan atau perubahan database manual.

SPB ID 1 tetap verified/unbilled untuk skenario berikutnya. SPB ID 119 tetap menjadi invoice Project `DR2026/0006`.

## Pemeriksaan teknis

| Pemeriksaan | Hasil |
|---|---|
| `php -l Modules/AssetManagement/Http/Controllers/HeavyEquipmentTripController.php` | Perlu dijalankan pada tahap teknis final; perubahan diuji melalui browser |
| `git diff --check` pada file perubahan tahap ini | Perlu dijalankan pada tahap teknis final |
| `php artisan view:cache --no-interaction` | Belum lulus: PHP CLI lokal berhenti pada `Pusher\\PusherException` karena ekstensi cURL tidak aktif |
| `php artisan route:cache --no-interaction` | Belum lulus karena blocker cURL/Pusher yang sama |
| Automated test Asset/Sell | Tidak tersedia test khusus yang dapat dijalankan pada suite saat ini |
| `graphify update .` | Belum dapat dijalankan; executable `graphify` tidak tersedia di PATH |
| Publish JavaScript module | Tidak diperlukan; perubahan hanya controller |

## Catatan tahap berikutnya

- Pada panduan, jelaskan bahwa status `Draft` dicari di **Sell -> List Drafts**, sedangkan **All sales** berisi transaksi final.
- Jangan membuat Sell kedua dari SPB ID 2. Untuk skenario lanjutan gunakan fixture baru atau sumber yang masih unbilled.
- Sell yang dibuat dari SPB tetap merupakan tagihan pelanggan; biaya/upah sopir tetap berasal dari SPB dan diproses terpisah pada settlement payroll.
- Tahap berikutnya: `02G-UJI-BONUS-AYAK-MANUAL-DAN-SUMBER-SPB.md`.
