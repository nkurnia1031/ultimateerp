# Hasil 01B — Audit SPB dan workbook Cangkang

## Ringkasan handoff

- Status bagian: **LULUS — audit read-only selesai**.
- Status import runtime: **BELUM DIUJI melalui agent-browser**.
- Tidak ada data SPB dibuat dan tidak ada file workbook diubah.
- Workbook Jatim: 32 sheet, seluruhnya dikenali parser sebagai format loading, 828 baris SPB, 122 nomor polisi unik, 118 label sopir unik, dan 21.054,6 ton netto.
- Workbook Putra Sanjaya: 2 sheet (`TAGIHAN  PT.BMT` dan `Sheet1`), 180 baris kandidat, 14 nomor polisi unik, 15 label sopir unik, 6.011,52 ton diterima, dan 54 baris logis berulang antar-sheet.
- Jatim memiliki header pada baris 9 dan subheader Gross/Tare/Nett pada baris 10. Rute tidak berada di setiap baris; default route perlu dipilih saat import.
- Putra Sanjaya memiliki header pada baris 7, asal pada `PKS`, tujuan pada `Pabrik`, ton tagihan pada `Diterima`, tarif pada `OA`, dan label sopir pada kolom U yang tidak memiliki header formal.
- Putra Sanjaya memiliki formula klaim pada baris sumber 20 dan 59: `O20*1100` dan `O59*1100`. Nilai `variance + tolerance` menghasilkan -284 kg dan -870 kg; penyesuaian masing-masing -Rp312.400 dan -Rp957.000 pada sheet pertama.
- Import SPB menyimpan sheet, baris, workbook, dan fingerprint; tetapi ticket Jatim dipertahankan dengan teks `No : ...` dan workbook Putra Sanjaya tidak memiliki nomor tiket formal.
- Gap penting: import dapat membuat master rute baru ketika route label tidak ditemukan, sedangkan Jatim memerlukan default route dan Putra Sanjaya membentuk route dari `asal - tujuan`. Ini harus terlihat jelas dan disetujui pada preview UI.
- Gap penting: `source_fingerprint` berbeda antar-sheet karena memuat nama sheet/baris; pencegahan duplikasi logis mengandalkan `logicalKey` preview dan belum dibuktikan untuk duplikasi dalam sheet yang sama.
- Bootstrap Laravel CLI untuk mencoba parser aktual terhenti oleh PHP cURL yang tidak aktif. Parser workbook tetap diperiksa dengan PhpSpreadsheet tanpa bootstrap dan tanpa write.
- Handoff: `02D` menguji import workbook, `02C` menguji form/rumus SPB, dan `02B` menyiapkan tarif/master yang dibutuhkan.

## Bukti source

| Area | Bukti | Temuan |
| --- | --- | --- |
| Kolom tambahan SPB | [migration 2026_09_09_000003](../../../database/migrations/2026_09_09_000003_add_spb_fields_to_heavy_equipment_trips.php) | Menambahkan ticket, tanggal muat/bongkar, gross/tare/diterima/tagihan, variance/tolerance, tarif klaim, nilai dasar/penyesuaian/potongan/nilai akhir, dan source workbook/sheet/row/fingerprint. |
| Model | [HeavyEquipmentTrip.php](../../../Modules/AssetManagement/Entities/HeavyEquipmentTrip.php) | Cast tanggal/decimal serta relasi route, asset, driver, project, customer, rate, billing, settlement, dan Bonus Ayak. |
| Preview parser | [HeavyEquipmentSpbImportService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentSpbImportService.php) | Membaca semua sheet, mencari `No.Polisi`/`No Tiket`, mengenali loading vs reconciliation, mencocokkan asset/user/route dan membentuk logical duplicate. |
| Import UI | [import view](../../../Modules/AssetManagement/Resources/views/fleet/trips/import.blade.php) | User memilih file lewat browser, melihat jumlah sheet/baris/siap/bermasalah, melihat sumber per sheet/baris, memilih default route, kemudian menekan Import Baris Siap. |
| Import endpoint | [HeavyEquipmentTripController.php](../../../Modules/AssetManagement/Http/Controllers/HeavyEquipmentTripController.php) | Preview disimpan sementara di cache berbasis user/token; confirm memeriksa business/user/token, melewati baris error, dan membuat baris valid dalam transaksi. |
| Perhitungan dan snapshot | [HeavyEquipmentTripService.php](../../../Modules/AssetManagement/Services/HeavyEquipmentTripService.php) | Mengambil `received_tons` sebagai fallback actual/billable, menghitung claim dari `(variance + tolerance) * claim_rate`, menyimpan snapshot, menolak ticket/fingerprint yang sudah ada. |
| Menu dan permission | [AssetManagement routes](../../../Modules/AssetManagement/Routes/web.php) dan [nav](../../../Modules/AssetManagement/Resources/views/layouts/nav.blade.php) | Import form, preview, confirm dan daftar SPB berada di prefix `asset/fleet/trips`; akses mutasi memakai permission fleet trips/manage/create. |

## Audit format workbook

### Workbook Jatim

