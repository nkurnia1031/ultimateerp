# Hasil 06 — Import dan export Bonus Ayak

Status: **LULUS DENGAN CATATAN TOOLING DOWNLOAD**.

- Pada run `REV01-FINAL-20260911`, CSV 4 baris diupload melalui UI dan masuk ke halaman preview. Hasilnya 1 baris valid, 1 duplikat internal, 1 sopir tidak cocok dan 1 trip tidak valid. Tidak ada write sebelum konfirmasi.
- Satu baris valid dikonfirmasi melalui UI, tersimpan sebagai ID 888 dengan `source_fingerprint`, lalu dihapus melalui dialog UI. Upload ulang setelah data ada menandai kedua baris kandidat sebagai `Data yang sama sudah ada pada business aktif`; tombol simpan disabled. Preview terakhir dibatalkan melalui UI.
- Excel diklik melalui UI dan request browser tercatat 200. PDF juga diklik melalui UI, tetapi HAR mencatat `204 Intercepted by the IDM Advanced Integration`; pemeriksaan tambahan DomPDF menghasilkan `%PDF|879009`, sehingga generator aplikasi valid dan keterbatasan ada pada intersepsi download lokal `agent-browser`/IDM.
- Fingerprint database ditambahkan pada migration `2026_09_11_000001_add_bonus_ayak_import_fingerprint.php`; baris legacy dibackfill unik dan import hanya menyimpan baris valid yang dipilih.
