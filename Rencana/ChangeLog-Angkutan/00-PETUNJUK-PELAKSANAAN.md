# 00 — Petunjuk pelaksanaan

## Rencana lanjutan revisi pengguna

Untuk perbaikan UI Bonus Ayak dengan Taste Skill, pendalaman artikel, serta akses gambar berpenanda dari Change Log dan Panduan, mulai dari [Revisi 01 — Peta tahap](Revisi-01/00-MULAI-DAN-PETA-TAHAP.md). Paket lanjutan dipecah per halaman dan per artikel. Status penyusunannya tidak berarti implementasi atau pengujiannya sudah dijalankan. Hasil historis paket ini tetap disimpan; klaim publikasi panduan harus dibuktikan ulang sesuai revisi.

Untuk integrasi Surat Kontrak dengan Project, SPB, billing, payroll, dan laporan, gunakan [Revisi 02 — Peta relasi kontrak](Revisi-02/00-MULAI-DAN-PETA-TAHAP.md). Revisi 02 menetapkan seluruh relasi dibangun sejak awal, tetapi kolom `status` kontrak tetap nullable agar migrasi dan pemakaian dapat dilakukan bertahap.

## Tujuan

Paket ini adalah instruksi pekerjaan bertahap untuk Change Log, panduan pengguna, dan pembuktian fitur angkutan. Paket inti dipecah menjadi 45 dokumen pelaksanaan kecil; tujuh dokumen utama menjadi petunjuk dan indeks. Dokumen [07 — Remediasi blocker final](07-REMEDIASI-BLOCKER-FINAL.md) adalah lampiran handoff opsional untuk blocker teknis yang ditemukan saat serah-terima. Penyusunan paket hanya mengubah dokumen. Tidak ada tahap di bawah yang otomatis dianggap sudah dijalankan.

## Urutan dan perintah

Baca petunjuk ini pada awal setiap sesi. Pilih satu bagian kecil dari indeks berikut, bukan seluruh kelompok:

1. [Audit fitur dan peta panduan](01-AUDIT-FITUR-DAN-PETA-PANDUAN.md).
2. [Uji journey dan perbaikan](02-UJI-JOURNEY-DAN-PERBAIKAN.md).
3. [Bangun halaman Change Log](03-BANGUN-HALAMAN-CHANGE-LOG.md).
4. [Tambahkan ikon navbar](04-TAMBAHKAN-IKON-NAVBAR.md).
5. [Tulis panduan dan screenshot](05-TULIS-PANDUAN-DAN-SCREENSHOT.md).
6. [Verifikasi akhir dan serah terima](06-VERIFIKASI-AKHIR-DAN-SERAH-TERIMA.md).

Jika hasil 06G/06H menyisakan blocker lingkungan atau bukti, jalankan [07 — Remediasi blocker final](07-REMEDIASI-BLOCKER-FINAL.md) pada sesi terpisah.

Contoh perintah: “Jalankan bagian 02E saja dari Rencana/ChangeLog-Angkutan, simpan hasil/02E-HASIL.md, lalu berhenti.”

## Prasyarat dan aturan bersama

