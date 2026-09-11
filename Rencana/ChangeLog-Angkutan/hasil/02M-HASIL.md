# Hasil 02M — Uji Konkurensi Bonus Ayak dan Payroll

## Status

**LULUS dengan catatan handoff data uji posted.**

Seluruh create, verifikasi, pemilihan sumber, settlement, dan posting dilakukan melalui dua sesi `agent-browser` pada aplikasi lokal. Pemeriksaan database hanya read-only untuk mencocokkan hasil akhir.

## Fixture yang dibuat melalui UI

- Business: `CV BM TRANSPORT` (`business_id=2`).
- SPB baru: `TRIP-202609-0004`, tiket `RUN02M-20260910-CONCURRENT`, ID `120`.
- Unit: `RUN02A Unit Angkutan Revised` (`asset_id=39`).
- Sopir: `RUN02A Driver Revised` (`driver_id=93`).
- Project: `RUN02A Project Angkutan` (`project_id=11`).
- Bonus Ayak yang berhasil dibuat: ID `883`.

SPB dibuat dari form, tanggal diisi dengan interaksi keyboard pada field date, lalu ditekan `Simpan SPB`, `Selesaikan`, dan `Verify`. Setelah status menjadi `Verified`, SPB yang sama dipakai pada dua sesi Bonus Ayak.

## Skenario A — Dua Bonus Ayak memakai SPB yang sama

### Tindakan pengguna

1. Membuka menu `Bonus Ayak` pada dua sesi browser.
2. Pada kedua sesi memilih sopir `RUN02A Driver Revised`, unit `RUN02A Unit Angkutan Revised`, project `RUN02A Project Angkutan`, dan SPB `TRIP-202609-0004`.
3. Mengisi nilai Trip `50` pada kedua form.
4. Menekan `Simpan` pada dua sesi secara bersamaan.

### Hasil

- Sesi A berhasil dan menampilkan `Data Bonus Ayak berhasil disimpan.`
- Sesi B ditolak dengan pesan `Salah satu SPB sudah menjadi sumber Bonus Ayak lain.`
- Daftar menampilkan sumber `TRIP-202609-0004` hanya pada satu baris.
- Read-only database: pivot `bonus_ayak_trip` untuk `trip_id=120` hanya memiliki satu baris, yaitu `bonus_ayak_id=883`.
- Bonus ID `883` tersimpan dengan `project_id=11`, `driver_id=93`, `asset_id=39`.

Catatan: setelah memilih satu sumber SPB, aplikasi menyimpan `trip=1` dan `bonus=0.00` pada fixture ini. Jadi pengujian ini membuktikan pencegahan sumber ganda dan relasinya, bukan nominal bonus positif dari input `50`. Perilaku nominal manual sudah dicakup pada hasil 02G.

Bukti:

- [Form Bonus Ayak sesi A](../../../hasil/screenshots/02M-bonus-prepared-a.png)
- [Simpan berhasil](../../../hasil/screenshots/02M-bonus-after-a-success.png)
- [Simpan kedua ditolak](../../../hasil/screenshots/02M-bonus-after-b-rejected.png)

## Skenario B — Dua reservasi settlement untuk sumber yang sama

### Tindakan pengguna

1. Pada dua sesi memilih sopir yang sama dan periode `10-09-2026` sampai `10-09-2026`.
2. Menekan `Review Settlement` pada kedua sesi.
3. Memastikan kedua halaman menampilkan SPB `TRIP-202609-0004` dan Bonus Ayak ID `883` sebagai sumber eligible.
4. Menekan `Buat Settlement Draft` pada kedua sesi secara bersamaan.

### Hasil

- Satu sesi berhasil membuat `SET-202609-0004`.
- Sesi lainnya ditolak dengan pesan `Sebagian SPB tidak tersedia, sudah dipakai settlement, atau tidak berada pada periode/sopir yang dipilih.`
- Daftar settlement hanya memiliki satu draft baru untuk fixture tersebut.
- Draft berisi 1 SPB dan relasi Bonus Ayak ID `883`.

