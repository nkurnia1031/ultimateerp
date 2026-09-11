# Hasil 02I — Uji Draft Settlement dan Pembatalan

## Status

**LULUS DENGAN CATATAN** — alur review, pemilihan sumber, perhitungan, pembuatan draft, netto nol, dan pembatalan berhasil dilakukan melalui browser. Posting Payroll sengaja tidak dijalankan pada tahap ini. Percobaan Kasbon melebihi hak tidak membuat settlement baru, tetapi pesan validasinya belum terdokumentasi dengan baik karena sesi browser berpindah dari halaman hasil submit.

Tanggal uji: 10 September 2026  
Run: `RUN02I-20260910`  
Business: **CV BM TRANSPORT** (`business_id=2`)  
Sopir: **RUN02A Driver Revised** (`driver_id=93`)

## Aturan interaksi

Semua perubahan dilakukan lewat `agent-browser` pada URL lokal. Field tanggal diisi melalui kontrol tanggal browser dan tombol dipilih dari halaman aplikasi. Tidak ada SQL write, pemanggilan controller/service, atau request HTTP manual.

## Sumber yang digunakan

| Sumber | ID | Nilai | Status akhir |
|---|---:|---:|---|
| SPB `TRIP-202609-0001` | 1 | Upah Rp2.665.000 | tersedia kembali setelah settlement dibatalkan |
| SPB `TRIP-202609-0002` | 2 | Upah Rp2.050.000 | tersedia kembali setelah settlement dibatalkan |
| SPB `TRIP-202609-0003` | 119 | Upah Rp2.730.000 | tersedia kembali setelah settlement dibatalkan |
| Bonus Ayak | 880 | Rp0 | tersedia kembali setelah settlement dibatalkan |
| Kasbon utama | 17 | Rp150.000 | tetap dipertahankan dan tersedia kembali |
| Kasbon sementara | 19 | Rp7.445.000 | dibuat untuk uji netto nol, lalu dihapus melalui UI |

Periode yang dipakai: `2026-09-10` sampai `2026-09-10`.

## Journey dan bukti

### 1. Review dan pengecualian sumber

Halaman `Asset Management → Upah Sopir` menampilkan tiga SPB verified, satu Bonus Ayak, dan satu Kasbon untuk sopir serta periode tersebut. Semua checkbox sumber awalnya aktif.

Pengujian melalui checkbox:

| Skenario | Upah trip | Bonus | Kasbon | Netto tampilan | Hasil |
|---|---:|---:|---:|---:|---|
| Keluarkan SPB `TRIP-202609-0002` | Rp5.395.000 | Rp0 | Rp150.000 | Rp5.245.000 | Lulus |
| Keluarkan Bonus Ayak | Rp5.395.000 | Rp0 | Rp150.000 | Rp5.245.000 | Lulus |
| Keluarkan Kasbon | Rp5.395.000 | Rp0 | Rp0 | Rp5.395.000 | Lulus |
| Keluarkan seluruh item | Rp0 | Rp0 | Rp0 | Rp0 | Lulus; hanya preview, tidak disimpan |

Bukti:

- [Preview dengan satu SPB dikeluarkan](../../../hasil/screenshots/02I-preview-exclude-trip.png)
- [Preview tanpa Bonus Ayak](../../../hasil/screenshots/02I-preview-exclude-bonus.png)
- [Preview tanpa Kasbon](../../../hasil/screenshots/02I-preview-exclude-kasbon.png)
- [Preview seluruh sumber dikeluarkan](../../../hasil/screenshots/02I-preview-all-excluded.png)

### 2. Draft normal dan pembatalan

Draft pertama tersimpan sebagai `SET-202609-0001` dengan tiga SPB dan Kasbon utama:

```text
Upah trip = Rp2.665.000 + Rp2.050.000 + Rp2.730.000 = Rp7.445.000
Bonus     = Rp0
Bruto     = Rp7.445.000
Kasbon    = Rp150.000
Netto     = Rp7.295.000
```

