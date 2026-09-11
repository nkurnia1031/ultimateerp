# Hasil 05F — Panduan Bonus Ayak

## Status

**LULUS dengan catatan.** Form Bonus Ayak berhasil diuji melalui browser untuk membuat, mengedit, reload, membatalkan dialog hapus, dan menghapus data uji. Relasi sopir, unit, dan Project tersedia pada form. Bukti beberapa SPB dalam satu Bonus memakai hasil browser 02G karena SPB yang sesuai pada fixture 05F sudah dipakai oleh Bonus Ayak handoff dan tidak boleh ditautkan ulang.

## Run, business, dan fixture

- Run screenshot: `RUN05F-20260911`.
- Sesi browser: `changelog02i`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Sopir: `RUN02A Driver Revised`, user ID `93`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Project: `RUN02A Project Angkutan`, ID `11`.
- Bonus uji sementara: ID `884`, dibuat dengan Trip `60`, diedit menjadi `61`, lalu dihapus melalui UI.
- Handoff relasi beberapa SPB: Bonus ID `880` dengan SPB ID `1` dan `119`, dibuktikan pada [hasil 02G](02G-HASIL.md) dan tetap dipertahankan untuk tahap payroll.

Tidak ada invoice pelanggan yang dibuat dari Bonus Ayak. Bonus adalah biaya/penghasilan sopir untuk payroll dan margin Project, bukan line tagihan pelanggan.

## Panduan untuk user awam

### Apa itu Bonus Ayak?

Bonus Ayak adalah tambahan perhitungan untuk sopir. Satu data Bonus dapat dikaitkan dengan satu atau beberapa SPB, yaitu Surat Perintah Bongkar atau catatan perjalanan. Bila sumber SPB dipilih, sopir, unit, dan Project harus sesuai. Bonus manual tetap boleh dibuat tanpa sumber SPB jika memang ada dasar administrasinya.

### Cara mengisi Bonus Ayak

1. Buka **Pool → Bonus Ayak**.
2. Pilih tanggal.
3. Pilih **Nama Operator/Supir**, **Unit**, dan **Project** dari pilihan yang tersedia.
4. Bila Bonus berasal dari perjalanan, tekan **Pilih** pada **Sumber SPB** lalu pilih satu atau beberapa SPB yang verified. Daftar hanya boleh dipilih untuk sopir dan unit yang sama.
5. Bila tidak memakai sumber SPB, isi **Trip** sesuai dokumen atau rekap yang disetujui.
6. Periksa nilai **Bonus** yang dihitung aplikasi.
7. Tekan **Simpan**.
8. Buka daftar dan reload untuk memastikan tanggal, relasi, jumlah trip, dan nominal tetap benar.

### Contoh angka yang sudah diuji

Rumus aplikasi yang diuji:

```text
Trip < 50              = Rp0
Trip >= 50             = Rp50.000 + (Trip - 50) × Rp2.000
Trip 60                = Rp70.000
Trip 61                = Rp72.000
```

Saat SPB sumber dipilih, jumlah trip mengikuti jumlah sumber yang dipilih. Pada hasil 02G, dua SPB (`TRIP-202609-0001` dan `TRIP-202609-0003`) tersimpan pada Bonus ID 880. SPB yang sama tidak boleh dipakai lagi pada Bonus lain untuk payroll.

### Cara mengenali proses berhasil

- baris baru muncul pada daftar Bonus Ayak;
- nama sopir, unit, dan Project tampil sebagai relasi, bukan hanya teks yang diketik;
- Bonus berubah otomatis setelah Trip diubah;
- sumber SPB tampil pada daftar/detail bila dipilih;
- Bonus belum menjadi line invoice pelanggan;
- Bonus yang belum masuk settlement tampil sebagai sumber yang dapat dipilih payroll.

### Masalah umum

| Masalah | Solusi |
|---|---|
| SPB tidak muncul | Pastikan SPB sudah verified, business aktif benar, dan sopir/unit sama. |
| SPB memakai sopir atau unit lain | Jangan dipaksa. Pilih data yang sesuai atau buat Bonus manual dengan dasar yang disetujui. |
| Bonus tidak muncul di invoice | Itu benar. Bonus masuk biaya/payroll dan margin Project, bukan tagihan pelanggan. |
| Bonus sudah masuk settlement | Data yang sudah diposting dikunci. Gunakan prosedur koreksi payroll, bukan mengedit langsung. |
| Import file yang sama membuat baris ganda | Catat sebagai risiko/gap yang harus ditangani sebelum mengandalkan import massal; periksa hasil dan hapus data uji melalui UI bila belum terkunci. |

## Bukti journey browser

