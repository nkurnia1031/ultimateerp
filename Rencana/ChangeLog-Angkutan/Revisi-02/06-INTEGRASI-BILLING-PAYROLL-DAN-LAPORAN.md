# 06 — Integrasi billing, payroll, Bonus Ayak, dan laporan

## Tujuan

Menyajikan jejak lengkap kontrak tanpa mencampurkan pendapatan pelanggan dengan biaya sopir dan tanpa menggandakan sumber pembayaran.

## Prasyarat

- Tahap 05 lulus.
- Tersedia SPB verified dengan dan tanpa kontrak, Bonus Ayak, Kasbon, invoice Project, Sell, settlement draft, serta payroll lokal yang aman.
- Baca service billing/settlement aktual sebelum mengubahnya.

## Langkah kerja

1. Pada invoice Project dan Sell, tampilkan kontrak sebagai informasi sumber setiap SPB. Pemilihan tetap berdasarkan SPB verified yang belum billed.
2. Pastikan satu SPB hanya dapat masuk satu billing record. Bila invoice menggabungkan beberapa kontrak, detail dan laporan harus menampilkan semua kontrak secara distinct.
3. Rekonsiliasi `billable_tons × snapshot sale rate`, adjustment klaim, potongan lain, dan total invoice. Jika total tidak sama, gunakan adjustment line/metadata yang eksplisit; jangan menyembunyikan selisih pada header.
4. Pada settlement payroll, pilih SPB, Bonus Ayak, dan Kasbon seperti alur existing. Kontrak hanya menjadi referensi audit; Bonus Ayak bukan line invoice pelanggan.
5. Tampilkan pada settlement/payroll kontrak asal SPB tanpa membuat satu kontrak menjadi satu payroll. Satu payroll dapat mencakup SPB dari beberapa kontrak bila business dan sopir sesuai.
6. Pada Project dan laporan kontrak tampilkan terpisah: revenue pelanggan, upah SPB, Bonus Ayak, Kasbon, biaya tambahan, dan kontribusi.
7. Pada asset ledger tampilkan kontrak sebagai kolom/filter turunan dari SPB; pada driver/payroll tampilkan kontrak sumber tanpa mengubah identitas driver.
8. Uji status invoice, settlement draft/cancelled/posted, duplicate submit dua tab, dan transaksi locked. Cleanup hanya melalui UI dan tidak membuka kunci posted.
9. Pastikan payment/customer ledger tetap menggunakan transaction existing dan business scope.

## Hasil wajib

- Perubahan service/query/view/test yang diperlukan.
- Peta rekonsiliasi kontrak → SPB → billing/payroll/report.
- `hasil/06-HASIL.md` dengan angka expected/actual, ID transaksi, status sebelum/sesudah, bukti browser, dan query read-only tambahan.

## Kriteria lulus

- Pendapatan pelanggan tidak bercampur dengan upah/Bonus/Kasbon.
- Tidak ada SPB, Bonus Ayak, atau Kasbon yang dibayar/tagih dua kali.
- Invoice multi-kontrak dan payroll multi-kontrak tetap dapat diaudit.
- Perubahan kontrak tidak mengubah transaksi historis.

## Catatan tahap berikutnya

Tahap 07 menangani data lama dan pengisian relasi secara bertahap. Jangan membuat backfill massal tanpa preview dan persetujuan per batch.

