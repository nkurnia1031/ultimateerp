# Hasil 02L — Uji Konkurensi Sumber Billing

## Status

**LULUS untuk satu SPB yang diperebutkan oleh Invoice Project dan Sell.** Dua sesi admin menyiapkan form berbeda untuk SPB yang sama lalu menekan tombol simpan secara bersamaan. Hanya satu billing tercatat; jalur lainnya tidak menghasilkan transaksi Sell kedua.

Tanggal uji: 10 September 2026  
Run: `RUN02L-20260910`  
Business: **CV BM TRANSPORT** (`business_id=2`)  
Sumber yang diperebutkan: SPB ID `1`, `TRIP-202609-0001`, status awal `Verified unbilled`  
Project: ID `11`, `RUN02A Project Angkutan`

## Tindakan browser

Semua tindakan dilakukan melalui UI dengan dua sesi `agent-browser` yang login sebagai akun admin lokal business 2:

1. Sesi `changelog02i` membuka Project 11, tab **Trip Angkutan**, lalu menekan **Pilih SPB untuk Invoice**.
2. Sesi tersebut memilih SPB 1, melanjutkan ke form Invoice Project, memilih lokasi `CV BM TRANSPORT (BL0001)`, dan berhenti pada form yang siap disimpan.
3. Sesi `changelog02l_sell` membuka **Asset Management → SPB Angkutan**, memilih SPB 1, menekan **Sell dari yang dipilih**, memilih produk jasa ID `3069` dan lokasi ID `2`, lalu berhenti pada form Sell yang siap disimpan.
4. Dua tombol simpan ditekan sedekat mungkin melalui dua proses browser paralel:
   - **Buat Invoice Project**;
   - **Buat Sell**.
5. Kedua sesi direload ke daftar SPB dan detail SPB 1 dibuka kembali.

Bukti form sebelum submit:

- [Form Invoice Project siap disimpan](../../../hasil/screenshots/02L-project-invoice-prepared.png)
- [Form Sell siap disimpan](../../../hasil/screenshots/02L-sell-prepared.png)

## Expected dan actual

| Pemeriksaan | Expected | Actual | Status |
|---|---|---|---|
| Dua form memakai SPB 1 yang sama | Sumber yang sama dapat diuji secara bersamaan | Kedua form menampilkan `TRIP-202609-0001`, 21 ton, Rp115.000/ton, total Rp2.515.000 | Lulus |
| Billing ganda | Tepat satu jalur berhasil | Invoice Project berhasil; Sell tidak menghasilkan transaksi kedua | Lulus |
| Daftar SPB sesudah reload | SPB berubah menjadi billed dan tidak dapat dipilih lagi | Kedua sesi menampilkan SPB 1 sebagai `Verified billed` tanpa checkbox pemilihan | Lulus |
| Detail sumber | Menunjukkan satu transaksi billing | Detail menampilkan `Sudah ditagihkan ke transaksi #54292 (project_invoice)` | Lulus |
| Jalur Sell yang kalah | Ditolak tanpa orphan billing | Tidak ada Sell baru; daftar tetap menampilkan satu billing Project | Lulus pada data, feedback jalur kalah tidak informatif |

Bukti sesudah submit:

- [Daftar sesudah race dari sesi Project](../../../hasil/screenshots/02L-after-project-invoice-race.png)
- [Daftar sesudah race dari sesi Sell](../../../hasil/screenshots/02L-after-sell-race.png)
- [Detail sumber sesudah race](../../../hasil/screenshots/02L-source-after-race.png)

## Rekonsiliasi read-only

Pemeriksaan database hanya digunakan setelah journey browser selesai:

- `heavy_equipment_trip_billings` untuk `trip_id=1` berisi tepat satu baris: billing ID `3`, transaction ID `54292`, tipe `project_invoice`, amount `2515000.0000`.
- Transaction `54292` adalah Invoice Project draft yang dibuat dari Project 11.
- Tidak ada billing link tipe `sell` untuk SPB 1.
- Detail UI mencocokkan nilai sumber: 21 ton × Rp115.000 = Rp2.515.000.

Tidak ada SQL write, penghapusan link, atau pemanggilan service/controller untuk memenangkan salah satu jalur.

## Temuan UX

Data dan constraint mencegah billing ganda. Namun, sesi Sell yang kalah kembali ke daftar tanpa pesan yang terlihat pada hasil browser race. Pengguna masih dapat memahami sumber sudah `Verified billed`, tetapi tidak mendapat penjelasan langsung bahwa penyimpanannya ditolak karena sesi lain lebih dulu menagihkan.

Temuan ini tidak mengubah hasil keamanan data, tetapi sebaiknya menjadi tugas perbaikan UX terpisah: tampilkan pesan seperti `SPB sudah ditagihkan oleh proses lain; form tidak disimpan` ketika transaksi gagal karena sumber sudah terkunci.

## Cleanup dan handoff

Invoice Project draft `54292` dan billing link SPB 1 dipertahankan sebagai fixture handoff. SPB 1 sebelumnya sudah masuk settlement posted `SET-202609-0003`, sehingga tidak dibuka kuncinya dan tidak dihapus. Tidak ada Sell kedua yang perlu dibersihkan.

Jangan memakai SPB 1 untuk pengujian billing berikutnya. Gunakan fixture baru yang verified dan unbilled.

## Kriteria tahap

| Kriteria | Hasil |
|---|---|
| Dua sesi/browser benar-benar menekan tombol UI | Lulus |
| Tidak ada dua billing untuk satu SPB | Lulus |
| Tidak ada orphan Sell | Lulus berdasarkan billing link dan transaksi |
| Status sumber tetap benar setelah reload | Lulus |
| Pesan jalur kalah mudah dipahami | Belum ideal; temuan UX dicatat |
