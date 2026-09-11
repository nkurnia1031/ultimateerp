# 11C — Panduan SPB manual dan verifikasi

## Tujuan

Terbitkan satu tutorial lengkap tentang SPB manual dan verifikasi di aplikasi. Status awal BELUM DIJALANKAN. Baca [aturan paket](00-MULAI-DAN-PETA-TAHAP.md), kontrak template 08, pipeline 09 dan akses 10 hanya bila diperlukan.

## Prasyarat dan batas konteks

Hasil 07–10 lulus, fitur terkait dapat dioperasikan melalui UI. Untuk Bonus/Kasbon/import Bonus, hasil 03–06 yang terkait harus lulus. Gunakan bukti lama hanya sebagai petunjuk fixture; verifikasi ulang sebelum menggunakannya. Jangan menulis topik lain dalam sesi ini.

## Cakupan artikel

form satu perjalanan; seluruh field tonase dan kg; simpan/edit, selesai/verifikasi, tarif snapshot, klaim dan potongan; aturan terkunci.

## Langkah kerja

1. Temukan route dan label aktual, pilih role/business demo, lalu jalankan skenario di atas melalui agent-browser. Catat input, klik, hasil sebelum/sesudah dan setelah reload. Jika alur gagal, perbaiki dalam lingkup atau tandai artikel belum siap terbit.
2. Susun tutorial Bahasa Indonesia: tujuan dan siapa pemakai; lokasi menu; syarat data/permission; istilah saat pertama digunakan; langkah bernomor per tindakan; hasil setiap langkah; arti field penting; kesalahan umum dan penyelesaian; tautan langkah berikutnya. Hindari satu paragraf yang menggabungkan seluruh transaksi.
3. Untuk operasi transaksi, jelaskan status awal/akhir, efek terhadap data lain, kapan boleh edit/hapus, dan apa yang dilakukan jika tombol tidak tersedia. Sebut koreksi hanya bila jalurnya terbukti tersedia; jika belum ada, jelaskan keterbatasan dan kontak admin.
4. Tulis contoh angka dari fixture yang diuji dan rekonsiliasi aritmetika dengan detail transaksi. Bedakan ton dan kilogram, netto, nilai dasar, penyesuaian dan nilai akhir; bedakan pendapatan pelanggan, upah SPB, Bonus, Kasbon dan payroll. Bonus bukan line invoice. Untuk artikel navigasi, gunakan contoh pencarian/klik, bukan contoh nominal yang tidak relevan.
5. Capture PNG raw dari keadaan nyata setelah perbaikan final. Buat gambar hasil Python/Pillow dengan penanda target sesuai nomor langkah; minimal gambar lokasi/menu, tindakan utama, dan hasil untuk tutorial transaksi bila memang berbeda. Untuk navigasi, gambar kedua pintu masuk dan artikel sudah cukup. Tambahkan gambar khusus untuk langkah yang sulit, bukan mengejar jumlah.
6. Pastikan screenshot hanya data demo aman. Simpan raw internal, preset/perintah, output annotated dan manifest. Sematkan PNG hasil proses sebagai figure tepat di dekat langkah, dengan alt dan caption serta akses perbesar. Jangan menaruhnya hanya di hasil Markdown internal.
7. Daftarkan artikel pada kategori Panduan dan rilis Change Log yang relevan; gunakan konten tunggal dan slug tetap. Coba membuka artikel dari kedua indeks dengan klik label yang terlihat.
8. Pada desktop dan ponsel, ikuti artikel sebagai pengguna baru: lakukan langkah tanpa instruksi chat, cocokkan nomor penanda dengan target UI, klik daftar isi/perbesar/kembali dan reload. Periksa gambar dimuat, ukuran baca nyaman, serta link fitur mengikuti permission.
9. Bersihkan fixture bebas melalui UI setelah bukti disimpan; catat fixture terkunci/posted dan kebutuhan tahap berikutnya. Jangan mengubah fixture operasional.

## Hasil wajib

Artikel terbit di aplikasi; slug/URL; daftar section dan link kedua pintu; contoh tervalidasi; manifest raw → preset → PNG → langkah; screenshot artikel yang menampilkan gambar; hasil pengujian dan cleanup di `hasil/11C-HASIL.md`.

## Kriteria lulus dan handoff

Pengguna dapat menemukan tutorial dari Change Log maupun Panduan, membaca penanda dan menyelesaikan skenario. Seluruh langkah sesuai label/hasil UI; tidak ada placeholder atau klaim belum diuji. Catat file konten/renderer yang berubah dan versi UI screenshot; berhenti setelah artikel ini.

