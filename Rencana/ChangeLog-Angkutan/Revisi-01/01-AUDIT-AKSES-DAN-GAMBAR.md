# 01-AUDIT-AKSES-DAN-GAMBAR — Audit jalur pembaca dan gambar

## Tujuan dan prasyarat

Audit jalur pembaca dan gambar. Prasyarat: Tidak ada; baca 00.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Telusuri lewat agent-browser: ikon ! → Change Log → detail; lalu masuk dari menu Panduan bila tersedia. Catat label, URL, klik, screenshot dan apakah pengguna menemukan artikel tanpa mengetik URL.
2. Petakan setiap PNG publik ke artikel, section, img src dan caption. Bedakan file tersedia, dirender, HTTP berhasil, serta terlihat dan terbaca. Inspeksi network/DOM read-only sebagai bukti tambahan.
3. Konfirmasi temuan awal pada sumber aktual: show.blade.php belum merender gambar; config/change_log.php belum berisi pemetaan artikel/gambar. Periksa apakah menu Panduan sebenarnya tersedia di tempat lain sebelum membuat route baru.
4. Catat masalah contoh angka 21 × 115.000 = 2.415.000; bila klaim +150.000 dan potongan nol, hasil aritmetika 2.565.000, bukan 2.515.000. Jangan mengganti contoh sebelum rekonsiliasi snapshot/rumus sebenarnya.
5. Susun peta source terbatas, daftar artikel, permission tiap audiens, inventaris gambar aman/usang dan prioritas masalah.

## Hasil wajib

Peta akses, matriks aset → artikel, bukti browser awal, daftar temuan dengan lokasi kode. Simpan `hasil/01-HASIL.md` memakai kontrak 00.

## Kriteria lulus

Penyebab gambar tak terlihat dibuktikan; URL Panduan aktual atau ketiadaannya tercatat. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