- Baca AGENTS.md secara lengkap, konfirmasi root lokal, git status, dan konfigurasi lingkungan tanpa mencetak rahasia. Pertahankan perubahan pengguna.
- Gunakan Graphify untuk pertanyaan kode; periksa source aktual setelah query. Laporan sesi sebelumnya bukan bukti kelulusan.
- Root rujukan: D:/Laragon/www/2025/ultimateerp. URL browser: http://localhost/2025/ultimateerp/public/. Kredensial demo dibaca dari AGENTS.md dan tidak disalin ke panduan.
- Gunakan agent-browser dengan sesi khusus per pekerjaan. Baca panduan CLI yang terpasang sebelum menggunakannya. Jangan berinteraksi dengan situs produksi.
- Periksa queue dan worker sebelum import. Jangan mengirim email, notifikasi eksternal, atau pembayaran nyata dari data uji.
- Gunakan data demonstrasi bertanda CL-ANGKUTAN-<run-id>. Catat business, user penguji, ID objek, relasi, dan tindakan. Jangan mengubah transaksi operasional untuk screenshot.
- Persiapkan akun/fixture business kedua untuk uji isolasi bila belum tersedia, hanya pada lingkungan lokal. Hindari perubahan role akun operasional.
- Perbaiki masalah yang ditemukan dalam lingkup angkutan/integrasi dan uji ulang. Masalah di luar lingkup dicatat dengan dampak serta bukti.
- Jangan menjalankan reset worktree, rollback massal, atau menghapus data tanpa target yang tercatat. Data uji tersimpan tetap dicatat; pembersihan hanya data milik run ini dan mengikuti foreign key.
- Status hasil hanya LULUS, GAGAL, atau BELUM DIUJI. Membuka halaman tidak membuktikan penyimpanan, perhitungan, atau keamanan transaksi.
- Publikasi panduan hanya untuk perilaku yang terbukti. Tanggal rilis tidak ditebak dari tanggal file atau tanggal pengujian.
- Aset publik tidak boleh berisi kredensial, data pribadi, rekening, atau informasi pelanggan operasional.
- JavaScript module diedit pada sumber Modules/<Module>/Resources/assets/js/, kemudian dipublish dengan php scripts/publish-module-js.php <Module> [file.js]. Jangan edit salinan runtime secara manual.
- Setelah perubahan kode, jalankan lint/test relevan dan graphify update .; jika CLI tidak tersedia, gunakan interpreter dari graphify-out/.graphify_python. Penulisan paket instruksi ini sendiri tidak memerlukan pembaruan graph kode.

## Aturan wajib pengujian melalui browser

- Pembaruan paket ini hanya mengubah dokumen; jangan menjalankan browser, migrasi, atau pengujian aplikasi sekarang. Jalankan pengujian hanya ketika tahapnya diminta.
- Perubahan data uji lokal sudah diizinkan: membuat, mengedit, menghapus, mengimpor, memverifikasi, membuat invoice, dan memposting Payroll. Tidak perlu meminta izin ulang untuk perubahan atau pembersihan data uji lokal dalam lingkup ini. Izin ini tidak mencakup produksi, transaksi operasional, atau pembayaran nyata.
- Semua journey utama wajib menggunakan agent-browser seolah pengguna bekerja langsung: mengetik field, memilih dropdown/tanggal, mencentang, upload file, menekan tombol, mengonfirmasi atau membatalkan dialog, dan mengikuti navigasi aplikasi.
- Data uji, termasuk master dan akun/business pembanding yang diperlukan, dibuat melalui UI yang tersedia. Jika tidak ada jalur UI, catat sebagai gap untuk tahap perbaikan; jangan diam-diam membuatnya lewat database atau backend. Jika agent-browser tidak tersedia, catat BELUM DIUJI dan kendalanya; jangan menggantinya dengan tes backend.
- Dilarang mengganti journey dengan SQL write, pemanggilan service/controller, HTTP langsung, atau JavaScript penguji yang memanggil API atau melewati validasi UI. Larangan ini tidak melarang JavaScript normal aplikasi yang berjalan akibat interaksi pengguna.
- Pemeriksaan database read-only, network, log, dan test otomatis hanya bukti tambahan. Request keamanan yang dimodifikasi dilaporkan terpisah, bukan pengganti journey UI. Duplikasi dan interaksi bersamaan wajib dicoba melalui dua tab atau sesi browser.
- Untuk setiap fitur yang menyediakan operasi terkait, jalankan siklus buat/simpan/daftar/detail, edit/simpan/reload, hapus/konfirmasi, batal dialog hapus, serta penolakan edit/hapus transaksi terkunci sesuai checklist CRUD di bawah. Operasi yang tidak tersedia dicatat beserta alasan/gap, bukan dinyatakan lulus.
- Screenshot wajib berasal dari keadaan yang benar-benar dicapai melalui browser, bukan manipulasi DOM, database, atau API untuk menata hasil.

## Standar default screenshot dan anotasi panduan

