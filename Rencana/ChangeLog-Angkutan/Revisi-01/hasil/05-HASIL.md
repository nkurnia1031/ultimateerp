# Hasil 05 — Halaman Kasbon

Status: **LULUS** untuk alur utama dan penguncian payroll.

- Run `REV01-KASBON-20260911`, business ID 2, driver ID 93. Kasbon ID 22 dibuat lewat form, nominal Rp123.456, diedit menjadi Rp123.457, disimpan dan bertahan setelah reload.
- Dialog hapus dibatalkan dan data tetap ada; dialog kemudian dikonfirmasi dan ID 22 hilang dari daftar.
- Baris Kasbon yang sudah dipotong payroll dicoba dihapus melalui UI. Aplikasi menolak dengan pesan `Kasbon yang sudah dipotong payroll tidak dapat dihapus.` dan baris tetap tersedia.
- Bukti gambar: `storage/app/changelog-evidence/REV01-FINAL-20260911/raw/kasbon-desktop.png` dan PNG anotasi publik `REV01-kasbon-desktop-annotated.png`.
- Catatan: tombol hapus pada baris terkunci masih dapat diklik sebelum server menolak; tidak ada data terkunci yang dibuka paksa.
