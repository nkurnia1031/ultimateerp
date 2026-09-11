# Hasil 06H — Cleanup lokal dan serah terima

## Status

**LULUS dengan handoff terbatas.** Data uji yang masih dapat dihapus dibersihkan melalui tombol UI dan dialog browser. Data yang sudah billed, settled, posted, atau tidak menyediakan aksi hapus dipertahankan serta dicatat agar tidak dibuka kuncinya secara paksa.

## Cleanup yang dilakukan melalui browser

Run: `RUN06H-20260911`, sesi `changelog06e`, business aktif `CV BM TRANSPORT` (`business_id=2`).

1. User membuka **Pool → Bonus Ayak → Form Kasbon Bonus Ayak**.
2. User melihat Kasbon ID `20` (`Rp1.699.963`) dan ID `21` (`Rp2.000.001`) sebagai data uji yang belum terkunci.
3. User menekan **Hapus** pada ID 21, memilih **Cancel/Dismiss** pada dialog `Hapus kasbon ini?`, lalu memastikan baris tetap ada.
4. User menekan **Hapus** lagi dan memilih **Accept/OK**. ID 21 hilang dari daftar.
5. User mengulangi langkah pada ID 20 dan memilih **Accept/OK**. ID 20 hilang setelah reload.

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Batal hapus | Data tetap ada | ID 21 tetap ada setelah dialog dibatalkan | LULUS |
| Hapus data uji | Baris dan total berkurang | ID 21 lalu ID 20 hilang melalui UI | LULUS |
| Reload daftar | Data yang dihapus tidak muncul lagi | Daftar akhir tidak menampilkan Rp1.699.963 atau Rp2.000.001 | LULUS |
| Sumber posted | Tidak boleh dihapus | Kasbon Rp300.037 dan Rp150.000 tetap berlabel `Terkunci payroll` | LULUS sebagai proteksi |

Total daftar Kasbon berubah dari `Rp6.990.001` menjadi `Rp3.290.037`, selisih `Rp3.699.964` yang sama dengan ID20 + ID21. Bukti akhir: [Kasbon setelah cleanup](screenshots/06H-kasbon-after-cleanup-annotated.png).

## Inventaris handoff yang dipertahankan

Semua item berikut berada pada business 2 dan tidak dihapus atau di-unlock:

- SPB yang sudah dipakai tahap billing/payroll, termasuk SPB ID 1, 2, 119, 120, dan 121. SPB ID121 adalah `TRIP-202609-0005` dengan settlement posted `SET-202609-0007`.
- Invoice Project `DR2026/0006` (transaction ID 54289) dan Sell `DR2026/0007` (transaction ID 54290) karena sudah terhubung dengan billing source dan/atau tidak menyediakan cleanup aman pada UI.
- Bonus Ayak ID 880, 885, dan 886 karena dipakai sebagai sumber cost/payroll atau settlement posted.
- Kasbon ID 17 (`Rp150.000`) dan ID 19 (`Rp300.037`) karena sudah terkunci payroll. Kasbon legacy lain yang tampil sebagai `Terkunci payroll` juga tidak disentuh.
- Settlement `SET-202609-0003`, `SET-202609-0007`, serta record settlement draft/cancelled hasil pengujian karena koreksi langsung akan memutus audit trail.
- Payroll `2026/0331` untuk regresi payroll biasa dan Payroll transaction ID 54295 dari settlement angkutan.
- Invoice Project legacy `DR2026/0010` (dibuat pada 06B) dan Sell draft `DR2026/0011` (dibuat pada 06C); daftar UI tidak menyediakan jalur Delete yang aman untuk kedua fixture tersebut.

## Daftar file dan aset

- Dokumen instruksi: `Rencana/ChangeLog-Angkutan/00-*.md` sampai `06-*.md`, termasuk dokumen pecahan per halaman/skenario.
- Hasil: `Rencana/ChangeLog-Angkutan/hasil/*-HASIL.md`.
- Screenshot raw internal: `storage/app/changelog-evidence/RUN05A-20260911` sampai `RUN06H-20260911`.
- Screenshot anotasi: `Rencana/ChangeLog-Angkutan/hasil/screenshots/` dan backup per run pada `storage/app/changelog-evidence/*/annotations/`.
- Gambar Change Log yang dipublikasikan: `public/images/change-log/release-2026-09-10-angkutan/`.
- Pipeline anotasi: `scripts/annotate_changelog_screenshots.py`; format final PNG raster dari Pillow.
- Kode Change Log: `app/Support/ChangeLogCatalog.php`, `app/Http/Controllers/ChangeLogController.php`, `app/ChangeLogRead.php`, `resources/views/change-log/`, `resources/views/layouts/partials/change-log-indicator.blade.php`, dan migration Change Log read state.
- Kode angkutan: module Asset Management untuk tarif, SPB, settlement, report, dan relasinya; integrasi Bonus Ayak/Project/Sell/Payroll sesuai file yang tercantum pada 06G.

## Deployment manual — tidak dijalankan

Operator deployment harus:

1. Membuat backup database dan file sebelum perubahan.
2. Meninjau migration angkutan, Bonus Ayak, Payroll, dan Change Log; jalankan migration pada target yang benar.
3. Menyalin source code, Blade, konfigurasi artikel, dan PNG public. Jangan menyalin raw screenshot atau fixture demo ke area publik.
4. Menjalankan `php artisan migrate --no-interaction`, `php artisan view:cache --no-interaction`, dan `php artisan route:cache --no-interaction`.
5. Jika job/import berubah, restart worker dengan konfigurasi queue deployment dan pastikan worker sehat.
6. Publish module JavaScript hanya bila source module berubah; untuk `public/js` gunakan asset application-wide yang sesuai.
7. Menjalankan smoke test berwenang: login → ikon `!` → Change Log → SPB → Project/Sell → settlement → rekap. Gunakan business target, bukan business demo.
8. Menambahkan rilis baru hanya dengan `release_id`, tanggal rilis yang benar, slug stabil, ringkasan bahasa awam, detail, link fitur, PNG anonim, dan alt text. Uji pulse/read state per user-business sebelum rilis.

Tidak ada deployment, migrasi target, restart worker produksi, atau perubahan data production yang dilakukan pada tahap ini.

## Masalah tersisa

- PHPUnit dan `migrate:status` masih menunggu ekstensi `mbstring` pada PHP CLI lokal, sesuai hasil 06G.
- Export CSV masih baru terbukti menerima response HTTP 200 pada tahap 02N; artifact file belum dibaca sel per sel.
- Add maintenance belum dapat diuji karena permission user demo tidak menampilkan tombol Add.
- Fixture invoice/Sell/payroll yang tidak memiliki Delete tetap ada di local dan harus dikecualikan dari data laporan deployment.

Instruksi untuk menutup tiga blocker tersebut tersedia pada [07 — Remediasi blocker final](../07-REMEDIASI-BLOCKER-FINAL.md).

## Kriteria serah-terima

- Cleanup dilakukan dari transaksi turunan dan tidak membuka kunci transaksi posted: terpenuhi.
- ID, business, status, relasi, screenshot, dan masalah tersisa terdokumentasi: terpenuhi.
- Panduan tidak menjanjikan perilaku yang belum terbukti: terpenuhi dengan catatan di atas.
- Deployment otomatis: tidak dilakukan.

Paket Change Log dan panduan siap diserahkan untuk review operator, dengan blocker environment dan fixture handoff tetap terlihat.
