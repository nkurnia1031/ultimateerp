# Hasil 02H — Kasbon Bonus Ayak

## Status

**LULUS dengan catatan.** Kasbon berhasil dibuat melalui UI dengan relasi business dan sopir, dibuka kembali melalui jalur Edit, diubah nominalnya, dipastikan bertahan setelah reload, diuji batal hapus, lalu fixture terpisah dihapus melalui konfirmasi browser. Validasi nominal negatif menghentikan submit tanpa request. Satu Kasbon utama dipertahankan untuk tahap settlement dan belum dipotong payroll.

Catatan implementasi: sebelum tahap ini halaman Kasbon hanya memiliki Buat dan Hapus, padahal instruksi mewajibkan Edit. Jalur Edit/Update ditambahkan sebagai perbaikan dalam lingkup tahap ini. Route cache lokal sempat menyembunyikan route baru; cache route generated di workspace dibersihkan melalui skrip sementara yang langsung dihapus setelah dipakai.

## Run, business, dan data uji

- Run ID: `RUN02H-20260910`.
- Browser: `agent-browser`, sesi `changelog02h2` digunakan untuk verifikasi akhir.
- URL lokal: `http://localhost/2025/ultimateerp/public/`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- Kasbon handoff: ID `17`, tanggal `2026-09-10`, nominal akhir `Rp150.000`, business ID `2`, driver ID `93`.
- Kasbon fixture sementara: ID `18`, dibuat Rp175.000, diedit menjadi Rp180.000, kemudian dihapus melalui UI.

Semua perubahan data dilakukan melalui form, tombol, navigasi, dan dialog aplikasi. Pemeriksaan database hanya baca untuk memastikan ID, business, relasi sopir, dan belum adanya pivot settlement.

## Journey browser dan hasil aktual

### A. Membuat Kasbon utama untuk settlement

1. Membuka menu **Pool -> Bonus Ayak -> Form Kasbon Bonus Ayak**.
2. Memilih `RUN02A Driver Revised` dari selector sopir.
3. Membiarkan tanggal UI pada `10-09-2026`.
4. Mengisi Jumlah Kasbon `150000`.
5. Menekan **Simpan**.

Hasil aktual: halaman menampilkan `Kasbon berhasil disimpan.` dan row baru muncul:

```text
10-09-2026 | RUN02A Driver Revised | Rp150.000
```

ID row yang dibuka dari link Edit adalah `17`. Setelah halaman dimuat ulang, row tetap tampil dan total berubah dari `Rp2.840.000` menjadi `Rp2.990.000`.

Bukti: [form Kasbon utama](../../../hasil/screenshots/02H-kasbon-primary-filled.png), [Kasbon tersimpan](../../../hasil/screenshots/02H-kasbon-primary-saved.png).

### B. Edit nominal dan reload

1. Membuat fixture terpisah ID `18` dengan nominal Rp175.000 melalui form UI.
2. Membuka link **Edit** pada row ID 18.
3. Halaman Edit menampilkan tanggal `10-09-2026`, sopir ID 93, dan nominal `175000`.
4. Mengubah nominal menjadi `180000`.
5. Menekan **Update**.
6. Memuat ulang halaman Kasbon.

Hasil aktual: aplikasi menampilkan `Kasbon berhasil diupdate.`. Row ID 18 berubah menjadi Rp180.000 dan tetap Rp180.000 setelah reload. Total sementara menjadi Rp3.170.000.

Bukti: [Kasbon setelah edit dan reload](../../../hasil/screenshots/02H-kasbon-edited-reloaded.png).

### C. Batal dan konfirmasi hapus

1. Pada row ID 18, menekan **Hapus**.
2. Memilih **Dismiss** pada dialog `Hapus kasbon ini?`.

Hasil aktual: row ID 18 tetap ada dengan nominal Rp180.000.

3. Memuat ulang halaman agar tombol submit aktif kembali.
4. Menekan **Hapus** pada row ID 18.
5. Memilih **Accept** pada dialog browser.

Hasil aktual: aplikasi menampilkan `Kasbon berhasil dihapus.`. ID 18 hilang dari daftar dan total kembali menjadi Rp2.990.000. Kasbon handoff ID 17 tetap ada.

Bukti: [batal hapus](../../../hasil/screenshots/02H-kasbon-delete-cancelled.png), [fixture terhapus](../../../hasil/screenshots/02H-kasbon-temp-deleted.png).

### D. Validasi nominal

1. Mengisi selector sopir 93 melalui form UI.
2. Mengisi nominal `-1`.
3. Menekan **Simpan**.

Hasil aktual: validasi native browser menolak input karena field memiliki `min=0`; tidak ada request `POST /bonus-ayak/kasbon` tercatat pada network log sesi browser. Halaman tetap berada di form, row ID 17 tetap ada, dan tidak ada Kasbon baru. Setelah reload nilai form kembali `0`.

Bukti: [nominal negatif ditolak](../../../hasil/screenshots/02H-kasbon-invalid-amount.png).

### E. Selector business dan relasi

