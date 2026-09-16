# Runbook Deploy Production — Surat Kontrak dan Warna Template Kop

Dokumen ini adalah checklist perintah manual untuk deploy perubahan **Template Kop Surat Kontrak**, termasuk kemampuan mengatur warna komponen. Jalankan satu bagian per satu dan catat hasilnya.

Perintah di bawah adalah contoh untuk server Linux berbasis Git, PHP-FPM, dan Supervisor. Sesuaikan path, versi PHP, nama service, branch, dan URL dengan server production. Jangan menyalin `.env` lokal ke production dan jangan menaruh password database di dokumen ini.

## 0. Nilai yang wajib disesuaikan

Jalankan di server production sebagai user deployment:

```bash
export APP_DIR="/var/www/ultimateerp"
export RELEASE_BRANCH="main"
export PHP_BIN="/usr/bin/php8.2"
export COMPOSER_BIN="/usr/local/bin/composer"
export APP_URL="https://domain-production.example"
export PHP_FPM_SERVICE="php8.2-fpm"
export SUPERVISOR_WORKER_GROUP="ultimateerp-worker:*"
export BACKUP_DIR="/var/backups/ultimateerp"
```

Periksa kembali nilainya sebelum lanjut:

```bash
printf 'APP_DIR=%s\nBRANCH=%s\nPHP_BIN=%s\nAPP_URL=%s\n' "$APP_DIR" "$RELEASE_BRANCH" "$PHP_BIN" "$APP_URL"
test -d "$APP_DIR"
test -x "$PHP_BIN"
cd "$APP_DIR"
```

## 1. Pemeriksaan sebelum deploy

Pastikan deployment dilakukan pada release/commit yang benar. Jangan lanjut jika ada perubahan manual yang belum dipahami:

```bash
cd "$APP_DIR"
git status --short
git branch --show-current
git log -1 --oneline
git fetch origin "$RELEASE_BRANCH"
git diff --stat "HEAD..origin/$RELEASE_BRANCH"
```

Jika production memakai artifact atau panel hosting, lewati `git pull` pada tahap berikut dan pastikan artifact memuat file aplikasi versi yang sudah disetujui.

File utama perubahan warna yang harus ada pada release:

```text
Modules/Crm/Resources/views/surat_kontrak/template/form.blade.php
Modules/Crm/Services/SuratKontrakTemplateService.php
```

Jika fitur Template Kop Surat belum pernah dipasang di production, pastikan migration dan file fitur sebelumnya juga ikut dalam release, bukan hanya dua file di atas.

## 2. Backup database dan file upload

Buat backup sebelum `migrate`. Gunakan file konfigurasi MySQL yang sudah memiliki permission aman, misalnya `/etc/ultimateerp/mysql.cnf`, bukan password langsung pada command line.

```bash
sudo install -d -m 700 "$BACKUP_DIR"
export BACKUP_STAMP="$(date +%Y%m%d-%H%M%S)"
export DB_NAME="$(grep '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '\"')"
test -n "$DB_NAME"
sudo mysqldump \
  --defaults-extra-file=/etc/ultimateerp/mysql.cnf \
  --single-transaction --routines --triggers --hex-blob \
  "$DB_NAME" | gzip > "$BACKUP_DIR/${DB_NAME}-${BACKUP_STAMP}.sql.gz"
sudo test -s "$BACKUP_DIR/${DB_NAME}-${BACKUP_STAMP}.sql.gz"
```

Backup file template/logo dan upload yang terkait kontrak:

```bash
sudo tar -czf "$BACKUP_DIR/surat-kontrak-files-${BACKUP_STAMP}.tar.gz" \
  storage/app public/uploads/surat_kontrak_logos 2>/dev/null || true
sudo ls -lh "$BACKUP_DIR"/*"$BACKUP_STAMP"*
```

Simpan nama file backup pada laporan deploy. Jangan menghapus backup sebelum masa observasi selesai.

## 3. Aktifkan mode pemeliharaan

