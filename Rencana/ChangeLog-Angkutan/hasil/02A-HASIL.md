# Hasil 02A — Sesi dan master uji

## Ringkasan handoff

- **Status:** LULUS dengan catatan perbaikan validasi form Customer.
- **Run-id:** `RUN02A-20260910`.
- **Lingkungan:** lokal, URL `http://localhost/2025/ultimateerp/public/`.
- **Sesi browser:** `changelog02a3`, dijalankan dengan `agent-browser --debug --no-auto-dialog`.
- **Viewport:** 1264×625, device pixel ratio 1.
- **Business aktif:** `CV BM TRANSPORT`; lokasi yang dipakai `CV BM TRANSPORT (BL0001)`, ID 2.
- **Fixture yang dipertahankan:** asset 39, driver/user 93, customer 1802, Project 11, produk jasa 3069.
- **Yang dibuktikan:** create, edit, reload, daftar, dan buka detail untuk master yang tersedia melalui browser.
- **Yang dibersihkan:** asset 40, user 94, customer 1803, Project 12, dan produk 3070 melalui UI setelah uji hapus-batal dan hapus-konfirmasi.
- **Belum diuji pada bagian ini:** import/preview/aktivasi Tarif Angkutan, input SPB, invoice, Sell, Bonus Ayak, dan Payroll.
- **Handoff:** lanjutkan hanya ke [02B — Tarif Angkutan](../02B-UJI-IMPORT-DAN-HISTORI-TARIF.md).

## Prasyarat dan aturan yang diterapkan

Saya membaca [petunjuk pelaksanaan](../00-PETUNJUK-PELAKSANAAN.md), [audit master dan tarif](../01A-AUDIT-MASTER-DAN-TARIF.md), serta hasil [01A](01A-HASIL.md). Semua create/edit/delete pada bagian ini dilakukan melalui interaksi UI `agent-browser`. Tidak ada SQL write, pemanggilan service/controller, HTTP langsung, atau JavaScript penguji yang memanggil API.

Data fixture diberi penanda `RUN02A`. Kredensial lokal tidak dicatat di laporan. Business aktif dan ID objek dicatat agar tahap berikutnya tidak memakai data yang salah.

## Bukti journey browser

