# 01 — Hasil audit akses dan gambar

## Status

LULUS untuk audit penyebab; fitur publikasi panduan masih GAGAL memenuhi revisi. Tahap 02 siap dikerjakan. Tidak ada perubahan kode aplikasi atau transaksi operasional pada tahap ini.

Run: REV01-AUDIT-20260911. Sesi agent-browser: revisi01-audit. Akun demo lokal dari AGENTS.md, business terlihat CV BM TRANSPORT. ID business tidak diperiksa ulang di database; jangan menebaknya dari nama. Desktop diminta 1440×900; screenshot detail full-page 1440×2156. Ponsel diminta 390×844.

## Journey aktual

1. Buka URL lokal dan isi login melalui field UI. Perintah awal gagal karena ref @ tidak dikutip pada PowerShell; diperbaiki dengan quoted ref, kemudian login berhasil.
2. Home menampilkan Application Tour yang menutupi klik. Tekan End tour melalui tombol UI; tidak menghapus overlay melalui DOM.
3. Klik ikon navbar menggunakan selector yang diperiksa, #change-log-indicator. Overview Change Log terbuka dan memuat satu rilis, satu tautan “Lihat penjelasan dan cara pakai”.
4. Klik tautan itu. URL menjadi http://localhost/2025/ultimateerp/public/change-log/angkutan-spb-dan-bonus-ayak.
5. Inspeksi read-only .change-log-detail img menghasilkan 0. Screenshot full-page diperiksa visual: semua langkah berbentuk paragraf, tanpa figure, gambar, daftar isi atau navigasi tutorial per topik.
6. Reload melalui browser. URL tetap benar dan jumlah img tetap 0. Ubah viewport ponsel lalu capture ulang.
7. Menu Panduan tidak ditemukan pada navigasi akun ini. Pencarian kode pada routes, middleware sidebar dan layout juga tidak menemukan pusat Panduan. Penelusuran seluruh modul belum lengkap; tahap 07 tetap wajib memeriksa konflik route sebelum membuat route baru.

## Matriks akses

| Jalur | Hasil aktual | Status kebutuhan |
| --- | --- | --- |
| Ikon ! → overview | Berhasil setelah menutup tour | LULUS |
| Overview → detail rilis | Berhasil melalui klik | LULUS |
| Detail → tutorial Bonus khusus | Tidak ada tautan tersebut | GAGAL |
| Menu Panduan → artikel | Menu tidak ditemukan pada akun demo | GAGAL |
| Gambar dalam detail setelah reload | 0 elemen img | GAGAL |
| Ponsel → detail | Halaman tercapture; tetap template teks | Gambar belum terpenuhi |
| Role Bonus saja / business kedua | Belum diuji browser pada tahap ini | BELUM DIUJI |

## Penyebab dan prioritas

P1: config/change_log.php berisi satu rilis dengan steps berupa title/description; tidak ada referensi screenshot/artikel terpisah. resources/views/change-log/show.blade.php merender teks saja. Menyalin PNG ke public tidak menghubungkannya ke artikel. Tidak ada request gambar artikel yang dapat dinilai berhasil karena renderer belum membuat img.

P1: Detail panjang hanya memiliki link fitur SPB dan Rekap, bukan tutorial Bonus/Kasbon/Payroll per topik. Detail internal hasil/05F-HASIL.md tidak menjadi sumber konten aplikasi.

P1: Contoh “21 × 115.000 + klaim 150.000, potongan nol = 2.515.000” salah secara aritmetika: hasil seharusnya 2.565.000. HeavyEquipmentTripService.php baris 94–100 memakai baseRevenue - otherDeduction + claimAdjustment; claimAdjustment = (varianceKg + toleranceKg) × claimRate. Laporan 05B lama juga menulis 2.515.000, tetapi tidak menyebut potongan pada tabel contoh. Nilai 2.515.000 baru cocok jika potongan 50.000; ini hipotesis, belum pemeriksaan snapshot transaksi. Tahap 11C harus membuka fixture melalui UI dan merekonsiliasi field, bukan langsung mengganti nominal atau rumus klaim.

