# Hasil 09 — Pipeline screenshot raster

Status: **LULUS**.

- Raw diambil dari browser pada run `REV01-FINAL-20260911`; output dibuat ulang oleh `python Rencana/ChangeLog-Angkutan/Revisi-01/hasil/annotate_final.py` menggunakan Pillow melalui utilitas anotasi.
- Tidak memakai SVG/HTML overlay. Output yang dipakai halaman adalah PNG raster pada `public/images/change-log/release-2026-09-10-angkutan/`.
- Manifest terverifikasi: 21 file PNG yang direferensikan `config/guides.php`, semua ada; tidak ada referensi path Windows, `Rencana/` atau `storage/app` pada URL pembaca.
- Aset final yang dipakai halaman: `REV01-guide-index-desktop-annotated.png`, `REV01-guide-index-mobile-annotated.png`, `REV01-bonus-ayak-list-desktop-final-annotated.png`, `REV01-bonus-ayak-list-mobile-final-annotated.png`, `REV01-bonus-ayak-import-preview-desktop-annotated.png`, `REV01-kasbon-desktop-annotated.png`, `REV01-change-log-detail-desktop-annotated.png`. Hash SHA-256 publik: guide-index desktop `910CA6D1B2C2F58320C72EF97912F58E9CDFA46C2DB7A8375A2DEE7175EDFF94`; guide-index mobile `B4FE0D1B4559CA964BAE1FB69673C7E8B919BC9BDCE05D1C4E978A7CA3C0DF9D`; bonus desktop final `273AB2CDDC972F5C3C1B335EC98A52A1FEBC85A50E1E2CC01CEC57A7274085C8`; bonus mobile final `6E83ED7A5B251A89565D17079AA6101B126928A11033A90F0875789D449092A9`; bonus preview `CFCFECDEC999FDB47287D6BE250E74A85ADA40E5A01FE8E72ADAFA7B76F0CD5B`; kasbon `685737756CBCAD263839E50D713F6415641C73956025F2EEDE4185ABD5C840B6`; Change Log detail `14446C39E76E222D593D3D02D2B79DA22DB8FA788592803B7F4C80228D39196`.
- Browser memastikan gambar complete, alt terisi, naturalWidth/naturalHeight positif; visual inspeksi memastikan nomor/kotak/panah menunjuk target.
- Manifest pengulangan dan pemetaan raw → annotated → publik: [manifest-final.md](manifest-final.md).
