# Glossary

Istilah domain untuk fitur penjualan dan import akuntansi.

| Istilah | Definisi |
|---|---|
| Transaksi sumber | Record bisnis—misalnya Sales, Purchase, Expense, payment, bank, atau stock transaction—yang dirujuk oleh tipe dan nomor dokumen pada workbook Journal. Import Journal dapat membuat record baru untuk tipe transaksi bisnis yang didukung, atau menautkan record yang sudah ada dan melengkapinya dengan informasi yang belum tercatat. Kecocokan ambigu atau konflik perlu ditinjau. |
| Entri Journal import | Satu grup baris akun GL untuk satu referensi sumber pada workbook, berisi tipe, nomor, tanggal, deskripsi, debit, dan kredit. Entri ini tetap dapat diimport sebagai GL meskipun transaksi sumber tidak ditemukan. |
| Klasifikasi akun pajak | Pengenalan komponen pajak dari nama akun GL dan sisi debit/kreditnya. VAT In adalah pajak masukan di sisi debit; VAT Out adalah pajak keluaran di sisi kredit. Nama akun tidak menetapkan TaxRate atau grup pajak tetap. |
| Tarif pajak transaksi | Tarif efektif yang dihitung untuk transaksi sumber dari nominal komponen pajak dibagi basis kena pajak transaksi. Tarif hasil hitung dicocokkan dengan TaxRate atau grup pajak yang tersedia untuk business tersebut. |
| Tax inklusif Expense | Nilai `TOTAL AMOUNT` pada import Expense mencakup basis sebelum pajak dan pajak. Basis berasal dari rincian biaya Expense atau total sebelum pajak; pajak tetap tercatat sebagai komponen terpisah. |
| Rekonsiliasi tax item | Penghitungan ulang tax pada item Sales/Purchase menggunakan basis item dan nominal pajak transaksi, lalu dicocokkan dengan TaxRate atau grup yang sesuai. Jumlah item dibandingkan dengan tax dari akun Journal; selisih ditandai untuk ditinjau dan tidak dialokasikan secara tebakan. |
| Relasi sumber Journal | Relasi antara Entri Journal import dan transaksi sumber yang cocok. Relasi hanya dibuat bila sumber ditemukan unik dalam business yang sama; kandidat hilang atau ambigu memerlukan peninjauan. |
