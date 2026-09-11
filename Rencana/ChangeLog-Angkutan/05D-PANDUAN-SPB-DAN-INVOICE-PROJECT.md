# 05D — Panduan SPB dan invoice Project

## Tujuan dan lingkup

Selesaikan hanya panduan spb dan invoice project. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02E-HASIL.md, hasil/03C-HASIL.md. Peta pemilik: [02E](02E-UJI-SPB-DAN-INVOICE-DARI-PROJECT.md), [03C](03C-BANGUN-DAN-UJI-DETAIL-ARTIKEL.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Tulis satu artikel slug usulan invoice-project; cocokkan slug dengan katalog final dan jangan mengganti slug yang sudah diterbitkan. Cakupan hanya Buat SPB dari Project, nilai awal, verified, pilih sumber, preview dan detail tagihan.
2. Gunakan Bahasa Indonesia awam: manfaat, siapa pengguna, menu aktual, persiapan, langkah bernomor, contoh angka dari hasil uji, tanda sukses dan masalah/solusi. Jelaskan SPB/verifikasi/settlement saat pertama digunakan; Bonus bukan line invoice pelanggan.
3. Melalui agent-browser ulangi langkah artikel memakai data demonstrasi yang sudah lulus, reload hasil tersimpan dan ambil screenshot baru. Jangan memperbaiki fitur lain; jika perilaku berubah/gagal, hentikan publikasi artikel dan handoff ke bagian pengujian pemilik.
4. Simpan raw internal `storage/app/changelog-evidence/<run-id>/raw/`, ambil keadaan final lewat browser, lalu proses raw menjadi PNG dengan `scripts/annotate_changelog_screenshots.py`. Hasil final harus PNG raster bernomor/kotak/panah/caption; jangan memakai SVG atau overlay HTML. Simpan PNG aman di `public/images/change-log/<release-id>/` beserta alt text dan teks setara gambar.
5. Periksa raw dan PNG hasil bebas data sensitif, jangan menutup field/nilai invoice penting, dan catat perintah Python, ukuran desktop/ponsel, link permission, serta ID fixture untuk cleanup atau handoff.

## Bukti dan hasil wajib

Simpan hasil/05D-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan. Sertakan file artikel, slug/release_id, contoh angka dan sumber hasil uji, inventaris raw/publik/anotasi serta review privasi.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Satu artikel dan asetnya cocok bukti lulus, aman dan dapat diikuti; artikel lain tidak ditulis pada sesi ini.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