File: [05. PT. JATIM PROPERTINDO JAYA CANGKANG (MV. QUEEN CORAL).xls](../../../ref/05.%20%20PT.%20JATIM%20PROPERTINDO%20JAYA%20CANGKANG%20%28MV.%20QUEEN%20CORAL%29.xls)

- Sheet: `BMT`, `EKA`, `AHONG`, `PENTA`, `AHO (PJP)`, `ARSYAD`, `BOY`, `AONG`, `AONG (4)`, `AONG (5)`, `AONG (2)`, `AONG (6)`, `SYAWAL`, `YUDI`, `YUSHAR`, `HARMOKO`, `WELKI`, `HADI`, `IQBAL`, `ALONG`, `ALBERTO`, `ANTON KJ`, `MARBUN`, `SIHOMBING`, `ADEK`, `ALFI`, `JPA`, `SPD`, `REGAR POLISI`, `RINGO`, `DAULAY`, dan `WASAKA`.
- Semua 32 sheet mempunyai header baris 9 dengan `NO`, `NO TIKET`, `NO.POLISI`, `TANGGAL`, `TONASE`, `HARGA`, `TOTAL`, dan `KETERANGAN`; baris 10 berisi `GROSS`, `TARE`, `NETT`.
- Parser mengambil nomor tiket dari kolom B, unit/polisi dari C, tanggal dari D, gross/tare/netto dari E/F/G, tarif dari H, dan sopir dari J.
- Hasil pembacaan: BMT 84, EKA 34, AHONG 32, PENTA 45, AHO (PJP) 34, ARSYAD 23, BOY 41, AONG 7, AONG (4) 7, AONG (5) 7, AONG (2) 5, AONG (6) 13, SYAWAL 7, YUDI 3, YUSHAR 14, HARMOKO 69, WELKI 33, HADI 6, IQBAL 9, ALONG 72, ALBERTO 16, ANTON KJ 31, MARBUN 14, SIHOMBING 11, ADEK 55, ALFI 24, JPA 1, SPD 4, REGAR POLISI 11, RINGO 53, DAULAY 26, dan WASAKA 37.
- Rute asal/tujuan tidak tersedia per baris. Import tidak dapat mencocokkan tarif rute hanya dari baris ini; user harus memilih route default atau data harus diperkaya melalui preview.
- Tidak ditemukan logical duplicate berdasarkan kombinasi tiket/polisi/tanggal/ton/tarif/sopir pada pembacaan ini.

### Workbook Putra Sanjaya

File: [02. CV. PUTRA SANJAYA, CANGKANG (PT. BMT).xlsx](../../../ref/02.%20%20CV.%20PUTRA%20SANJAYA%2C%20CANGKANG%20%20%28PT.%20BMT%29.xlsx)

- Sheet `TAGIHAN  PT.BMT` dan `Sheet1` memakai layout yang sama dan masing-masing menghasilkan 90 baris kandidat mulai baris 8.
- Header baris 7 memakai `No`, `No. Polisi`, `Tgl`, `PKS`, `Muat`, `Tgl`, `Pabrik`, `Bongkar`, `Diterima`, `OA`, `Jumlah`, `+/-`, `Toleransi`, `Kg`, `Rp`, `Total`. Label sopir berada di kolom U tanpa nama header.
- Parser mengenali format sebagai reconciliation karena ada `No. Polisi`, `PKS`, `Pabrik`, dan `OA`.
- Nomor tiket formal tidak tersedia; key logical mengandalkan polisi, tanggal muat, asal, tujuan, ton diterima, tarif dan sopir.
- Route label dibentuk sebagai `PKS - Pabrik`; 17 label route unik ditemukan pada dua sheet. Jika tidak ada route master yang sama, `prepareForImport()` dapat membuat route baru.
- 54 baris logis berulang setelah kemunculan pertama, terutama overlap `Sheet1` terhadap sheet tagihan. Sheet duplikat tidak boleh dianggap sebagai data baru. Preview harus memperlihatkan sumber/baris dan status duplikat sebelum konfirmasi.
- Perbedaan data antar-sheet tetap ada pada sebagian baris, sehingga sistem tidak boleh menghapus seluruh `Sheet1` secara nama saja; keputusan harus per baris berdasarkan logical key/fingerprint dan hasil preview.

## Expected perhitungan dan formula klaim

| Sumber | Nilai terbaca | Expected |
| --- | --- | --- |
| Jatim, contoh sheet BMT baris 11 | Gross 38,97; tare 12,58; nett 26,39; tarif Rp33.500 | Netto dipakai sebagai actual; pendapatan dasar 26,39 × 33.500 = Rp884.065. |
| Putra Sanjaya, baris 8 | Diterima 26,660; OA 115.000; sopir `LIADY` | Pendapatan dasar 26,660 × 115.000 = Rp3.065.900. |
| Putra Sanjaya, baris 20 | M -570 kg; N 286 kg; formula klaim `O20*1100`; O -284 kg | `(−570 + 286) × 1.100 = −Rp312.400`; total sheet pertama Rp3.265.100 dari dasar Rp3.577.500. |
| Putra Sanjaya, baris 59 | M -1.150 kg; N 280 kg; formula klaim `O59*1100`; O -870 kg | `(−1.150 + 280) × 1.100 = −Rp957.000`; total sheet pertama Rp2.120.800 dari dasar Rp3.077.800. |

