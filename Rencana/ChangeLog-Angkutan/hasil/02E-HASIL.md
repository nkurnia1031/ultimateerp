# Hasil 02E - SPB dari Project dan Invoice Project

## Status

**LULUS dengan catatan.** Journey browser dari Project membuat SPB, menyelesaikan dan memverifikasi SPB, memilih SPB untuk invoice, membuat invoice Project, membuka detail invoice, reload, serta menguji penguncian sumber berhasil. Dua masalah keterbacaan yang terbukti saat uji diperbaiki dan diuji ulang.

Catatan teknis: pemeriksaan `artisan` belum dapat berjalan pada PHP CLI lokal karena ekstensi cURL tidak aktif dan bootstrap berhenti pada `Pusher\\PusherException`. `graphify update .` juga belum dapat dijalankan karena executable `graphify` tidak tersedia di PATH.

## Run, business, dan data uji

- Run ID: `RUN02E-20260910`.
- Business aktif: `CV BM TRANSPORT`, business ID `2`.
- Project: `RUN02A Project Angkutan`, ID `11`.
- Customer: `RUN02A Customer Demo Revised`, ID `1802`.
- Lokasi invoice: `CV BM TRANSPORT (BL0001)`, ID `2`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- SPB baru: ID `119`, nomor `TRIP-202609-0003`, tiket `RUN02E-20260910-PROJECT`.
- Invoice Project: transaction ID `54289`, nomor `DR2026/0006`, status `draft`.

Semua pembuatan dan perubahan transaksi dilakukan melalui interaksi browser pada aplikasi lokal. Query database hanya dipakai setelahnya untuk rekonsiliasi read-only.

## Journey browser dan hasil aktual

### A. Membuat SPB dari Project

1. Membuka halaman Project melalui menu Project.
2. Membuka tab **Trip Angkutan**.
3. Menekan **Buat SPB**.
4. Memastikan konteks Project dan Customer sudah terisi otomatis.
5. Mengisi form melalui browser:
   - tiket `RUN02E-20260910-PROJECT`;
   - rute `SSP2 - EUP/GBE`;
   - unit ID `39` dan sopir ID `93`;
   - tanggal bongkar `2026-09-10`;
   - gross `24` ton, tare `2` ton, netto aktual `22` ton;
   - tonase diterima `21` ton dan tonase tagihan `21` ton;
   - selisih `100` kg, toleransi `50` kg, tarif klaim `Rp1.000/kg`;
   - potongan lain `Rp50.000`.
6. Menekan **Simpan**.
7. Memastikan SPB muncul pada tab Project, lalu membuka detailnya.

Hasil: SPB ID 119 muncul pada Project dengan rute, unit, sopir, tonase, nilai akhir, dan status `DRAFT`. Detail dapat dibuka dan menampilkan nilai dasar `Rp2.415.000`, penyesuaian klaim `Rp150.000`, nilai akhir `Rp2.515.000`, serta total upah sopir `Rp2.730.000`.

Bukti: [form SPB terisi](screenshots/02E-project-spb-filled.png), [SPB muncul pada Project](screenshots/02E-project-trip-created.png), [detail SPB](screenshots/02E-project-spb-detail.png).

### B. Menyelesaikan dan memverifikasi SPB

1. Membuka daftar **SPB Angkutan**.
2. Menekan **Selesaikan** pada SPB ID 119.
3. Menekan **Verify** pada SPB yang sudah selesai.

Hasil: status berubah berurutan dari `DRAFT` menjadi `SELESAI`, kemudian `VERIFIED`. SPB verified dapat dipilih untuk invoice. Bukti: [SPB verified](screenshots/02E-project-spb-verified.png).

### C. Memilih SPB untuk invoice Project

1. Kembali ke Project ID 11 dan membuka tab **Trip Angkutan**.
2. Menekan **Pilih SPB untuk Invoice**.
3. Memeriksa daftar sebelum memilih.
4. Memilih hanya SPB ID 119.
5. Menekan **Lanjutkan**.
6. Memilih lokasi bisnis ID 2 pada form invoice.
7. Memeriksa preview lalu menekan **Buat Invoice Project**.

