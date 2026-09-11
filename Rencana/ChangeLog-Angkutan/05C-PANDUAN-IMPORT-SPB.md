# 05C — Panduan import SPB

## Tujuan dan lingkup

Selesaikan hanya panduan import spb. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02D-HASIL.md, hasil/03C-HASIL.md. Peta pemilik: [02D](02D-UJI-IMPORT-SPB.md), [03C](03C-BANGUN-DAN-UJI-DETAIL-ARTIKEL.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Tulis satu artikel slug usulan import-spb; cocokkan slug dengan katalog final dan jangan mengganti slug yang sudah diterbitkan. Cakupan hanya upload, sheet/baris, unmatched, koreksi preview, konfirmasi dan import duplikat.
2. Gunakan Bahasa Indonesia awam: manfaat, siapa pengguna, menu aktual, persiapan, langkah bernomor, contoh angka dari hasil uji, tanda sukses dan masalah/solusi. Jelaskan SPB/verifikasi/settlement saat pertama digunakan; Bonus bukan line invoice pelanggan.
3. Melalui agent-browser ulangi langkah artikel memakai data demonstrasi yang sudah lulus, reload hasil tersimpan dan ambil screenshot baru. Jangan memperbaiki fitur lain; jika perilaku berubah/gagal, hentikan publikasi artikel dan handoff ke bagian pengujian pemilik.
4. Simpan raw internal `storage/app/changelog-evidence/<run-id>/raw/`, lalu jalankan utilitas Python `scripts/annotate_changelog_screenshots.py` dari raw ke PNG hasil bernomor/kotak/panah/caption. Jangan gunakan SVG atau overlay HTML. Salin hanya PNG hasil ke `public/images/change-log/<release-id>/`, lengkap dengan alt text dan teks setara gambar.
5. Periksa raw dan hasil PNG bebas data sensitif, penanda tidak menutup field penting, tampilan desktop/ponsel, link permission, serta kecocokan label. Catat perintah Python dan ID fixture untuk cleanup atau handoff.

## Bukti dan hasil wajib

Simpan hasil/05C-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan. Sertakan file artikel, slug/release_id, contoh angka dan sumber hasil uji, inventaris raw/publik/anotasi serta review privasi.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Satu artikel dan asetnya cocok bukti lulus, aman dan dapat diikuti; artikel lain tidak ditulis pada sesi ini.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