Kode saat ini memang menghitung `claim_adjustment_total` dari `(variance_kg + tolerance_kg) * claim_rate_per_kg`, bukan dari nilai kolom P secara langsung. Bagian `02C` dan `02D` wajib membuktikan nilai ini lewat UI dan detail SPB. Jika bisnis memaknai `variance` atau `tolerance` berbeda, hentikan perubahan nominal dan catat keputusan yang dibutuhkan.

## Risiko implementasi yang harus diuji

1. **Tanggal:** Jatim memakai format `23-Aug-26`; Putra Sanjaya memakai `01-Aug-26`, tetapi ada contoh tanggal bongkar `15-Aug-15` yang mungkin data sumber salah. Preview harus menampilkan tanggal mentah/hasil dan menolak atau meminta koreksi bila tanggal tidak masuk akal.
2. **Label driver:** Jatim memakai nama pada kolom Keterangan dan Putra Sanjaya memakai kolom U tanpa header. `resolveDriverId()` hanya menganggap hasil unik; unmatched harus terlihat dan tidak boleh dipaksa ke user lain.
3. **Label unit:** nomor polisi dapat memiliki spasi ganda, misalnya `BM  9600 RE` atau `BM  9893 XX`. Resolver menormalisasi whitespace, tetapi hasil cocok tetap harus diverifikasi di UI.
4. **Route:** Jatim tidak mempunyai asal/tujuan per baris. Putra Sanjaya membentuk route baru dari teks sumber jika route belum ada. Jangan mengaktifkan tarif yang salah hanya karena route otomatis terbentuk.
5. **Duplikasi:** logical duplicate menandai duplikat antar-sheet, tetapi kondisi duplikat dalam sheet yang sama belum diberi error pada loop preview. Fingerprint baru ditolak saat `create()` jika sudah tersimpan, tetapi kompetisi dua import dan preview ulang harus diuji.
6. **Import atomik:** `importConfirm()` melompati baris error dan menjalankan pembuatan baris valid dalam satu transaksi. Jika `create()` melempar error pada satu baris, seluruh transaksi dapat rollback; pesan sukses/gagal dan jumlah created harus diuji.
7. **Formula Excel:** nilai formula dibaca sebagai calculated value oleh `toArray`, sedangkan tarif klaim dibaca lagi dari formula string. Workbook copy/format lama harus diuji di browser dengan error per baris.
8. **Pemetaan tarif:** baris import SPB dapat membawa tarif workbook sebagai override dengan alasan `Tarif OA dari workbook SPB` atau `Tarif dari workbook SPB`. Bagian berikut harus memastikan override ini tidak mengubah master tarif dan snapshot SPB tetap stabil.

## Skenario yang diserahkan

- `02B`: siapkan tarif aktif/rute/unit dan uji histori sebelum import SPB.
- `02C`: buat SPB manual dengan gross/tare serta kasus klaim/potongan; gunakan expected values tabel di atas.
- `02D`: upload kedua workbook melalui UI, cek 32/2 sheet, source row, unmatched, default route, 54 logical duplicate dan baris formula klaim.
- `02K`: uji upload/confirm dari business lain dan token preview user lain; selector saja tidak cukup.
- `02L` dan `02M`: uji dua tab/sesi memakai workbook atau sumber yang sama untuk memastikan tidak ada duplicate billing/settlement/Bonus.
- `05C`: artikel import SPB tidak boleh menyebut semua baris berhasil; jelaskan status “Perlu koreksi” jika itu yang terbukti.

## Pemeriksaan yang dijalankan

- PhpSpreadsheet membaca kedua workbook secara read-only tanpa macro. Hasil statistik dan contoh formula dicatat di dokumen ini.
- `graphify query` dan interpreter Graphify dicoba sesuai aturan repository; CLI tidak tersedia dan interpreter tersimpan menunjuk ke path yang tidak ada. Graph existing dibaca read-only dengan NetworkX sebagai fallback.
- Percobaan bootstrap Laravel untuk menjalankan parser aktual gagal sebelum service dipanggil karena PHP CLI tidak memuat ekstensi `curl`, sehingga Pusher melempar `PusherException`. Ini adalah blocker environment untuk pemeriksaan service CLI, bukan bukti bahwa alur browser import gagal.
- Tidak ada browser atau import nyata dijalankan pada bagian audit ini.

## Cleanup dan handoff

Tidak ada data uji dibuat. Tidak ada cleanup yang diperlukan. Lanjutkan hanya bila diminta ke [02B](../02B-UJI-IMPORT-DAN-HISTORI-TARIF.md), [02C](../02C-UJI-FORM-DAN-VERIFIKASI-SPB.md), atau [02D](../02D-UJI-IMPORT-SPB.md). Jangan menganggap bagian-bagian itu sudah dijalankan.