| ID | Tindakan pengguna melalui browser | Objek dan business | Expected | Actual sebelum/sesudah/reload | Status | Bukti |
|---|---|---|---|---|---|---|
| 02A-01 | Membuka menu Asset Management → Assets, mengisi form unit, menyimpan, mencari hasil, membuka detail, mengedit nama, menyimpan, lalu reload | Asset **39**, `RUN02A-20260910-UNIT`, business `CV BM TRANSPORT` | Unit tersimpan dan perubahan bertahan | Baris muncul di daftar. Nama akhir `RUN02A Unit Angkutan Revised` tetap ada setelah reload. Detail asset dapat dibuka. | LULUS | [02A-assets-final.png](screenshots/02A-assets-final.png) |
| 02A-02 | Membuat asset sementara, membuka menu hapus, membatalkan dialog, reload, membuka dialog lagi, mengonfirmasi hapus, reload | Asset **40** sementara, business `CV BM TRANSPORT` | Batal tidak menghapus; konfirmasi menghapus hanya fixture | Setelah batal baris tetap ada. Setelah konfirmasi dan reload asset 40 tidak lagi muncul. Asset 39 tetap ada. | LULUS | Screenshot internal sesi browser |
| 02A-03 | Membuka Users → membuat driver, mengedit, menyimpan, mencari, dan reload | User/driver **93**, username `run02a_driver`, business aktif `CV BM TRANSPORT` | Driver dapat dipakai sebagai sumber pilihan SPB | Nama akhir `RUN02A Driver Revised`, role Driver, dan email uji tetap tampil setelah reload. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-04 | Membuat user sementara, mencoba hapus dengan batal, kemudian mengonfirmasi hapus dan reload | User **94** sementara | Dialog batal mempertahankan user; konfirmasi menghapus | User tetap ada setelah batal dan hilang setelah konfirmasi/reload. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-05 | Membuka Contacts → Customers, mengisi form customer dari UI, menyimpan, mencari hasil, mengedit, menyimpan, reload, dan membuka View | Customer **1802**, kode `CO0103`, business `CV BM TRANSPORT` | Customer tersimpan dan detail dapat dibuka untuk Project/SPB | Customer awal tersimpan; nama akhir `RUN02A Customer Demo Revised`, mobile `081200000291`, dan detail View terbuka setelah reload. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-06 | Membuat customer sementara, membuka dialog hapus, membatalkan, reload, lalu mengonfirmasi hapus dan reload | Customer **1803** sementara | Batal tidak menghapus; konfirmasi menghapus fixture | Customer tetap ada setelah batal dan hilang setelah konfirmasi/reload. Customer 1802 tetap ada. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-07 | Membuka Project, membuat project dengan customer, status, lead, user, dan kategori melalui form UI, membuka detail, mengedit Settings, reload | Project **11**, business `CV BM TRANSPORT`, customer 1802, driver/user 93 | Project tersedia sebagai konteks pembuatan SPB | Project `RUN02A Project Angkutan` tersimpan dengan status `in_progress`; detail menampilkan `Trip Angkutan (0)`. Prefix task berubah dari `#` menjadi `RUN02A-` dan tetap setelah reload. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-08 | Membuat Project sementara, menguji hapus-batal, mengonfirmasi hapus, lalu reload | Project **12** sementara | Project tetap setelah batal dan hilang setelah konfirmasi | Hasil sesuai; Project 11 tetap tersedia. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-09 | Membuka Products, membuat produk jasa, mengedit harga/nama, menyimpan, mencari, reload, dan membuka View | Produk **3069**, SKU `RUN02A-SPB`, business `CV BM TRANSPORT` | Produk jasa dapat dipilih ketika SPB dijual/tagih | Nama akhir `RUN02A Jasa Angkutan SPB Revised`, unit Ton, selling price 120.000, dan detail modal View terbuka. | LULUS | Bukti DOM/snapshot sesi browser |
| 02A-10 | Membuat produk sementara, menguji hapus-batal, mengonfirmasi hapus, lalu reload | Produk **3070** sementara | Batal tidak menghapus; konfirmasi menghapus fixture | Hasil sesuai; produk 3069 tetap ada. | LULUS | Bukti DOM/snapshot sesi browser |

## Temuan dan perbaikan yang dilakukan

Pada percobaan pertama, tombol Save Customer tidak menutup modal atau menyimpan data walaupun nama dan nomor telepon sudah diisi. Pemeriksaan UI read-only terhadap `:invalid` menunjukkan field kontak-person opsional yang tersembunyi masih diberi `required`, termasuk username/password dan lead tersembunyi. Ini merupakan blocker langsung untuk membuat customer sebagai prasyarat SPB.

Perbaikan yang dilakukan:

- [contact_login_from.blade.php](../../../Modules/Crm/Resources/views/contact_login/partial/contact_login_from.blade.php) hanya mewajibkan identitas kontak-person pada baris yang benar-benar sedang dibuat.
- [contact/create.blade.php](../../../resources/views/contact/create.blade.php) tidak lagi memberi `required` tanpa syarat pada field individual dan lead tersembunyi.
- [app.js](../../../public/js/app.js) menyelaraskan `required` berdasarkan jenis kontak yang dipilih pada modal, sehingga validasi native browser mengikuti pilihan pengguna.

Setelah perbaikan, `:invalid` pada modal customer kosong, Save berhasil, dan customer 1802 dapat dibuat lalu diverifikasi melalui reload. Perubahan ini menyentuh validasi form CRM sebagai prasyarat umum; tidak ada perubahan pada nominal atau relasi transaksi angkutan.

## Data uji dan keputusan cleanup

| Data | ID | Status | Alasan |
|---|---:|---|---|
| Asset angkutan | 39 | Dipertahankan | Dipakai untuk mapping Tarif/SPB tahap 02B dan seterusnya. |
| User/driver | 93 | Dipertahankan | Dipakai untuk SPB dan settlement. |
| Customer | 1802 | Dipertahankan | Dipakai oleh Project dan invoice/Sell uji. |
| Project | 11 | Dipertahankan | Dipakai untuk pembuatan SPB dari Project. |
| Produk jasa | 3069 | Dipertahankan | Dipakai untuk Sell angkutan dari SPB. |
| Asset probe, user probe, customer probe, Project probe, product probe | 40, 94, 1803, 12, 3070 | Dibersihkan melalui UI | Tidak diperlukan penerus dan sudah diverifikasi hilang setelah reload. |

