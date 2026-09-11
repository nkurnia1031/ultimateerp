# Hasil 02B — Import dan histori tarif

## Ringkasan handoff

- **Status:** LULUS dengan perbaikan service dan gap UX yang tersisa.
- **Run-id:** `RUN02A-20260910`.
- **Sesi browser:** `changelog02a3`, lokal, viewport 1264×625.
- **Business:** `CV BM TRANSPORT`.
- **Workbook utama:** `ref/LIST UANG JALAN PT BMT 2026.xlsx`.
- **Batch aktif:** ID **3**, berlaku mulai **01-01-2026**, `88/91` baris valid, `28` rute valid, `14` label unit, status `ACTIVE`.
- **Peringatan yang dipertahankan:** baris 32, 44, dan 57 tidak memiliki angka valid untuk `Borongan Supir/Ton` dan `Uang Jalan`; ketiganya tidak dibuat sebagai tarif nol.
- **Pemetaan:** 132 token unit valid dipetakan melalui dropdown UI ke asset business aktif; asset 39 adalah fixture utama untuk SPB berikutnya.
- **Duplikasi:** sebelum perbaikan batch identik sempat dibuat sebagai batch 4; setelah perbaikan upload identik tidak membuat batch baru.
- **Data yang dipertahankan:** batch 3 dan tarif aktif untuk dipakai 02C.
- **Handoff:** lanjutkan hanya ke [02C — Form dan verifikasi SPB](../02C-UJI-FORM-DAN-VERIFIKASI-SPB.md).

## Prasyarat dan aturan

Saya membaca [petunjuk pelaksanaan](../00-PETUNJUK-PELAKSANAAN.md), [hasil 01A](01A-HASIL.md), [hasil 02A](02A-HASIL.md), dan dokumen aktif [02B](../02B-UJI-IMPORT-DAN-HISTORI-TARIF.md). Semua upload, pilih tanggal, mapping asset, konfirmasi, pembatalan, dan reload dilakukan melalui `agent-browser` sebagai tindakan pengguna. Pemeriksaan source dan log hanya menjadi bukti tambahan.

Tanggal `01-01-2026` dimasukkan melalui kontrol tanggal browser dengan memilih bulan, hari, dan tahun. Tidak ada tanggal yang ditebak dari keterangan Excel.

## Skenario dan bukti browser

