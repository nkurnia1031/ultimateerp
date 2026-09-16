# 08 — Uji browser dan regresi relasi kontrak

## Tujuan

Membuktikan bahwa user dapat menjalankan alur kontrak sampai Project, SPB, invoice/Sell, Bonus Ayak, payroll, dan laporan melalui UI nyata. Pengujian bukan simulasi backend.

## Prasyarat

- Tahap implementasi yang diuji memiliki hasil LULUS.
- Gunakan URL lokal dan sesi khusus `agent-browser`; baca aturan browser pada paket ChangeLog utama.
- Buat fixture melalui UI dengan run-id `CL-KONTRAK-<run-id>`. Catat ID dan business pemiliknya.

## Journey wajib

1. Buat kontrak tanpa status → simpan → daftar → detail → reload.
2. Edit kontrak draft/null status → simpan → reload → pastikan relasi Project/customer bertahan.
3. Buat Project → buat kontrak dari Project → buat SPB dari Project → pilih kontrak → selesaikan → verifikasi.
4. Buat beberapa SPB pada satu kontrak; pastikan ringkasan kontrak, Project, unit, dan sopir sesuai.
5. Buat invoice Project dan Sell dari SPB verified; cek nomor, line, tonase, harga, kontrak, dan status billed.
6. Buat Bonus Ayak dari sumber SPB/manual dan settlement payroll; pastikan kontrak hanya menjadi referensi dan Bonus tidak menjadi line invoice.
7. Batalkan settlement draft; pastikan SPB/Bonus/Kasbon kembali tersedia. Uji posting dan penguncian tanpa membuka posted.
8. Buka laporan kontrak/Project/asset/driver/customer ledger; cocokkan angka dengan detail transaksi.
9. Uji kontrak null status dan SPB tanpa kontrak tetap dapat dipahami.

## Uji negatif dan visual

- Cross-business pada daftar, selector, URL langsung, Project, SPB, invoice, payroll, dan dokumen.
- Permission tidak cukup; kontrak expired/cancelled; mismatch customer/project/route; tarif kosong; duplicate submit; dua tab billing/payroll; edit/delete locked.
- Desktop minimal 1280/1440 px dan mobile 390 px: label, padding, tombol, tabel responsive, overflow, loading, error, kembali/batal, focus keyboard, tooltip, aria-label, dan reduced motion.
- Screenshot raw dari browser, kemudian anotasi memakai Python/Pillow. Tidak menggunakan SVG, overlay HTML, SQL write, service/controller, HTTP/API langsung, atau JS bypass UI.

## Hasil wajib

- `hasil/08-HASIL.md` dengan tabel tindakan user, expected/actual, reload, ID, business, screenshot, dan status.
- Bukti database/network/log hanya sebagai lampiran terpisah.
- Daftar bug dan perbaikan yang benar-benar diuji ulang.

## Kriteria lulus

- Semua journey transaksi berhasil atau gap dicatat sebagai GAGAL/BELUM DIUJI.
- Tidak ada klaim bahwa relasi aman hanya karena halaman dapat dibuka.
- Tidak ada duplikasi billing/payroll dan tidak ada kebocoran business.

## Catatan tahap berikutnya

Tahap 09 hanya menyusun verifikasi dan handoff setelah bukti tahap 08 lengkap. Artikel Change Log tidak boleh menjanjikan perilaku yang belum LULUS.

