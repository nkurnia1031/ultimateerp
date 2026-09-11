# 02F — Uji Sell angkutan

## Tujuan dan lingkup

Selesaikan hanya uji sell angkutan. Jangan mengerjakan halaman/skenario lain di luar langkah di bawah.

## Prasyarat dan bacaan minimum

Baca [00 — aturan bersama](00-PETUNJUK-PELAKSANAAN.md) dan dokumen ini.

Baca ringkasan dan bukti terkait dari hasil/01C-HASIL.md, hasil/02C-HASIL.md. Peta pemilik: [01C](01C-AUDIT-BILLING-PROJECT-DAN-SELL.md), [02C](02C-UJI-FORM-DAN-VERIFIKASI-SPB.md). Buka dokumen pemilik hanya bila kontrak belum jelas; jangan menjalankannya otomatis.

Jika prasyarat yang diperlukan belum ada atau gagal, simpan blocker dan berhenti; jangan mengarang hasil atau membuat prasyarat diam-diam.

## Langkah kerja terbatas

1. Gunakan SPB lain, atau Buat SPB dari Sell dengan nilai awal customer/project; selesaikan dan verifikasi.
2. Klik Angkutan dari SPB, pilih customer/lokasi/produk jasa, tinjau line dan konfirmasi.
3. Buka ulang Sell; cocokkan ton tagihan, penyesuaian dan nilai akhir; pastikan SPB billed tidak tersedia di Sell maupun Project.

## Bukti dan hasil wajib

Simpan hasil/02F-HASIL.md sesuai format 00: ringkasan handoff, tindakan agent-browser, ID/business, expected/actual sebelum/sesudah/reload, screenshot, temuan dan pemeriksaan tambahan yang relevan.

Terapkan checklist CRUD 00 hanya pada operasi UI dalam lingkup. Fixture untuk penerus dipertahankan dengan ID/dependensi; cleanup lainnya lewat UI tanpa unlock posted. Test backend tidak menggantikan journey browser.

## Kriteria lulus dan berhenti

Service line dan total benar, billing lintas pintu masuk tidak ganda.

Jangan mengklaim lulus tanpa bukti atau jika langkah wajib belum diuji. Jika perbaikan melebar, tulis reproduksi dan usulan tugas terpisah, lalu berhenti. Setelah hasil tersimpan, berhenti; jangan otomatis menjalankan dependensi, bagian berikut atau deployment.
