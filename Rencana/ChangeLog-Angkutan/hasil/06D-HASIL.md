# Hasil 06D — Regresi payroll bulanan

## Status

**LULUS dengan catatan cleanup.** Payroll bulanan biasa berhasil dibuat, dibuka kembali, dan direkonsiliasi melalui UI. Tidak ada SPB, Bonus Ayak, atau Kasbon yang ditambahkan ke payroll biasa ini.

## Fixture dan sesi

- Run browser: `RUN06D-20260911`, sesi `changelog06d`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Employee: `Mr Sahar -`, user ID `5`.
- Periode: September 2026.
- Payroll baru: reference `2026/0331`.

## Journey browser dan hasil

1. Dari menu **HRM → Payroll**, user menekan **Add**.
2. User memilih `Mr Sahar -` melalui selector employee dan memilih bulan `09/2026` melalui date picker.
3. User memilih payroll group `Draft`, mengisi basic salary `Rp1.000.000`, melakukan blur agar total terhitung, lalu menekan **Save**.
4. Daftar payroll menampilkan `Mr Sahar -`, `September 2026`, reference `2026/0331`, total `Rp1.000.000`, status `Due`.
5. User membuka employee, membuka rincian payroll melalui ikon lihat, lalu memuat ulang halaman.

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Simpan payroll | Baris baru muncul | `2026/0331` muncul pada daftar | LULUS |
| Earnings | Basic salary Rp1.000.000 | Total earnings Rp1.000.000 | LULUS |
| Deductions | Tidak ada deduction | Total deductions Rp0 | LULUS |
| Net pay | Earnings - deductions | Net pay Rp1.000.000 | LULUS |
| Reload | Nilai tetap sama | Baris dan rincian tetap sama setelah reload | LULUS |
| Sumber angkutan | Tidak ada duplikasi SPB/Bonus/Kasbon | Rincian hanya memuat Basic salary | LULUS |
| Edit/hapus transaksi terkunci | Aplikasi menolak atau tidak menyediakan aksi | Daftar hanya menyediakan View, Ledger, dan View Payments | LULUS sebagai uji kunci |

## Bukti rekonsiliasi

```text
Basic salary = 1 x Rp1.000.000 = Rp1.000.000
Earnings     = Rp1.000.000
Deductions   = Rp0
Net pay      = Rp1.000.000 - Rp0 = Rp1.000.000
```

- [Form payroll terisi](screenshots/06D-payroll-form-filled-annotated.png)
- [Rincian payroll](screenshots/06D-payroll-detail-modal-annotated.png)
- Raw browser: `storage/app/changelog-evidence/RUN06D-20260911/raw/06D-payroll-form-filled.png`, `06D-payroll-detail-modal.png`, `06D-payroll-created.png`.

## Cleanup dan handoff

Payroll `2026/0331` dipertahankan sebagai fixture demonstrasi karena daftar tidak menyediakan Edit/Delete untuk transaksi tersebut. Tidak ada backend cleanup dan tidak ada transaksi posted yang dibuka kuncinya.

Screenshot memuat nama demo lokal dan hanya untuk bukti internal. Jangan menyalinnya ke panduan publik tanpa anonymisasi.

## Tahap berikutnya

Lanjutkan ke [06E — regresi maintenance dan notifikasi](../06E-REGRESI-MAINTENANCE-DAN-NOTIFIKASI.md).