Lakukan pada waktu yang sudah disepakati. Secret maintenance harus diganti dengan nilai internal dan tidak ditulis pada tiket publik:

```bash
cd "$APP_DIR"
"$PHP_BIN" artisan down --render="errors::503" --secret="GANTI_DENGAN_SECRET_INTERNAL"
```

Jika mekanisme deploy memakai blue-green release tanpa downtime, tahap ini boleh diganti dengan prosedur switching release milik hosting.

## 4. Ambil kode release

Untuk deployment berbasis Git:

```bash
cd "$APP_DIR"
git pull --ff-only origin "$RELEASE_BRANCH"
git status --short
git log -1 --oneline
```

Install dependency production tanpa package development:

```bash
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Jangan menjalankan `composer update` pada production. Lock file harus berasal dari release yang diuji.

## 5. Permission dan storage

Sesuaikan `APP_USER` dan `APP_GROUP` dengan user PHP-FPM pada server:

```bash
export APP_USER="www-data"
export APP_GROUP="www-data"
sudo chown -R "$APP_USER:$APP_GROUP" storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Pastikan storage link sudah tersedia. Command ini aman dijalankan ulang:

```bash
"$PHP_BIN" artisan storage:link || true
```

## 6. Migration dan cache Laravel

Perubahan warna disimpan di konfigurasi komponen JSON sehingga tidak menambah migration baru. Tetap jalankan migration karena release dapat memuat migration fitur template yang sebelumnya belum diterapkan.

```bash
cd "$APP_DIR"
"$PHP_BIN" artisan migrate --force --no-interaction
```

Bersihkan cache release lama, kemudian bangun ulang cache secara berurutan:

```bash
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
```

Periksa migration dan route yang relevan:

```bash
"$PHP_BIN" artisan migrate:status --no-interaction
"$PHP_BIN" artisan route:list --name=crm.surat-kontrak-templates --no-interaction
```

Jika `route:list` gagal karena masalah route lama di luar fitur ini, hentikan deployment dan catat error. Jangan menghapus route lain untuk memaksa deploy.

## 7. Publish JavaScript module bila memang berubah

Perubahan warna saat ini berada pada Blade inline dan service PHP, sehingga **tidak perlu** publish JavaScript module khusus untuk perubahan ini.

Jika release yang sama juga mengubah JavaScript sumber module, jalankan publish dari root aplikasi:

```bash
"$PHP_BIN" scripts/publish-module-js.php Crm
```

Lanjutkan hanya jika command melaporkan berhasil dan file sumber serta runtime dinyatakan byte-identical. Jangan mengedit file `public/modules/...` secara manual.

## 8. Restart worker dan reload PHP-FPM

Perubahan template tidak mengubah queue, tetapi restart worker setelah release menjaga worker tidak memakai kode lama. Jalankan sesuai cara service production:

```bash
"$PHP_BIN" artisan queue:restart
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart "$SUPERVISOR_WORKER_GROUP"
sudo supervisorctl status
```

Jika Supervisor tidak digunakan, gunakan service manager yang berlaku pada server. Worker standar aplikasi:

```bash
"$PHP_BIN" artisan queue:work --queue=solar,imports,default --tries=1 --timeout=1800
```

Command worker di atas biasanya dijalankan oleh Supervisor, bukan terminal deploy.

Reload PHP-FPM agar OPcache mengambil file release terbaru:

```bash
sudo systemctl reload "$PHP_FPM_SERVICE"
sudo systemctl is-active "$PHP_FPM_SERVICE"
```

## 9. Buka aplikasi dan smoke test production

Jika semua pemeriksaan teknis lulus:

```bash
cd "$APP_DIR"
"$PHP_BIN" artisan up
curl --fail --silent --show-error --head "$APP_URL/login"
```

Lakukan pemeriksaan browser dengan akun yang memang berwenang. Jangan memakai kredensial demo lokal dan jangan memasukkan data pribadi ke screenshot.

Checklist minimum:

