# 09-PIPELINE-GAMBAR — Hubungkan screenshot berpenanda ke artikel

## Tujuan dan prasyarat

Hubungkan screenshot berpenanda ke artikel. Prasyarat: 08.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Audit ulang gambar publik lama untuk data sensitif dan UI usang. Jangan menyalin seluruh folder bukti ke public. Ambil raw baru melalui agent-browser setelah UI terkait final.
2. Proses raw memakai scripts/annotate_changelog_screenshots.py/Pillow atau utilitas Python setara: nomor langkah, kotak/panah nyata pada target, caption di ruang kosong. Tidak memakai SVG/HTML overlay atau gambar UI rekaan.
3. Buat manifest: article slug, section/step, raw internal, preset/koordinat/perintah, output PNG, hash/versi, ukuran, alt dan caption. Pengulangan perintah harus mereproduksi hasil.
4. Publikasikan PNG terpilih ke lokasi publik terkelola dan gunakan asset helper yang bekerja pada /2025/ultimateerp/public/. Jangan tautkan D:/..., Rencana/..., atau storage/app privat sebagai URL pembaca.
5. Lewat halaman artikel, gulir hingga gambar dimuat; cek src/currentSrc, HTTP/content-type, naturalWidth/naturalHeight > 0 dan inspeksi visual penanda. Cek gambar resolusi penuh dan ponsel.
6. Simpan bukti gambar tampil di artikel, bukan hanya screenshot folder/file. Bila CSS/caching/deploy path salah, perbaiki penyebab dan uji ulang.

## Hasil wajib

Pipeline/manifest, PNG aman terpasang pada pilot, bukti render browser. Simpan `hasil/09-HASIL.md` memakai kontrak 00.

## Kriteria lulus

Minimal satu artikel pilot menampilkan PNG berpenanda yang terbaca; file lokal saja tidak dihitung lulus. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