Draft terlihat pada daftar settlement dengan status `DRAFT`. Tombol `Batalkan` ditekan, dialog `Batalkan draft settlement ini?` dikonfirmasi, dan status berubah menjadi `CANCELLED`. Setelah itu sumber dapat muncul kembali pada review periode yang sama.

Catatan: interaksi pertama dengan sesi browser menghasilkan halaman error GET preview setelah submit, tetapi daftar settlement membuktikan POST telah membuat `SET-202609-0001`. Kode tombol kemudian diperjelas menjadi `type="submit"` dan diberi ID `submit-settlement-draft`; pengujian berikutnya berhasil membuat draft kedua melalui form yang sama.

### 3. Netto nol

Kasbon sementara `ID=19` dibuat melalui form Kasbon dengan nominal Rp7.445.000. Pada review settlement, tiga SPB dan Kasbon sementara dipilih, sedangkan Kasbon utama dikeluarkan:

```text
Bruto  = Rp7.445.000
Kasbon = Rp7.445.000
Netto  = Rp0
```

Draft kedua tersimpan sebagai `SET-202609-0002`, menampilkan bruto Rp7.445.000 dan netto Rp0. Draft tersebut dibatalkan melalui tombol dan dialog konfirmasi. Kasbon sementara kemudian dihapus melalui tombol `Hapus`; pemeriksaan daftar Kasbon hanya menyisakan Kasbon utama Rp150.000.

Bukti:

- [Preview netto nol](../../../hasil/screenshots/02I-preview-zero-net.png)

### 4. Kasbon melebihi hak

Pada preview, seluruh SPB dikeluarkan, Bonus Ayak Rp0 tetap dipilih, dan Kasbon Rp150.000 tetap dipilih. Tampilan menghitung netto `Rp-150.000`. Submit tidak menghasilkan draft settlement baru; daftar settlement tetap hanya berisi `SET-202609-0001` dan `SET-202609-0002`, keduanya `CANCELLED`.

Bukti preview:

- [Preview Kasbon melebihi hak](../../../hasil/screenshots/02I-preview-overlimit.png)

Catatan perbaikan: pesan kegagalan belum berhasil ditangkap sebagai screenshot karena sesi browser berpindah ke halaman error GET preview saat menunggu hasil submit. Tahap berikutnya perlu memastikan redirect kembali ke halaman review/index dan menampilkan alasan `Total Kasbon melebihi hak payroll` secara jelas.

## Pembersihan

- Kasbon sementara `ID=19` dihapus lewat UI setelah uji netto nol.
- Settlement `SET-202609-0001` dibatalkan; tidak diposting.
- Settlement `SET-202609-0002` dibatalkan; tidak diposting.
- Kasbon utama `ID=17` tetap ada dengan nominal Rp150.000.
- SPB, Bonus Ayak, invoice Project, dan Sell tidak dihapus karena merupakan data handoff untuk tahap berikutnya.

## Kriteria tahap

| Kriteria | Hasil |
|---|---|
| Pilih periode dan sopir melalui UI | Lulus |
| Tampilkan SPB, Bonus Ayak, dan Kasbon yang eligible | Lulus |
| Keluarkan sebagian dan seluruh sumber dari preview | Lulus |
| Hitung bruto, potongan, dan netto secara otomatis | Lulus |
| Simpan draft dan tampilkan pada daftar | Lulus |
| Uji netto nol | Lulus |
| Batalkan draft dan lepaskan sumber | Lulus |
| Tolak Kasbon melebihi hak tanpa membuat draft | Lulus dengan catatan pesan error |
| Posting Payroll | Belum diuji sesuai ruang lingkup 02I |

## Catatan untuk tahap berikutnya

1. Tahap `02J` boleh memakai settlement yang sudah dibatalkan dan sumber yang kembali tersedia.
2. Sebelum posting payroll, dokumentasikan dengan browser bahwa item tidak dapat masuk dua settlement posted.
3. Perbaiki atau verifikasi pesan validasi Kasbon melebihi hak agar pengguna memahami penyebab penolakan tanpa melihat halaman error teknis.
4. Jangan memakai `SET-202609-0001` atau `SET-202609-0002` sebagai settlement posted; keduanya hanya bukti draft/cancel.

