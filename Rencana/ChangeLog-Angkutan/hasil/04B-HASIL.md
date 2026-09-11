# Hasil 04B — Ikon Navbar dan Alur Menandai Baca

## Status

**LULUS untuk alur normal user unread → klik ikon → overview → status tersimpan → pulse berhenti.** Ikon tetap tampil setelah dibaca, reload tidak mengirim POST kedua, dan dropdown notifikasi lama tetap berfungsi. Variasi reduced-motion, rilis baru, pergantian business, layar ponsel, dan kegagalan penyimpanan menjadi scope 04C.

## Perubahan yang dibuat

- Partial baru [change-log-indicator.blade.php](../../../resources/views/layouts/partials/change-log-indicator.blade.php) ditempatkan tepat setelah partial notifikasi pada header utama.
- Ikon menggunakan karakter `!`, tooltip, `aria-label`, focus outline, dan target klik `44 × 44`.
- Pulse menggunakan pseudo-element lingkaran di luar tombol agar tidak menggeser layout atau menangkap pointer.
- CSS menghormati `prefers-reduced-motion`: animasi berhenti dan ring tetap menjadi indikator statis.
- Partial membaca rilis published/eligible dan status dari kombinasi user aktif + business aktif + release ID.
- `public/js/app.js` menunggu marker overview di DOM, lalu mengirim POST valid dengan CSRF. Pulse hanya dihapus jika response sukses; saat gagal, pulse tetap ada untuk percobaan berikutnya.
- `resources/views/change-log/index.blade.php` memiliki marker `data-change-log-overview="true"` yang hanya ada pada overview.
- Notifikasi lama tidak diubah. Partial `header-notifications` dan handler `load_notifications` tetap digunakan.

## Fixture dan scope

- User admin sesi `changelog02i`, user ID `5`, business `CV BM TRANSPORT` (`business_id=2`).
- Release: `release-2026-09-10-angkutan`.
- Sebelum alur normal, tidak ada row read untuk kombinasi user 5/business 2/release tersebut.
- Row status baca yang dihasilkan sengaja dipertahankan sebagai fixture untuk uji “sudah membaca”; tidak ada transaksi operasional yang dibuat.

## Journey browser normal

### 1. Unread pada navbar

Tindakan user:

1. Membuka `/home` melalui sesi admin.
2. Memastikan ikon `!` berada di sebelah tombol notifikasi.
3. Mengambil screenshot sebelum menekan ikon.
4. Memeriksa class dan data state melalui pemeriksaan DOM tambahan.

Expected:

- Ikon visible dan pulse menarik perhatian.
- Pulse tidak memindahkan posisi tombol lain.
- Notifikasi lama masih ada di sebelahnya.

Actual:

- Accessibility snapshot menampilkan link `Perubahan terbaru aplikasi`.
- Class link mengandung `is-unread` dan `data-unread=1`.
- Screenshot memperlihatkan ring pulse di sekitar ikon `!` tanpa menutupi tombol notifikasi.

Bukti: [navbar unread dengan pulse](../../../hasil/screenshots/04B-navbar-unread-before-click-fixed.png).

### 2. Klik ikon menuju overview dan menandai baca

Tindakan user:

1. Menekan ikon `!` dengan klik browser.
2. Memastikan URL berubah ke `/change-log`.
3. Menunggu overview selesai dirender.
4. Memeriksa kartu rilis dan state ikon.

Expected:

- User tiba di overview Change Log.
- Browser mengirim satu POST CSRF ke endpoint baca setelah overview tersedia.
- Pulse berhenti hanya setelah response berhasil.
- Ikon tetap tampil.

Actual:

- URL menjadi `http://localhost/2025/ultimateerp/public/change-log`.
- Overview menampilkan kartu `Perjalanan angkutan, SPB, dan rekap upah sopir`.
- Network tambahan menunjukkan:

```text
POST /change-log/read (Fetch) 200
```