Semua tindakan berikut dilakukan melalui `agent-browser`; tidak ada SQL write, controller/service langsung, request langsung, atau JavaScript penguji yang melewati validasi UI.

### Buat, edit, reload, batal hapus, dan hapus

1. Membuka menu **Pool → Bonus Ayak**.
2. Memilih sopir ID 93, unit ID 39, dan Project ID 11 melalui selector.
3. Mengisi Trip `60`; preview berubah menjadi `Rp70.000`.
4. Menekan **Simpan**; Bonus ID 884 muncul pada daftar.
5. Membuka **Edit**, mengubah Trip menjadi `61`, menekan **Update**, lalu reload.
6. Memeriksa Trip `61` dan Bonus `Rp72.000` tetap tersimpan.
7. Menekan **Hapus** lalu membatalkan dialog `Hapus data ini?`; row tetap ada.
8. Membuka dialog lagi, mengonfirmasi, reload daftar; ID 884 hilang.

Expected: data dan perhitungan bertahan setelah reload; batal hapus tidak mengubah data; konfirmasi hapus menghilangkan fixture.

Actual: seluruh expected terpenuhi. Tidak ada data uji manual yang tersisa.

Bukti raw dan hasil anotasi:

- [form desktop raw](../../../hasil/screenshots/05F-bonus-form-filled-desktop.png)
- [form ponsel raw](../../../hasil/screenshots/05F-bonus-form-filled-mobile.png)
- [form desktop beranotasi](../../../hasil/screenshots/05F-bonus-form-desktop-annotated.png)
- [form ponsel beranotasi](../../../hasil/screenshots/05F-bonus-form-mobile-annotated.png)
- [edit desktop beranotasi internal](../../../hasil/screenshots/05F-bonus-edit-desktop-annotated.png)

### Beberapa SPB dan validasi sumber

Hasil 02G membuktikan melalui UI bahwa Bonus ID 880 dapat menyimpan SPB ID 1 dan 119 sekaligus. SPB mismatch ID 2 ditolak dengan pesan yang mudah dipahami: `SPB sumber harus menggunakan sopir dan unit yang sama dengan Bonus Ayak.` Setelah Bonus ID 880 tersimpan, SPB ID 1 dan 119 tidak lagi ditawarkan pada selector UI.

Pada run 05F, selector hanya menyisakan SPB yang tidak cocok karena sumber yang benar sudah menjadi handoff ID 880. Penguji tidak membuat relasi palsu atau menautkan ulang SPB yang sudah dipakai.

## Inventaris bukti dan metode anotasi

| Jenis | Lokasi | Keterangan |
|---|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05F-20260911/raw/` | PNG keadaan UI nyata dari agent-browser. |
| PNG anotasi internal | `storage/app/changelog-evidence/RUN05F-20260911/annotations/` | PNG baru hasil Python Pillow dari raw. |
| PNG panduan publik | `public/images/change-log/release-2026-09-10-angkutan/` | Form desktop/ponsel yang sudah ditinjau privasinya. |
| Bukti tahap | `Rencana/ChangeLog-Angkutan/hasil/screenshots/05F-*.png` | Raw dan hasil raster untuk rekonsiliasi. |

Perintah yang dipakai, misalnya:

```text
python scripts/annotate_changelog_screenshots.py --preset 05F-bonus-form-desktop --input <raw.png> --output <annotated.png>
```

Anotasi final tidak memakai SVG, overlay HTML, atau renderer browser. PNG hasil Python berisi nomor, kotak, panah, dan caption; teks panduan serta alt text tetap menjelaskan gambar untuk pembaca nonvisual.

## Review privasi dan pemeriksaan

- Screenshot tidak berisi kredensial, password, email, atau nomor rekening.
- Label sopir/unit/Project adalah fixture demonstrasi yang dicatat di atas, bukan data operasional yang perlu dipublikasikan.
- Detail dengan data kontak demo tidak dipasang sebagai asset publik.
- `php -d extension=mbstring -l config/change_log.php` dan cache/view/route wajib dijalankan pada pemeriksaan final setelah perubahan anotasi ini.
- Tidak ada JavaScript module yang berubah; publish module tidak diperlukan.

## Cleanup dan handoff

Bonus ID 884 sudah dihapus melalui tombol aplikasi. Bonus ID 880 serta SPB ID 1 dan 119 dipertahankan untuk tahap settlement/payroll; jangan menghapus atau menautkan ulang sumber tersebut. Tidak ada transaksi posted yang dibuka kuncinya.

Lanjutkan ke [05G — Panduan Kasbon](../05G-PANDUAN-KASBON.md). Tahap itu harus mempertahankan pemisahan Bonus sebagai biaya/payroll dan tidak memasukkannya ke line invoice pelanggan.
