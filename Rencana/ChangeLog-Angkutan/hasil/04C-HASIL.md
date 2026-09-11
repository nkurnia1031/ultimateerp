# Hasil 04C — Variasi Navbar, Business, dan Kegagalan Status Baca

## Status

**LULUS TERARAH.** Variasi rilis baru, reduced motion, ponsel, dua sesi, kegagalan penyimpanan status baca, pemulihan, dan business lain berhasil dibuktikan melalui `agent-browser`. Isolasi business dibuktikan dengan business uji baru yang kosong. Tidak ada transaksi operasional yang dibuat atau diubah untuk tahap ini.

## Fixture dan batasan cleanup

- Business utama: `CV BM TRANSPORT`, `business_id=2`, admin user `id=5`.
- Business pembanding: `RUN04C Business Other`, `business_id=7`, dibuat melalui wizard registrasi browser untuk pengujian isolasi.
- Owner business pembanding: user `id=99`; kredensial tidak ditulis dalam hasil publik.
- Business pembanding tidak memiliki SPB, invoice, payroll, atau transaksi operasional.
- Rilis produksi yang diuji: `release-2026-09-10-angkutan`.
- Tiga rilis sementara untuk variasi rilis baru, gagal, dan dua sesi sudah dikeluarkan kembali dari `config/change_log.php` setelah pengujian.
- Row `change_log_reads` untuk tiga release sementara tetap berada di database lokal sebagai fixture teknis. Karena tidak ada tombol UI untuk menghapus histori status baca, row tersebut tidak dihapus dengan SQL. Row itu tidak menunjuk transaksi operasional dan tidak muncul lagi karena release sudah tidak ada di katalog.
- Business uji dan owner tidak memiliki jalur penghapusan melalui UI yang aman pada tahap ini; keduanya dipertahankan sebagai fixture lokal kosong dan dicatat untuk cleanup/handoff. Tidak ada password atau data pribadi yang dipublikasikan.

## Matriks hasil

| Variasi | Expected | Actual | Status | Bukti |
|---|---|---|---|---|
| Reduced motion | Pulse tidak bergerak, tetapi indikator unread tetap terlihat | Ring statis tetap terlihat saat `prefers-reduced-motion` aktif; link dan label tetap tersedia | Lulus | [reduced-motion](../../../hasil/screenshots/04C-navbar-new-release-reduced-motion.png) |
| Ponsel | Ikon tetap berada di navbar, target klik nyaman, tidak menutupi notifikasi | Ikon `!` tetap terlihat pada viewport ponsel dan dapat dibuka | Lulus | [navbar ponsel](../../../hasil/screenshots/04C-navbar-mobile-unread.png) |
| Rilis baru | Release baru memunculkan pulse dan menjadi item terbaru | Rilis sementara tampil paling atas, `data-unread=1`, lalu berhenti setelah overview dibuka | Lulus | [overview setelah rilis baru dibaca](../../../hasil/screenshots/04C-overview-new-release-read.png) |
| POST status baca gagal | Artikel tetap terbuka, pulse tidak boleh hilang | Request POST di-abort; overview tetap terbuka tetapi class `is-unread` dan `data-unread=1` bertahan | Lulus | [gagal tetap unread](../../../hasil/screenshots/04C-read-status-failure-kept-unread.png) |
| Pemulihan | Setelah jaringan pulih, percobaan berikutnya menyimpan status | Request berikutnya 200, pulse hilang, state menjadi `data-unread=0` | Lulus | [status pulih](../../../hasil/screenshots/04C-read-status-recovered.png) |
| Dua sesi | Request bersamaan tidak menggandakan histori baca | Dua sesi admin membuka overview; keduanya menerima 200. Pemeriksaan read-only menghasilkan satu row per user/release, bukan duplikasi | Lulus terarah | — |
| Business lain | Rilis tetap terlihat sesuai business aktif; SPB business lain tidak bocor | Owner business 7 melihat release produksi namun halaman SPB menampilkan `Belum ada trip`; tidak ada `TRIP-` atau data business 2 | Lulus | [overview business lain sebelum baca](../../../hasil/screenshots/04C-business-other-overview-before-read.png), [setelah baca](../../../hasil/screenshots/04C-business-other-overview-after-read.png), [SPB kosong](../../../hasil/screenshots/04C-business-other-spb-empty.png) |

## Tindakan browser dan hasil aktual

### 1. Rilis baru mengaktifkan kembali pulse

Tindakan pengguna pada sesi admin business 2:

1. Rilis demonstrasi baru ditambahkan ke katalog kode.
2. Home dibuka melalui browser setelah release produksi sudah berstatus terbaca.
3. Ikon `!` diamati sebelum diklik.
4. Ikon diklik dan overview Change Log ditunggu sampai selesai.
5. Overview direload.

Hasil:

- Rilis demonstrasi muncul sebagai rilis paling baru.
- Ikon kembali memiliki class `is-unread` dan `data-unread=1`.
- Setelah klik normal, URL berubah menjadi `/change-log`, request `POST /change-log/read` berstatus 200, dan pulse berhenti.
- Setelah reload, release tetap tampil tanpa POST ulang.
- Katalog kemudian dikembalikan sehingga hanya release produksi yang diterbitkan.

