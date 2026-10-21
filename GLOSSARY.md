# Glossary

Istilah domain untuk fitur penjualan dan import akuntansi.

| Istilah | Definisi |
|---|---|
| Transaksi sumber | Record bisnis—misalnya Sales, Purchase, Expense, payment, bank, atau stock transaction—yang dirujuk oleh tipe dan nomor dokumen pada workbook Journal. Import Journal dapat membuat record baru untuk tipe transaksi bisnis yang didukung, atau menautkan record yang sudah ada dan melengkapinya dengan informasi yang belum tercatat. Kecocokan ambigu atau konflik perlu ditinjau. |
| Entri Journal import | Satu grup baris akun GL untuk satu referensi sumber pada workbook, berisi tipe, nomor, tanggal, deskripsi, debit, dan kredit. Entri ini tetap dapat diimport sebagai GL meskipun transaksi sumber tidak ditemukan. |
| Pemetaan akun pajak | Pemetaan akun GL ke TaxRate atau TaxRate group yang sudah terdaftar, tersimpan dalam lingkup business. Hanya akun yang dipetakan secara eksplisit dan valid yang dapat memengaruhi tax transaksi; akun GL lain tetap menjadi baris GL biasa. |
| Tax inklusif Expense | Nilai `TOTAL AMOUNT` pada import Expense mencakup basis sebelum pajak dan pajak. Import mempertahankan total tersebut, menghitung basis/pajak dari TaxRate yang dipilih, dan tidak membuat TaxRate baru. |
| Rekonsiliasi tax item | Penghitungan ulang tax pada item Sales/Purchase yang sudah ada menggunakan TaxRate/group hasil pemetaan. Jumlah item dibandingkan dengan tax dari akun Journal; selisih ditandai untuk ditinjau dan tidak dialokasikan secara tebakan. |
| Relasi sumber Journal | Relasi antara Entri Journal import dan transaksi sumber yang cocok. Relasi hanya dibuat bila sumber ditemukan unik dalam business yang sama; kandidat hilang atau ambigu memerlukan peninjauan. |