Hasil sebelum penagihan: chooser hanya menampilkan SPB Project yang verified dan belum billed; SPB ID 119 serta SPB ID 1 tersedia. SPB dari Project lain tidak muncul.

Hasil invoice: aplikasi menampilkan pesan `Invoice Project DR2026/0006 berhasil dibuat.` Invoice berstatus `Draft`, payment status `Due`, customer sesuai Project, dan nilai `Rp2.515.000`.

Bukti: [chooser sebelum billing](screenshots/02E-project-invoice-chooser.png), [preview invoice awal](screenshots/02E-project-invoice-preview.png), [hasil invoice pada Project](screenshots/02E-project-invoice-result.png).

### D. Detail invoice dan reload

Invoice dibuka dari nomor invoice pada tab **Invoices** Project. Detail aktual:

- customer `RUN02A Customer Demo Revised`;
- Project `RUN02A Project Angkutan`;
- task `Angkutan TRIP-202609-0003`;
- quantity `21.00` ton;
- rate `Rp115.000`;
- total line, subtotal, dan total payable `Rp2.515.000`.

Halaman Project kemudian direload. Invoice `DR2026/0006` tetap tampil dengan nilai yang sama. Ini membuktikan relasi invoice tersimpan, bukan hanya hasil tampilan sementara.

Bukti: [detail invoice](screenshots/02E-project-invoice-detail.png), [detail invoice setelah perbaikan label customer](screenshots/02E-project-invoice-detail-after-fix.png).

### E. Uji chooser setelah billing

Chooser dibuka kembali melalui Project. SPB ID 119 tidak lagi muncul, sedangkan SPB ID 1 yang belum billed masih muncul. Ini memenuhi pencegahan billing ganda pada jalur normal UI.

Bukti: [chooser setelah billing](screenshots/02E-project-invoice-chooser-after-billing.png).

### F. Uji transaksi terkunci

Detail SPB ID 119 menampilkan pesan bahwa SPB sudah ditagihkan ke transaksi `#54289 (project_invoice)`. Tidak ada tombol edit yang tersedia pada detail. Percobaan membuka jalur edit melalui browser ditolak dengan pesan:

`SPB yang sudah ditagihkan, disettle, verified, atau dibatalkan tidak dapat diedit.`

Menu SPB saat ini tidak menyediakan tombol/route hapus. Penghapusan tidak digantikan dengan SQL atau request manual.

Bukti: [SPB terkunci](screenshots/02E-locked-source-edit.png).

## Rekonsiliasi nominal

Rumus yang tampak dari form dan detail:

```text
Netto             = 24 - 2 = 22 ton
Tonase tagihan    = 21 ton
Nilai dasar       = 21 x Rp115.000 = Rp2.415.000
Penyesuaian klaim = (100 + 50) kg x Rp1.000 = Rp150.000
Potongan lain     = Rp50.000
Nilai akhir       = Rp2.415.000 - Rp50.000 + Rp150.000 = Rp2.515.000
```

Upah sopir pada detail SPB:

```text
Upah variabel = 22 x Rp65.000 = Rp1.430.000
Uang jalan    = Rp1.300.000
Total upah    = Rp2.730.000
```

Pemeriksaan database read-only mengonfirmasi:

- SPB ID 119 berada pada business 2, Project 11, customer 1802, status `verified`, `invoice_status=billed`, `payroll_status=unsettled`.
- Billing link hanya satu: trip 119 ke transaction 54289 dengan `billing_type=project_invoice`, quantity 21, amount 2515000.
- Transaction 54289 berada pada business 2, Project 11, customer 1802, `sub_type=project_invoice`, `status=draft`, `final_total=2515000`.
- Invoice line menyimpan quantity 21, rate 115000, dan total 2515000.

## Perbaikan yang dilakukan

1. Daftar invoice Project sebelumnya membaca `contact->name` saja. Customer uji menyimpan nama badan usaha pada `supplier_business_name`, sehingga kolom Customer kosong. Daftar sekarang menggunakan fallback nama badan usaha atau contact ID.
2. Modal detail invoice memiliki masalah yang sama. Label customer sekarang menampilkan `supplier_business_name`, lalu `name`, lalu `contact_id` sebagai fallback.
3. Preview invoice sebelumnya memberi label umum `Ton` tetapi menampilkan `actual_tons`, sementara invoice memakai `billable_tons`. Preview sekarang menampilkan `Ton Aktual`, `Ton Tagihan`, `Harga/Ton`, dan `Nilai Akhir`, disertai penjelasan bahwa nilai akhir dapat memuat klaim dan potongan.

