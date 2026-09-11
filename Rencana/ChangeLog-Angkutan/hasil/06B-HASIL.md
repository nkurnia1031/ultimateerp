# Hasil 06B - Regresi invoice Project lama

## Status

**LULUS dengan catatan cleanup.** Jalur invoice Project non-angkutan lama tetap dapat membuat invoice draft, mengubah line, dan menyimpan nominal setelah reload. Tidak ada SPB yang ikut tertagih pada skenario ini.

## Journey browser

- Run/sesi: `RUN06B-20260911`, `changelog06b`.
- Business: `CV BM TRANSPORT`, ID 2.
- Project fixture: `SURAT PERJANJIAN KERJA`, ID 10.
- Customer: `PT. Dumai Bone Indonesia`.
- Melalui tab Project `Invoices`, user menekan `Add`.
- User mengisi judul `RUN06B Invoice Legacy`, lokasi business, tanggal 11-09-2026, status Draft, satu service line, rate Rp100.000, quantity 1, lalu Save.
- Invoice `DR2026/0010` muncul pada daftar dengan status Draft dan total Rp100.000.
- User membuka Action → Edit, mengubah rate menjadi Rp120.000, Update, kembali ke tab invoice dan reload.
- `DR2026/0010` tetap tampil dengan total Rp120.000.

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Create | Draft invoice lama tersimpan di Project | DR2026/0010 tampil | LULUS |
| Detail/list | Customer, title, status, dan total terlihat | Semua terlihat pada daftar Project | LULUS |
| Edit | Line berubah dan total ikut dihitung | Rp100.000 → Rp120.000 | LULUS |
| Reload | Perubahan tidak hilang | Rp120.000 tetap tampil | LULUS |
| SPB isolation | Invoice non-angkutan tidak memerlukan SPB | Form lama tetap berjalan tanpa memilih SPB | LULUS |
| Delete | Draft dapat dihapus jika menu menyediakan | Menu Action Project hanya menyediakan Add payment, View Payments, dan Edit; tidak ada Hapus | CATATAN |

## Bukti

Raw internal:

- `storage/app/changelog-evidence/RUN06B-20260911/raw/06B-invoice-form-filled.png`
- `storage/app/changelog-evidence/RUN06B-20260911/raw/06B-invoice-created.png`
- `storage/app/changelog-evidence/RUN06B-20260911/raw/06B-invoice-edit-filled.png`
- `storage/app/changelog-evidence/RUN06B-20260911/raw/06B-invoice-edited-reloaded.png`

Bukti teks browser setelah reload menunjukkan `DR2026/0010`, title `RUN06B Invoice Legacy`, status Draft, dan total `Rp 120,000.00`. Tidak ada pembayaran yang dibuat.

## Cleanup/handoff

Invoice `DR2026/0010` adalah draft lokal, business 2, dan belum dibayar. Tidak ada tombol Hapus pada daftar invoice Project dan saya tidak menjalankan SQL write atau unlock. Sisa ini dicatat untuk koreksi UI oleh pemilik modul bila cleanup wajib tersedia. Invoice operasional `DR2026/0003` tidak disentuh.

Lanjutkan ke [06C - Regresi Sell biasa](../06C-REGRESI-SELL-BIASA.md).
