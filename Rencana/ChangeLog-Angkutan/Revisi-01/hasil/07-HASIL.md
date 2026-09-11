# Hasil 07 — Katalog dan route Panduan

Status: **LULUS**.

- Route aktual: `GET /panduan` dan `GET /panduan/{slug}`, keduanya login-protected. Katalog terkurasi ada di `config/guides.php`; controller memakai filter permission dan business yang sedang aktif.
- Browser menampilkan 12 kartu, pencarian, kategori, thumbnail dan link artikel. Slug `slug-tidak-dikenal` menghasilkan 404 `NOT FOUND`.
- Route Change Log lama tetap dipertahankan. Link tindakan tetap mengikuti permission endpoint tujuan.
- Handoff: gunakan katalog ini sebagai satu sumber saat mengubah artikel; jangan membuat konten CMS atau URL file privat.
