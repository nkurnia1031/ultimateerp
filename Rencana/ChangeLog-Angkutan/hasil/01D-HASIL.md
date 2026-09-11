# Hasil 01D — Audit Bonus Ayak, Relasi SPB, dan Kasbon

## Status

**LULUS untuk audit kode read-only.** Journey browser, import, settlement, dan posting payroll belum diuji pada bagian ini. Tidak ada data operasional yang dibuat atau diubah.

## Kesimpulan

Perbaikan relasi sudah tersedia secara struktural. Bonus Ayak sekarang bukan hanya teks: ia dapat memiliki business, user/sopir, asset/unit, project, pembuat, beberapa SPB sumber, dan settlement. Kasbon juga memiliki business, user/sopir, pembuat, dan settlement.

Namun, masih ada risiko yang harus dibuktikan melalui bagian pengujian:

1. Rumus Bonus Ayak yang berjalan adalah `0` untuk kurang dari 50 trip, lalu `50.000 + 2.000 × (trip - 50)`. Ini belum tentu sama dengan maksud bisnis pada workbook/contoh. Bonus yang dibuat dari SPB menghitung ulang jumlah SPB sebagai `trip`, sehingga satu SPB dapat menghasilkan bonus `0`.
2. Import Bonus Ayak belum memiliki preview, belum membungkus seluruh pembuatan dalam satu transaksi, belum mengimpor project/SPB sumber, dan belum mendeteksi duplikasi sebelum menulis.
3. Backfill relasi driver mencari user dari seluruh business lalu hanya mengisi jika hasilnya unik. Data yang memiliki nama sama antar-business perlu diuji agar tidak salah terhubung.
4. Penghapusan Bonus Ayak menghapus parent dan mengandalkan foreign key cascade pada pivot. Perilaku pelepasan SPB harus diverifikasi lewat UI dan pemeriksaan database read-only.

## Matriks implementasi

| Area | Yang ditemukan | Bukti kode | Status/risko |
|---|---|---|---|
| Identitas sopir | Form memakai `driver_id`; nama disalin ke `nama_operator` sebagai snapshot | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:85), [`BonusAyak.php`](../../../app/BonusAyak.php:11) | Ada; uji business dan legacy |
| Identitas unit | Form memakai `asset_id`; nama disalin ke `unit` sebagai snapshot | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:98), [`BonusAyak.php`](../../../app/BonusAyak.php:34) | Ada; uji business dan legacy |
| Project | `project_id` nullable dan divalidasi dalam business aktif | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:100), [`2026_09_09_000002_add_bonus_ayak_relations.php`](../../../database/migrations/2026_09_09_000002_add_bonus_ayak_relations.php:1) | Ada; uji pilihan project |
| Sumber SPB | Pivot many-to-many `bonus_ayak_trip`; satu SPB dibatasi unik di pivot | [`2026_09_10_000001_integrate_spb_bonus_payroll.php`](../../../database/migrations/2026_09_10_000001_integrate_spb_bonus_payroll.php:33), [`BonusAyak.php`](../../../app/BonusAyak.php:44) | Ada; uji multi-SPB dan duplikasi |
| Validasi sumber SPB | SPB harus verified, business aktif, driver/unit sama, dan belum dipakai Bonus lain | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:332) | Ada; uji pesan error |
| Kasbon | Kasbon dibuat dengan business, driver, creator; filter query business aktif | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:267), [`BonusAyakKasbon.php`](../../../app/BonusAyakKasbon.php:1) | Ada; uji CRUD dan settlement lock |
| Settlement Bonus | Pivot settlement membatasi satu Bonus Ayak hanya pada satu settlement | [`2026_09_10_000001_integrate_spb_bonus_payroll.php`](../../../database/migrations/2026_09_10_000001_integrate_spb_bonus_payroll.php:66) | Ada secara DB; uji draft/posting/concurrency |
| Settlement Kasbon | Pivot settlement membatasi satu kasbon hanya pada satu settlement | [`2026_09_10_000001_integrate_spb_bonus_payroll.php`](../../../database/migrations/2026_09_10_000001_integrate_spb_bonus_payroll.php:84) | Ada secara DB; uji draft/posting/concurrency |
| Legacy | Snapshot lama dipertahankan; backfill hanya mengisi relasi jika nama cocok unik | [`2026_09_09_000004_backfill_bonus_ayak_driver_relations.php`](../../../database/migrations/2026_09_09_000004_backfill_bonus_ayak_driver_relations.php:10) | Perlu uji data unmatched |
| Import | Import memakai `BonusAyakImport`, langsung membuat banyak row, tanpa preview dan tanpa project/SPB | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:206), [`BonusAyakImport.php`](../../../app/Imports/BonusAyakImport.php:1) | Gap P1 untuk UX dan atomicity |
| Edit/hapus terkunci | Edit dan hapus menolak Bonus yang sudah memiliki settlement; kasbon menolak yang sudah dipotong payroll | [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:164), [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:195), [`BonusAyakController.php`](../../../app/Http/Controllers/BonusAyakController.php:288) | Uji browser |