- Ambil screenshot raw setelah keadaan UI benar-benar dicapai melalui `agent-browser`. Ukuran yang digunakan untuk panduan adalah desktop `1440×900` bila halaman mendukungnya dan ponsel `390×844`; bila ukuran hasil berbeda, catat ukuran aktualnya.
- Simpan raw PNG untuk bukti internal di `storage/app/changelog-evidence/<run-id>/raw/`. Raw tidak boleh dipotong, direka ulang, atau dipakai untuk menyamarkan data sensitif.
- Semua penanda panduan wajib dibuat dengan [scripts/annotate_changelog_screenshots.py](../../scripts/annotate_changelog_screenshots.py) atau utilitas Python setara yang membaca raw PNG dan menulis PNG baru. Penanda dapat berupa nomor langkah, kotak sorotan, panah, dan caption singkat.
- Gambar yang disematkan ke panduan dan disalin ke `public/images/change-log/<release-id>/` harus berupa PNG hasil proses Python. Jangan membuat atau memakai SVG, overlay HTML, lapisan DOM, atau renderer browser sebagai hasil anotasi final.
- Untuk screenshot baru, tambahkan preset koordinat yang dapat diaudit atau file spesifikasi JSON pada utilitas Python. Jalankan ulang dari raw yang sama untuk mereproduksi gambar; jangan menggambar manual pada hasil akhir.
- Caption, nomor, dan kotak tidak boleh menutup field atau nilai penting. Jika ruang layar sempit, pindahkan caption ke ruang kosong/tepi layar dan tetap jelaskan maknanya dalam teks panduan.
- Setiap gambar wajib memiliki alt text dan penjelasan teks yang setara di Markdown/Change Log. Metadata PNG boleh memuat judul/deskripsi, tetapi metadata tidak menggantikan alt text.
- Bukti harus mencatat nama raw, nama PNG hasil anotasi, perintah Python, ukuran gambar, serta pemeriksaan visual. File SVG/HTML lama yang tersisa dinyatakan superseded dan tidak boleh ditautkan atau dipublikasikan.

## Pembersihan data uji

Setelah bukti dan screenshot selesai, bersihkan hanya ID milik run-id yang tercatat, mulai dari transaksi turunan, melalui tombol edit, hapus, batal, atau koreksi yang disediakan aplikasi. Periksa hasil setelah reload pada daftar, detail terkait, dan rekap. Jangan menggunakan SQL write atau membuka kunci transaksi posted untuk cleanup.

Data yang diperlukan tahap berikutnya boleh dipertahankan dengan handoff ID, business, relasi, alasan, dan tahap pembersihannya. Transaksi posted yang tidak dapat dihapus tetap dicatat beserta alasan, status, dan dependensinya. Jangan memaksakan penghapusan master yang masih dipakai. Tidak boleh ada penghapusan luas atau data sisa yang tidak dilaporkan.

## Hasil dan format handoff

Saat suatu tahap benar-benar dijalankan, buat hasil/<ID>-HASIL.md di folder paket ini. Folder hasil tidak dibuat sebagai bukti palsu saat paket instruksi ditulis. Screenshot mentah dan bukti teknis disimpan di storage/app/changelog-evidence/<run-id>/, bukan direktori publik.

Setiap laporan tahap wajib berisi:

- Nomor tahap, waktu, status, run-id, lingkungan, dan ringkasan perilaku yang dibuktikan.
- Prasyarat yang dibaca serta temuan tahap sebelumnya.
- File yang berubah dan alasan; keputusan implementasi yang perlu diketahui penerus.
- Tabel skenario: ID, peran/business, tindakan pengguna melalui browser, ID objek, hasil sebelum/sesudah, hasil diharapkan, hasil aktual, hasil setelah reload, status, lokasi screenshot, dan masalah yang ditemukan. Pisahkan bukti tambahan backend dari bukti journey UI.
- Daftar data uji/ID dan apakah dipertahankan atau dibersihkan.
- Perintah pemeriksaan, exit code, kegagalan, penyebab, dan hasil tes ulang.
- Masalah terbuka, dampaknya, dan tahap yang harus menangani.
- Instruksi handoff konkret. Berhenti; jangan mengeksekusi tahap berikutnya.

