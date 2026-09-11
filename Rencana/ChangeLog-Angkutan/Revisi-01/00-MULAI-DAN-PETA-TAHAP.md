# Revisi 01 — Bonus Ayak dan panduan yang dapat ditemukan

## Tujuan dan status

Paket lanjutan ini dibuat atas tiga revisi pengguna: perbaiki UI Bonus Ayak dengan Taste Skill, perluas panduan setingkat tutorial terstruktur, dan pastikan gambar berpenanda serta detail bisa ditemukan dari Change Log dan Panduan. Paket instruksi ini tetap dapat dijalankan satu tahap per permintaan. Status eksekusi terbaru dicatat di folder `hasil/`; status awal di dokumen tahap tidak diubah agar sejarah instruksi tetap jelas.

Baca [petunjuk paket sebelumnya](../00-PETUNJUK-PELAKSANAAN.md) untuk aturan umum. Hasil lama adalah bahan awal, bukan bukti bahwa publikasi panduan sudah lengkap. [Remediasi 07](../07-REMEDIASI-BLOCKER-FINAL.md) hanya menjadi prasyarat bila hambatannya menyentuh tahap yang sedang dijalankan; tidak perlu mengulang semua tahap lama.

> Catatan status: paragraf di bawah adalah catatan baseline saat paket dibuat. Hasil eksekusi terbaru yang menggantikannya adalah bagian **Status handoff eksekusi 2026-09-11** di atas; jangan memperlakukan temuan baseline sebagai kondisi aplikasi saat ini.

## Temuan awal dari inspeksi sumber, bukan uji browser baru

Status pelaksanaan terbaru: tahap 01 selesai sebagai audit; lihat [hasil 01](hasil/01-HASIL.md). Kekurangan akses/gambar telah direproduksi melalui agent-browser dan belum diperbaiki. Tahap berikutnya adalah 02 — Arah desain Taste. Tahap lain tetap BELUM DIJALANKAN; pernyataan status awal di atas adalah kondisi saat penyusunan paket.

- `public/images/change-log/release-2026-09-10-angkutan/` memuat PNG annotated.
- `resources/views/change-log/show.blade.php` yang diperiksa menampilkan teks, tanpa renderer gambar. `config/change_log.php` memuat satu ringkasan rilis dan belum memetakan artikel per topik/gambar.
- `hasil/05F-HASIL.md` lama memuat penjelasan rinci; aksesnya dari aplikasi belum terhubung pada sumber yang diperiksa.
- Satu contoh angka pada katalog tidak konsisten. Tahap 01 wajib merekonsiliasi contoh sebelum publikasi.
- Keberadaan menu Panduan khusus belum terkonfirmasi; tahap 01 harus menemukannya atau mencatat kebutuhan route baru.
- Graphify mengarahkan penelusuran ke `app/Support/ChangeLogCatalog.php`, `app/Http/Controllers/ChangeLogController.php` dan `app/Http/Controllers/BonusAyakController.php`. Periksa ulang sumber saat eksekusi karena worktree dapat berubah.

## Status handoff eksekusi 2026-09-11

- Perbaikan UI Bonus Ayak, halaman Panduan, hubungan Change Log–Panduan, dan pipeline screenshot raster sudah diterapkan. Lihat [hasil 02](hasil/02-HASIL.md) sampai [hasil 10](hasil/10-HASIL.md).
- Dua pintu navigasi serta 12 artikel sudah diuji melalui `agent-browser` dengan session `revisi01-dialog`; matriks ada di [hasil 12](hasil/12-HASIL.md).
- Create/edit/delete Bonus Ayak dan Kasbon sudah diuji lewat UI dengan data lokal terpisah; ID dan cleanup ada di [hasil 04](hasil/04-HASIL.md) dan [hasil 05](hasil/05-HASIL.md).
- Gambar final diproses menggunakan Pillow, dipublikasikan sebagai PNG, dan dirender oleh halaman artikel. Screenshot Bonus Ayak diambil ulang setelah perbaikan padding/border, diberi nama aset baru untuk menghindari cache browser lama, lalu diverifikasi dari Change Log dan Panduan. Manifest/hash ada di [hasil 09](hasil/09-HASIL.md).
- PHPUnit belum dapat mulai karena PHP CLI tidak memiliki ekstensi `mbstring`. Import Bonus Ayak kini memiliki preview, pemilihan baris valid, fingerprint dan guard duplikasi yang sudah diuji melalui browser; keterbatasan yang tersisa pada tahap ini adalah download PDF yang diintersepsi IDM lokal, lihat [hasil 06](hasil/06-HASIL.md) dan [hasil 13](hasil/13-HASIL.md).

