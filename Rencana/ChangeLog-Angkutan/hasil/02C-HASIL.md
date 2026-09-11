# Hasil 02C — Uji Form dan Verifikasi SPB

## Status

**LULUS dengan catatan UX/perilaku.** Form SPB, penyimpanan, edit, hitung ulang, transisi status, validasi tonase, penolakan tarif yang tidak tersedia, snapshot tarif, dan penguncian SPB verified berhasil dibuktikan melalui `agent-browser` pada business `CV BM TRANSPORT`.

## Data uji dan handoff

Run ID: `RUN02C-20260910`

| ID | Nomor tiket | Status | Pemakaian berikutnya |
|---:|---|---|---|
| 1 | `RUN02C-20260910-MAIN` | `verified`, `unbilled`, `unsettled` | Project invoice dan settlement pada 02E/02K |
| 2 | `RUN02C-20260910-SELL` | `verified`, `unbilled`, `unsettled` | Sell dari SPB pada 02F |

Relasi fixture yang dipakai:

- Business: `CV BM TRANSPORT`.
- Project: ID `11`, `RUN02A Project Angkutan`.
- Customer: ID `1802`, `RUN02A Customer Demo Revised`.
- Unit utama: asset ID `39`, `RUN02A Unit Angkutan Revised`.
- Unit kedua: asset ID `38`, `B 0004`.
- Sopir: user ID `93`, `RUN02A Driver Revised`.
- Tarif aktif: batch tarif ID `3`, hasil import workbook pada 02B.

Jangan hapus atau ubah dua SPB di atas sebelum tahap invoice, Sell, Bonus Ayak, dan settlement selesai. Keduanya belum menjadi invoice atau payroll.

## Bukti browser

- [Detail SPB utama](screenshots/02C-spb-detail-main.png) — keadaan setelah dibuat: route `SSP2 - EUP/GBE`, unit, sopir, tonase, tarif, klaim, nilai akhir, dan total upah.
- [Daftar SPB verified](screenshots/02C-spb-verified-list.png) — daftar menampilkan SPB utama dengan status `Verified` dan `unbilled`.

Screenshot pertama diambil sebelum edit akhir sehingga masih memperlihatkan contoh gross `22`, tare `2`, netto `20`. Nilai final yang bertahan setelah reload adalah gross `23`, tare `2`, netto `21`, diterima/tagihan `21`, nilai akhir `Rp2.515.000`, dan total upah `Rp2.665.000`.

## Journey yang dijalankan

### 1. Buat SPB utama

1. Membuka menu Asset Management → **SPB Angkutan**.
2. Menekan **Buat Trip**.
3. Mengisi nomor tiket `RUN02C-20260910-MAIN` melalui form.
4. Memilih rute `SSP2 - EUP/GBE`, unit asset `39`, sopir user `93`, Project `11`, dan customer `1802` melalui dropdown.
5. Mengisi gross `22`, tare `2`, netto `20`, diterima `20`, selisih `100 kg`, toleransi `50 kg`, tarif klaim `Rp1.000/kg`, dan potongan lain `Rp50.000`.
6. Menekan **Simpan SPB**.
7. Membuka detail SPB dari hasil penyimpanan.

Hasil aktual: SPB ID `1`, nomor internal `TRIP-202609-0001`, status `DRAFT`. Relasi unit, sopir, Project, customer, dan tarif tampil benar.

Perhitungan yang tampil:

```text
Netto              = 22 - 2 = 20 ton
Tonase tagihan     = 20 ton
Nilai dasar        = 20 × Rp115.000 = Rp2.300.000
Penyesuaian klaim  = (100 + 50) kg × Rp1.000 = Rp150.000
Nilai akhir        = Rp2.300.000 - Rp50.000 + Rp150.000 = Rp2.400.000
Upah variabel     = 20 × Rp65.000 = Rp1.300.000
Total upah        = Rp1.300.000 + Rp1.300.000 uang jalan = Rp2.600.000
```

### 2. Edit dan reload

1. Menekan **Edit** dari detail SPB draft.
2. Mengubah gross menjadi `23`, netto menjadi `21`, diterima menjadi `21`, dan ton tagihan menjadi `21`.
3. Menekan **Simpan Perubahan**.
4. Memuat ulang detail melalui navigasi aplikasi.

Hasil aktual setelah reload: gross `23`, tare `2`, netto `21`, diterima `21`, tagihan `21`, nilai dasar `Rp2.415.000`, nilai akhir `Rp2.515.000`, dan total upah `Rp2.665.000`. Snapshot Harga/Ton `Rp115.000` dan Supir/Ton `Rp65.000` tetap berasal dari tarif aktif.

Catatan: field netto dapat diedit manual. Pengujian mengisinya eksplisit menjadi `21`, sehingga sistem menerima nilai tersebut sebagai tonase aktual. Jika bisnis mengharuskan netto selalu dipaksa sama dengan gross dikurangi tare, itu masih menjadi keputusan/pekerjaan UX terpisah.

