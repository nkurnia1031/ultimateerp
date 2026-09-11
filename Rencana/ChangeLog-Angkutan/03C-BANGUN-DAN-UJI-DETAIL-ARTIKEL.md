# 03C — Bangun dan uji detail artikel

## Tujuan dan lingkup

Selesaikan hanya bangun dan uji detail artikel. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/03B-HASIL.md. Peta pemilik: [03B](03B-BANGUN-DAN-UJI-OVERVIEW.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Bangun template manfaat/menu/persiapan/langkah/hasil/masalah umum/tautan terkait dan kembali overview.
2. Lewat agent-browser klik artikel lalu fitur terkait dan kembali; uji guest, slug invalid, permission, keyboard dan ponsel.
3. Tombol fitur mengikuti akses, endpoint tujuan tetap melindungi; tidak memuat path/HTML bebas.

## Bukti dan hasil wajib

Simpan hasil/03C-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Detail dan navigasi aman; draft tidak diklaim panduan final.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
