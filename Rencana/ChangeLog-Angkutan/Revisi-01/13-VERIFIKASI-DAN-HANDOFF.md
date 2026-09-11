# 13 — Verifikasi dan handoff

## Tujuan dan prasyarat

Tutup revisi dengan bukti UI, isi dan publikasi. Prasyarat 03–06 serta 12 lulus. Baca [aturan paket](00-MULAI-DAN-PETA-TAHAP.md) dan handoff relevan.

## Langkah

1. Rekonsiliasi tiga permintaan pengguna terhadap file implementasi dan bukti: UI Bonus memakai hasil audit Taste; panduan rinci terstruktur; dua akses jelas dan gambar raster tampil.
2. Ulangi smoke test terfokus Bonus: create manual → edit/reload → sumber SPB sesuai → status payroll; Kasbon bebas → batal hapus → hapus/reload. Gunakan fixture lokal yang tercatat, jangan mengulang transaksi posted lama.
3. Verifikasi integrasi terkait yang terkena perubahan: Project, Sell, payroll dan navbar/notifikasi. Pisahkan kekurangan lama dari regresi baru dengan bukti.
4. Periksa angka artikel dengan manifest fixture, relevansi screenshot final, tidak ada informasi sensitif, link/slug/anchor valid dan akses role/business benar.
5. Jalankan lint/test relevan, Blade/route cache jika berubah, migration pretend hanya bila ada migration; publish JS module yang berubah dan verifikasi hasil, lalu Graphify update. PHPUnit yang tidak dapat mulai bukan test lulus; tangani prasyarat lokal yang bisa diperbaiki atau catat blocker.
6. Periksa deployment manifest memuat konten dan semua PNG publik yang direferensikan; URL tidak bergantung folder lokal, dan cache/version asset diperbarui sesuai mekanisme aplikasi. Siapkan instruksi deployment manual.
7. Cleanup fixture melalui UI mulai transaksi turunan. Catat ID yang tertinggal karena posted/locked, tanpa membuka kunci. Jangan menghapus bukti atau hasil historis.
8. Tulis MD masalah terpisah bila tersisa: reproduksi, expected/actual, file, dampak, prasyarat dan input yang benar-benar diperlukan. Jangan melabeli kelulusan penuh jika salah satu permintaan belum terpenuhi.

## Hasil wajib

`hasil/13-HASIL.md` berisi matriks acceptance, daftar file, URL Panduan/Change Log, screenshot final, hasil tes, keputusan adaptasi Taste, cleanup, blocker dan deployment manual.

## Kriteria lulus

UI dapat dioperasikan, artikel dapat diikuti, angka benar dan gambar berpenanda terbaca dari kedua pintu. Bukti internal saja tidak cukup. Handoff tidak melakukan deployment otomatis.

## Setelah selesai

Berhenti dan serahkan lokasi hasil. Jangan memperluas ke redesign fitur lain.

