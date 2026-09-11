# Hasil 01F — Audit Change Log dan Ikon Navbar

## Status

**LULUS untuk audit kode read-only.** Halaman Change Log dan ikon `!` belum diimplementasikan, sehingga browser journey belum dapat dijalankan pada bagian ini.

## Temuan utama

1. Belum ditemukan route, controller, view, model konten, atau JavaScript untuk `/change-log` maupun `/change-log/{slug}`.
2. Navbar aplikasi utama sudah memiliki partial notifikasi yang dipanggil dari header. Partial ini mengambil notifikasi Laravel user dan menampilkan ikon lonceng serta jumlah unread.
3. Belum ada partial Change Log yang reusable, pulse, status baca per user/business/rilis, endpoint POST CSRF, tooltip, label khusus, atau dukungan `prefers-reduced-motion`.
4. Kode polling notifikasi lama memakai endpoint `/get-total-unread` dan tidak boleh dicampur dengan status baca Change Log.

## Bukti implementasi saat ini

| Area | Temuan | Bukti | Dampak |
|---|---|---|---|
| Include navbar | Header memanggil partial notifikasi | [`header.blade.php`](../../../resources/views/layouts/partials/header.blade.php:192) | Titik integrasi paling aman adalah partial/header utama |
| Notifikasi lama | Dropdown memakai `load_notifications`, `notifications_count`, dan endpoint lama | [`header-notifications.blade.php`](../../../resources/views/layouts/partials/header-notifications.blade.php:1), [`web.php`](../../../routes/web.php:621) | Jangan mengubah jumlah atau AJAX notifikasi |
| Change Log route | Tidak ada hasil pencarian route/controller/view | Pemeriksaan `app`, `routes`, `resources/views`, `Modules` | Tahap `03A` perlu membuat route login-protected |
| Konten | Tidak ada CMS atau artikel terkurasi Change Log | Pemeriksaan source read-only | Gunakan data konten statis bersama kode sesuai rencana |
| Status baca | Tidak ada tabel/endpoint status rilis | Pemeriksaan source read-only | Tahap `03C`/`04B` perlu migration + POST CSRF |
| Akses | Tidak ada permission Change Log khusus | Pemeriksaan source read-only | Artikel harus aman untuk user login; link fitur tetap memeriksa permission target |

## Rekomendasi UI/UX untuk tahap pembangunan

- Tempatkan ikon `!` langsung setelah partial notifikasi agar urutan dan kedekatan visual mudah dipahami.
- Ikon tetap terlihat walaupun semua rilis sudah dibaca; yang berhenti hanya pulse.
- Pulse harus menjadi indikator lembut, bukan mengambil alih dropdown notifikasi.
- Klik harus membuka overview Change Log. GET overview tidak mengubah status baca; endpoint POST terpisah dipanggil setelah halaman berhasil dibuka.
- Isi overview memakai bahasa sederhana: “Apa yang baru?”, “Manfaatnya”, dan tombol “Lihat penjelasan dan cara pakai”.
- Detail artikel wajib memisahkan pendapatan/tagihan pelanggan dari biaya/upah sopir. Bonus Ayak harus dijelaskan sebagai komponen payroll/cost dan bukan baris invoice.
- Link tindakan dari artikel boleh mengarah ke menu SPB, Project, Sell, Bonus Ayak, Settlement, dan Rekap, tetapi endpoint tujuan tetap menjadi penjaga permission.
- Detail rilis yang tidak ada harus 404, bukan menampilkan artikel lain.

## Artikel awal yang perlu disiapkan

Daftar ini adalah daftar konten untuk `03B` dan `05`, bukan pernyataan bahwa fitur sudah selesai:

1. Master tarif angkutan: menyimpan rute, unit, tarif, tanggal berlaku, dan histori.
2. SPB Angkutan: membuat satu perjalanan dengan unit, sopir, rute, tonase, dan verifikasi.
3. SPB dari Project dan tagihan pelanggan: memilih SPB verified yang belum ditagihkan.
4. SPB melalui Sell: membuat transaksi jasa angkutan.
5. Bonus Ayak dan Kasbon: mengaitkan bonus ke beberapa SPB serta menyiapkan potongan.
6. Settlement upah sopir: menggabungkan upah SPB + Bonus Ayak − Kasbon dan memposting Payroll.
7. Rekap dan ledger: membaca tonase, pendapatan, biaya sopir, kontribusi, status invoice, dan status payroll.

Artikel tidak boleh memuat data pelanggan/sopir nyata atau menjanjikan alur yang belum lulus pada hasil `02*`.

## Handoff

- `03A` bangun route/controller overview dan detail dengan login serta 404 slug.
- `03B` susun katalog artikel terkurasi dan tautan fitur.
- `03C` buat status baca server-side per user/business/release.
- `04A` pasang partial ikon setelah notifikasi.
- `04B` implementasikan pulse/status baca dan fallback gagal POST.
- `04C` uji akses keyboard, mobile, reduced motion, pergantian business, dan interaksi dengan notifikasi lama.
- `05` hanya menulis panduan final dari hasil browser yang sudah lulus.

Audit tahap `01A` sampai `01F` telah memiliki hasil terpisah. Mulai sekarang, tahap `02A` boleh dijalankan melalui `agent-browser` menggunakan data lokal bertanda run-id.
