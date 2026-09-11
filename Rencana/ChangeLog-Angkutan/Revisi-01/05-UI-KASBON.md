# 05-UI-KASBON — Perbaiki halaman Kasbon

## Tujuan dan prasyarat

Perbaiki halaman Kasbon. Prasyarat: 04.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Terapkan komponen Taste yang disepakati ke form dan daftar Kasbon: sopir, periode, jumlah, status dipotong, aksi jelas.
2. Jelaskan kaitannya dengan potongan payroll; tampilkan state terkunci dengan alasan, jangan membuka kunci posted.
3. Buat fixture melalui UI; edit jika tersedia; batalkan dialog hapus; hapus fixture bebas dan reload. Jika edit tidak tersedia, catat gap nyata tanpa mengarang tombol.
4. Periksa total, filter yang tersedia, format nominal dan navigasi kembali ke Bonus pada desktop/ponsel.

## Hasil wajib

UI Kasbon, bukti CRUD tersedia dan locked state, nominal contoh. Simpan `hasil/05-HASIL.md` memakai kontrak 00.

## Kriteria lulus

Kasbon dapat dipahami dan dioperasikan tanpa tertukar dengan Bonus/upah SPB. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

