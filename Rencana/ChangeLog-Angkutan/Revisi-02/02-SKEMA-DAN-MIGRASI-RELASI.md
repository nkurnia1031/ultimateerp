# 02 — Skema dan migrasi relasi

## Tujuan

Menyediakan seluruh struktur relasi secara additive dan aman untuk data lama. `status` harus opsional dan tidak boleh memaksa migrasi selesai dalam satu deployment.

## Prasyarat

- `hasil/01-HASIL.md` berstatus LULUS atau keputusan yang dibutuhkan sudah jelas.
- Pastikan root dan koneksi database yang aktif benar; jangan mencetak rahasia.
- Pastikan ada backup lokal dan migration dapat diuji dengan `migrate --pretend`.

## Langkah kerja

1. Tambahkan migration idempotent untuk `surat_kontrak.project_id` nullable dengan index dan FK ke `pjt_projects`.
2. Tambahkan `heavy_equipment_trips.surat_kontrak_id` nullable dengan index dan FK ke `surat_kontrak`. Trip lama tetap valid tanpa kontrak.
3. Tambahkan `proposal_id` nullable dan FK yang tidak menghapus kontrak ketika proposal dihapus. Relasi tersedia sejak awal walaupun tidak semua kontrak berasal dari proposal.
4. Tambahkan kolom audit nullable seperti `created_by`, `approved_by`, `approved_at`, `updated_by` bila belum tersedia. Jangan mengisi ID dengan tebakan.
5. Tambahkan `status` nullable. Jangan memberi default aktif pada baris lama. Tetapkan label null sebagai `Belum ditentukan`.
6. Buat tabel `surat_kontrak_scopes` sejak awal yang menyimpan `surat_kontrak_id`, `route_id`/scope, cargo bila ada, referensi master rate bila digunakan, harga customer, tarif klaim, periode, dan catatan. Detail boleh kosong untuk kontrak legacy/simple; semua FK nullable hanya bila legacy memang dapat kosong.
7. Jangan membuat `invoice_id`, `sell_id`, `payroll_id`, `driver_id`, atau `asset_id` langsung pada header kontrak. Relasi tersebut melalui SPB dan tabel existing.
8. Tinjau FK `contact_id`; dokumen legal tidak boleh hilang hanya karena Contact dihapus. Gunakan restrict/soft-delete sesuai kemampuan database dan catat dampaknya.
9. Tambahkan unique/index yang sesuai dengan business, contract number, project, trip, dan periode. Jangan mengubah nomor lama tanpa rencana kompatibilitas.
10. Jalankan `php artisan migrate --pretend --no-interaction`, lalu test migration pada database lokal. Jangan menjalankan migration production pada tahap ini.

## Hasil wajib

- File migration yang additive/idempotent.
- Diagram tabel sebelum/sesudah.
- `hasil/02-HASIL.md` berisi SQL schema read-only sebelum/sesudah, hasil pretend, dan daftar data yang tidak disentuh.
- Keputusan on-delete dan alasan keamanan histori.

## Kriteria lulus

- Migration dapat diserialisasi dan tidak menghapus data.
- Semua kolom relasi baru nullable sesuai keputusan.
- `status` nullable; data lama tidak berubah menjadi active secara diam-diam.
- Constraint mencegah relasi lintas business melalui validasi tahap berikutnya.

## Catatan tahap berikutnya

Jangan membuat selector UI sebelum model dan scope pada tahap 03 memastikan record target berasal dari business aktif.
