# Handoff — Standardisasi Import — 2026-11-21

## Status repo

- Root: `D:\Laragon\www\2025\ultimateerp`
- Branch: `main`
- HEAD: `29b4e98` — `Stabilize Journal shared import history tests`
- HEAD sudah dipush. Worktree bersih saat handoff dibuat.
- GitHub Actions PHPUnit run [`44926358818`](https://github.com/nkurnia1031/ultimateerp/actions/runs/44926358818) berhasil.
- PHPUnit lokal tidak dijalankan. E2E belum dijalankan; permintaan pengguna adalah menjalankannya hanya di akhir.

## Permintaan aktif

Seragamkan pola fitur import dan rapikan kode yang terdampak. Kerjakan bertahap: bangun pola bersama, migrasikan satu importer sebagai contoh, lalu minta konfirmasi sebelum menerapkan pola ke importer lain.

Batasan pengguna:

- Hindari micro-optimization tanpa masalah terukur.
- Jaga perilaku bisnis dan validasi yang sudah ada.
- Tambahkan pola bersama hanya saat beberapa importer memang membenarkan seam tersebut; jika belum jelas, catat sebagai saran.
- Perubahan ini tidak menambahkan fitur pengguna baru seperti preview UI atau undo/revert import.
- Hindari komentar yang tidak membantu dan lapisan defensif tanpa kebutuhan nyata.
- Jalankan tes PHPUnit melalui GitHub Actions saja. Jalankan E2E satu kali di akhir pekerjaan.

## Yang sudah diketahui

Parent issue [#56](https://github.com/nkurnia1031/ultimateerp/issues/56) dan [spec lifecycle](../docs/specs/user-initiated-import-lifecycle.md) menetapkan pola batch bersama dengan adapter domain. ADR [#0006](../docs/adr/0006-shared-import-batch-lifecycle.md) mempertahankan state, validasi, dan rollback spesifik importer. Keputusan D2 dan D11 menyatakan batch bersama menjadi envelope riwayat, sementara model dan aturan domain tetap authoritative, serta migrasi dilakukan satu importer per tahap.

Pengguna memberi batas tambahan untuk pekerjaan aktif ini: refactor tidak boleh menambahkan preview atau undo/revert baru. Batas terbaru ini berlaku untuk pekerjaan ini meski parent issue #56 juga membahas fitur tersebut.

Implementasi shared batch yang ada:

- Model dan operasi umum: `app/UserImportBatch.php`, `app/Services/UserImportBatchService.php`.
- Adapter Expense: `app/Services/ExpenseImportHistoryService.php`.
- Adapter Solar: `app/Services/SolarImportHistoryService.php`.
- Adapter Journal: `Modules/Accounting/Services/JournalImportHistoryService.php`.
- Adapter Vendor Loading: `Modules/AssetManagement/Services/HeavyEquipmentVendorLoadingImportHistoryService.php`.
- Adapter tarif alat berat: `Modules/AssetManagement/Services/HeavyEquipmentRateImportHistoryService.php`.
- SPB memiliki batch domain khusus di `Modules/AssetManagement/Services/HeavyEquipmentSpbImportBatchService.php`.
- Import salin lintas bisnis memiliki `app/Services/ContactBusinessImportService.php`.

Graphify query menemukan modul import Project, Legacy Attachment, Journal, dan daftar adapter di atas. Kandidat lain yang terlihat antara lain Bonus Ayak (`app/Imports/BonusAyakImportPreview.php`, `app/Imports/BonusAyakImport.php`, `app/Http/Controllers/BonusAyakController.php`) dan Attendance (`Modules/Essentials`).

## Keputusan pilot — 2026-11-21

Pilih Bonus Ayak sebagai pilot. Alur saat ini sudah punya parsing multi-sheet, preview tersimpan, konfirmasi eksplisit, pembatalan, fingerprint duplikat, dan transaksi saat menyimpan. Batch bersama dapat membungkus alur itu tanpa mengubah validasi bisnis atau UI. Attendance ditunda karena import-nya menulis langsung dalam satu request tanpa seam preview/pembatalan; mengadopsi lifecycle lebih luas di sana akan mengubah lebih banyak perilaku.

Pakai `UserImportBatchService` yang sudah digunakan beberapa adapter sebagai pola bersama; jangan menambah lapisan generik baru. Pilot hanya menautkan batch bersama dan status/payload pada alur Bonus Ayak yang ada. Pertahankan proses sinkron, preview, konfirmasi, pembatalan, validasi, dan aturan duplikat. Jangan menambah halaman riwayat, queue, snapshot/download, atau revert pada tahap ini.

Tiket terkait parent #56 mencakup #63 Journal, #64 Sales/Purchase/Expense, #65 Product/Opening Stock, #66 Purchase Product/Price Group, #67 Bonus Ayak, #68 Attendance, #69 Project, #70 Contact CSV, #71 CRM Contract, dan #72 import salin lintas bisnis. Acceptance criteria tiket-tiket ini mencakup pekerjaan produk yang lebih luas; jangan mengadopsinya sebagai fitur tambahan untuk refactor tanpa mencocokkan dengan batas terbaru pengguna.

## Riwayat penting sebelum handoff

Tiket Journal #63 sudah diimplementasikan di `9a8dc4f` dan perbaikan namespace di `f099d18`. CI run `44919385482` menunjukkan integrasi shared history memerlukan penyesuaian pada jalur CLI dan fixture tes Journal lama. Penyesuaian itu masuk ke `29b4e98`; CI run `44926358818` hijau. E2E untuk Journal belum dijalankan dan tetap ditunda ke akhir sesuai instruksi pengguna.

## Langkah berikutnya

1. Pastikan `git status --short` masih bersih dan baca `AGENTS.md`, `GLOSSARY.md`, `docs/agents/engineering-workflow.md`, serta issue #56.
2. Temukan ask-matt router pada mesin baru sebelum memilih alur. Di mesin ini `.codex/skills/ask-matt/SKILL.md` dan pencarian skill roots tidak menemukannya; konteks sesi sebelumnya mencatat route `/implement` dengan TDD/code-review.
3. Bandingkan dua atau lebih implementasi yang sudah ada untuk menetapkan seam yang benar-benar mengurangi duplikasi. Pertahankan adapter domain; jangan membuat lapisan pass-through.
4. Pilih satu importer pilot dengan bukti alur, model, route, dan tesnya. Catat keputusan/pilot sebelum implementasi. Pertahankan validasi dan hasil bisnis lama; jangan tambahkan preview atau undo.
5. Implementasikan pola bersama dan migrasikan pilot itu saja. Jalankan lint statis yang relevan, lalu push ke `main` dan tunggu PHPUnit GitHub Actions. Jangan menjalankan PHPUnit lokal.
6. Setelah pilot selesai dan hasilnya bisa ditinjau, mintakan konfirmasi pengguna sebelum migrasi importer lain. Jalankan E2E pada akhir rangkaian, bukan per importer.