### 3. Selesaikan dan verifikasi

1. Kembali ke daftar SPB.
2. Menekan **Selesaikan** pada SPB utama.
3. Memastikan daftar berubah menjadi `Selesai`.
4. Menekan **Verify**.
5. Memastikan daftar berubah menjadi `Verified` dan tetap `unbilled`.

Hasil aktual: kedua transisi berhasil melalui tombol POST yang terlihat pada daftar. Setelah verified, checkbox billing tampil dan link detail tidak lagi menampilkan tombol **Edit**.

### 4. Buat SPB kedua untuk Sell

SPB kedua dibuat melalui form browser dengan nomor tiket `RUN02C-20260910-SELL`, unit `B 0004`, sopir user `93`, customer `1802`, netto/diterima/tagihan `10 ton`, dan route yang sama. Setelah disimpan, diedit untuk melengkapi nomor tiket dan customer, lalu melalui `Selesaikan` dan `Verify`.

Hasil aktual: SPB ID `2`, nomor internal `TRIP-202609-0002`, status `verified`, `unbilled`, `unsettled`; siap dipakai 02F.

### 5. Validasi negatif

| Skenario | Tindakan browser | Hasil aktual |
|---|---|---|
| Tonase negatif | Mengisi netto `-1` lalu menekan simpan | Browser menolak submit dengan pesan native `Value must be greater than or equal to 0.`; tidak membuat SPB baru. |
| Tarif tidak tersedia | Memilih rute yang sama dengan unit `Dozer 01` yang tidak punya tarif aktif, mengisi netto `1`, lalu simpan | Form kembali ke halaman yang sama dan menampilkan alert `Tarif aktif untuk rute, unit, dan tanggal trip tidak ditemukan...`; tidak ada SPB baru. |
| Edit transaksi terkunci | Membuka URL edit SPB ID `1` setelah status verified melalui browser | Aplikasi menolak dengan halaman HTTP 422 berjudul `SPB yang sudah ditagihkan, disettle, verified, atau dibatalkan tidak dapat diedit.` |

### 6. Temuan dan perbaikan selama uji

Percobaan pertama menekan **Selesaikan** mengirim browser ke form invoice karena tabel dibungkus form billing dan form status berada di dalamnya. Ini adalah bug HTML form bersarang yang memengaruhi alur user.

Perbaikan yang diterapkan di `Modules/AssetManagement/Resources/views/fleet/trips/index.blade.php`:

- form billing dibuat terpisah;
- checkbox menggunakan atribut `form="trip_billing_form"`;
- form status tidak lagi menjadi form bersarang;
- tombol status diberi `type="submit"` eksplisit;
- pesan status/error ditampilkan sebagai alert di halaman daftar dan form.

Setelah perbaikan, alur `Draft → Selesai → Verified` diulang dan berhasil.

## CRUD dan cleanup

- Buat dan edit berhasil.
- Daftar dan detail berhasil dibuka, termasuk pemeriksaan setelah reload.
- Tidak ada tombol atau route hapus SPB pada implementasi yang diaudit; tidak menambahkan penghapusan lewat SQL/controller/API karena itu bukan bagian uji browser. Karena tidak ada operasi hapus yang disediakan, skenario konfirmasi batal/hapus dicatat sebagai **belum tersedia**, bukan dianggap lulus.
- SPB utama dan kedua sengaja dipertahankan sebagai handoff untuk tahap berikutnya.
- Percobaan negatif tidak menghasilkan transaksi tambahan. Tidak ada data posted yang perlu dibuka kuncinya.

## Pemeriksaan tambahan

| Pemeriksaan | Hasil |
|---|---|
| `php -l Modules/AssetManagement/Services/HeavyEquipmentTripService.php` | Lulus, tidak ada syntax error. |
| `git diff --check` | Lulus; hanya ada peringatan normal perubahan line ending pada worktree. |
| `php artisan view:cache --no-interaction` | Belum dapat dijalankan karena PHP CLI lokal tidak memiliki ekstensi cURL; bootstrap gagal pada `Pusher\\PusherException`. |
| `graphify update .` | Belum dapat dijalankan karena perintah `graphify` tidak tersedia di PATH; graph lama tetap dipakai read-only. |

## Catatan tahap selanjutnya

- 02D masih menguji import workbook SPB, sehingga jangan mencampur fixture import dengan dua SPB ini.
- 02E harus memilih hanya SPB ID `1` untuk invoice Project agar SPB ID `2` tetap tersedia untuk Sell 02F.
- 02K harus menggunakan driver user `93`, periode `2026-09-10`, dan memasukkan SPB ID `1`/`2` hanya sesuai skenario settlement yang dipilih.
- Nominal invoice harus diverifikasi terhadap `revenue_total` SPB, sedangkan payroll harus memakai `driver_pay_total`; keduanya tidak boleh dicampur.