Lokasi business tidak dibuat karena lokasi `CV BM TRANSPORT (BL0001)` sudah tersedia dan dipilih melalui UI. Tidak ada transaksi operasional, invoice, payroll, atau import workbook yang dibuat pada bagian ini.

## Pemeriksaan tambahan

- URL akhir navigasi user detail terbukti `/users/93`.
- Customer detail terbukti melalui menu action → View pada `/contacts/1802`.
- Produk detail terbukti melalui menu action → View; aplikasi menampilkan modal detail produk `RUN02A Jasa Angkutan SPB Revised`.
- Asset, customer, project, dan produk utama dicari kembali setelah reload; fixture tetap tampil.
- Dialog hapus diuji pada cabang batal dan konfirmasi. Tidak ada fixture utama yang terhapus.
- Tidak ada pemeriksaan database write. Pemeriksaan DOM dan halaman hanya digunakan sebagai bukti tambahan dari hasil tindakan UI.
- Graphify CLI tidak tersedia pada PATH dan interpreter yang dirujuk `graphify-out/.graphify_python` juga tidak ada; ini dicatat sebagai blocker pemeriksaan Graphify, bukan dianggap sukses.

### Perintah setelah perubahan kode

| Pemeriksaan | Hasil | Catatan |
|---|---|---|
| `node --check public/js/app.js` | LULUS, exit code 0 | JavaScript aplikasi valid. Tidak ada JavaScript module di `Modules/<Module>/Resources/assets/js/` yang berubah, sehingga publish module tidak diperlukan pada bagian ini. |
| `php artisan migrate --pretend --no-interaction` | GAGAL, exit code 1 | Bootstrap Laravel berhenti sebelum pemeriksaan migration karena PHP CLI tidak memuat ekstensi cURL; Pusher melempar `PusherException`. Tidak ada migration yang dijalankan. |
| `php artisan view:cache --no-interaction` | GAGAL, exit code 1 | Penyebab sama: bootstrap Laravel berhenti pada Pusher/cURL. |
| `php artisan route:cache --no-interaction` | GAGAL, exit code 1 | Penyebab sama: bootstrap Laravel berhenti pada Pusher/cURL. |
| `graphify update .` | GAGAL, exit code 1 | Perintah `graphify` tidak tersedia pada PATH; interpreter fallback yang dirujuk konfigurasi juga tidak tersedia. |

Kegagalan pemeriksaan CLI di atas adalah masalah lingkungan lokal yang berulang dari audit 01B, bukan hasil sukses yang disamarkan. Journey browser tetap berhasil karena dijalankan melalui aplikasi HTTP lokal. Pemeriksaan CLI perlu diulang setelah ekstensi cURL PHP CLI tersedia sebelum verifikasi akhir.

## Masalah terbuka

1. Tarif Angkutan belum di-upload atau diaktifkan; wajib ditangani di 02B.
2. Akun pembanding/business kedua belum dibuat karena bagian ini hanya mempertahankan fixture yang diperlukan untuk journey utama. Isolasi business/permission wajib diuji di 02M.
3. Pemeriksaan cache/lint setelah perbaikan kode perlu dijalankan sebelum handoff final. Perintah dan hasilnya menjadi bagian pemeriksaan sesi ini, bukan pengganti journey browser.

## Handoff konkret

Baca hasil ini dan jalankan [02B — Uji Tarif Angkutan](../02B-UJI-IMPORT-DAN-HISTORI-TARIF.md) saja. Gunakan asset 39 dan business/location yang tercatat. Uji upload workbook tarif melalui UI, mapping unit ke asset 39, preview/duplikasi, konfirmasi, dan histori. Jangan membuat ulang fixture atau melanjutkan SPB sebelum 02B menyimpan hasilnya.
