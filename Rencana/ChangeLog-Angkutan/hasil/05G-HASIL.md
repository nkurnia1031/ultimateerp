# Hasil 05G — Panduan Kasbon

## Status

**LULUS dengan catatan.** Kasbon dapat dibuat, diedit, dimuat ulang, dan dihapus melalui UI. Dialog hapus yang dibatalkan mempertahankan data. Kasbon yang sudah masuk payroll tampil `Terkunci payroll` dan tetap ada setelah percobaan hapus. Satu Kasbon handoff ID 17 tetap tersedia untuk settlement berikutnya.

## Handoff singkat

- Run browser: `RUN05G-20260911`, sesi `changelog05g`.
- Business: `CV BM TRANSPORT`, ID `2`.
- Driver: `RUN02A Driver Revised`, user ID `93`.
- Kasbon uji: ID `884` (nominal awal `175.037`, diubah menjadi `180.037`), dibuat dan dihapus lewat UI.
- Kasbon payroll handoff: ID `17`, nominal `150.000`, tidak diubah.
- Slug panduan memakai release yang sudah ada: `angkutan-spb-dan-bonus-ayak`; tidak dibuat release baru.

## Panduan untuk user awam

Kasbon adalah uang muka/potongan yang nantinya dapat dikurangkan dari rekap pembayaran sopir. Kasbon tidak menjadi baris tagihan pelanggan. Bonus Ayak dan upah perjalanan juga tetap dihitung pada payroll, sedangkan invoice pelanggan hanya memuat pendapatan angkutan.

### Membuat Kasbon

1. Buka **Pool → Bonus Ayak → Form Kasbon Bonus Ayak**.
2. Pastikan tanggal Kasbon sesuai.
3. Pilih **Nama Operator / Supir** dari daftar pada business aktif.
4. Isi **Jumlah Kasbon (Rp)**.
5. Tekan **Simpan**.
6. Pastikan baris baru muncul pada **Daftar Kasbon Bonus Ayak**, lalu reload halaman.

### Mengubah Kasbon

1. Pada baris Kasbon yang belum terkunci, tekan **Edit**.
2. Ubah nominal sesuai dokumen yang disetujui.
3. Tekan **Update**.
4. Kembali ke daftar dan reload. Nominal baru harus tetap tampil.

### Menghapus Kasbon

1. Tekan **Hapus** pada baris data uji atau data yang memang boleh dihapus.
2. Pilih **Batal/Dismiss** bila belum yakin; baris harus tetap ada.
3. Untuk data yang benar-benar salah, buka dialog lagi dan pilih **Accept/OK**.
4. Reload dan pastikan baris hilang serta total berkurang.

Kasbon yang sudah dipakai settlement payroll tidak boleh diedit. Sistem menandainya `Terkunci payroll`; gunakan prosedur koreksi payroll resmi.

## Contoh angka teruji

Pada run ini Kasbon uji dibuat `Rp175.037`, lalu diedit menjadi `Rp180.037`. Total daftar berubah dari `Rp3.165.037` menjadi `Rp3.170.037`, dan setelah data uji dihapus kembali menjadi `Rp2.990.000`. Kasbon handoff ID 17 tetap `Rp150.000`.

Saat settlement dibuat, Kasbon yang dipilih menjadi potongan. Rumus gabungan yang akan diperiksa pada tahap settlement adalah:

```text
Bruto = Upah SPB + Bonus Ayak
Netto = Bruto - Kasbon
```

## Bukti journey browser

Semua create, edit, reload, dialog dismiss, dialog accept, dan percobaan pada data terkunci dilakukan lewat `agent-browser`. Tidak ada SQL write, pemanggilan controller/service, HTTP langsung, atau JavaScript penguji yang melewati UI.