P2: ChangeLogCatalog::isVisible menyaring permission tunggal; rilis berpermission asset.view. BonusAyakController::authorizeAccess menerima superadmin atau bonus_ayak.access atau bonus_ayak.manage. Peran Bonus saja berpotensi tidak melihat artikel yang dibutuhkan; ini temuan kode, bukan bukti login multi-role.

P2: PNG lama bercampur raw dan annotated; kelayakan data publik serta kesesuaian UI belum diaudit visual satu per satu. Semua berstatus kandidat internal, bukan otomatis aman untuk artikel baru. Gambar Bonus/Kasbon dan navigasi wajib diambil ulang setelah redesign.

## Peta source dan handoff

- routes/web.php: grup change-log dan route index/show/read.
- app/Http/Controllers/ChangeLogController.php: pemanggilan katalog dan views.
- app/Support/ChangeLogCatalog.php: visibility rilis/link berdasarkan permission/module.
- config/change_log.php: ringkasan, steps, contoh dan related_links.
- resources/views/change-log/index.blade.php dan show.blade.php: dua tampilan yang terbukti di browser.
- resources/views/layouts/partials/change-log-indicator.blade.php: tombol navbar.
- app/Http/Controllers/BonusAyakController.php: izin Bonus dan titik masuk fitur.
- Modules/AssetManagement/Services/HeavyEquipmentTripService.php: rumus klaim/nilai akhir, bukan sumber kebenaran snapshot historis.
- resources/views/bonus_ayak/index.blade.php, edit.blade.php, kasbon_form.blade.php: fokus audit Taste tahap 02.

Katalog yang dibutuhkan tetap 12 topik 11A–11L: master, tarif, SPB manual, import SPB, Project invoice, Sell, Bonus, Kasbon, draft/batal settlement, posting, rekap, navigasi. Tahap 07 memetakan permission nyata setiap topik; jangan mewariskan asset.view ke semua.

## Bukti

Raw internal: storage/app/changelog-evidence/REV01-AUDIT-20260911/raw/change-log-overview.png, change-log-detail.png, detail-mobile.png. File overview.png pertama sebenarnya Home dengan tour dan **bukan overview Change Log**; tidak digunakan sebagai bukti overview atau aset publik.

## Re-verifikasi setelah implementasi

Pada `REV01-FINAL-20260911`, temuan baseline sudah ditangani: Change Log dan Panduan kini merender kartu artikel serta gambar, ikon `!` memiliki tautan terlihat, dan contoh pada halaman yang dirender menggunakan `21 × Rp115.000 = Rp2.415.000` lalu `+Rp150.000 = Rp2.565.000`. Screenshot baseline yang salah tetap tidak digunakan.

Anotasi internal dibuat dengan Python/Pillow:
`python Rencana/ChangeLog-Angkutan/Revisi-01/hasil/annotate_audit.py`.
Output: storage/app/changelog-evidence/REV01-AUDIT-20260911/annotations/detail-audit-annotated.png.
Raw tidak dipublikasikan; screenshot memuat identitas akun lokal dan memerlukan kurasi sebelum dipakai panduan pengguna.

## Inventaris PNG publik

Semua file berikut berada di public/images/change-log/release-2026-09-10-angkutan/. Untuk semua baris: artikel runtime/section/img src/caption **belum terhubung**, render artikel **tidak ada**, HTTP gambar **BELUM DIUJI**, keamanan/usang **perlu kurasi visual**. Topik hanya rencana pemetaan berdasarkan nama, bukan bukti isi gambar.

