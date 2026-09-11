# 06H — Cleanup lokal dan serah terima

## Tujuan dan lingkup

Selesaikan hanya cleanup lokal dan serah terima. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/06G-HASIL.md. Peta pemilik: [06G](06G-PEMERIKSAAN-TEKNIS-FINAL.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Dari inventaris fixture kumpulkan target run-id dan dependensi; pastikan screenshot sudah selesai.
2. Gunakan agent-browser untuk edit/hapus/batal/koreksi mulai turunan; reload daftar/rekap. Jangan SQL write, unlock posted atau hapus master masih terpakai.
3. Catat ID sisa/business/status/alasan, screenshot final, daftar file/migration/asset, hasil setiap bagian dan blocker.
4. Tulis instruksi deployment: backup, deploy file, migration ditinjau, cache, restart worker bila job berubah, smoke host oleh operator berwenang; cara menambah release_id/tanggal/artikel baru. Jangan deploy otomatis.

## Bukti dan hasil wajib

Simpan hasil/06H-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Inventaris cleanup lengkap dan handoff jujur; siap rilis hanya bila seluruh kontrol kritis lulus.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