| Skenario | Tindakan dan hasil aktual | Status |
|---|---|---|
| Buat | Pilih driver 93, isi `175037`, tekan Simpan. Baris baru ID 884 muncul dengan tanggal 11-09-2026. | LULUS |
| Detail/daftar | Daftar menampilkan driver dan nominal; reload mempertahankan baris. | LULUS |
| Edit | Buka Edit ID 884, ubah menjadi `180037`, tekan Update; reload menampilkan `180.037`. | LULUS |
| Batal hapus | Dialog `Hapus kasbon ini?` dibuka lalu Dismiss; ID 884 tetap ada. | LULUS |
| Hapus | Dialog dibuka lagi lalu Accept; ID 884 hilang dan total kembali `2.990.000`. | LULUS |
| Validasi negatif | Isi `-1` melalui field nominal; validasi native mencegah submit dan daftar tidak berubah. | LULUS |
| Terkunci | Kasbon ID 17 berlabel `Terkunci payroll`; tidak memiliki Edit. Percobaan Hapus melalui UI tidak menghilangkan row. | LULUS |
| Business/driver | Selector driver berasal dari business aktif; driver 93 berhasil dipilih melalui Select2 UI. | LULUS |

Bukti raw:

- [form desktop](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-kasbon-form-filled.png)
- [form tersimpan mobile](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-kasbon-saved-mobile.png)
- [edit mobile](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-kasbon-edit-filled-mobile.png)
- [hasil edit mobile](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-kasbon-edited-saved-mobile.png)
- [daftar desktop](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-kasbon-list-desktop.png)
- [validasi negatif](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-kasbon-invalid-negative.png)
- [Kasbon terkunci](../../../storage/app/changelog-evidence/RUN05G-20260911/raw/05G-locked-kasbon-rejected.png)

Bukti PNG beranotasi:

- [form desktop beranotasi](../../../hasil/screenshots/05G-kasbon-form-desktop-annotated.png)
- [form mobile beranotasi](../../../hasil/screenshots/05G-kasbon-form-mobile-annotated.png)
- [edit mobile beranotasi](../../../hasil/screenshots/05G-kasbon-edit-mobile-annotated.png)
- [Kasbon terkunci beranotasi](../../../hasil/screenshots/05G-kasbon-locked-desktop-annotated.png)

## Perbaikan dan gap

Perbaikan jalur Edit/Update Kasbon sudah dilakukan pada tahap 02H dan terbukti ulang pada run ini. Tidak ada perubahan kode pada 05G. Import Bonus Ayak yang belum memiliki preview/fingerprint dan export PDF yang merespons 204 tetap menjadi gap tahap sebelumnya; keduanya tidak dijanjikan pada panduan Kasbon.

## Inventaris, privasi, dan anotasi

| Jenis | Lokasi | Keterangan |
|---|---|---|
| Raw internal | `storage/app/changelog-evidence/RUN05G-20260911/raw/` | Capture keadaan UI nyata dari agent-browser. |
| PNG anotasi internal | `storage/app/changelog-evidence/RUN05G-20260911/annotations/` | Hasil `scripts/annotate_changelog_screenshots.py`. |
| PNG publik | `public/images/change-log/release-2026-09-10-angkutan/` | Hanya form desktop yang sudah ditinjau privasinya. |

Anotasi dibuat dari raw PNG dengan Python/Pillow. Tidak ada SVG, overlay HTML, atau anotasi DOM yang dipakai sebagai hasil final. PNG beranotasi memiliki pita legenda di luar raw sehingga nilai dan field penting tidak ditutup. Screenshot tidak memuat kredensial atau nomor rekening; data yang terlihat adalah fixture lokal yang sudah dicatat di atas.

## Pemeriksaan tambahan

- Browser: LULUS untuk create/edit/delete/cancel/locked validation.
- Pemeriksaan read-only: ID 17 masih berada pada business 2, driver 93, dan belum berubah pada run ini.
- Screenshot desktop `1440×900`, desktop form `1264×625`, dan mobile `390×844` tersimpan dengan ukuran aktual.
- Proses anotasi PNG Python lulus untuk preset 05G.
- Cache, lint, migrate pretend, dan Graphify dijalankan pada handoff paket setelah perubahan dokumentasi/anotasi.
- Tidak ada JavaScript module yang berubah; publish module tidak diperlukan.

## Cleanup dan handoff

Kasbon ID 884 sudah dihapus melalui UI. Kasbon ID 17 sengaja dipertahankan untuk tahap settlement; jangan menghapus atau mengubahnya. Tidak ada transaksi posted yang dibuka kuncinya.

Lanjutkan ke [05H — Panduan draft dan pembatalan settlement](../05H-PANDUAN-DRAFT-DAN-BATAL-SETTLEMENT.md). Tahap berikut harus memastikan Kasbon ID 17 tetap tersedia dan kembali tersedia jika draft settlement dibatalkan.