| ID | Tindakan pengguna | ID/data | Hasil sebelum/sesudah/reload | Expected | Actual | Status | Bukti |
|---|---|---|---|---|---|---|---|
| 02B-01 | Membuka Asset Management → Tarif Angkutan, memilih workbook XLSX utama, memilih tanggal berlaku manual, lalu klik Upload & Preview | Batch 1 | Sebelum belum ada batch. Sesudah/reload muncul draft `88/91`, `29` rute pada implementasi awal, `14` unit, dan error baris 32/44/57 serta unmatched unit. | Upload menampilkan draft, jumlah baris, warning, dan kebutuhan mapping. | Workbook diterima, draft dibuat, warning terlihat. Halaman pertama saja tidak cukup untuk aktivasi; pagination perlu diperiksa. | LULUS | [02B-rates-active.png](screenshots/02B-rates-active.png) |
| 02B-02 | Mencoba Konfirmasi Aktif sebelum mapping, menerima dialog browser | Batch 1 | Status tetap DRAFT lalu dibatalkan melalui tombol Batal. | Sistem menolak unit yang belum cocok. | Konfirmasi menghasilkan warning unmatched; pembatalan mengubah batch menjadi CANCELLED dan tidak menyisakan tarif aktif dari batch itu. | LULUS | Bukti snapshot sesi browser |
| 02B-03 | Upload ulang workbook, membuka pagination rate, memilih asset pada setiap dropdown, dan membiarkan halaman reload setelah setiap submit | Batch 3, 132 token unit valid | Pemetaan dilakukan pada tiga halaman tabel tarif. Setiap select yang tersisa diproses sampai jumlah dropdown menjadi 0. | Semua unit pada baris valid memiliki asset fisik dan tidak ada unit yang diam-diam dilewati. | Semua token unit valid berhasil memiliki mapping. Contoh asset utama 39 tampil sebagai `DT → RUN02A Unit Angkutan Revised`. | LULUS | [02B-rates-active.png](screenshots/02B-rates-active.png) |
| 02B-04 | Konfirmasi Aktif batch yang sudah dimapping, menerima dialog, lalu reload | Batch **3** | Sebelum DRAFT; sesudah ACTIVE; setelah reload status tetap ACTIVE. | Tarif valid aktif; warning invalid row tidak menjadi tarif nol. | Batch 3 ACTIVE, `88/91`, `28` rute valid, `14` label unit. Warning baris 32/44/57 tetap tampil sebagai informasi, sementara baris invalid tidak muncul sebagai tarif. | LULUS | [02B-rates-active.png](screenshots/02B-rates-active.png) |
| 02B-05 | Upload workbook identik lagi dengan tanggal yang sama setelah batch 3 aktif, melalui form yang sama | Percobaan duplikasi; batch 4 lama | Sebelum perbaikan batch identik baru muncul sebagai DRAFT. Batch 4 dibatalkan melalui UI. Sesudah patch, percobaan identik tidak menambah ID batch. | Upload identik harus diberi tahu/ditolak dan tidak boleh menghasilkan sumber tarif kedua. | Sesudah patch, daftar tetap berisi batch 1–4 tanpa batch baru; batch 3 tetap ACTIVE. Penolakan ditampilkan pada alur kembali aplikasi, walaupun halaman tarif belum menampilkan flash message secara jelas. | LULUS dengan catatan UX | Bukti tabel batch dan network request browser |
| 02B-06 | Membuka export tarif aktif melalui tombol Export Aktif | Business `CV BM TRANSPORT` | Tombol tersedia pada halaman tarif. | File aktif dapat diekspor tanpa mengubah batch. | Link export terlihat dan tidak mengubah status batch; verifikasi isi file unduhan belum dilakukan pada bagian ini. | BELUM DIUJI | Belum ada |
| 02B-07 | Membuka opsi edit/hapus rate individual | Tarif aktif | Tidak ada tombol edit/hapus individual pada UI. | Operasi yang tidak tersedia harus dilaporkan, bukan dianggap berhasil. | Tidak tersedia; histori dijaga dengan batch/efektif berlaku dan pembatalan batch. | BELUM DIUJI | Tidak ada |
| 02B-08 | Menyiapkan tarif lama dan baru dengan tanggal berlaku berbeda | Belum ada batch versi baru yang aktif | Tarif baru membutuhkan workbook yang berbeda atau upload ulang dengan tanggal lain; CSV fixture minimal yang disiapkan tidak menghasilkan batch pada UI dan tidak dipakai. | Pemilihan tarif historis berdasarkan tanggal harus terbukti. | BELUM DIUJI pada bagian ini; gunakan tarif aktif batch 3 untuk 02C dan lakukan uji tanggal historis ketika jalur upload versi baru tersedia. | BELUM DIUJI | Tidak ada |

## Mapping asset yang dipakai

Pemetaan dilakukan dengan memilih option pada dropdown unit, bukan dengan menulis database. Untuk menghindari ambiguity label `DT`/`DT01`, mapping berikut dicatat persis:

| Label workbook | Asset ID | Nama asset yang tampil di UI |
|---|---:|---|
| DT | 39 | RUN02A Unit Angkutan Revised |
| DT 01 | 38 | B 0004 |
| DT 05 | 1 | B 138 BMT |
| DT 11 | 2 | B 9185 TYV |
| TK 01 | 3 | B 9243 WV |
| TK 02 | 4 | B 9845 QZ |
| TK 06 | 5 | B 9917 TYU |
| TK 03 | 6 | B 9920 TYU |
| TK 05 | 7 | B 9979 OL |
| DT01 | 8 | BK 8766 LL |
| DT05 | 9 | BM 2436 HU |
| DT11 | 10 | BM 8361 DK |
| TK | 11 | BM 8536 RG |
| DT 10 | 12 | BM 8736 DI |

Asset ID 39 berasal dari fixture 02A. Asset ID lainnya sudah ada pada business lokal dan dipilih untuk melengkapi mapping workbook; perubahan ini berada di database lokal. Tahap berikut harus menghindari perubahan nama atau penghapusan asset yang sudah dipakai oleh batch aktif.

## Masalah yang ditemukan dan perbaikan

### 1. Error konfirmasi lama menempel pada batch

Pada batch uji awal, konfirmasi sebelum mapping menambahkan pesan `unit belum cocok` ke `error_message`. Setelah unit diperbaiki, pesan itu tidak dibersihkan sehingga konfirmasi berikutnya tetap gagal. Service sekarang memisahkan warning import dari error konfirmasi dan menghitung ulang error mapping/duplikasi pada setiap percobaan.

### 2. Baris invalid dibuat sebagai tarif nol

Sebelumnya baris invalid tetap dimasukkan ke `sourceRows` dengan tarif nol. Service sekarang hanya membuat rate untuk baris valid, tetapi tetap menyimpan warning baris invalid pada batch. Hasilnya batch 3 berisi 88 rate valid dari 91 baris sumber.

