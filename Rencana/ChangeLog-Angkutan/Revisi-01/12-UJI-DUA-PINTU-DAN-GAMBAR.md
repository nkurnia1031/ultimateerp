# 12 — Uji dua pintu dan gambar

## Tujuan dan prasyarat

Buktikan semua tutorial dapat ditemukan dan dibaca. Prasyarat 10 dan 11A–11L lulus. Baca [aturan paket](00-MULAI-DAN-PETA-TAHAP.md) dan hanya daftar URL/manifest dari handoff artikel.

## Langkah

1. Gunakan sesi agent-browser baru pada aplikasi lokal. Setelah login, mulai dari ikon !; jangan mengetik URL artikel atau path gambar untuk membuktikan discoverability.
2. Dari overview Change Log temukan setiap topik, buka artikelnya, gunakan daftar isi, gulir, perbesar gambar dan kembali. Ulangi secara independen dari menu Panduan dengan pencarian/kategori. Catat jumlah klik; setiap artikel maksimal dua klik dari masing-masing indeks.
3. Buat matriks 12 artikel × dua pintu. Untuk masing-masing catat URL, label link, hasil render, jumlah figure, broken image, dan bukti screenshot. Jangan menganggap sampel satu artikel mewakili semua.
4. Periksa semua figure melalui DOM/network read-only: src/currentSrc sesuai base URL lokal, response gambar benar, naturalWidth/Height positif setelah lazy-load, bukan raw privat atau path Windows. Inspeksi visual memastikan nomor/kotak/panah berada di target yang benar. HTTP 200 yang ternyata HTML tidak lulus.
5. Lakukan putaran navigasi desktop dan ponsel; periksa teks/angka pada gambar saat inline dan diperbesar, caption, horizontal overflow, anchor, browser back, refresh dan pencarian tanpa hasil.
6. Uji akun dengan akses Bonus saja, payroll dan angkutan sesuai fixture tersedia, serta business lain. Artikel yang boleh dibaca harus mudah ditemukan; link aksi tidak memberikan izin lebih besar. Jangan memakai akun admin sebagai satu-satunya bukti akses.
7. Uji slug salah, aset hilang pada fixture pengujian terkontrol, rilis sudah dibaca/belum dibaca dan rilis baru. Pastikan tidak ada halaman kosong, icon hilang atau notifikasi lama terganggu.
8. Jika ada kegagalan, perbaiki source/asset/link terkait dan ulangi sel matriks yang gagal; perubahan UI memerlukan pengambilan ulang gambar terkait. Jangan menutupi error dengan placeholder yang tampak sukses.

## Hasil wajib

`hasil/12-HASIL.md`: matriks lengkap, bukti dua jalur, pemeriksaan gambar desktop/ponsel, akses dan masalah tersisa. Hasil per sel LULUS/GAGAL/BELUM DIUJI.

## Kriteria lulus

Semua artikel dapat ditemukan dari dua tempat dan semua gambar berpenanda benar-benar terlihat. Tidak ada artikel yatim, gambar broken/usang atau link internal ke Rencana. Bila gagal, tahap 13 tidak boleh mengklaim selesai penuh.

## Handoff

Catat rute final, manifest dan sel yang perlu diulang; berhenti.

