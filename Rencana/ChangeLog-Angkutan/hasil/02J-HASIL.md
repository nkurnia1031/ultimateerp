# Hasil 02J — Uji Posting Payroll dan Kunci Sumber

## Status

**LULUS DENGAN CATATAN** — tepat satu settlement payroll angkutan berhasil diposting, nominalnya dapat direkonsiliasi melalui halaman HRM Payroll, ledger sopir, dan payslip. Sumber yang sudah diposting tidak tersedia lagi untuk settlement kedua dan tidak dapat diubah melalui jalur UI normal.

Catatan yang masih terbuka:

- Bonus Ayak sumber uji memiliki nominal Rp0, sehingga payslip tidak menampilkan baris allowance `Bonus Ayak`; allowance positif belum diuji pada posting ini.

Tanggal uji: 10 September 2026  
Run: `RUN02J-20260910`  
Business: **CV BM TRANSPORT** (`business_id=2`)  
Sopir: **RUN02A Driver Revised** (`driver_id=93`)

## Data dan settlement

| Data | ID | Keterangan |
|---|---:|---|
| Settlement posted | `SET-202609-0003` | 3 SPB, 1 Bonus Ayak Rp0, 1 Kasbon Rp150.000 |
| Payroll transaction | `54291` | Referensi `SET-202609-0003`, status Due |
| SPB | 1, 2, 119 | verified; sumber payroll dikunci setelah posting |
| Bonus Ayak | 880 | 2 trip sumber, nominal Rp0 |
| Kasbon | 17 | Rp150.000, dikunci payroll |

Settlement sebelumnya `SET-202609-0001` dan `SET-202609-0002` tetap `CANCELLED` dari tahap 02I dan tidak dihitung sebagai payroll posted.

## Journey browser

### 1. Buat draft dan posting

1. Buka `Asset Management → Upah Sopir`.
2. Pilih `RUN02A Driver Revised` dan periode `10-09-2026` sampai `10-09-2026`.
3. Review menampilkan tiga SPB verified, satu Bonus Ayak, dan satu Kasbon.
4. Tekan `Buat Settlement Draft`.
5. Pada daftar, settlement `SET-202609-0003` muncul sebagai `DRAFT`.
6. Tekan `Post Payroll` dan konfirmasi dialog `Posting settlement ke Payroll Essentials?`.
7. Setelah reload, status berubah menjadi `POSTED`; tombol posting/batal tidak lagi tersedia, hanya `Lihat Payroll`.

Bukti preview sebelum posting:

- [Preview sumber posting](../../../hasil/screenshots/02J-preview-posting.png)

Bukti daftar setelah posting:

- Status `SET-202609-0003` terlihat sebagai `POSTED` pada halaman `Upah Sopir`.

### 2. Rekonsiliasi melalui Payroll dan payslip

Menu `HRM → Payroll` menampilkan baris:

```text
Employee       = RUN02A Driver Revised
Month/Year     = September 2026
Reference No   = SET-202609-0003
Total amount   = Rp7.295.000
Payment Status = Due
```

Ledger user sopir menampilkan satu transaksi Payroll dengan referensi yang sama dan total Rp7.295.000. Modal payslip menampilkan:

```text
Basic salary = Rp3.445.000  (53 ton × Rp65.000)
Uang Jalan   = Rp4.000.000  (3 trip × Rp4.000.000)
Total earn.  = Rp7.445.000
Kasbon       = Rp150.000
Net pay      = Rp7.295.000
```

Bukti:

- [Payroll dari tombol Lihat Payroll](../../../hasil/screenshots/02J-payroll-direct-link.png)
- [Payslip Payroll](../../../hasil/screenshots/02J-payslip-modal.png)
- [Detail SPB yang sudah masuk settlement](../../../hasil/screenshots/02J-spb-posted-detail.png)

Rumus settlement cocok dengan tampilan payslip:

```text
Bruto = Rp7.445.000
Netto = Rp7.445.000 - Rp150.000 = Rp7.295.000
```

### 3. Percobaan posting ulang dan settlement kedua

Daftar settlement direload dan `SET-202609-0003` tetap `POSTED`, tanpa tombol `Post Payroll` atau `Batalkan`.

Review periode dan sopir yang sama setelah posting menampilkan:

- `Tidak ada SPB eligible.`
- `Tidak ada Bonus Ayak eligible.`
- `Tidak ada Kasbon eligible.`