- [ ] Login berhasil dan business aktif yang tampil benar.
- [ ] Buka `CRM → Pengaturan Surat Kontrak → Template Kop Surat`.
- [ ] Pastikan template hanya menampilkan data business aktif.
- [ ] Buka editor template dan pilih komponen nama bisnis, subjudul, alamat/kontak, judul, isi, atau garis pemisah.
- [ ] Pastikan kontrol warna dan kode hex terlihat; komponen logo tidak menampilkan kontrol warna.
- [ ] Pastikan preview berubah saat warna diubah.
- [ ] Bila perlu menyimpan perubahan, gunakan template uji nonaktif yang sudah disetujui; jangan mengubah template legal aktif tanpa persetujuan.
- [ ] Buka preview dokumen dan pastikan warna hasil render sama dengan preview editor.
- [ ] Periksa tampilan desktop dan layar sempit tanpa horizontal overflow.
- [ ] Pastikan halaman kontrak lama masih dapat dibuka.
- [ ] Pastikan menu invoice, Project, Sell, payroll, dan maintenance yang termasuk smoke test deployment tetap dapat dibuka.

Untuk pengecekan read-only endpoint:

```bash
curl --fail --silent --show-error "$APP_URL/login" >/dev/null
curl --fail --silent --show-error "$APP_URL/crm/surat-kontrak-templates" >/dev/null
```

Endpoint kedua tetap memerlukan login; status `302` ke login dapat diterima bila pemeriksaan dilakukan tanpa session. Verifikasi akses sebenarnya tetap dilakukan melalui browser.

## 10. Observasi setelah deploy

Periksa log aplikasi, error PHP-FPM, dan status worker selama masa observasi:

```bash
tail -n 100 storage/logs/laravel.log
sudo journalctl -u "$PHP_FPM_SERVICE" -n 100 --no-pager
sudo supervisorctl status
```

Cari error baru yang berkaitan dengan:

- `SuratKontrakTemplateService`;
- upload logo atau `surat_kontrak_logos`;
- render preview/cetak kontrak;
- business scope atau permission;
- route template kontrak;
- queue worker.

Jangan menghapus log sebelum salin bukti error dan waktu ke laporan deployment.

## 11. Rollback bila ada regresi

Untuk error kode, kembalikan ke release/commit terakhir yang sudah disetujui. Contoh jika deployment memakai folder release dan symlink:

```bash
cd /var/www
sudo ln -sfn /var/www/ultimateerp-releases/RELEASE_SEBELUMNYA ultimateerp
cd /var/www/ultimateerp
sudo -u www-data "$PHP_BIN" artisan optimize:clear
sudo -u www-data "$PHP_BIN" artisan config:cache
sudo -u www-data "$PHP_BIN" artisan route:cache
sudo -u www-data "$PHP_BIN" artisan view:cache
sudo systemctl reload "$PHP_FPM_SERVICE"
sudo -u www-data "$PHP_BIN" artisan queue:restart
```

Jika layout repository tidak memakai symlink, lakukan rollback sesuai mekanisme release hosting. Jangan menjalankan `migrate:fresh`, `db:wipe`, atau menghapus migration.

Migration tidak boleh di-rollback otomatis hanya karena ada error tampilan. Jika migration baru sudah berjalan dan membutuhkan rollback database, hentikan proses, gunakan backup yang sudah dicatat, dan lakukan pemulihan bersama penanggung jawab database.

Setelah rollback:

```bash
curl --fail --silent --show-error --head "$APP_URL/login"
tail -n 100 storage/logs/laravel.log
```

## 12. Catatan hasil deploy

Isi setelah selesai:

```text
Tanggal/waktu:
Operator:
Release/commit:
Database:
Backup database:
Backup file:
Migration:
Cache config/route/view:
Publish JS:
Queue worker:
PHP-FPM:
Smoke test browser:
Hasil: LULUS / GAGAL / ROLLBACK
Masalah tersisa:
```

Deployment dinyatakan selesai hanya jika backup tercatat, migration/cache berhasil, service sehat, smoke test browser selesai, dan tidak ada error baru yang belum memiliki keputusan.
