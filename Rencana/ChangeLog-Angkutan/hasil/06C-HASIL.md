# Hasil 06C - Regresi Sell biasa

## Status

**LULUS dengan catatan cleanup.** Draft Sell biasa tanpa SPB berhasil dibuat, diedit, dibuka kembali, dan direkonsiliasi melalui UI. Ditemukan serta diperbaiki validasi field langganan tersembunyi yang sebelumnya mencegah penyimpanan Sell biasa.

## Journey browser

- Run/sesi: `RUN06C-20260911`, `changelog06c2`.
- Business/lokasi: `CV BM TRANSPORT`, business location BL0001.
- Customer: Walk-In Customer.
- User membuka Sell → Add Draft melalui sidebar, bukan menu SPB.
- User mengisi Pay term 30 Days, memilih produk `Untuk Contoh 2011`, lalu Save.
- Draft `DR2026/0011` muncul pada List Drafts dengan 1 item.
- User membuka Action → Edit, mengubah quantity dari 1 menjadi 2, lalu Update.
- User membuka View dan reload; detail menampilkan quantity 2, unit price Rp10.000, subtotal/total payable Rp20.000, payment status Due.

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Create tanpa SPB | Draft Sell biasa dapat disimpan | DR2026/0011 tersimpan | LULUS |
| Total awal | 1 x Rp10.000 = Rp10.000 | Detail awal menunjukkan Rp10.000 | LULUS |
| Edit | Quantity dan total berubah | 1 → 2; total Rp10.000 → Rp20.000 | LULUS |
| Reload/detail | Perubahan tetap tersimpan | View menampilkan 2 unit dan Rp20.000 | LULUS |
| No external payment | Draft tidak membuat pembayaran | Payment status Due, total paid Rp0 | LULUS |
| Delete | Hapus dilakukan bila tersedia | Action draft hanya menampilkan View; tidak ada Hapus | CATATAN |

## Perbaikan regresi

Percobaan pertama ditolak oleh browser karena `recur_interval` pada modal langganan tersembunyi memiliki atribut `required` walaupun checkbox `Subscribe?` tidak dipilih. Ini membuat user Sell biasa harus mengisi field yang tidak terlihat.

Perbaikan:

- `resources/views/sale_pos/partials/recurring_invoice_modal.blade.php` tidak lagi memberi `required` secara permanen pada interval langganan.
- `public/js/pos.js` menambahkan sinkronisasi `required`: field interval hanya wajib ketika `Subscribe?` aktif, dan modal tetap terbuka untuk konfigurasi langganan.

Setelah perbaikan, form diperbarui ulang melalui browser. Pemeriksaan `:invalid` setelah Pay term, Days, dan produk terisi tidak lagi berisi field recurring; Save berhasil dan draft terlihat pada daftar.

## Bukti

Raw internal:

- `storage/app/changelog-evidence/RUN06C-20260911/raw/06C-sell-form-filled.png`
- `storage/app/changelog-evidence/RUN06C-20260911/raw/06C-sell-saved.png`
- `storage/app/changelog-evidence/RUN06C-20260911/raw/06C-sell-edit-filled.png`

Detail UI `DR2026/0011` setelah reload memuat:

```text
Product       Untuk Contoh 2011
Quantity      2.00 Pc(s)
Unit price    Rp10.000,00
Subtotal      Rp20.000,00
Total paid    Rp0,00
Remaining     Rp20.000,00
Status        Draft / Due
```

## Cleanup/handoff

`DR2026/0011` adalah draft lokal business 2 dan belum dibayar. UI List Drafts tidak menyediakan Hapus; saya tidak memanggil backend untuk memaksa penghapusan. Draft ini dicatat sebagai sisa cleanup UI. Sell angkutan `DR2026/0009` dan transaksi lama tidak disentuh.

Lanjutkan ke [06D - Regresi Payroll bulanan](../06D-REGRESI-PAYROLL-BULANAN.md).
