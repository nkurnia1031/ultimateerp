# 02L — Uji konkurensi sumber billing

## Tujuan dan lingkup

Selesaikan hanya uji konkurensi sumber billing. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/02E-HASIL.md, hasil/02F-HASIL.md. Peta pemilik: [02E](02E-UJI-SPB-DAN-INVOICE-DARI-PROJECT.md), [02F](02F-UJI-SELL-ANGKUTAN.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Buka SPB unbilled yang sama melalui dua tab/sesi: invoice Project dan Sell.
2. Siapkan form lalu tekan konfirmasi sedekat mungkin; catat waktu/respons kedua tab dan reload detail.
3. Periksa read-only bahwa tepat satu billing berhasil tanpa orphan; laporkan bila overlap tidak terbukti.

## Bukti dan hasil wajib

Simpan hasil/02L-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Tidak ada dua tagihan atas satu sumber; backend concurrency test hanya tambahan.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
