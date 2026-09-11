# 06-UI-IMPORT-EKSPOR — Perbaiki dan uji import/export Bonus Ayak

## Tujuan dan prasyarat

Perbaiki dan uji import/export Bonus Ayak. Prasyarat: 05.
Baca [aturan, bukti dan handoff](00-MULAI-DAN-PETA-TAHAP.md). Status awal: BELUM DIJALANKAN.

## Batas kerja

Kerjakan hanya halaman/skenario pada judul. Jangan menulis semua artikel atau menjalankan tahap berikutnya. Baca hasil prasyarat dan source yang relevan saja.

## Langkah

1. Audit apakah import dan preview tersedia melalui UI. Bila belum, catat gap dan implementasikan jalur minimal dalam cakupan Bonus; jangan gunakan backend untuk menggantikan interaksi.
2. Rapikan upload, template, preview, mapping sopir/unit, error per baris, konfirmasi dan hasil import sesuai kontrak backend yang ada.
3. Upload contoh kecil melalui agent-browser, cek unmatched dan duplikat, konfirmasi hanya baris valid, reload daftar.
4. Gunakan tombol export Excel/PDF; simpan file hasil download browser, buka/periksa isi, cakupan business dan rentang filter. HTTP 200 saja tidak cukup.
5. Uji permission dan state gagal; jangan mengubah sistem import modul lain.

## Hasil wajib

Bukti import UI dan file export nyata, hasil per baris dan rekap. Simpan `hasil/06-HASIL.md` memakai kontrak 00.

## Kriteria lulus

Import/export dapat dijalankan dari layar Bonus dan artefaknya sesuai data yang dipilih. Bukti browser mencatat tindakan, sebelum/sesudah, reload dan ID bila ada transaksi. Pemeriksaan backend hanya pelengkap.

## Handoff

Catat label/URL/file final, temuan tersisa dan fixture yang dipertahankan; tandai dependensi siap atau terblokir pada [peta tahap](00-MULAI-DAN-PETA-TAHAP.md). Berhenti.

