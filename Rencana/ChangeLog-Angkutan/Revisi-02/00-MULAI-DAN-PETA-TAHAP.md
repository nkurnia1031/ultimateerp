# Revisi 02 — Integrasi relasi Surat Kontrak

## Tujuan

Paket ini berisi instruksi implementasi dan pembuktian relasi Surat Kontrak dengan alur angkutan. Jalankan satu dokumen per permintaan pengguna, simpan hasilnya, lalu berhenti. Paket ini belum berarti kode sudah diubah atau fitur sudah lulus.

## Keputusan yang dikunci

Semua relasi yang relevan diimplementasikan sejak awal, tetapi pengisian data dibuat bertahap:

- `SuratKontrak` wajib memiliki konteks `business` dan `customer/contact`.
- `SuratKontrak` memiliki `project_id` nullable; satu Project dapat memiliki beberapa kontrak, revisi, atau addendum.
- `HeavyEquipmentTrip` memiliki `surat_kontrak_id` nullable; satu kontrak dapat menaungi banyak SPB, tetapi satu SPB hanya memiliki satu kontrak utama.
- Dokumen kontrak memakai relasi polymorphic `DocumentAndNote`/media yang sudah digunakan aplikasi.
- Kontrak memiliki relasi pengguna untuk `created_by`, `approved_by`, dan bila relevan pengguna pengubah; semua nullable untuk data lama.
- Kontrak terhubung ke proposal CRM melalui `proposal_id` nullable. Proposal bukan syarat untuk membuat kontrak, tetapi relasinya tetap tersedia sejak awal.
- Ruang lingkup rute dan harga menggunakan tabel `surat_kontrak_scopes` sejak awal. Baris detail boleh kosong untuk kontrak lama/simple; jangan memaksa satu `route_id` pada header bila kontrak mencakup banyak rute.
- Invoice Project dan Sell, customer ledger, payroll, Bonus Ayak, settlement, dan asset ledger ditelusuri melalui SPB. Relasi turunan/reporting tetap dibuat, tetapi tidak membuat foreign key kontrak langsung ke satu invoice, satu payroll, satu sopir, atau satu asset.
- `status` kontrak **nullable/opsional**. Nilai null ditampilkan sebagai **Belum ditentukan** dan tidak menghalangi data lama, import, atau hubungan Project/SPB. Jika status diisi, lifecycle dan pembatasannya berlaku.

## Rantai data target

```text
Business
  └── Surat Kontrak ── Customer
        ├── Project ── SPB/Trip ── Trip Billing ── Invoice Project/Sell ── Payment
        │                  ├── Asset/Unit ── Asset Ledger
        │                  ├── Sopir ── Settlement ── Payroll Essentials
        │                  └── Bonus Ayak
        ├── Scope Rute/Tarif
        ├── Proposal CRM (opsional)
        └── Dokumen, catatan, approval, dan riwayat
```

## Urutan tahap

1. [01 — Audit sumber dan keputusan relasi](01-AUDIT-SUMBER-DAN-KEPUTUSAN-RELASI.md)
2. [02 — Skema dan migrasi nullable](02-SKEMA-DAN-MIGRASI-RELASI.md)
3. [03 — Model, scope, dan aturan business](03-MODEL-SCOPE-DAN-ATURAN-BISNIS.md)
4. [04 — UI Surat Kontrak dan Project](04-UI-KONTRAK-DAN-PROJECT.md)
5. [05 — UI SPB dan snapshot kontrak](05-UI-SPB-DAN-SNAPSHOT-KONTRAK.md)
6. [06 — Billing, payroll, Bonus Ayak, dan laporan](06-INTEGRASI-BILLING-PAYROLL-DAN-LAPORAN.md)
7. [07 — Migrasi data lama dan backfill bertahap](07-MIGRASI-DATA-LAMA-DAN-BACKFILL.md)
8. [08 — Uji browser dan regresi](08-UJI-BROWSER-DAN-REGRESI.md)
9. [09 — Verifikasi akhir dan handoff](09-VERIFIKASI-AKHIR-DAN-HANDOFF.md)

## Aturan bersama

- Baca `AGENTS.md`, root repository, `git status`, hasil tahap terkait, dan gunakan Graphify sebelum menelusuri kode. Jangan membaca seluruh paket bila tahap aktif sudah cukup jelas.
- Gunakan aplikasi lokal dan `agent-browser` untuk semua journey user. Data lokal boleh dibuat, diedit, dihapus, diimport, diverifikasi, ditagihkan, dan diposting bila memang diperlukan oleh skenario. Jangan menjalankan alur itu di production.
- SQL write, controller/service call, HTTP langsung, dan JavaScript yang melewati UI dilarang sebagai pengganti tindakan user. Database read-only, log, network, dan test otomatis hanya bukti tambahan.
- Tandai fixture dengan `CL-KONTRAK-<run-id>`, catat business, user, ID, relasi, status awal/akhir, serta hasil setelah reload.
- Jangan menghapus atau membuka kunci transaksi posted untuk cleanup. Bersihkan data turunan lebih dahulu melalui UI; data yang dipertahankan untuk handoff harus dilaporkan.
- Screenshot raw harus berasal dari browser. Anotasi wajib diproses ulang dengan Python/Pillow menjadi PNG baru; jangan gunakan SVG atau overlay HTML. Simpan raw, annotated, perintah, ukuran, alt text, dan tautan.
- Status hasil hanya `LULUS`, `GAGAL`, atau `BELUM DIUJI`. Membuka halaman tidak membuktikan relasi, nominal, isolasi business, atau keamanan.
- Setelah perubahan kode jalankan pemeriksaan relevan, publish JavaScript module bila berubah, dan `graphify update .`. Jangan melakukan deployment otomatis.

## Kontrak hasil

Setiap tahap membuat `hasil/<ID>-HASIL.md` di folder `Revisi-02/hasil/` saat tahap dijalankan. Isi minimal: status, cakupan, file/rute aktual, tindakan browser, expected/actual, ID dan business, hasil reload, bukti raw/annotated, pemeriksaan tambahan, masalah, cleanup, dan handoff satu tahap berikutnya.
