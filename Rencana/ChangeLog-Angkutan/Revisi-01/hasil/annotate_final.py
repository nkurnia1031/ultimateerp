"""Create final annotated guide images from real agent-browser screenshots.

All annotations are raster compositing with Pillow.  The source files are
browser captures; no SVG, HTML overlay, or generated UI is used.
"""

from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "scripts"))

from annotate_changelog_screenshots import annotate  # noqa: E402


EVIDENCE = ROOT / "storage/app/changelog-evidence/REV01-FINAL-20260911"
RAW = EVIDENCE / "raw"
OUT = EVIDENCE / "annotated"


SCREENS = {
    "REV01-bonus-ayak-list-desktop-final": {
        "source": "bonus-ayak-list-desktop-refresh-full.png",
        "title": "Bonus Ayak: daftar dan form pada layar komputer",
        "description": "Nomor 1 aksi utama. Nomor 2 ringkasan. Nomor 3 panel form dengan padding, border field, relasi dan perhitungan.",
        "items": [
            {"number": 1, "box": [1048, 150, 1412, 203], "caption": "1. Aksi utama: tambah Bonus atau Kasbon", "marker": [1020, 170], "arrow_to": [1048, 170]},
            {"number": 2, "box": [286, 220, 1410, 303], "caption": "2. Ringkasan jumlah data, trip, dan bonus", "marker": [258, 240], "arrow_to": [286, 240]},
            {"number": 3, "box": [286, 324, 1410, 687], "caption": "3. Pilih relasi dan lihat bonus otomatis", "marker": [258, 345], "arrow_to": [286, 345]},
        ],
    },
    "REV01-bonus-ayak-list-mobile-final": {
        "source": "bonus-ayak-list-mobile-refresh-full.png",
        "title": "Bonus Ayak: daftar dan form pada ponsel",
        "description": "Nomor 1 aksi. Nomor 2 form dengan field ber-border. Nomor 3 daftar hasil dan status payroll.",
        "items": [
            {"number": 1, "box": [10, 284, 380, 325], "caption": "1. Aksi utama", "marker": [25, 270], "arrow_to": [40, 284]},
            {"number": 2, "box": [10, 642, 380, 1516], "caption": "2. Panel form dengan field yang jelas", "marker": [25, 625], "arrow_to": [40, 642]},
            {"number": 3, "box": [10, 2623, 380, 3008], "caption": "3. Daftar hasil dan status payroll", "marker": [25, 2606], "arrow_to": [40, 2623]},
        ],
    },
    "REV01-bonus-ayak-import-preview-desktop": {
        "source": "bonus-ayak-import-preview-desktop.png",
        "title": "Preview import Bonus Ayak pada layar komputer",
        "description": "Nomor 1 file dan aturan preview. Nomor 2 ringkasan baris. Nomor 3 status valid atau duplikat.",
        "items": [
            {"number": 1, "box": [286, 198, 1410, 344], "caption": "1. Aturan preview sebelum simpan", "marker": [260, 216], "arrow_to": [286, 216]},
            {"number": 2, "box": [286, 360, 1410, 441], "caption": "2. Ringkasan baris valid dan bermasalah", "marker": [260, 378], "arrow_to": [286, 378]},
            {"number": 3, "box": [286, 538, 1410, 783], "caption": "3. Pilih hanya baris siap disimpan", "marker": [260, 556], "arrow_to": [286, 556]},
        ],
    },
    "REV01-kasbon-desktop": {
        "source": "kasbon-desktop.png",
        "title": "Kasbon Sopir: input, daftar, dan status payroll",
        "description": "Nomor 1 form kasbon. Nomor 2 daftar. Nomor 3 status terkunci atau aksi yang tersedia.",
        "items": [
            {"number": 1, "box": [288, 274, 1408, 425], "caption": "1. Pilih sopir dan isi nominal", "marker": [260, 292], "arrow_to": [288, 292]},
            {"number": 2, "box": [288, 442, 1408, 999], "caption": "2. Daftar Kasbon tersimpan", "marker": [260, 460], "arrow_to": [288, 460]},
            {"number": 3, "box": [1142, 505, 1312, 542], "caption": "3. Status terkunci payroll", "marker": [1115, 520], "arrow_to": [1142, 520]},
        ],
    },
    "REV01-guide-index-desktop": {
        "source": "guide-index-desktop.png",
        "title": "Pusat Panduan: katalog artikel",
        "description": "Nomor 1 pencarian. Nomor 2 kategori. Nomor 3 kartu artikel dengan screenshot dan link detail.",
        "items": [
            {"number": 1, "box": [302, 229, 1408, 302], "caption": "1. Cari topik panduan", "marker": [275, 245], "arrow_to": [302, 245]},
            {"number": 2, "box": [286, 330, 1410, 377], "caption": "2. Kategori pekerjaan", "marker": [258, 345], "arrow_to": [286, 345]},
            {"number": 3, "box": [286, 381, 1035, 680], "caption": "3. Screenshot dan link artikel detail", "marker": [258, 397], "arrow_to": [286, 397]},
        ],
    },
    "REV01-guide-index-mobile": {
        "source": "guide-index-mobile.png",
        "title": "Pusat Panduan: katalog artikel pada ponsel",
        "description": "Nomor 1 navigasi. Nomor 2 pencarian. Nomor 3 kartu artikel yang dapat dibuka.",
        "items": [
            {"number": 1, "box": [0, 0, 389, 129], "caption": "1. Navigasi Change Log dan Panduan", "marker": [18, 136], "arrow_to": [40, 129]},
            {"number": 2, "box": [14, 294, 375, 467], "caption": "2. Cari topik panduan", "marker": [18, 282], "arrow_to": [40, 294]},
            {"number": 3, "box": [14, 492, 375, 843], "caption": "3. Kartu artikel dan link detail", "marker": [18, 480], "arrow_to": [40, 492]},
        ],
    },
    "REV01-change-log-detail-desktop": {
        "source": "change-log-detail-desktop.png",
        "title": "Change Log: detail rilis dan link panduan",
        "description": "Nomor 1 manfaat. Nomor 2 langkah bernomor. Nomor 3 daftar panduan lengkap.",
        "items": [
            {"number": 1, "box": [270, 137, 1425, 272], "caption": "1. Manfaat dan istilah penting", "marker": [244, 155], "arrow_to": [270, 155]},
            {"number": 2, "box": [270, 565, 1425, 1000], "caption": "2. Alur kerja bernomor", "marker": [244, 585], "arrow_to": [270, 585]},
        ],
    },
    "REV01-guide-bonus-figures-desktop": {
        "source": "guide-bonus-figures-desktop.png",
        "title": "Artikel Bonus Ayak: gambar langkah yang diuji",
        "description": "Nomor 1 menunjukkan artikel menampilkan gambar inline. Nomor 2 membuka gambar ukuran penuh.",
        "items": [
            {"number": 1, "box": [270, 86, 1425, 998], "caption": "1. Gambar raster beranotasi di dalam artikel", "marker": [244, 105], "arrow_to": [270, 105]},
        ],
    },
}


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for name, spec in SCREENS.items():
        source = RAW / spec["source"]
        output = OUT / f"{name}-annotated.png"
        annotate(source, output, spec["title"], spec["description"], spec["items"])
        print(f"{output.name}: {output}")


if __name__ == "__main__":
    main()
