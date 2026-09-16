# 05 — UI SPB dan snapshot kontrak

## Tujuan

Menghubungkan kontrak sebagai dasar pekerjaan pada SPB tanpa membuat kontrak menjadi jurnal perjalanan. User tetap dapat membuat SPB lama tanpa kontrak selama kebijakan migrasi mengizinkannya.

## Prasyarat

- Tahap 04 lulus.
- Tersedia Project, customer, route, asset, driver, dan tarif lokal yang dibuat/ditetapkan melalui UI.
- Tersedia kontrak active, kontrak null status, kontrak expired, dan kontrak business lain untuk uji selector.

## Langkah kerja

1. Tambahkan field `Kontrak` pada form SPB, nullable, dengan pilihan yang difilter oleh business, Project, customer, dan tanggal perjalanan.
2. Jika SPB dibuat dari Project, isi Project/customer dan kontrak yang tersedia sebagai konteks; user tetap melihat apa yang otomatis terisi.
3. Jika kontrak dipilih, tampilkan ringkasan periode, scope rute, harga, dan status. Jangan menampilkan sopir/unit sebagai atribut kontrak.
4. Ketika disimpan, salin nilai yang diperlukan ke snapshot SPB: kontrak yang dipakai, tarif jual, tarif sopir, uang jalan, harga klaim, dan keterangan. Perubahan master/kontrak setelah SPB verified tidak boleh mengubah snapshot.
5. Validasi mismatch Project/customer/route, kontrak expired/cancelled, tarif tidak lengkap, tonase, nomor tiket, dan duplikasi import.
6. Pada detail SPB tampilkan kontrak, Project, customer, rute, unit, sopir, tonase, pendapatan pelanggan, upah sopir, dan status billing/payroll.
7. Uji SPB manual dan import melalui UI. Import harus memetakan kontrak bila ada kolom sumber; bila tidak ada, hasilnya null/unmatched dan dapat ditautkan melalui UI sesuai kebijakan.
8. Uji edit draft, edit verified, billed, settled, kontrak null, dan perubahan tarif. Jangan membuka kunci transaksi posted.
9. Pastikan tombol kembali, batal, error, responsif, tooltip, focus ring, dan DataTables tidak membingungkan.

## Hasil wajib

- Form/detail/chooser SPB dengan relasi kontrak.
- Snapshot dan guard histori yang terbukti.
- `hasil/05-HASIL.md` berisi ID kontrak, Project, SPB, tarif sebelum/sesudah, tindakan browser, dan screenshot Pillow.

## Kriteria lulus

- Satu kontrak dapat terkait ke banyak SPB.
- SPB tanpa kontrak tetap dapat ditangani pada masa migrasi jika diizinkan.
- SPB verified menyimpan snapshot dan tidak berubah ketika master kontrak/tarif berubah.
- Kontrak lintas business atau tidak valid tidak dapat dipilih.

## Catatan tahap berikutnya

Tahap 06 menggunakan SPB sebagai sumber billing dan payroll. Jangan membuat line invoice dari total kontrak global.

