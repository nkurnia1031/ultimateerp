# Hasil 02N — Uji Rekap dan Ledger

## Status

**LULUS untuk rekap, ledger asset, dan ledger Project; export lulus pada level request/download response dengan catatan agent-browser tidak menyimpan file ke folder bukti.**

Pengujian tampilan dan navigasi dilakukan lewat `agent-browser`. Pemeriksaan database tidak digunakan untuk menggantikan tampilan; hanya dipakai pada tahap sebelumnya untuk mengunci ID sumber.

## Fixture dan batas data

- Business aktif: `CV BM TRANSPORT` (`business_id=2`).
- Unit: `RUN02A Unit Angkutan Revised` (`asset_id=39`).
- Sopir: `RUN02A Driver Revised` (`driver_id=93`).
- Project: `RUN02A Project Angkutan` (`project_id=11`).
- SPB yang tampak pada ledger: `TRIP-202609-0004` ID `120`, `TRIP-202609-0003` ID `119`, dan `TRIP-202609-0001` ID `1`.
- Bonus Ayak yang tampak pada Project: ID `883` untuk SPB `TRIP-202609-0004`, serta ID `880` untuk SPB `TRIP-202609-0001` dan `TRIP-202609-0003`.

## Langkah browser dan hasil

### 1. Filter rekap

Melalui menu `Asset Management → Rekap Alat & Sopir`, filter diisi melalui form:

- Dari: `10-09-2026`.
- Sampai: `10-09-2026`.
- Unit: `RUN02A Unit Angkutan Revised`.
- Sopir: `RUN02A Driver Revised`.
- Project: `RUN02A Project Angkutan`.

URL hasil filter hanya memuat parameter tersebut pada business aktif. Tampilan menampilkan:

| Ukuran | Hasil |
| --- | ---: |
| Trip | 3 |
| Ton | 63,00 |
| Pendapatan | Rp 7.330.000,00 |
| Upah sopir | Rp 7.995.000,00 |
| Kontribusi | Rp -665.000,00 |

Rincian unit dan sopir sama-sama menunjukkan 3 perjalanan, 63 ton, pendapatan Rp7.330.000, dan upah sopir Rp7.995.000. Pendapatan pelanggan tetap ditampilkan terpisah dari biaya/upah sopir.

Bukti: [rekap dengan filter](../../../hasil/screenshots/02N-report-filtered.png).

### 2. Ledger asset/unit

Dari baris unit pada rekap, pengguna membuka `RUN02A Unit Angkutan Revised`. Ledger asset menampilkan tiga sumber berikut:

| SPB | Ton | Pendapatan | Upah | Invoice | Settlement | Status |
| --- | ---: | ---: | ---: | --- | --- | --- |
| TRIP-202609-0004 (ID 120) | 20 | Rp2.300.000 | Rp2.600.000 | Belum | SET-202609-0004 | VERIFIED |
| TRIP-202609-0003 (ID 119) | 22 | Rp2.515.000 | Rp2.730.000 | PROJECT_INVOICE | SET-202609-0003 | VERIFIED |
| TRIP-202609-0001 (ID 1) | 21 | Rp2.515.000 | Rp2.665.000 | PROJECT_INVOICE | SET-202609-0003 | VERIFIED |

Penjumlahan baris cocok dengan kartu ledger: 63 ton, Rp7.330.000 pendapatan, Rp7.995.000 upah, dan kontribusi negatif Rp665.000. Tidak ada SPB yang muncul dua kali.

Bukti: [ledger asset](../../../hasil/screenshots/02N-asset-ledger.png).

### 3. Ledger Project dan Bonus Ayak

Melalui menu `Project`, pengguna membuka `RUN02A Project Angkutan` lalu tab `Trip Angkutan (3)`. Tampilan menunjukkan:

- Total ton: 63,00.
- Pendapatan: Rp7.330.000,00.
- Upah sopir: Rp7.995.000,00.
- Kontribusi: Rp-665.000,00.
- Ketiga SPB berada pada project dan berstatus `VERIFIED`.
- Bonus Ayak Project: Rp0,00 dengan keterangan bahwa Bonus Ayak **bukan line invoice pelanggan**.
- Sumber Bonus Ayak ditampilkan satu kali untuk SPB ID120 dan satu baris gabungan untuk SPB ID1 serta ID119.

Hasil ini membuktikan bahwa Bonus Ayak tidak hilang dari Project, tidak dihitung dua kali, dan tidak bercampur dengan pendapatan invoice pelanggan.

Bukti: [ledger Project](../../../hasil/screenshots/02N-project-trip-ledger.png).

### 4. Export rekap

Pengguna menekan `Export Rekap` dari halaman rekap yang sudah difilter. Browser mengirim request berikut melalui sesi aktif:

`GET /asset/fleet/reports/export?start_date=2026-09-10&end_date=2026-09-10&asset_id=39&driver_id=93&project_id=11`

Response yang teramati melalui pemeriksaan network tambahan:

- HTTP `200`.
- `Content-Disposition: attachment; filename=rekap-spb-20260910-224504.csv`.
- `Content-Type: text/csv; charset=UTF-8`.
- Ukuran response `1268` byte.

Klik UI sudah berhasil mencapai endpoint export dan server mengembalikan attachment. Helper `agent-browser download` membatalkan penyimpanan file lokal setelah response selesai, sehingga tidak ada CSV yang diterbitkan ke folder bukti dan isi byte-per-byte belum diverifikasi sebagai artifact. Ini adalah keterbatasan bukti tool, bukan redirect/error aplikasi; status export dicatat sebagai **terverifikasi pada HTTP response, belum terverifikasi pada file hasil simpan**.

## Rekonsiliasi tambahan

- SPB ID120: 20 ton × Rp115.000 = Rp2.300.000; total upah Rp2.600.000; kontribusi Rp-300.000.
- SPB ID119: 22 ton × Rp115.000 = Rp2.530.000 sebelum penyesuaian; nilai yang tampil pada ledger Rp2.515.000 sesuai snapshot nilai akhirnya.
- SPB ID1: 21 ton × Rp115.000 = Rp2.415.000 sebelum penyesuaian; nilai yang tampil pada ledger Rp2.515.000 sesuai snapshot nilai akhirnya.
- Nilai ledger Project dan asset memakai nilai akhir SPB, bukan menghitung ulang dari master tarif yang sekarang.
- Status billing menunjukkan dua SPB lama sudah `PROJECT_INVOICE`, sedangkan SPB ID120 belum ditagihkan; status payroll ketiganya tetap terlihat melalui settlement.

## Cleanup dan handoff

Tidak ada data uji tambahan yang dibuat pada tahap ini. SPB, Bonus Ayak, settlement, dan Payroll posted dari 02M tetap dipertahankan sesuai aturan handoff. Jangan menghapus atau membuka kunci transaksi posted untuk kebutuhan screenshot.

## Masalah tersisa

Jika bukti CSV harus dibaca sel per sel pada tahap dokumentasi, ulangi klik export pada browser dengan mekanisme penyimpanan yang dapat menangkap attachment, lalu cocokkan baris `TRIP-202609-0004`, `TRIP-202609-0003`, dan `TRIP-202609-0001` dengan ledger di atas. Jangan menyatakan isi CSV sudah diverifikasi sebelum artifact tersebut tersedia.

## Tahap berikutnya

Tahap panduan dapat menggunakan screenshot rekap, ledger asset, dan ledger Project di atas. Pisahkan catatan export sebagai bukti response sampai file CSV berhasil disimpan dan dibaca.
