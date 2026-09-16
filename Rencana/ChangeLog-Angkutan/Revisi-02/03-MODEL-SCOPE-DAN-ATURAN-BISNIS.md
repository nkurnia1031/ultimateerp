# 03 — Model, scope, dan aturan business

## Tujuan

Membuat relasi aplikasi yang konsisten dan mencegah kontrak, Project, customer, SPB, proposal, dokumen, serta sumber payroll tercampur antar business.

## Prasyarat

- Tahap 02 lulus.
- Baca model dan controller aktual, terutama `SuratKontrak`, `Project`, `HeavyEquipmentTrip`, `HeavyEquipmentTripBilling`, `Transaction`, `BonusAyak`, settlement, `Contact`, `User`, dan `DocumentAndNote`.
- Gunakan Graphify untuk memastikan tidak membuat model paralel yang sudah tersedia.

## Langkah kerja

1. Tambahkan relasi `SuratKontrak`: `business`, `contact/customer`, `project`, `proposal`, `createdBy`, `approvedBy`, detail scope/rate, `documentsAndnote`, dan `trips`.
2. Tambahkan relasi balik: Project → contracts; SPB → contract; asset/driver/Bonus/settlement/transaction memperoleh kontrak melalui SPB atau billing yang sudah ada.
3. Sediakan query turunan yang tidak mengklaim satu kontrak tunggal bila satu invoice atau payroll berisi banyak SPB. Gunakan collection/query contracts atau billings yang distinct.
4. Semua query dan selector harus memakai business aktif. Validasi ulang `project.business_id`, `contact.business_id`, `proposal.business_id`, `trip.business_id`, dan detail scope.
5. Validasi konsistensi: contract customer sama dengan Project customer bila keduanya terisi; contract project sama dengan trip project; tanggal trip berada pada periode kontrak bila tanggal tersedia; route termasuk scope bila scope digunakan.
6. Terapkan aturan nullable status: null hanya label belum ditentukan; status active/expired/cancelled tidak boleh diperlakukan sama. Jangan blokir data lama hanya karena status null.
7. Kontrak active/within-period boleh dipilih untuk SPB baru. Kontrak null boleh dipilih hanya sesuai kebijakan migrasi dan harus menampilkan peringatan; kontrak expired/cancelled tidak boleh dipakai untuk SPB baru tanpa override beralasan.
8. Kontrak atau SPB yang sudah menjadi invoice/payroll tidak boleh diedit pada field yang mengubah histori. Perubahan memakai addendum/version atau koreksi yang jelas.
9. Dokumen dan catatan mengikuti permission serta business scope yang sama. Jangan menerima `notable_type`/ID tanpa memeriksa model dan business.
10. Tambahkan test otomatis untuk cross-business, mismatch customer/project, null status, expired contract, duplicate contract-trip, dan histori snapshot.

## Hasil wajib

- Model, relationship, scope/policy/request rule, dan test yang berubah.
- Matriks aturan validasi di `hasil/03-HASIL.md`.
- Contoh query read-only yang menunjukkan contract → SPB → billing/payroll/asset/Bonus.

## Kriteria lulus

- Relasi dapat di-eager-load tanpa N+1 kritis.
- Record business lain tidak muncul atau dapat dipakai.
- Kontrak null status tetap dapat dilihat dan dimigrasikan.
- Satu invoice/payroll multi-SPB tetap menampilkan semua kontrak terkait tanpa data ganda.

## Catatan tahap berikutnya

Tahap 04 membangun UI header kontrak dan Project. Jangan menambahkan input sopir/unit/payroll ke form kontrak; field tersebut milik SPB.