## Temuan UX

- Menu Bonus Ayak menyediakan input, filter, export, edit, hapus, dan tautan Kasbon.
- Form menyediakan project dan pilihan beberapa SPB sumber. Keterangan form sudah menjelaskan bahwa SPB harus verified serta memakai sopir dan unit yang sama.
- Hapus memakai dialog konfirmasi browser. Tombol batal dialog harus masuk skenario pengujian agar data dipastikan tetap ada.
- Import masih berupa satu aksi upload langsung; tidak terlihat tahap preview/mapping seperti import tarif/SPB. Ini dapat membingungkan pengguna dan berisiko menghasilkan partial write bila baris berikutnya gagal.
- Nama snapshot masih dipakai untuk export/PDF/rekap bersama foreign key. Ini baik untuk histori, tetapi harus diuji ketika user atau asset berubah nama.

## Risiko business dan permission

- Controller membatasi user dan asset dengan `business_id` aktif saat membuat/edit/kasbon.
- Daftar sumber SPB juga dibatasi business aktif, status `verified`, dan belum memiliki Bonus Ayak.
- Pivot menyimpan `business_id` dan foreign key ke business.
- Backfill migration memuat seluruh user lintas business sebelum mencocokkan nama. Walaupun hasil harus unik secara global, ini tidak membuktikan bahwa data legacy tidak salah business. Bagian `02K` wajib membuat fixture lokal lintas business melalui UI bila memungkinkan dan memeriksa hasilnya secara read-only.
- Permission umum memakai `bonus_ayak.access` atau `bonus_ayak.manage`; permission detail perlu diuji untuk input, edit, hapus, import, kasbon, dan export.

## Ketidaksesuaian terhadap rencana bisnis

- Rencana menginginkan Bonus Ayak dapat dikaitkan ke beberapa SPB dan hanya satu kali untuk setiap SPB. Struktur dan validasi dasar sudah ada.
- Rencana menginginkan import dengan preview dan error per baris. Implementasi import saat ini belum memenuhi ini.
- Rencana menginginkan Bonus Ayak dapat manual tanpa SPB. Implementasi mendukungnya, tetapi nilai manual tetap tunduk pada rumus `hitungBonus(trip)` dan bukan nominal bebas.
- Rencana menginginkan settlement gabungan SPB + Bonus Ayak − Kasbon. Kolom/pivot pendukung sudah dibuat, tetapi proses UI dan posting payroll belum diaudit pada bagian ini.

## Handoff pengujian

- `02G` menguji Bonus Ayak manual, edit, hapus, batal hapus, project, dan multi-SPB.
- `02H` menguji Kasbon dan kunci setelah masuk settlement.
- `02I` menguji settlement gabungan serta memastikan rumus bisnis Bonus Ayak disepakati dari perilaku aplikasi.
- `02K` menguji business scope, permission, dan validasi sumber.
- `02L` menguji duplikasi dan dua tab.
- `02N` menguji cleanup melalui UI.

Bagian berikutnya yang dapat dijalankan tanpa input tambahan adalah [`01E-AUDIT-SETTLEMENT-DAN-LAPORAN.md`](../01E-AUDIT-SETTLEMENT-DAN-LAPORAN.md).