### 3. Variabel warning tidak ikut ke closure transaksi

Perbaikan pertama sempat menghasilkan `Undefined variable $importErrors` saat status hendak diaktifkan. Closure transaksi sekarang menerima `$importErrors`; batch 3 berhasil berpindah menjadi ACTIVE melalui tombol UI.

### 4. Workbook identik belum ditolak

Service sekarang menghitung fingerprint file sebelum membaca workbook dan menolak hash yang sama jika sudah ada batch business tersebut berstatus `draft` atau `active`. Batch cancelled tidak mengunci reimport. Uji UI setelah patch tidak membuat batch baru, tetapi pesan penolakan perlu diperjelas di view agar mudah dipahami pengguna.

File yang berubah:

- [HeavyEquipmentRateService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentRateService.php)
- [rates/index.blade.php](../../../Modules/AssetManagement/Resources/views/fleet/rates/index.blade.php)

Perubahan validasi Customer pada 02A tercatat di [hasil 02A](02A-HASIL.md); tidak diubah lagi pada bagian ini.

## Data uji dan cleanup

| Data | Status |
|---|---|
| Batch 1 dan 2 | CANCELLED melalui UI; dibiarkan sebagai histori uji yang tercatat. |
| Batch 3 | ACTIVE dan dipertahankan untuk pembuatan SPB 02C. Jangan dibatalkan sebelum handoff SPB selesai. |
| Batch 4 | CANCELLED melalui dialog Batal setelah uji duplikasi sebelum patch. |
| Asset mapping ID 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 38, 39 | Dipertahankan karena batch 3 aktif merujuk ke unit-unit tersebut. Jangan hapus atau ubah nama pada cleanup berikutnya. |
| `fixture/02B-tarif-lama.csv` dan `fixture/02B-tarif-baru.csv` | File uji lokal; CSV tidak berhasil menghasilkan batch pada browser dan tidak dipakai sebagai sumber aktif. |

Tidak ada trip, invoice, Sell, Bonus Ayak, atau settlement dibuat pada bagian ini.

## Pemeriksaan tambahan

| Pemeriksaan | Hasil |
|---|---|
| `php -l Modules/AssetManagement/Services/HeavyEquipmentRateService.php` | LULUS, exit code 0. |
| `git diff --check` pada file terkait | LULUS, hanya warning normal line ending Git pada file lain yang sudah berubah. |
| `node --check public/js/app.js` dari 02A | LULUS, exit code 0. |
| `php artisan migrate --pretend --no-interaction` | GAGAL sebelum pemeriksaan migration karena PHP CLI tidak memuat cURL dan Pusher melempar `PusherException`; tidak ada migration dijalankan. |
| `php artisan view:cache --no-interaction` | GAGAL dengan penyebab lingkungan cURL/Pusher yang sama. |
| `php artisan route:cache --no-interaction` | GAGAL dengan penyebab lingkungan cURL/Pusher yang sama. |
| `graphify update .` | GAGAL karena executable `graphify` tidak tersedia pada PATH dan fallback interpreter tidak tersedia. |

Pembukaan awal halaman Tarif menghasilkan ParseError `unexpected token endforeach` pada view. Loop Blade yang ditulis satu baris dipecah menjadi blok multiline; setelah itu halaman berhasil dibuka dan dipakai untuk seluruh journey tarif.

## Masalah terbuka dan tahap penerus

1. Halaman belum menampilkan flash message penolakan duplicate upload secara jelas. Perbaikan UX dapat dicatat saat tahap halaman/Change Log jika dibutuhkan, tetapi jangan mengubah scope SPB pada 02C.
2. Export belum diperiksa isi file hasil unduhan.
3. Versi tarif lama/baru belum terbukti karena CSV minimal tidak masuk dan belum dibuat workbook XLSX versi kedua. Uji historis wajib dilanjutkan sebelum artikel menjanjikan perilaku histori.
4. Batch 3 memiliki warning tiga baris invalid; jangan menggunakan rute yang hanya ada pada baris invalid untuk SPB tanpa tarif lengkap.

## Handoff konkret

Baca hasil ini lalu jalankan [02C — Uji Form dan Verifikasi SPB](../02C-UJI-FORM-DAN-VERIFIKASI-SPB.md) saja. Gunakan business `CV BM TRANSPORT`, asset 39 `RUN02A Unit Angkutan Revised`, driver 93, customer 1802, Project 11, serta tarif aktif batch 3. Pilih rute yang pada halaman tarif terlihat memiliki `Harga/Ton` agar SPB dapat diuji tanpa mengandalkan baris warning kosong.