File yang berubah:

- `Modules/Project/Http/Controllers/InvoiceController.php`
- `Modules/Project/Resources/views/invoice/show.blade.php`
- `Modules/AssetManagement/Resources/views/fleet/billing/project_invoice.blade.php`

Bukti perbaikan preview: [preview dengan label yang diperjelas](screenshots/02E-project-invoice-preview-after-ux-fix.png). Preview menggunakan SPB ID 1 lalu dibatalkan melalui tombol **Batal**; tidak ada invoice tambahan.

## Checklist hasil

| Pemeriksaan | Hasil |
|---|---|
| Project/customer otomatis terisi saat membuat SPB | Lulus |
| SPB tampil pada Project dan detail dapat dibuka | Lulus |
| Status draft -> selesai -> verified melalui tombol UI | Lulus |
| Hanya SPB verified/unbilled Project yang muncul pada chooser | Lulus |
| Invoice Project tersimpan dan tampil setelah reload | Lulus |
| Total invoice sama dengan nilai akhir SPB | Lulus |
| SPB hilang dari chooser setelah billed | Lulus |
| Edit SPB yang sudah billed/verified ditolak | Lulus |
| Hapus SPB billed melalui UI | Belum tersedia; tidak dipaksakan |
| Batalkan dialog hapus | Belum tersedia karena operasi hapus tidak ada |
| Sell dan settlement Payroll | Tidak diuji pada tahap ini; dilanjutkan 02F dan 02K |
| Business scoping pada Project/chooser/billing | Lulus pada business aktif; akses business lain dilanjutkan uji negatif khusus |

## Handoff dan cleanup

SPB ID 119 dan invoice draft `DR2026/0006` dipertahankan sebagai data handoff karena sumber sudah billed dan tidak tersedia proses koreksi/unlink yang aman pada UI. Tidak ada transaksi posted yang dibuka kuncinya. SPB ID 1 tetap unbilled untuk pengujian berikutnya; SPB ID 2 tetap disediakan untuk tahap Sell.

Import SPB ID 3 - 118 dari 02D tetap draft dan tidak dipakai pada tahap ini.

## Pemeriksaan teknis

| Pemeriksaan | Hasil |
|---|---|
| `php -l Modules/Project/Http/Controllers/InvoiceController.php` | Lulus |
| `git diff --check` pada file perubahan tahap ini | Lulus; peringatan line ending worktree tetap ada |
| `php artisan migrate --pretend --no-interaction` | Belum lulus: PHP CLI lokal tidak memiliki ekstensi cURL; bootstrap berhenti pada `Pusher\\PusherException` |
| `php artisan view:cache --no-interaction` | Belum lulus karena blocker cURL/Pusher yang sama |
| `php artisan route:cache --no-interaction` | Belum lulus karena blocker cURL/Pusher yang sama |
| Automated test Asset/Project invoice | Tidak tersedia test khusus yang dapat dijalankan pada suite saat ini |
| `graphify update .` | Belum dapat dijalankan; executable `graphify` tidak tersedia di PATH |
| Publish JavaScript module | Tidak diperlukan; perubahan hanya controller dan Blade |

## Catatan tahap berikutnya

- Jangan membuat invoice kedua dari SPB ID 119. Gunakan SPB ID 2 untuk tahap Sell dan pertahankan SPB ID 1 bila perlu skenario preview yang belum billed.
- Ketidaksesuaian nominal antara rate x tonase dan nilai akhir sudah dibuat eksplisit di preview. Jika bisnis menginginkan invoice line memecah klaim/potongan sebagai baris terpisah, itu perlu keputusan desain dan tahap implementasi tersendiri.
- Invoice draft dan SPB billed membutuhkan prosedur koreksi UI sebelum dapat dibersihkan; jangan menghapus billing link langsung dari database.
- Tahap berikutnya: `02F-UJI-SPB-MELALUI-SELL.md`.