Percobaan membuat settlement dari review kosong tidak menambah nomor settlement baru. Daftar tetap berisi satu settlement posted dan dua settlement cancelled. Ini membuktikan sumber posted tidak dapat masuk ke settlement kedua melalui UI.

### 4. Edit/hapus sumber yang sudah posted

#### SPB

Detail `TRIP-202609-0001` menampilkan:

```text
Status: VERIFIED
Sudah masuk settlement SET-202609-0003.
```

Halaman detail tidak menyediakan tombol edit/hapus. Nilai tarif, tonase, nilai akhir, dan total upah tetap dapat dibaca tanpa form perubahan.

#### Bonus Ayak

Pada Bonus Ayak `ID=880`, percobaan edit trip dari 2 menjadi 3 melalui form dan tombol `Update` ditolak. Setelah perbaikan controller, pengguna dikembalikan ke halaman edit dengan pesan:

`Bonus Ayak yang sudah masuk settlement tidak dapat diedit.`

Data pada daftar tetap 2 trip dan sumber `TRIP-202609-0001, TRIP-202609-0003`. Percobaan `Hapus` juga ditolak dengan pesan yang sama untuk operasi penghapusan.

Bukti:

- [Edit Bonus Ayak terkunci dengan pesan validasi](../../../hasil/screenshots/02J-bonus-edit-locked-validation.png)

#### Kasbon

Kasbon `ID=17` tetap tampil Rp150.000 dengan label `Terkunci payroll` dan tidak memiliki tautan Edit. Tombol Hapus masih terlihat karena kompatibilitas tampilan, tetapi setelah konfirmasi baris tetap ada dan nominal tidak berubah.

## Perbaikan yang dilakukan saat uji

`app/Http/Controllers/BonusAyakController.php` sebelumnya memakai `abort(422)` untuk edit/hapus Bonus Ayak yang sudah masuk settlement. Pada aplikasi lokal dengan debug aktif, ini menampilkan halaman teknis. Guard diganti menjadi `ValidationException` agar kembali ke form dengan pesan yang dapat dipahami pengguna; aturan penolakan dan data posted tidak berubah.

Tautan `Lihat Payroll` sebelumnya memakai `TransactionPaymentController::show`, padahal ID yang tersimpan adalah transaksi Payroll Essentials. Tautan diganti ke `Modules\Essentials\Http\Controllers\PayrollController::show`. Setelah reload, klik dari settlement membuka `/hrm/payroll/54291` dan menampilkan payslip yang benar.

Tombol settlement juga diperjelas sebagai `type="submit"` dan diberi ID `submit-settlement-draft` pada:

`Modules/AssetManagement/Resources/views/fleet/settlements/preview.blade.php`

## Kriteria tahap

| Kriteria | Hasil |
|---|---|
| Buat draft melalui browser | Lulus; `SET-202609-0003` |
| Konfirmasi posting Payroll | Lulus |
| Tepat satu payroll posted | Lulus; transaction `54291` |
| Nominal Payroll sama dengan netto settlement | Lulus; Rp7.295.000 |
| Earnings, potongan, dan net pay terlihat pada payslip | Lulus |
| Reload mempertahankan status posted | Lulus |
| Posting ulang ditolak/tidak tersedia | Lulus; tombol hilang |
| Settlement kedua atas sumber yang sama | Lulus; tidak ada sumber eligible |
| Edit/hapus SPB posted | Lulus; form perubahan tidak tersedia |
| Edit/hapus Bonus Ayak posted | Lulus setelah pesan validasi diperbaiki |
| Edit/hapus Kasbon posted | Lulus; terkunci dan tetap ada |
| Allowance Bonus Ayak positif | Belum diuji; fixture Bonus Ayak bernilai Rp0 |
| Tautan `Lihat Payroll` | Lulus setelah diarahkan ke `/hrm/payroll/54291` |

## Pembersihan dan handoff

Tidak ada transaksi posted yang dihapus atau dibuka kuncinya. Data berikut dipertahankan sebagai bukti dan handoff:

- Settlement `SET-202609-0003` berstatus `POSTED`.
- Payroll transaction `54291` berstatus Due.
- SPB 1, 2, dan 119 dengan status payroll settled.
- Bonus Ayak 880 dan Kasbon 17 sebagai sumber posted.
- Settlement 1 dan 2 berstatus `CANCELLED` sebagai bukti uji draft.

Tahap berikutnya harus memakai status ini sebagai data baca/audit. Jangan mencoba menghapus atau mengeditnya melalui database maupun membuka kunci secara paksa.