## Referensi dan pemakaiannya

[Taste Skill](https://www.tasteskill.dev/) menyediakan varian redesign untuk aplikasi yang sudah ada. Gunakan [redesign-existing-projects SKILL.md](https://raw.githubusercontent.com/Leonxlnx/taste-skill/main/skills/redesign-skill/SKILL.md) pada tahap 02: audit dahulu dan pertahankan stack. Catat versi sumber yang benar-benar dibaca. Tidak perlu memasang semua skill atau menjalankan installer saat menyusun rencana.

[Tutorial Kasm](https://docs.kasm.com/docs/tutorials/install/single-server-install) menjadi acuan struktur tutorial: navigasi, prasyarat, langkah, gambar, catatan dan pemecahan masalah. Tulis isi asli berbahasa Indonesia sesuai UI ERP. Tidak menyalin tutorial instalasi atau desain mereknya.

## Batas konteks dan cara menjalankan

Jalankan **satu file tahap per permintaan**, lalu berhenti. Contoh: “Jalankan Revisi-01/04-UI-FORM-BONUS.md saja, simpan hasil dan handoff.”

Pada awal sesi cukup baca file ini, file tahap aktif, dan bagian hasil prasyarat yang disebut. Jangan membaca seluruh paket/semua screenshot. Baca source relevan setelah Graphify; catat file dan simbol yang menjadi dasar keputusan. Jangan menebak selector, route, field, permission, angka atau hasil pengujian.

Jika tahap membesar, simpan checkpoint dan buat sub-tahap bernama spesifik sebelum melanjutkan cakupan tambahan. Prasyarat yang gagal memblokir hanya dependennya. Perbaikan kecil dalam lingkup boleh dilakukan dan wajib diuji ulang. Masalah di luar lingkup dibuat MD tersendiri berisi reproduksi, dampak dan kebutuhan input.

## Aturan eksekusi dan bukti

- Baca AGENTS.md, jaga perubahan pengguna. Aplikasi lokal dan sesi khusus agent-browser; jangan menyalin kredensial ke artikel.
- Semua journey pengguna memakai agent-browser: ketik, dropdown, checkbox, upload, klik, dialog, navigasi dan reload. SQL write, service/controller atau HTTP/API langsung tidak menggantikan UI. DB read-only/network/log/test otomatis boleh menjadi bukti tambahan.
- Perubahan dan cleanup data uji lokal sudah diizinkan. Tandai run-id, business, ID dan relasi; buat fixture lewat UI. Jika UI belum tersedia, catat gap dan tangani secara eksplisit.
- Uji create/detail, edit/reload, konfirmasi hapus, batal hapus, penolakan transaksi terkunci bila operasinya tersedia. Jangan membuka kunci posted untuk cleanup.
- Raw screenshot dari browser → proses Python/Pillow → PNG annotated → manifest artikel → aset publik → figure dalam artikel → verifikasi browser dari dua pintu masuk. SVG/HTML overlay tidak digunakan untuk anotasi.
- Nomor/kotak/panah harus menunjuk target pada gambar; legenda di luar screenshot saja tidak cukup. Jangan merekayasa data atau UI untuk gambar.
- Hanya data demo aman yang dipublikasikan. Redaksi dengan Python bila diperlukan tidak boleh mengubah angka yang dijadikan bukti; lebih baik ambil ulang dengan fixture aman.
- Setelah perubahan UI, gambar lama pada bagian berubah wajib diambil ulang. Periksa desktop 1440×900 dan ponsel 390×844; catat ukuran aktual.
- Dokumen hasil pengujian internal bukan halaman panduan pengguna. Artikel final wajib dirender oleh aplikasi dan terhubung dari kedua indeks.
- Setelah perubahan kode: lint/test relevan, cache Blade/route bila terkait, publish JS module bila diubah, Graphify update. Jangan menjalankan migrasi yang tidak diperlukan atau deployment otomatis.

## Kontrak hasil setiap tahap

Tulis `hasil/<ID>-HASIL.md` relatif terhadap folder Revisi-01 (buat saat eksekusi). Isi: status LULUS/GAGAL/BELUM DIUJI; cakupan; source/rute aktual; tindakan UI dan expected/actual; ID/run/business; hasil setelah reload; bukti raw/annotated/URL; perintah/exit code; file berubah; masalah; cleanup; handoff ringkas. Jangan menandai LULUS bila hanya file/screenshot tersedia atau halaman terbuka.

Handoff harus menyebut prasyarat yang lulus, ID yang boleh dipakai ulang, target berikutnya dan batasan. Maksimal baca handoff prasyarat yang terkait; jangan menggantungkan sesi baru pada riwayat chat.

## Urutan tahap

- [01-AUDIT-AKSES-DAN-GAMBAR — Audit jalur pembaca dan gambar](01-AUDIT-AKSES-DAN-GAMBAR.md)
- [02-ARAH-DESAIN-TASTE — Arah desain Bonus Ayak dengan Taste Skill](02-ARAH-DESAIN-TASTE.md)
- [03-UI-DAFTAR-BONUS — Perbaiki daftar, filter dan rekap Bonus Ayak](03-UI-DAFTAR-BONUS.md)
- [04-UI-FORM-BONUS — Perbaiki input dan edit Bonus Ayak](04-UI-FORM-BONUS.md)
- [05-UI-KASBON — Perbaiki halaman Kasbon](05-UI-KASBON.md)
- [06-UI-IMPORT-EKSPOR — Perbaiki dan uji import/export Bonus Ayak](06-UI-IMPORT-EKSPOR.md)
- [07-KATALOG-PANDUAN — Bangun katalog artikel dan route Panduan](07-KATALOG-PANDUAN.md)
- [08-TEMPLATE-ARTIKEL — Bangun halaman Panduan yang mendalam](08-TEMPLATE-ARTIKEL.md)
- [09-PIPELINE-GAMBAR — Hubungkan screenshot berpenanda ke artikel](09-PIPELINE-GAMBAR.md)
- [10-AKSES-DUA-PINTU — Perjelas akses dari Change Log dan Panduan](10-AKSES-DUA-PINTU.md)
- [11A — Master unit dan sopir](11A-PANDUAN-MASTER-UNIT-SOPIR.md)
- [11B — Tarif Angkutan](11B-PANDUAN-TARIF-ANGKUTAN.md)
- [11C — SPB manual dan verifikasi](11C-PANDUAN-SPB-MANUAL.md)
- [11D — Import SPB](11D-PANDUAN-IMPORT-SPB.md)
- [11E — SPB dan invoice Project](11E-PANDUAN-PROJECT-INVOICE.md)
- [11F — Sell dari SPB](11F-PANDUAN-SELL-ANGKUTAN.md)
- [11G — Bonus Ayak manual dan dari SPB](11G-PANDUAN-BONUS-AYAK.md)
- [11H — Kasbon sopir](11H-PANDUAN-KASBON.md)
- [11I — Draft dan pembatalan settlement](11I-PANDUAN-SETTLEMENT-DRAFT.md)
- [11J — Posting payroll sopir](11J-PANDUAN-POSTING-PAYROLL.md)
- [11K — Rekap unit, sopir dan ledger](11K-PANDUAN-REKAP-LEDGER.md)
- [11L — Mulai dari Change Log dan Panduan](11L-PANDUAN-MEMULAI-PANDUAN.md)
- [12 — Uji dua pintu dan gambar](12-UJI-DUA-PINTU-DAN-GAMBAR.md)
- [13 — Verifikasi dan handoff](13-VERIFIKASI-DAN-HANDOFF.md)

Tahap 11A–11L dapat dijalankan terpisah setelah 10 dan UI fitur terkait lulus; satu artikel per sesi. Tahap 12 memerlukan semuanya. Penyusunan rencana selesai setelah file dan tautan valid; pelaksanaan baru selesai jika akses, isi, angka dan gambar telah dibuktikan di aplikasi.
