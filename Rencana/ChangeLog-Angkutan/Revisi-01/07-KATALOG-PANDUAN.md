# 07-KATALOG-PANDUAN — Bangun katalog artikel dan route Panduan

## Tujuan dan prasyarat

Bangun katalog artikel dan route Panduan. Prasyarat: 01; dapat dikerjakan setelah 06 atau terpisah dari perubahan UI.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Gunakan katalog terkurasi bersama kode; satu sumber konten untuk rujukan Change Log dan Panduan. Pertahankan slug/rilis lama dan status baca.
2. Jika audit tidak menemukan pusat Panduan, buat GET /panduan dan GET /panduan/{slug} dengan nama route konsisten. Jika ada, perluas yang ada dan catat URL aktual.
3. Definisikan judul, slug, kategori, audiens, permission/module, urutan, tanggal pembaruan aktual, release terkait, section anchor, langkah, field, gambar, contoh, hasil dan troubleshooting.
4. Petakan topik 11A–11L. Filter artikel/link per akses aktual; jangan menggantungkan seluruh dokumentasi Bonus/Payroll pada asset.view jika perannya memiliki akses fitur sendiri.
5. Uji login, slug tak dikenal, peran relevan, modul nonaktif dan business berbeda. Konten tutorial hanya data demo. Jangan expose path file arbitrary atau render HTML tidak tepercaya.

## Hasil wajib

Katalog/route dan matriks akses artikel; kompatibilitas URL lama. Simpan `hasil/07-HASIL.md` memakai kontrak 00.

## Kriteria lulus

Setiap artikel punya URL tetap dan satu sumber konten; akses selaras dengan fitur. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

