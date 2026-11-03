# Glossary

Istilah domain untuk fitur penjualan dan import akuntansi.

| Istilah | Definisi |
|---|---|
| Transaksi sumber | Record bisnis—misalnya Sales, Purchase, Expense, payment, bank, atau stock transaction—yang dirujuk oleh tipe dan nomor dokumen pada workbook Journal. Import legacy menautkan atau melengkapi record yang cocok secara unik, dan membuat record baru hanya bila detail wajib tersedia. Jika relasi operasional ambigu atau tidak lengkap, posting Journal tetap masuk ke GL dan relasi yang belum terselesaikan dicatat di crosswalk/audit tanpa antrean review per baris. |
| Entri Journal import | Satu grup baris akun GL untuk satu referensi sumber pada workbook, berisi tipe, nomor, tanggal, deskripsi, debit, dan kredit. Entri ini tetap dapat diimport sebagai GL meskipun transaksi sumber tidak ditemukan. |
| Klasifikasi akun pajak | Pengenalan komponen pajak dari nama akun GL dan sisi debit/kreditnya. VAT In adalah pajak masukan di sisi debit; VAT Out adalah pajak keluaran di sisi kredit. Nama akun tidak menetapkan TaxRate atau grup pajak tetap. |
| Tarif pajak transaksi | Tarif efektif yang dihitung untuk transaksi sumber dari nominal komponen pajak dibagi basis kena pajak transaksi. Tarif hasil hitung dicocokkan dengan TaxRate atau grup pajak yang tersedia untuk business tersebut. |
| Tax inklusif Expense | Nilai `TOTAL AMOUNT` pada import Expense mencakup basis sebelum pajak dan pajak. Basis berasal dari rincian biaya Expense atau total sebelum pajak; pajak tetap tercatat sebagai komponen terpisah. |
| Rekonsiliasi tax item | Penghitungan ulang tax pada item Sales/Purchase menggunakan basis item dan nominal pajak transaksi, lalu dicocokkan dengan TaxRate atau grup yang sesuai. Jumlah item dibandingkan dengan tax dari akun Journal; selisih ditandai untuk ditinjau dan tidak dialokasikan secara tebakan. |
| Relasi sumber Journal | Relasi antara Entri Journal import dan transaksi sumber yang cocok. Relasi hanya dibuat bila sumber ditemukan unik dalam business yang sama. Kandidat yang hilang atau ambigu tidak ditautkan; posting GL tetap memakai Journal dan ketidakcocokan dicatat pada crosswalk/audit. |
| Audit legacy tahunan | Rekonsiliasi baca-saja per tahun antara bukti posting Journal, transaksi operasional, dan workbook kontrol. Audit menghasilkan laporan tanpa membuat atau mengubah transaksi maupun posting GL. |
| Saldo bertanda | Saldo audit yang dihitung sebagai debit dikurangi kredit. Nilai negatif menunjukkan posisi kredit; saldo akhir suatu tahun dibawa sebagai saldo awal tahun berikutnya dan tetap ditandai belum lengkap bila saldo dasar sumber tidak tersedia. |
| Akun CoA | Akun yang mengelompokkan nilai debit dan kredit dalam pembukuan, dengan kode dan sifat akun seperti aset, liabilitas, ekuitas, pendapatan, atau biaya. |
| Akun operasional | Akun yang dipilih pada transaksi atau pembayaran untuk mencatat rekening kas/bank atau akun sumber operasional; akun ini bukan dengan sendirinya akun posting CoA. |
| Crosswalk akun | Hubungan eksplisit antara akun operasional dan akun CoA dalam business yang sama. Pencocokan memakai kode atau nama yang tepat dan tunggal; nomor akun tepat dapat membedakan nama duplikat. Label account type lama bukan bukti jenis akun. |
| Klasifikasi kas/bank | Penetapan eksplisit bahwa akun CoA termasuk kas, bank, atau dikecualikan dari ledger kas/bank. Klasifikasi ini terpisah dari crosswalk dan label account type operasional. |
| Anomali rekonsiliasi | Selisih bernilai yang dapat dihitung antara Journal, transaksi operasional, posting GL, atau workbook kontrol. Sumber operasional yang tidak tertaut tidak menjadi anomali jika posting Journal sudah seimbang dan tidak ada selisih nominal. |
| Temuan rekonsiliasi | Anomali nominal atau keputusan klasifikasi yang belum terkonfirmasi, disimpan untuk ditinjau dalam business dan periode tertentu dengan bukti sumber dan status penyelesaian. |
| Klasifikasi pokok/bunga belum terkonfirmasi | Temuan untuk pembayaran pinjaman yang nominal Journal-nya diketahui tetapi pembagian pokok dan bunga belum didukung jadwal angsuran atau rekening koran. Temuan ini tidak mengubah atau mem-posting ulang Journal. |
| Selisih diterima | Anomali yang setelah ditinjau dinyatakan sah oleh pengguna berwenang; alasan wajib disimpan dan nilai tetap tersedia di histori audit. |
| Koreksi rekonsiliasi | Perubahan terkonfirmasi untuk menghilangkan anomali. Nilai operasional hanya dikoreksi bila komponennya didukung bukti yang unik; selisih GL lainnya memakai jurnal penyesuaian tertaut, tanpa mengubah Journal legacy. |
| Akun pembulatan | Akun CoA yang dipetakan secara eksplisit per business untuk jurnal pembulatan yang berada di bawah presisi mata uang business. |
