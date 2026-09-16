# 04 — UI Surat Kontrak dan Project

## Tujuan

Membuat user dapat mengelola kontrak dari menu CRM dan menemukan kontrak yang terkait dari Project tanpa memaksa pengisian status atau relasi pada data lama.

## Prasyarat

- Tahap 03 lulus.
- Baca hasil tahap 01–03 yang relevan saja.
- Siapkan fixture lokal melalui UI: customer, Project, kontrak baru bertanda `CL-KONTRAK-<run-id>`, kontrak legacy tanpa project/status, dan bila tersedia proposal.

## Langkah kerja

1. Pada menu Surat Kontrak, pertahankan CRUD lama dan tambahkan selector Project yang hanya memuat Project business aktif.
2. Saat Project dipilih, tampilkan customer Project sebagai nilai awal; tetap validasi customer di server.
3. Tambahkan status opsional dengan pilihan `Belum ditentukan`, `Draft`, `Active`, `Expired`, `Cancelled`. Jangan memaksa status pada kontrak lama.
4. Tambahkan area dokumen/catatan kontrak dengan jalur UI yang mengikuti DocumentAndNote dan permission yang sudah ada.
5. Pada detail kontrak tampilkan customer, Project, proposal jika ada, scope, status, dokumen, dan ringkasan realisasi. Ringkasan belum boleh menjanjikan angka jika data belum tersedia.
6. Pada tab Project tampilkan kontrak terkait, status billing, jumlah SPB, tonase, revenue, upah sopir, Bonus Ayak, dan kontribusi. Bedakan pendapatan pelanggan dari biaya sopir.
7. Sediakan tombol `Buat Kontrak` dari Project dengan Project/customer otomatis terisi dan tombol `Lihat Kontrak` dari daftar Project.
8. Untuk kontrak legacy, sediakan tindakan `Tautkan ke Project` yang dapat dibatalkan sebelum disimpan. Jangan menyamakan teks `pekerjaan` dengan Project tanpa konfirmasi user.
9. Uji desktop dan ponsel: label awam, padding, tombol simpan/batal, tabel DataTables responsive, pesan error, modal, keyboard focus, dan status null.
10. Screenshot raw hanya diambil setelah UI tercapai melalui agent-browser; anotasi dengan Python/Pillow bila menjadi bukti/panduan.

## Hasil wajib

- CRUD dan detail kontrak yang bisa dipahami user awam.
- Tab Project dengan daftar kontrak dan ringkasan yang scoped.
- `hasil/04-HASIL.md` berisi create/edit/delete/cancel dialog, legacy mapping, business isolation, screenshot raw/annotated, dan hasil reload.

## Kriteria lulus

- Kontrak dapat dibuat tanpa status.
- Kontrak dapat ditautkan ke Project yang benar dan relasinya bertahan setelah reload.
- Customer/Project business lain tidak muncul.
- Kontrak dengan SPB/invoice/payroll terkunci mengikuti aturan, bukan diam-diam mengubah histori.

## Catatan tahap berikutnya

Tahap 05 menghubungkan kontrak ke SPB. Semua nilai billing dan payroll tetap berasal dari snapshot SPB, bukan dihitung ulang dari header kontrak saat histori sudah terkunci.

