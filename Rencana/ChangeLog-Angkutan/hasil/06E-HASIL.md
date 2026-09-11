# Hasil 06E — Regresi maintenance dan notifikasi

## Status

**LULUS dengan catatan.** Daftar maintenance, status locked, dropdown notifikasi lama, navigasi notifikasi, dan tampilan ponsel berhasil diuji melalui `agent-browser`. Menu maintenance pada business aktif tidak menyediakan tombol Add untuk user uji, sehingga pembuatan fixture baru tidak dapat diuji tanpa melewati UI.

## Fixture dan sesi

- Run browser: `RUN06E-20260911`, sesi `changelog06e` untuk notifikasi dan `changelog06d` untuk maintenance.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Maintenance dibaca: `2025/0002`, asset `BM 9174 LP`, status `Completed`, priority `Medium`.
- Notifikasi lama dibaca tanpa membuat atau mengirim notifikasi baru.

## Maintenance

1. User membuka **Asset Management → Asset maintenance**.
2. Daftar menampilkan maintenance `2025/0002`.
3. User membuka **Edit** melalui tombol UI.
4. Form menampilkan status `Completed`, priority `Medium`, dan catatan `Rehap Kabin` dalam keadaan readonly.
5. Percobaan mengisi catatan tidak mengubah nilai karena maintenance completed terkunci.
6. User menutup form tanpa menyimpan perubahan.

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Daftar maintenance | Data lama tetap muncul | `2025/0002` tampil | LULUS |
| Edit completed | Tidak boleh mengubah catatan terkunci | Textarea readonly; tidak ada perubahan tersimpan | LULUS |
| Hapus/batal | Data tidak hilang saat tidak dikonfirmasi | Tombol Delete tidak menghapus record pada uji ini; data tetap ada | LULUS untuk preservasi |
| Create maintenance | Ada form Add untuk fixture baru | Tombol Add tidak tersedia pada permission user uji | BELUM DIUJI / GAP |

## Notifikasi lama

1. User membuka dropdown lonceng melalui selector UI notifikasi.
2. Dropdown menampilkan notifikasi leave dan project lama.
3. User memilih `Mr Robert Robinson applied for a leave`.
4. Browser berpindah ke **HRM → Leave** dan baris leave `2026/0001` tampil.
5. Pada viewport 390×844, dropdown tetap terbaca, item dapat dipilih, dan ikon `!` Change Log tetap terlihat.

| Pemeriksaan | Actual | Status |
|---|---|---|
| Dropdown dan daftar lama | Notifikasi lama tampil | LULUS |
| Navigasi item | Menuju daftar Leave, bukan error | LULUS |
| Navbar desktop | Lonceng dan ikon `!` tampil berdampingan | LULUS |
| Navbar ponsel | Dropdown dan ikon `!` tetap terlihat | LULUS |
| Notifikasi baru | Tidak dibuat agar tidak mengirim notifikasi nyata | Tidak diperlukan |

## Bukti

- [Maintenance edit terkunci](screenshots/06E-maintenance-edit-open-annotated.png)
- [Dropdown notifikasi desktop](screenshots/06E-notification-dropdown-clean-annotated.png)
- [Dropdown notifikasi ponsel](screenshots/06E-notification-mobile-annotated.png)
- Raw browser tersimpan di `storage/app/changelog-evidence/RUN06E-20260911/raw/`.

Semua anotasi dibuat ulang sebagai PNG raster dengan Pillow dari screenshot browser; tidak ada SVG/HTML overlay sebagai aset final.

## Masalah tersisa

- User uji tidak memiliki jalur Add maintenance, sehingga create/edit/delete penuh belum terbukti.
- Tombol Delete pada maintenance existing belum menghasilkan dialog yang dapat direkam pada sesi ini; record tetap ada dan tidak dihapus.
- Uji ini tidak menyentuh maintenance production atau mengirim notifikasi baru.

## Tahap berikutnya

Lanjutkan ke [06F — rekonsiliasi akhir angkutan](../06F-REKONSILIASI-AKHIR-ANGKUTAN.md).