## Kriteria lulus

Paket lulus jika tujuh petunjuk/indeks dan 45 bagian tersedia, semua tautan/dependensi valid, serta setiap bagian dapat dijalankan tanpa riwayat chat. Pembaruan instruksi lulus hanya jika semua tahap konsisten mewajibkan agent-browser dan tidak mengizinkan backend menggantikan tindakan pengguna. Tahap implementasi lulus hanya setelah hasil wajib tersedia. Jika prasyarat gagal dan menghalangi hasil, catat GAGAL; jangan menerbitkan panduan seolah fitur sudah bekerja.

## Tahap selanjutnya

Mulai dari [01](01-AUDIT-FITUR-DAN-PETA-PANDUAN.md) hanya ketika diminta pengguna.

## Batas konteks dan satu bagian per permintaan

- Baca AGENTS.md, dokumen 00 ini, satu dokumen bagian aktif, dan hanya ringkasan hasil prasyarat langsung. Jangan membaca seluruh paket atau mengikuti dependensi secara rekursif.
- Indeks 01–06 bukan perintah eksekusi massal. Jika pengguna hanya menyebut indeks, minta pilihan satu bagian; jangan menjalankan semua bagian.
- Hasil prasyarat belum ada/gagal: catat blocker dan berhenti. Jangan membuat fixture atau hasil dependensi diam-diam untuk melanjutkan.
- Graphify/source hanya ditelusuri sesuai pertanyaan bagian aktif. Rencana bukan bukti route, schema, selector atau fitur tersedia; verifikasi aktual, jangan mengarang.
- Perbaikan saat tes dibatasi penyebab langsung skenario bagian aktif. Jika melebar lintas fitur atau butuh keputusan bisnis, simpan reproduksi, dampak, file terkait dan usulan bagian perbaikan terpisah; minta arah pengguna.
- Jangan menjalankan bagian berikut otomatis, termasuk perbaikan milik bagian lain. Jika konteks menipis, simpan checkpoint langkah terakhir, ID/bukti/file berubah dan satu tindakan berikut, lalu berhenti tanpa klaim selesai.
- Setiap hasil bagian diawali ringkasan handoff sekitar 30 baris maksimum. Bukti rinci tetap di bawah atau file bukti internal; penerus hanya membaca bukti relevan.
- Laporan lama hasil/01-HASIL.md sampai hasil/06-HASIL.md bila ada tetap dipertahankan. Ambil bukti relevan ke bagian baru dan cek kesesuaiannya; tidak otomatis dianggap lulus.
- Penulisan satu artikel hanya membaca hasil tes fitur terkait dan kontrak halaman. Jangan menulis semua panduan dalam satu sesi.
- Periksa visual tiap halaman yang diuji pada desktop 1440×900 dan ponsel 390×844: label, tombol, loading/error, tabel, navigasi kembali, keyboard/fokus.
- Katalog draft internal boleh dibangun sebelum semua tes selesai, tetapi artikel publik hanya untuk perilaku terbukti dan tanggal terbit yang sah.

## Checklist CRUD bagian aktif

Terapkan hanya operasi yang tersedia pada fitur dalam lingkup bagian:

1. Buat → simpan → muncul di daftar → buka detail dan cocokkan field/nominal.
2. Edit → simpan → reload → perubahan bertahan dan perhitungan/rekap diperbarui.
3. Hapus fixture yang boleh dihapus → konfirmasi → reload daftar/rekap → hilang tanpa relasi rusak.
4. Buka dialog hapus fixture lain → batalkan → reload → data tetap ada.
5. Coba edit/hapus transaksi terkunci → ditolak atau tombol tidak tersedia → reload memastikan tidak berubah; pengujian endpoint tambahan dicatat terpisah.
6. Verifikasi/invoice/settlement/posting memakai tombol hanya saat termasuk lingkup bagian.

Operasi tidak tersedia dicatat beserta alasan/gap, bukan LULUS palsu.