Pada halaman Kasbon browser menampilkan business aktif `CV BM TRANSPORT`. Selector sopir berisi `86` option user, sama dengan jumlah user `user_type=user` pada business ID 2 yang dibaca dari database. Driver ID 93 tersedia dan berhasil dipilih.

Pemeriksaan database baca saja setelah semua journey:

```text
bonus_ayak_kasbon ID 17 | business_id 2 | driver_id 93
tanggal 2026-09-10 | jumlah_kasbon 150000.00
heavy_equipment_settlement_kasbons untuk kasbon 17 = 0 row
```

Kasbon ID 18 tidak lagi ada setelah cleanup UI. Tidak ada pilihan user business lain yang ditambahkan ke form; pengujian lintas business dan permission lengkap tetap menjadi bagian tahap khusus berikutnya.

## Handoff settlement

Kasbon ID `17` dipertahankan untuk settlement gabungan bersama Bonus Ayak ID `880` dan sumber SPB yang sudah dicatat pada hasil 02G. Kasbon ini masih tersedia, belum masuk settlement, dan belum menjadi deduction Payroll. Tahap settlement harus memeriksa bahwa nominal `Rp150.000` masuk sebagai potongan dan tidak dapat dipilih dua kali.

## Perbaikan yang dilakukan

1. Menambahkan route UI:
   - `GET /bonus-ayak/kasbon/{id}/edit`;
   - `PUT /bonus-ayak/kasbon/{id}`.
2. Menambahkan `kasbonEdit` dan `kasbonUpdate` dengan business scope, validasi driver aktif, dan penguncian ketika sudah memiliki settlement.
3. Menambahkan tombol Edit pada daftar Kasbon dan menampilkan badge `Terkunci payroll` untuk sumber yang sudah masuk settlement.
4. Menambahkan pesan error validasi pada halaman Kasbon.
5. Mengubah penolakan hapus Kasbon yang sudah settle menjadi validasi form yang ramah, bukan halaman debug 422.

File perubahan:

- `routes/web.php`
- `app/Http/Controllers/BonusAyakController.php`
- `resources/views/bonus_ayak/kasbon_form.blade.php`

## Checklist

| Pemeriksaan | Hasil |
|---|---|
| Buat Kasbon melalui UI | Lulus; ID 17 |
| Relasi business dan sopir | Lulus; business 2, driver 93 |
| Buka daftar dan detail/edit | Lulus; `/bonus-ayak/kasbon/18/edit` terbuka melalui link UI |
| Edit nominal | Lulus; Rp175.000 menjadi Rp180.000 |
| Reload mempertahankan perubahan | Lulus |
| Batal dialog hapus | Lulus; ID 18 tetap ada |
| Hapus fixture terpisah | Lulus; ID 18 hilang dan total kembali |
| Validasi nominal negatif | Lulus; submit diblokir browser, tidak ada request POST |
| Kasbon handoff tersedia untuk payroll | Lulus; ID 17 belum memiliki pivot settlement |
| Kasbon locked setelah settlement | Belum diuji pada tahap ini; menunggu settlement 02I |
| Lintas business dan permission | Belum diuji; tahap permission/business khusus |

## Cleanup dan masalah tersisa

- ID 17 dipertahankan sebagai handoff.
- ID 18 sudah dihapus melalui UI setelah screenshot.
- Tidak ada transaksi posted yang dibuka kuncinya.
- Route cache generated sempat berisi route lama sehingga route Edit tidak ditemukan. Setelah cache lokal dibersihkan, route baru terbukti melalui browser. Cache deployment perlu dibangun ulang pada environment dengan PHP CLI yang sehat.

## Pemeriksaan teknis

| Pemeriksaan | Hasil |
|---|---|
| `php artisan migrate --pretend --no-interaction` | Lulus; `Nothing to migrate.` |
| `php -l app/Http/Controllers/BonusAyakController.php` | Lulus; no syntax errors |
| `git diff --check` pada file tahap ini | Lulus; hanya peringatan line ending yang ada |
| `php artisan view:cache --no-interaction` | Lulus; Blade templates cached successfully |
| `php artisan route:cache --no-interaction` | Lulus; routes cached successfully, termasuk route Edit/Update Kasbon |
| `python -m graphify update .` | Lulus; graph updated melalui module Python karena executable wrapper tidak tersedia di PATH |
| Publish JavaScript module | Tidak diperlukan; tidak ada source JavaScript module yang diubah |

Prasyarat CLI yang sempat menghambat cache diperbaiki pada sesi ini di PHP lokal Laragon dengan mengaktifkan `curl` dan `pdo_mysql` pada `php.ini`. Perubahan ini berada di environment lokal, bukan di repository aplikasi.

## Catatan tahap berikutnya

Lanjutkan ke tahap settlement yang memakai Bonus Ayak ID 880, Kasbon ID 17, dan SPB sumbernya. Jangan membuat Kasbon baru untuk menggantikan ID 17. Uji kunci edit/hapus setelah settlement draft atau posted sesuai instruksi tahap tersebut.
