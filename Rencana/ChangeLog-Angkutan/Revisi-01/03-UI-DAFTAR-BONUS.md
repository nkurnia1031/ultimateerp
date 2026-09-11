# 03-UI-DAFTAR-BONUS — Perbaiki daftar, filter dan rekap Bonus Ayak

## Tujuan dan prasyarat

Perbaiki daftar, filter dan rekap Bonus Ayak. Prasyarat: 02.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Ubah hanya daftar Bonus Ayak dan komponen bersama yang diperlukan. Pisahkan aksi Tambah Bonus, Kasbon, Import, Export dan Panduan; jangan menjejalkan semua menjadi tombol utama.
2. Tampilkan filter yang benar-benar didukung, ringkasan sesuai filter, operator/unit/project, sumber SPB dan status pembayaran. Jangan menjanjikan filter yang tidak bekerja.
3. Rapikan tabel, nominal, empty state dan tampilan ponsel; pastikan aksi baris tetap terlihat saat tabel digeser.
4. Uji filter/reset/pagination, detail, navigasi ke edit/Kasbon; batalkan hapus, lalu hapus data uji yang boleh dihapus dan reload. Uji penolakan data terkunci.
5. Ambil bukti sebelum/sesudah desktop/ponsel dengan data uji; catat pemeriksaan teknis terkait.

## Hasil wajib

Perubahan halaman daftar, bukti filter dan aksi, daftar selector/label final. Simpan `hasil/03-HASIL.md` memakai kontrak 00.

## Kriteria lulus

Daftar mudah dipindai, ringkasan cocok dengan filter, aksi lama tetap bekerja. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

