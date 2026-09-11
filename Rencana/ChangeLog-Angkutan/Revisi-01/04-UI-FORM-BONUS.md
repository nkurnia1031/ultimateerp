# 04-UI-FORM-BONUS — Perbaiki input dan edit Bonus Ayak

## Tujuan dan prasyarat

Perbaiki input dan edit Bonus Ayak. Prasyarat: 03.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Kelompokkan identitas tanggal/sopir/unit/project, pilihan SPB, perhitungan bonus dan aksi simpan/batal. Gunakan penjelasan singkat bahwa Bonus adalah biaya/upah sopir.
2. Pilihan manual dan sumber SPB harus mudah dibedakan; ringkasan SPB terpilih menampilkan identitas dan jumlah yang relevan tanpa mengarang formula baru.
3. Periksa auto-fill dari Project/SPB, ketergantungan dropdown, multi-select, pesan ketidakcocokan sopir/unit/project, sumber terpakai dan snapshot legacy.
4. Lewat UI: buat manual, edit dan reload; buat dari beberapa SPB; coba sumber tidak cocok/duplikat melalui dua sesi; coba ubah data terkunci; batal dan hapus fixture yang diizinkan.
5. Pastikan error dekat field, nilai input bertahan setelah gagal, fokus keyboard dan tombol simpan nyaman di ponsel.

## Hasil wajib

Form final, bukti simpan/edit/validasi, contoh angka terverifikasi. Simpan `hasil/04-HASIL.md` memakai kontrak 00.

## Kriteria lulus

FK dan nominal bertahan setelah reload; kegagalan tidak menghilangkan input atau menciptakan bonus ganda. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