### 2. Reduced motion

Media browser diubah melalui perintah `agent-browser` menjadi `light reduced-motion`. Tidak ada CSS atau status baca yang dipalsukan melalui JavaScript.

Hasil visual:

- Ring indikator tetap ada sebagai tanda unread.
- Animasi tidak berjalan.
- Ikon tetap dapat difokuskan dan diklik.

### 3. Kegagalan penyimpanan dan pemulihan

Tindakan pengguna:

1. Release demonstrasi dibuat melalui katalog kode.
2. Network route sesi browser untuk endpoint `/change-log/read` di-abort.
3. Ikon `!` diklik secara normal.
4. Overview dibuka.
5. State DOM dan screenshot diperiksa.
6. Route gangguan dilepas.
7. Ikon diklik lagi secara normal.

Expected:

- Gangguan hanya mencegah penyimpanan, bukan membuka halaman.
- Pulse bertahan setelah POST gagal.
- Percobaan berikutnya dapat menyimpan status.

Actual:

- Overview tetap terbuka.
- Network menunjukkan satu request POST yang di-abort.
- `is-unread` dan `data-unread=1` tetap ada pada kegagalan.
- Setelah route dipulihkan, POST 200 diterima dan pulse berhenti.
- Release demonstrasi dikeluarkan dari konfigurasi setelah bukti tersimpan.

### 4. Dua sesi browser

Dua sesi admin business 2 dibuka menggunakan akun yang sama. Keduanya berada pada Home dengan release demonstrasi belum terbaca, kemudian ikon `!` diklik melalui browser pada masing-masing sesi.

Hasil:

- Kedua sesi sampai ke `/change-log`.
- Kedua request `POST /change-log/read` berstatus 200.
- `upsert` dan unique key pada server menghasilkan satu row untuk setiap kombinasi user, business, dan release.
- Tidak ada transaksi, invoice, payroll, atau SPB yang dibuat.

### 5. Business lain dan isolasi data

Business pembanding dibuat hanya melalui alur UI registrasi:

1. Mengisi nama business, lokasi, mata uang IDR, dan pemilik melalui wizard.
2. Login sebagai owner business pembanding.
3. Membuka link Change Log dari Home.
4. Membaca release produksi.
5. Membuka Asset Management lalu SPB Angkutan.

Hasil:

- Overview Change Log tersedia untuk business aktif pembanding dan memiliki status baca sendiri.
- Halaman SPB menampilkan `Belum ada trip`.
- SPB fixture dari business 2 tidak terlihat.
- Pemeriksaan read-only menunjukkan row production terpisah berdasarkan `business_id=2` dan `business_id=7`.
- Tidak ada upaya mengubah password user business 6 yang sebelumnya diketahui dari audit; pengujian memakai business baru dengan kredensial uji lokal yang tidak dipublikasikan.

## Pemeriksaan tambahan

- Sesi user terbatas dari tahap sebelumnya tetap ditolak saat membuka detail artikel yang tidak eligible; ini memperkuat bahwa endpoint tujuan tetap memeriksa akses.
- POST tanpa CSRF dan release ID tidak eligible sudah diuji pada [hasil 04A](04A-HASIL.md), masing-masing 419 dan 422. Pemeriksaan itu bersifat tambahan dan tidak menggantikan click journey.
- Notifikasi lama tidak disentuh pada tahap ini; regresinya sudah dibuktikan pada [hasil 04B](04B-HASIL.md).
- Tidak ditemukan file diagnostik sementara setelah pemeriksaan read-only selesai.

## Temuan dan keputusan

1. **Tidak ada defect baru pada ikon/navbar.** State unread, reduced motion, mobile, retry, dan idempotensi bekerja sesuai kontrak.
2. **Business pembanding tidak dapat dihapus melalui UI yang tersedia.** Karena business baru kosong dan tidak memiliki transaksi, dibiarkan sebagai fixture lokal. Tahap cleanup wajib mencatatnya dan tidak boleh menghapusnya langsung dengan SQL.
3. **Histori status baca release sementara tidak memiliki UI cleanup.** Row lokal dipertahankan, tetapi katalog publik sudah dikembalikan ke satu release produksi sehingga tidak memengaruhi pengguna.

## Kriteria berhenti

Tahap 04C berhenti setelah bukti variasi tersimpan. Tidak ada perubahan pada notifikasi lama, tidak ada transaksi posted yang dibuka, dan tidak ada data operasional yang dihapus.

## Tahap berikutnya

Lanjutkan [05A — Panduan master unit dan tarif](../05A-PANDUAN-MASTER-UNIT-DAN-TARIF.md). Panduan harus hanya menjelaskan perilaku yang sudah terbukti pada hasil 01A–04C dan tidak menjanjikan cleanup business atau penghapusan status baca yang belum tersedia melalui UI.
