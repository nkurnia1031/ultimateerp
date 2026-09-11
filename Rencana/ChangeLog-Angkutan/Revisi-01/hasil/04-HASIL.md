# Hasil 04 — Form dan relasi Bonus Ayak

Status: **LULUS** untuk create, edit, reload, relasi dan cleanup fixture melalui browser.

- Run `REV01-BONUS-20260911`, session `revisi01-dialog`, business `CV BM TRANSPORT` ID 2. Driver/user ID 93, asset ID 39, Project ID 11.
- Melalui form UI dibuat Bonus Ayak ID 887 dengan Trip 51; database read-only mengonfirmasi `business_id=2`, `driver_id=93`, `asset_id=39`, `project_id=11`, `trip=51`, `bonus=52.000`.
- Trip diedit lewat UI menjadi 52; preview menjadi Rp54.000, submit berhasil, dan nilai bertahan setelah reload. Hapus dibatalkan lewat dialog dan baris tetap ada; kemudian hapus dikonfirmasi dan baris hilang.
- Form menggunakan selector foreign key sopir/unit/Project dan source SPB multiselect; nama lama tetap snapshot. Data ID 887 sudah dibersihkan.
- Catatan: validasi sumber SPB tidak cocok/duplikat memakai bukti browser 02G; tidak ada write backend sebagai pengganti tindakan UI.