Bukti:

- [Preview settlement sesi A](../../../hasil/screenshots/02M-settlement-preview-a.png)
- [Preview settlement sesi B](../../../hasil/screenshots/02M-settlement-preview-b.png)
- [Hasil reservasi berhasil](../../../hasil/screenshots/02M-settlement-race-success.png)
- [Hasil reservasi ditolak](../../../hasil/screenshots/02M-settlement-race-rejected.png)

## Skenario C — Dua posting Payroll untuk draft yang sama

### Tindakan pengguna

1. Membuka daftar settlement pada dua sesi.
2. Menekan `Post Payroll` pada kedua sesi.
3. Mengonfirmasi dialog `Posting settlement ke Payroll Essentials?` pada kedua sesi.

### Hasil

- Kedua sesi berakhir pada daftar yang sama dan menampilkan `SET-202609-0004` sebagai `POSTED`.
- Read-only database menunjukkan hanya satu `payroll_transaction_id=54293`.
- Transaksi Payroll `54293` memiliki `business_id=2`, `type=payroll`, `sub_type=heavy_equipment_trip`, `status=final`, `final_total=2,600,000`, dan `ref_no=SET-202609-0004`.
- Settlement hanya memiliki satu relasi ke SPB ID `120` dan satu relasi ke Bonus Ayak ID `883`.
- Tidak ada payroll kedua yang terbentuk dari klik bersamaan.

Bukti:

- [Settlement setelah posting sesi A](../../../hasil/screenshots/02M-settlement-posted-a.png)
- [Settlement setelah posting sesi B](../../../hasil/screenshots/02M-settlement-posted-b.png)

## Perbaikan yang ditemukan saat pengujian

1. `HeavyEquipmentSettlementController::store()` sebelumnya memakai `back()` setelah POST. Browser kemudian diarahkan GET ke URL preview yang hanya menerima POST dan menampilkan `GET method is not supported`. Redirect sukses dan gagal sekarang menuju daftar settlement pada [HeavyEquipmentSettlementController.php](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentSettlementController.php:47).
2. `selectedIds()` sebelumnya mengakses array optional secara langsung. Saat user mengeluarkan seluruh Bonus/Kasbon dari preview, request dapat tidak memiliki key tersebut dan memunculkan `Undefined array key`. Sekarang key yang tidak ada diperlakukan sebagai array kosong pada [HeavyEquipmentTripService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php:685).

## Validasi teknis

- `php -l Modules/AssetManagement/Http/Controllers/HeavyEquipmentSettlementController.php` — lulus.
- `php -l Modules/AssetManagement/Services/HeavyEquipmentTripService.php` — lulus.
- `php artisan migrate --pretend --no-interaction` — lulus, tidak ada migrasi tertunda.
- `php artisan view:cache --no-interaction` — lulus.
- `php artisan route:cache --no-interaction` — lulus.
- `python -m graphify update .` — lulus; ada peringatan dependency `tree_sitter_sql` yang tidak terpasang, tidak menghambat graph code.

## Cleanup dan handoff

Settlement `SET-202609-0004` sudah `POSTED`, Payroll `54293` sudah `final`, dan sumbernya terkunci oleh aplikasi. Transaksi posted tidak dihapus atau dibuka paksa. Data uji yang tersisa untuk tahap berikutnya:

- SPB `120` — verified, payroll settled, belum ditagihkan ke invoice pelanggan.
- Bonus Ayak `883` — sudah masuk settlement posted.
- Settlement `SET-202609-0004` — posted.
- Payroll transaction `54293` — final.

Jangan memakai SPB `120` atau Bonus `883` untuk uji billing/payroll baru tanpa membuat fixture baru atau melakukan proses koreksi yang tersedia di aplikasi.

## Tahap berikutnya

Gunakan hasil ini untuk 03A–06H. Catat secara eksplisit bahwa uji konkurensi payroll sudah dibuktikan pada satu fixture, sedangkan uji permission khusus selain role Driver dan skenario request yang dimodifikasi masih mengikuti blocker 02K.
