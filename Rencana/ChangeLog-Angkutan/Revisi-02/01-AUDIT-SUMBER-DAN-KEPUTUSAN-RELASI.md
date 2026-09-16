# 01 — Audit sumber dan keputusan relasi

## Tujuan

Memastikan implementasi mengikuti makna bisnis Surat Kontrak dan tidak mencampurkan dokumen kontrak dengan jurnal perjalanan. Tahap ini audit saja; jangan membuat migration, fixture, atau mengubah data.

## Prasyarat

- Baca [00 — Peta relasi kontrak](00-MULAI-DAN-PETA-TAHAP.md).
- Baca `AGENTS.md` dan jalankan `git status --short` tanpa menghapus perubahan pengguna.
- Jalankan query Graphify yang fokus pada `SuratKontrak`, `Project`, `HeavyEquipmentTrip`, `Transaction`, `BonusAyak`, settlement, dan `DocumentAndNote`.
- Baca hasil audit sebelumnya hanya jika diperlukan; jangan menganggap hasil lama sebagai kondisi terkini.

## Langkah kerja

1. Periksa migration, model, controller, route, view, import, dan permission Surat Kontrak.
2. Periksa struktur file sumber cangkang yang tersedia. Catat header seperti customer, kapal, tujuan, nomor tiket, kendaraan, tanggal, gross, tare, netto, harga, total, dan nama sopir.
3. Petakan makna setiap field: identitas kontrak, ruang lingkup, rencana tonase, tanggal berlaku, pelaksanaan aktual, atau nilai billing.
4. Verifikasi cardinality: satu kontrak ke banyak Project/SPB atau satu Project ke banyak kontrak. Jangan menyimpulkan one-to-one hanya dari form saat ini.
5. Telusuri jalur billing dan payroll. Catat bahwa invoice memakai `HeavyEquipmentTripBilling`, sedangkan payroll memakai settlement dan sumber SPB/Bonus/Kasbon.
6. Audit risiko: mismatch customer/project/business, kontrak expired, harga kontrak berbeda dari master tarif, klaim/potongan yang tidak tercermin pada line invoice, delete customer cascade, nomor kontrak, serta transaksi lama tanpa status.
7. Buat matriks relasi berisi: sumber, tujuan, kardinalitas, FK/pivot/derived query, nullable, validasi, dampak UI, dan keputusan `implementasikan sekarang` atau `fase berikutnya`.

## Hasil wajib

- `hasil/01-HASIL.md` dengan matriks relasi dan keputusan final.
- Daftar field legacy yang dipertahankan sebagai snapshot.
- Daftar relasi yang harus direct FK, pivot, polymorphic, atau relasi turunan melalui SPB.
- Daftar gap implementasi dan risiko yang menjadi input tahap 02–06.

## Kriteria lulus

- Makna kontrak dan SPB dibedakan jelas.
- Semua relasi pada dokumen 00 punya bukti source atau diberi label perlu dikonfirmasi.
- Tidak ada rekomendasi foreign key tunggal untuk invoice/payroll yang sebenarnya dapat memiliki banyak SPB.
- Status nullable dan jalur data lama tercatat eksplisit.

## Catatan tahap berikutnya

Tahap 02 hanya mengubah skema berdasarkan matriks yang sudah disetujui. Jika audit menemukan kontrak multi-rute atau multi-harga, gunakan tabel detail; jangan menambah kolom header berulang.

