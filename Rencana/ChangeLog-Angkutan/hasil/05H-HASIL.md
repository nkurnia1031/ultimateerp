# Hasil 05H - Panduan draft dan batal settlement

## Status

**LULUS dengan catatan.** Review settlement, pemilihan sumber, pembuatan draft, pembatalan, pengembalian sumber, netto nol, dan penolakan Kasbon melebihi hak telah dijalankan melalui `agent-browser`. Tidak ada write melalui SQL, controller/service, HTTP langsung, atau JavaScript yang melewati UI.

## Handoff

- Run browser: `RUN05H-20260911`.
- Sesi: `changelog05h`.
- Business aktif: `CV BM TRANSPORT`, ID `2`.
- Driver: `RUN02A Driver Revised`, user ID `93`.
- Unit: `RUN02A Unit Angkutan Revised`, asset ID `39`.
- Project: `RUN02A Project Angkutan`, ID `11`.
- SPB uji: ID `121`, nomor `TRIP-202609-0005`, tiket `RUN05H-20260911-SETTLE`.
- Bonus Ayak terkait: ID `885` dari SPB 121, nominal Rp0; ID `886` manual, 50 trip, nominal Rp50.000.
- Kasbon: ID `19` Rp300.037; ID `20` Rp1.699.963; ID `21` Rp2.000.001.
- Settlement draft: `SET-202609-0005` dan `SET-202609-0006`; keduanya dibatalkan melalui UI.

## Journey browser dan rekonsiliasi

| Skenario | Expected | Actual setelah UI dan reload | Status |
|---|---|---|---|
| Isi filter sopir/periode | Daftar sumber sesuai business, sopir, dan tanggal | Driver 93 dan periode 11-09-2026 menampilkan SPB 121, Bonus 885/886, dan Kasbon 19/20/21 | LULUS |
| Pilih/eject item | Ringkasan berubah tanpa menyimpan transaksi | Saat Bonus 886 dan Kasbon dikeluarkan, bruto berubah menjadi Rp1.950.000 dan netto menjadi Rp1.950.000; saat dipilih lagi angka kembali | LULUS |
| Buat draft normal | Sistem menyimpan sumber yang dipilih dan menghitung bruto/netto | `SET-202609-0005`: 1 SPB, Bonus Rp50.000, Kasbon Rp300.037, bruto Rp2.000.000, netto Rp1.699.963 | LULUS |
| Coba memilih sumber yang sudah dicadangkan | Sumber tidak muncul pada review baru | Review baru menampilkan tidak ada SPB/Bonus/Kasbon eligible selama draft aktif | LULUS |
| Batal dialog | Data tidak berubah | Dialog batal tidak membuat settlement baru dan data tetap tersedia | LULUS |
| Batalkan draft | Sumber kembali tersedia dan status menjadi CANCELLED | `SET-202609-0005` menjadi `CANCELLED`; review setelahnya menampilkan sumber lagi | LULUS |
| Netto tepat nol | Draft boleh dibuat jika Kasbon sama dengan bruto | `SET-202609-0006` dibuat dengan bruto Rp2.000.000 dan Kasbon Rp2.000.000, netto Rp0; lalu dibatalkan melalui UI | LULUS |
| Kasbon melebihi bruto | Submit ditolak, tidak ada settlement baru | Dengan Kasbon ID21, preview menunjukkan netto -Rp2.000.001 dan daftar menampilkan peringatan: `Total Kasbon melebihi hak payroll...` | LULUS |
| Reload | Hasil terakhir tetap dapat direkonsiliasi | Status draft/cancelled dan pesan hasil tetap terlihat pada daftar setelah reload | LULUS |

Rumus yang terbukti pada fixture:

```text
Upah SPB       = Rp1.950.000
Bonus Ayak     = Rp50.000
Bruto          = Rp1.950.000 + Rp50.000 = Rp2.000.000
Kasbon dipilih = Rp300.037
Netto          = Rp2.000.000 - Rp300.037 = Rp1.699.963
```

Satu SPB 121 juga memperlihatkan sumber historis yang sama pada detail: 10 ton x Rp115.000 = Rp1.150.000 pendapatan pelanggan; upah sopir terdiri dari Rp650.000 borongan variabel dan Rp1.300.000 uang jalan, total Rp1.950.000. Pendapatan pelanggan dan hak sopir tetap dua angka yang berbeda.

## Bukti screenshot

Raw hanya untuk bukti internal dan semuanya berasal dari keadaan UI nyata:

- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-spb-form-filled.png`
- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-spb-detail-created.png`
- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-settlement-review-restored.png`
- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-settlement-draft-list.png`
- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-settlement-cancelled.png`
- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-zero-net-cancelled.png`
- `storage/app/changelog-evidence/RUN05H-20260911/raw/05H-overlimit-inline-error.png`

PNG panduan diproses ulang dari raw memakai Python/Pillow; anotasi adalah raster PNG dengan pita legenda di luar capture. Tidak digunakan SVG, overlay HTML, anotasi DOM, atau gambar yang dibuat untuk menggantikan UI:

- [Form SPB](../../../hasil/screenshots/05H-spb-form-filled-annotated.png)
- [Detail SPB](../../../hasil/screenshots/05H-spb-detail-created-annotated.png)
- [Review settlement](../../../hasil/screenshots/05H-settlement-review-annotated.png)
- [Draft settlement](../../../hasil/screenshots/05H-settlement-draft-list-annotated.png)
- [Settlement dibatalkan](../../../hasil/screenshots/05H-settlement-cancelled-annotated.png)
- [Netto nol dibatalkan](../../../hasil/screenshots/05H-zero-net-cancelled-annotated.png)
- [Kasbon melebihi hak](../../../hasil/screenshots/05H-overlimit-inline-error-annotated.png)

Perintah reproduksi anotasi, contoh:

```text
python scripts/annotate_changelog_screenshots.py --preset 05H-settlement-review --input storage/app/changelog-evidence/RUN05H-20260911/raw/05H-settlement-review-restored.png --output hasil/screenshots/05H-settlement-review-annotated.png
```

Salinan anotasi berada di `storage/app/changelog-evidence/RUN05H-20260911/annotations/`, `Rencana/ChangeLog-Angkutan/hasil/screenshots/`, dan `public/images/change-log/release-2026-09-10-angkutan/`.

## Perbaikan yang dilakukan

Submit Kasbon berlebih awalnya hanya mengandalkan pesan session/toast yang mudah terlewat. Daftar settlement diperbaiki agar `session('status')` ditampilkan sebagai alert inline setelah kembali ke halaman. Uji browser diulang dan pesan penolakan terlihat jelas; tidak ada settlement baru yang dibuat.

## Privasi dan cleanup

Screenshot memakai business, project, driver, asset, dan customer fixture lokal yang sudah dicatat di atas; tidak memuat password atau data rekening. Settlement posted tidak dihapus dan tidak dibuka kuncinya. Kasbon ID20 dan ID21 belum menjadi sumber posted dan perlu dihapus melalui tombol UI pada tahap cleanup. SPB/Bonus yang kemudian dipakai tahap 05I dipertahankan sebagai handoff.

Lanjutkan ke [05I - Panduan posting Payroll](../05I-PANDUAN-POSTING-PAYROLL.md).