| File | Kandidat topik | Jenis berdasarkan nama |
| --- | --- | --- |
| 05A-master-tarif-desktop-annotated.png | Master/tarif | Annotated — belum kurasi |
| 05A-master-tarif-desktop.png | Master/tarif | Raw — jangan sematkan langsung |
| 05A-master-tarif-mobile-annotated.png | Master/tarif | Annotated — belum kurasi |
| 05A-master-tarif-mobile.png | Master/tarif | Raw — jangan sematkan langsung |
| 05B-spb-detail-desktop-lower.png | SPB manual | Raw — jangan sematkan langsung |
| 05B-spb-detail-desktop.png | SPB manual | Raw — jangan sematkan langsung |
| 05B-spb-detail-mobile.png | SPB manual | Raw — jangan sematkan langsung |
| 05B-spb-form-desktop-annotated.png | SPB manual | Annotated — belum kurasi |
| 05B-spb-form-filled-desktop.png | SPB manual | Raw — jangan sematkan langsung |
| 05C-import-preview-desktop-annotated.png | Import SPB | Annotated — belum kurasi |
| 05C-import-preview-duplicate-desktop.png | Import SPB | Raw — jangan sematkan langsung |
| 05C-import-preview-duplicate-mobile.png | Import SPB | Raw — jangan sematkan langsung |
| 05C-import-preview-mobile-annotated.png | Import SPB | Annotated — belum kurasi |
| 05D-project-invoice-chooser-desktop-annotated.png | Project invoice | Annotated — belum kurasi |
| 05D-project-invoice-chooser-desktop.png | Project invoice | Raw — jangan sematkan langsung |
| 05D-project-invoice-chooser-mobile-annotated.png | Project invoice | Annotated — belum kurasi |
| 05D-project-invoice-chooser-mobile.png | Project invoice | Raw — jangan sematkan langsung |
| 05D-project-trips-desktop-annotated.png | Project invoice | Annotated — belum kurasi |
| 05D-project-trips-desktop.png | Project invoice | Raw — jangan sematkan langsung |
| 05D-project-trips-mobile.png | Project invoice | Raw — jangan sematkan langsung |
| 05E-sell-form-desktop-annotated.png | Sell | Annotated — belum kurasi |
| 05E-sell-form-filled-desktop.png | Sell | Raw — jangan sematkan langsung |
| 05E-sell-form-filled-mobile.png | Sell | Raw — jangan sematkan langsung |
| 05E-sell-form-mobile-annotated.png | Sell | Annotated — belum kurasi |
| 05F-bonus-form-desktop-annotated.png | Bonus | Annotated — belum kurasi |
| 05F-bonus-form-filled-desktop.png | Bonus | Raw — jangan sematkan langsung |
| 05F-bonus-form-filled-mobile.png | Bonus | Raw — jangan sematkan langsung |
| 05F-bonus-form-mobile-annotated.png | Bonus | Annotated — belum kurasi |
| 05G-kasbon-form-desktop-annotated.png | Kasbon | Annotated — belum kurasi |
| 05H-overlimit-inline-error-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05H-settlement-cancelled-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05H-settlement-draft-list-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05H-settlement-review-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05H-spb-detail-created-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05H-spb-form-filled-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05H-zero-net-cancelled-annotated.png | Settlement draft | Annotated — belum kurasi |
| 05I-payroll-detail-annotated.png | Posting | Annotated — belum kurasi |
| 05I-settlement-posted-list-annotated.png | Posting | Annotated — belum kurasi |
| 05I-settlement-posted-row-annotated.png | Posting | Annotated — belum kurasi |
| 05I-spb-after-payroll-annotated.png | Posting | Annotated — belum kurasi |
| 05J-asset-ledger-with-manual-bonus-annotated.png | Rekap | Annotated — belum kurasi |
| 05J-project-trip-tab-with-bonus-contribution-annotated.png | Rekap | Annotated — belum kurasi |
| 05J-rekap-with-bonus-annotated.png | Rekap | Annotated — belum kurasi |
| 05K-change-log-detail-annotated.png | Navigasi | Annotated — belum kurasi |
| 05K-change-log-detail-mobile-annotated.png | Navigasi | Annotated — belum kurasi |
| 05K-change-log-overview-annotated.png | Navigasi | Annotated — belum kurasi |
| 05K-change-log-overview-mobile-annotated.png | Navigasi | Annotated — belum kurasi |

## Cleanup dan langkah berikutnya

Tidak membuat/mengedit/menghapus transaksi. Login, menutup tour dan membuka Change Log dapat mengubah preferensi tour/status baca normal akun lokal; status itu tidak direset. Session ditutup setelah bukti disimpan. Tidak menjalankan migrasi, deployment atau cache aplikasi pada audit ini.

Baca hasil ini lalu jalankan [02 — Arah desain Taste](../02-ARAH-DESAIN-TASTE.md). Tahap implementasi katalog/gambar 07–10 menggunakan temuan P1 di atas. Pemeriksaan snapshot angka, multi-role dan keamanan setiap PNG tetap pekerjaan dependennya, bukan kelulusan yang diasumsikan.
