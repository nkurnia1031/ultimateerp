# Hasil 02K — Uji Isolasi Akses

## Status

**BLOCKED — bukti isolasi business dan penolakan permission sudah tersedia, tetapi matriks tiga role khusus dan request keamanan tambahan belum lengkap.** Jangan memakai tahap ini sebagai klaim bahwa semua kombinasi permission sudah lulus.

Tanggal uji: 10 September 2026  
Run: `RUN02K-20260910`  
Business utama: **CV BM TRANSPORT** (`business_id=2`)  
Business pembanding: **RUN02K Business Isolation** (`business_id=6`)  
Pemilik business pembanding: user `95` (dibuat melalui halaman registrasi UI)

## Aturan yang dipatuhi

Semua pembuatan business, pembuatan user pembanding, login, navigasi, dan pemeriksaan akses dilakukan melalui `agent-browser` pada URL lokal. Tidak ada SQL write, pemanggilan controller/service, request HTTP manual, atau JavaScript yang memanggil API untuk menggantikan tindakan pengguna.

Pemeriksaan database hanya read-only untuk mencatat ID business/user. Password akun uji tidak ditulis ke repository.

## Fixture dan pembersihan

| Data | ID/business | Cara dibuat | Status akhir |
|---|---:|---|---|
| Business `RUN02K Business Isolation` | business 6 | UI `/business/register` | Dipertahankan sebagai fixture handoff; tidak ada tombol hapus business di UI yang ditemukan |
| Pemilik business pembanding | user 95 / business 6 | UI registrasi | Dipertahankan bersama business fixture |
| `RUN02K Permission User` role Driver | user 96 / business 2 | UI `/users/create` | Dihapus melalui tombol Hapus dan konfirmasi UI; filter sesudah reload menampilkan `No matching records found` |
| SPB business 2 yang dipakai sebagai target lintas business | SPB 1 / business 2 | Fixture tahap sebelumnya | Tidak diubah |

## Skenario browser yang lulus

### A. Isolasi business pada daftar dan detail SPB

Sesi `changelog02k_iso` login sebagai pemilik business 6.

1. Membuka halaman SPB melalui browser: `/asset/fleet/trips`.
2. Header menampilkan `RUN02K Business Isolation`.
3. Daftar menampilkan `Belum ada trip.` dan tidak menampilkan SPB business 2.
4. Membuka URL detail fixture business 2 `/asset/fleet/trips/1` melalui browser.

Expected: data SPB business 2 tidak muncul dan detail lintas business ditolak.  
Actual: daftar business 6 kosong; detail SPB 1 menghasilkan halaman `Not Found`.  
Status: **Lulus untuk isolasi daftar/detail SPB**.

Bukti:

- [Daftar SPB business 6 kosong](../../../hasil/screenshots/02K-business6-spb-empty.png)
- [Detail SPB business 2 ditolak](../../../hasil/screenshots/02K-business6-direct-spb-denied.png)

### B. Permission role Driver pada SPB, Bonus Ayak, dan settlement

Sesi `changelog02k_perm` login sebagai user `RUN02K Permission User` role `Driver` pada business 2. Sebelum dihapus, Home hanya menampilkan menu CRM, Project, HRM, Essentials, dan Catalogue QR; Asset Management tidak tersedia.

| Tindakan browser | Expected | Actual | Status |
|---|---|---|---|
| Membuka `/asset/fleet/trips` | Ditolak karena role tidak memiliki izin Asset | Halaman `Forbidden` | Lulus |
| Membuka `/bonus-ayak` | Ditolak karena role tidak memiliki izin Bonus Ayak | Halaman `Forbidden` | Lulus |
| Membuka `/asset/fleet/settlements` | Ditolak karena role tidak memiliki izin settlement | Halaman `Forbidden` | Lulus |
| Membuka `/hrm/payroll` | Hanya dapat melihat area Payroll yang memang diizinkan role | Halaman Payroll terbuka, tabel kosong, tidak ada data settlement angkutan | Lulus terbatas |

Bukti:

- [Role Driver ditolak pada SPB](../../../hasil/screenshots/02K-driver-spb-forbidden.png)
- [Role Driver ditolak pada Bonus Ayak](../../../hasil/screenshots/02K-driver-bonus-forbidden.png)

## Skenario yang belum selesai

| Skenario wajib | Status | Catatan |
|---|---|---|
| Selector Project/Customer/Asset/SPB/Bonus/Kasbon lintas business | Belum diuji penuh | Daftar/detail SPB sudah terbukti; selector lintas business belum dapat diuji karena business pembanding belum memiliki fixture angkutan |
| Role input-only | Belum diuji | Baru role Driver yang dibuat dan diuji |
| Role billing-only | Belum diuji | Belum ada role/akun khusus |
| Role payroll-only | Belum diuji | Belum ada role/akun khusus |
| Create/edit/delete/import/export/post berdasarkan tiga role | Belum diuji penuh | Tidak boleh disimpulkan dari hasil role Driver |
| Request dengan ID yang dimodifikasi | Belum diuji | Menjadi tes keamanan tambahan, bukan pengganti journey UI |
| Dua sesi melakukan posting bersamaan | Belum diuji pada tahap ini | Belum ada data draft baru yang aman untuk skenario tersebut |

## Temuan dan risiko

1. Isolasi business pada daftar dan detail SPB terverifikasi pada business pembanding yang dibuat melalui UI.
2. Middleware/permission menolak akses langsung role Driver ke SPB, Bonus Ayak, dan settlement.
3. Payroll umum tetap terbuka untuk role Driver, tetapi halaman tidak menampilkan settlement angkutan. Hal ini perlu dikonfirmasi sebagai desain permission, bukan otomatis dianggap bug.
4. Registrasi business tidak menyediakan jalur hapus yang terlihat di UI. Fixture business 6 dan user 95 sengaja dipertahankan sebagai handoff lokal; tidak berisi transaksi operasional pelanggan.

## Pemeriksaan tambahan read-only

Pemeriksaan read-only mencatat business 6, user 95, dan user 96. User 96 hilang dari daftar setelah penghapusan UI. Tidak dilakukan write database untuk membuat, menghapus, atau memindahkan data.

## Kriteria tahap

| Kriteria | Hasil |
|---|---|
| Business aktif teridentifikasi | Lulus |
| Sesi business kedua | Lulus |
| Daftar/detail SPB tidak bocor lintas business | Lulus untuk fixture SPB 1 |
| Permission tidak cukup ditolak | Lulus untuk role Driver pada SPB, Bonus, settlement |
| Input-only/billing-only/payroll-only | Belum diuji |
| Selector lintas business untuk semua objek | Belum diuji penuh |
| Modified request dan posting bersamaan | Belum diuji |
| Klaim keamanan penuh | Tidak diklaim |

## Handoff

Tahap berikutnya yang ingin menutup blocker harus memakai business 6 sebagai fixture lokal, lalu membuat melalui UI:

- satu akun input-only;
- satu akun billing-only;
- satu akun payroll-only;
- fixture Project/Customer/Asset/Route/Rate/SPB/Bonus/Kasbon pada business 6;
- dua sesi untuk percobaan posting bersamaan.

Setelah semua bukti tersedia, ulangi hanya baris yang berstatus `Belum diuji` dan perbarui file ini. Jangan membuka kunci settlement posted business 2 dan jangan memasukkan data uji ke transaksi pelanggan.
