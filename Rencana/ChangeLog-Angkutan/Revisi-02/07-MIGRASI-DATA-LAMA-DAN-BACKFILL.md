# 07 — Migrasi data lama dan backfill bertahap

## Tujuan

Mengisi relasi baru secara aman tanpa menghapus data lama dan tanpa memaksa semua kontrak memiliki status, Project, atau SPB pada hari pertama.

## Prasyarat

- Tahap 02–06 lulus atau gap-nya sudah disetujui.
- Ada salinan/backup lokal dan daftar jumlah baris kontrak, Project, SPB, customer, dan business.
- Jalur UI untuk mapping manual tersedia; jika belum, catat gap dan jangan menulis langsung ke database sebagai pengganti UI.

## Langkah kerja

1. Buat laporan read-only jumlah kontrak per business, customer, status null, Project null, proposal null, dan kontrak yang sudah memiliki kandidat SPB.
2. Buat preview kandidat mapping berdasarkan business + customer + nomor/pekerjaan + periode + kapal/lokasi. Kandidat ambigu harus masuk unmatched.
3. Sediakan konfirmasi per baris/batch melalui UI. User dapat menolak, memilih Project, atau membiarkan relasi null.
4. Backfill `project_id`, `proposal_id`, `created_by`, dan `surat_kontrak_id` hanya untuk kandidat unik yang telah dikonfirmasi. Jangan menebak status; biarkan null jika belum diverifikasi.
5. Pertahankan `pekerjaan`, lokasi, kapal, tonase, notes, nomor, import batch, dan keterangan asli sebagai snapshot histori.
6. Jika ada addendum, buat versi/parent sesuai keputusan tahap 01; jangan mengedit kontrak active yang sudah menjadi dasar transaksi.
7. Setelah setiap batch, cek jumlah sebelum/sesudah, unmatched, relasi lintas business, dan sampel detail melalui browser.
8. Sediakan rollback/koreksi untuk mapping draft; jangan menghapus dokumen legal atau membuka transaksi posted.

## Hasil wajib

- Preview mapping, daftar unmatched, dan log konfirmasi.
- `hasil/07-HASIL.md` berisi jumlah sebelum/sesudah per business, batch ID, ID yang berubah, status null yang dipertahankan, dan cleanup/handoff.

## Kriteria lulus

- Tidak ada relasi lintas business.
- Mapping ambigu tidak otomatis ditetapkan.
- Kontrak lama tetap tampil dan dapat diexport.
- Status null tetap valid dan jelas bagi user.

## Catatan tahap berikutnya

Tahap 08 menguji journey end-to-end menggunakan data yang dibuat melalui browser, termasuk satu kontrak legacy yang sengaja dibiarkan null.