- Class `is-unread` hilang dan `data-unread=0`.
- Link `Perubahan terbaru aplikasi` tetap ada.

### 3. Reload setelah dibaca

Tindakan user:

1. Reload overview melalui browser.
2. Memeriksa kembali state ikon.
3. Mengambil screenshot final.

Expected:

- Rilis tetap tampil.
- Pulse tidak kembali untuk rilis yang sama.
- Tidak ada POST baru karena status sudah dibaca.

Actual:

- Rilis tetap tampil.
- Class tidak mengandung `is-unread` dan `data-unread=0`.
- Setelah request log dibersihkan, reload tidak menghasilkan `POST /change-log/read` baru.

Bukti: [overview setelah baca dan reload](../../../hasil/screenshots/04B-overview-after-read-reload.png).

Pemeriksaan database read-only menghasilkan satu row:

```text
user_id=5, business_id=2,
release_id=release-2026-09-10-angkutan,
read_at=2026-09-10T16:46:18.000000Z
```

### 4. User yang sudah membaca menekan ikon lagi

Tindakan user:

1. Kembali ke Home.
2. Memastikan ikon tetap tampil tanpa pulse.
3. Membersihkan network log.
4. Menekan ikon dan menunggu overview terbuka.

Actual:

- Ikon tetap ada dengan `data-unread=0`.
- Overview terbuka normal.
- Network log menampilkan `No requests captured` untuk endpoint `change-log/read` setelah log dibersihkan.

### 5. Notifikasi lama

Tindakan user:

1. Dari Home menekan tombol notifikasi lama menggunakan selector UI `#show_unread_notifications`.
2. Menunggu daftar notifikasi dimuat.

Actual:

- Request lama `GET /load-more-notifications?page=1` berstatus 200.
- Dropdown menampilkan notifikasi yang sudah ada.
- Screenshot menunjukkan ikon `!` tetap terpisah dari tombol notifikasi dan tidak mengubah dropdown.

Bukti: [dropdown notifikasi lama](../../../hasil/screenshots/04B-notification-dropdown-unchanged.png).

## Perbaikan saat pengujian

Uji visual pertama menemukan `data-unread=1` tetapi class `is-unread` belum dipasang, sehingga pulse tidak terlihat. Partial diperbaiki untuk menambahkan class tersebut secara kondisional. Setelah view cache dibangun ulang, screenshot kedua menunjukkan ring pulse dan alur normal lulus.

## Validasi teknis

- `node --check public/js/app.js` — lulus.
- `php artisan view:cache --no-interaction` — lulus setelah perubahan partial.
- `php artisan route:cache --no-interaction` — lulus pada tahap 04A dan route tidak diubah setelahnya.
- Migration `change_log_reads` sudah dijalankan pada tahap 04A.
- Publish JavaScript module tidak diperlukan karena file yang berubah adalah `public/js/app.js`, yaitu asset aplikasi-wide dengan source canonical di lokasi itu.

## Batas pengujian dan handoff

- Belum menguji `prefers-reduced-motion` melalui variasi browser.
- Belum menguji rilis baru yang mengaktifkan pulse kembali, pergantian business, mobile navbar, atau kegagalan response POST. Jalur tersebut wajib diselesaikan pada 04C.
- Uji CSRF 419 dan release ID invalid 422 sudah dicatat di [hasil 04A](04A-HASIL.md); bukan bagian dari click journey normal ini.
- Row read admin/business 2 dipertahankan untuk skenario user sudah membaca. Jangan menghapusnya sebelum 04C selesai.

## Tahap berikutnya

Jalankan [04C — Uji variasi navbar dan kegagalan](../04C-UJI-VARIASI-NAVBAR-DAN-KEGAGALAN.md) secara terpisah. Tahap tersebut harus menguji reduced-motion, rilis baru, business lain, ponsel, dan POST gagal tanpa memalsukan status baca.
