#!/usr/bin/env python3
"""Raster annotations for Change Log screenshots.

The input must be a PNG captured from the real application UI.  This utility
draws the annotation layer into a new PNG so the guide never depends on SVG,
HTML overlays, or a browser's SVG renderer.

Example:
    python scripts/annotate_changelog_screenshots.py \
      --preset 05E-sell-form-desktop \
      --input storage/app/changelog-evidence/RUN05E-20260911/raw/05E-sell-form-filled-desktop.png \
      --output Rencana/ChangeLog-Angkutan/hasil/screenshots/05E-sell-form-desktop-annotated.png

For a new screen, pass --spec with a JSON file containing a list of objects:
    {"box": [x1, y1, x2, y2], "label": "Isi field", "number": 1}
The JSON may also contain an optional ``marker`` and ``arrow_to`` point.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
from pathlib import Path
from typing import Any

from PIL import Image, ImageDraw, ImageFont, PngImagePlugin


RED = (220, 38, 38, 255)
DARK_RED = (127, 29, 29, 255)
WHITE = (255, 255, 255, 255)


def _font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = []
    if os.name == "nt":
        candidates.extend([
            r"C:\Windows\Fonts\arialbd.ttf" if bold else r"C:\Windows\Fonts\arial.ttf",
            r"C:\Windows\Fonts\segoeuib.ttf" if bold else r"C:\Windows\Fonts\segoeui.ttf",
        ])
    candidates.extend([
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        if bold else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    ])
    for candidate in candidates:
        if Path(candidate).exists():
            return ImageFont.truetype(candidate, size=size)
    return ImageFont.load_default()


def _clamp_point(point: tuple[float, float], size: tuple[int, int]) -> tuple[int, int]:
    return (
        max(0, min(int(point[0]), size[0] - 1)),
        max(0, min(int(point[1]), size[1] - 1)),
    )


def _marker(draw: ImageDraw.ImageDraw, point: tuple[int, int], number: int, radius: int) -> None:
    x, y = point
    draw.ellipse((x - radius, y - radius, x + radius, y + radius), fill=RED, outline=DARK_RED, width=max(2, radius // 7))
    font = _font(max(14, radius + 2), bold=True)
    text = str(number)
    bounds = draw.textbbox((0, 0), text, font=font, stroke_width=0)
    draw.text((x - (bounds[2] - bounds[0]) / 2, y - (bounds[3] - bounds[1]) / 2 - 1), text, font=font, fill=WHITE)


def _arrow(draw: ImageDraw.ImageDraw, start: tuple[int, int], end: tuple[int, int], width: int) -> None:
    draw.line((start, end), fill=RED, width=width)
    angle = math.atan2(end[1] - start[1], end[0] - start[0])
    length = max(8, width * 4)
    wing = math.pi / 7
    left = (end[0] - length * math.cos(angle - wing), end[1] - length * math.sin(angle - wing))
    right = (end[0] - length * math.cos(angle + wing), end[1] - length * math.sin(angle + wing))
    draw.polygon((end, left, right), fill=RED)


def _caption(draw: ImageDraw.ImageDraw, xy: tuple[int, int], value: str, font_size: int) -> None:
    draw.text(xy, value, font=_font(font_size, bold=True), fill=DARK_RED, stroke_width=max(2, font_size // 4), stroke_fill=WHITE)


def _wrap_text(draw: ImageDraw.ImageDraw, value: str, font: ImageFont.FreeTypeFont | ImageFont.ImageFont, max_width: int) -> list[str]:
    words = value.split()
    lines: list[str] = []
    current = ""
    for word in words:
        candidate = f"{current} {word}".strip()
        if current and draw.textlength(candidate, font=font) > max_width:
            lines.append(current)
            current = word
        else:
            current = candidate
    if current:
        lines.append(current)
    return lines or [value]


def annotate(source: Path, output: Path, title: str, description: str, items: list[dict[str, Any]]) -> None:
    raw = Image.open(source).convert("RGBA")
    width, height = raw.size
    # Keep labels outside the browser capture. This prevents annotations from
    # obscuring form labels, values, buttons, or table cells on narrow screens.
    band_height = 96 if width < 700 else 84
    image = Image.new("RGBA", (width, height + band_height), (247, 250, 252, 255))
    image.paste(raw, (0, band_height))
    draw = ImageDraw.Draw(image)
    canvas_height = image.height
    draw.line((0, band_height - 1, width, band_height - 1), fill=(203, 213, 225, 255), width=2)
    scale = max(1.0, width / 1000)
    line_width = max(2, round(3 * scale))
    radius = max(15, round(18 * scale)) if width >= 700 else 17
    caption_size = max(13, round(15 * scale)) if width >= 700 else 13

    # The legend is deliberately outside the raw capture and is rendered as
    # raster text by Pillow. It is the accessible visual key for the markers;
    # the Markdown guide still supplies equivalent text and alt text.
    legend_font = _font(14 if width >= 700 else 11, bold=True)
    column_width = max(1, width // max(1, len(items)))
    for index, item in enumerate(items):
        legend_x = index * column_width + 10
        legend_marker = (legend_x + 10, band_height // 2)
        _marker(draw, legend_marker, int(item["number"]), max(12, radius // 2))
        caption = str(item.get("caption") or item.get("label") or "").strip()
        caption = re.sub(r"^\d+\.\s*", "", caption)
        legend_lines = _wrap_text(draw, caption, legend_font, max(30, column_width - 34))
        line_height = max(13, int((legend_font.getbbox("Ag")[3] - legend_font.getbbox("Ag")[1]) * 1.2))
        legend_y = max(5, (band_height - line_height * len(legend_lines)) // 2)
        for line_index, line in enumerate(legend_lines[:4]):
            draw.text((legend_x + 25, legend_y + line_index * line_height), line, font=legend_font, fill=DARK_RED)

        box = item.get("box")
        if not box or len(box) != 4:
            raise ValueError(f"Annotation item has no valid box: {item!r}")
        x1, y1, x2, y2 = [int(v) for v in box]
        y1 += band_height
        y2 += band_height
        x1 = max(0, min(x1, width - 1))
        y1 = max(band_height, min(y1, canvas_height - 1))
        x2 = max(x1 + 1, min(x2, width - 1))
        y2 = max(y1 + 1, min(y2, canvas_height - 1))
        draw.rounded_rectangle((x1, y1, x2, y2), radius=max(4, round(7 * scale)), outline=RED, width=line_width)

        marker = item.get("marker")
        if marker is None:
            marker = [max(12, x1 - round(10 * scale)), max(radius, y1 + round(20 * scale))]
        marker = _clamp_point((marker[0], int(marker[1]) + band_height), image.size)
        _marker(draw, marker, int(item["number"]), radius)

        arrow_to = item.get("arrow_to")
        if arrow_to:
            start = item.get("arrow_from") or [marker[0], marker[1] + radius]
            start_y = int(start[1]) + (0 if item.get("arrow_from") and start[1] > height else band_height)
            # End at the highlighted box edge rather than crossing the field
            # contents. The box itself identifies the complete target area.
            edge = (x1, y1 + min(10, max(2, y2 - y1)))
            _arrow(draw, _clamp_point((start[0], start_y), image.size), _clamp_point(edge, image.size), line_width)

    output.parent.mkdir(parents=True, exist_ok=True)
    metadata = PngImagePlugin.PngInfo()
    metadata.add_text("Title", title)
    metadata.add_text("Description", description)
    metadata.add_text("AnnotationMethod", "Python Pillow raster compositing from a browser-captured PNG; legend outside raw capture")
    image.convert("RGB").save(output, format="PNG", optimize=True, pnginfo=metadata)


def _items(*items: tuple[int, list[int], list[int], str, list[int] | None, list[int] | None]) -> list[dict[str, Any]]:
    return [
        {"number": number, "box": box, "marker": marker, "caption": caption, "caption_at": caption_at, "arrow_to": arrow_to}
        for number, box, marker, caption, caption_at, arrow_to in items
    ]


# Coordinates are in the dimensions of the raw browser screenshots recorded in
# storage/app/changelog-evidence. They are deliberately explicit and auditable.
PRESETS: dict[str, dict[str, Any]] = {
    "05A-master-tarif-desktop": {"title": "Panduan Tarif Angkutan pada layar komputer", "description": "Nomor 1 upload dan tanggal berlaku. Nomor 2 daftar batch dan status. Nomor 3 tabel tarif aktif.", "items": _items(
        (1, [270, 367, 1265, 565], [287, 391], "1. Upload file + tanggal berlaku", [330, 347], [420, 367]),
        (2, [270, 594, 1265, 895], [287, 615], "2. Status batch dan peringatan", [330, 577], [450, 594]),
        (3, [620, 674, 1265, 895], [648, 696], "3. Tarif aktif yang akan dipakai", [690, 657], [820, 674]),
    )},
    "05A-master-tarif-mobile": {"title": "Panduan Tarif Angkutan pada layar ponsel", "description": "Nomor 1 pilih file. Nomor 2 isi tanggal berlaku. Nomor 3 unggah dan preview.", "items": _items(
        (1, [38, 469, 351, 504], [24, 486], "1. Pilih workbook", [45, 456], [90, 470]),
        (2, [38, 542, 351, 577], [24, 558], "2. Isi tanggal berlaku", [45, 529], [90, 543]),
        (3, [39, 618, 310, 657], [24, 637], "3. Unggah & Preview", [45, 605], [90, 619]),
    )},
    "05B-spb-form-desktop": {"title": "Panduan mengisi SPB pada komputer", "description": "Nomor 1 identitas perjalanan dan relasi. Nomor 2 tonase. Nomor 3 klaim dan potongan.", "items": _items(
        (1, [285, 343, 1233, 461], [282, 321], "1. Identitas perjalanan + relasi", [330, 329], [500, 345]),
        (2, [285, 477, 1233, 551], [282, 491], "2. Gross, tare, netto, ton tagihan", [330, 469], [560, 479]),
        (3, [285, 568, 1233, 625], [282, 575], "3. Klaim dan potongan", [330, 562], [470, 570]),
    )},
    "05B-spb-detail-desktop": {"title": "Panduan memeriksa detail SPB", "description": "Nomor 1 identitas dan relasi. Nomor 2 tonase dan Harga/Ton. Nomor 3 pendapatan, upah, dan status.", "items": _items(
        (1, [285, 383, 1233, 447], [282, 313], "1. Pastikan rute, unit, dan sopir benar", [330, 370], [540, 385]),
        (2, [285, 477, 1233, 551], [282, 487], "2. Cocokkan tonase dan Harga/Ton", [330, 470], [510, 480]),
        (3, [285, 568, 1233, 625], [282, 584], "3. Cocokkan pendapatan, upah, dan status", [330, 561], [510, 570]),
    )},
    "05B-spb-detail-lower": {"title": "Panduan memeriksa nominal dan status SPB", "description": "Nomor 1 tonase dan harga. Nomor 2 pendapatan pelanggan. Nomor 3 upah sopir dan status.", "items": _items(
        (1, [285, 20, 1233, 99], [282, 22], "1. Tonase + Harga/Ton", [330, 4], [500, 22]),
        (2, [285, 101, 1233, 180], [282, 103], "2. Pendapatan pelanggan", [330, 85], [510, 103]),
        (3, [285, 182, 1233, 262], [282, 184], "3. Upah sopir + status", [330, 166], [510, 184]),
    )},
    "05B-spb-detail-mobile": {"title": "Panduan memeriksa SPB pada ponsel", "description": "Nomor 1 identitas SPB. Nomor 2 tonase dan nilai setelah menggulir.", "items": _items(
        (1, [38, 300, 351, 482], [24, 287], "1. Identitas SPB", [66, 293], [66, 310]),
        (2, [38, 515, 351, 776], [24, 511], "2. Tonase dan nilai", [66, 508], [66, 525]),
    )},
    "05C-import-preview-desktop": {"title": "Panduan import SPB melalui komputer", "description": "Nomor 1 pilih workbook dan preview. Nomor 2 baca ringkasan preview.", "items": _items(
        (1, [270, 366, 817, 484], [282, 389], "1. Pilih workbook lalu Preview", [330, 352], [510, 367]),
        (2, [270, 575, 1232, 625], [282, 596], "2. Ringkasan sheet dan baris", [330, 566], [550, 576]),
    )},
    "05C-import-preview-mobile": {"title": "Panduan preview import SPB pada ponsel", "description": "Nomor 1 pilih file dan preview. Nomor 2 ringkasan. Nomor 3 peringatan duplikat.", "items": _items(
        (1, [38, 372, 351, 444], [24, 378], "1. Pilih file + Preview", [52, 365], [52, 380]),
        (2, [38, 575, 351, 660], [24, 590], "2. Baca ringkasan", [52, 568], [52, 583]),
        (3, [38, 674, 351, 822], [24, 721], "3. Baca peringatan duplikat", [52, 667], [52, 682]),
    )},
    "05D-project-trips-desktop": {"title": "Panduan perjalanan angkutan pada Project", "description": "Nomor 1 ringkasan. Nomor 2 tindakan SPB/invoice. Nomor 3 daftar perjalanan verified.", "items": _items(
        (1, [270, 211, 1230, 268], [282, 227], "1. Ringkasan Project: tonase, pendapatan, upah, kontribusi", [330, 201], [600, 212]),
        (2, [270, 282, 910, 322], [282, 301], "2. Buat SPB atau pilih SPB untuk invoice", [330, 275], [560, 283]),
        (3, [270, 337, 1230, 550], [282, 354], "3. Daftar perjalanan yang sudah Verified", [330, 331], [570, 338]),
    )},
    "05D-project-invoice-chooser-desktop": {"title": "Panduan memilih SPB untuk invoice Project", "description": "Nomor 1 konteks Project/customer. Nomor 2 SPB verified yang belum ditagihkan.", "items": _items(
        (1, [295, 449, 1208, 505], [282, 475], "1. Pastikan Project dan customer benar", [330, 441], [560, 450]),
        (2, [295, 518, 1208, 529], [282, 521], "2. Daftar SPB verified yang belum ditagihkan", [330, 511], [560, 519]),
    )},
    "05D-project-invoice-chooser-mobile": {"title": "Panduan memilih SPB dari layar ponsel", "description": "Nomor 1 konteks Project/customer. Nomor 2 SPB. Nomor 3 tombol lanjut.", "items": _items(
        (1, [38, 374, 351, 446], [24, 374], "1. Konteks Project/customer", [45, 364], [190, 375]),
        (2, [38, 468, 351, 566], [24, 512], "2. Pilih SPB yang tersedia", [45, 459], [190, 469]),
        (3, [38, 621, 129, 665], [24, 643], "3. Lanjutkan", [45, 611], [90, 622]),
    )},
    "05E-sell-form-desktop": {"title": "Panduan membuat Sell dari trip angkutan", "description": "Nomor 1 trip. Nomor 2 customer/jasa/lokasi. Nomor 3 preview. Nomor 4 buat Sell.", "items": _items(
        (1, [294, 338, 564, 370], [282, 344], "1. Pastikan trip yang dipilih benar", [340, 329], [500, 339]),
        (2, [294, 377, 1402, 421], [282, 402], "2. Pilih customer, jasa, dan lokasi", [340, 370], [570, 378]),
        (3, [294, 548, 1402, 625], [282, 575], "3. Cocokkan ton, Harga/Ton, dan total", [340, 539], [590, 549]),
        (4, [294, 623, 377, 659], [282, 641], "4. Buat Sell", [390, 618], [335, 624]),
    )},
    "05E-sell-form-mobile": {"title": "Panduan Sell angkutan dari ponsel", "description": "Nomor 1 trip. Nomor 2 pilihan utama. Nomor 3 preview yang dapat digeser.", "items": _items(
        (1, [38, 300, 351, 325], [24, 309], "1. Trip terpilih", [45, 290], [150, 301]),
        (2, [38, 334, 351, 565], [24, 422], "2. Isi tiga pilihan utama", [45, 326], [170, 335]),
        (3, [38, 726, 351, 843], [24, 780], "3. Preview; geser tabel bila perlu", [45, 717], [190, 727]),
    )},
    "05E-sell-detail-desktop": {"title": "Panduan memeriksa detail Sell angkutan", "description": "Nomor 1 identitas dan status. Nomor 2 line jasa dari SPB. Nomor 3 total tagihan pelanggan.", "items": _items(
        (1, [86, 104, 1360, 216], [68, 130], "1. Cek nomor, status, dan customer", [100, 88], [430, 105]),
        (2, [86, 282, 1353, 411], [68, 305], "2. Cek line jasa, rute, unit, sopir, dan ton", [100, 265], [500, 283]),
        (3, [735, 476, 1353, 772], [718, 510], "3. Cek total tagihan pelanggan", [750, 450], [980, 477]),
    )},
    "05E-sell-detail-mobile": {"title": "Panduan memeriksa detail Sell pada ponsel", "description": "Nomor 1 identitas. Nomor 2 line jasa. Nomor 3 total tagihan setelah menggulir.", "items": _items(
        (1, [35, 110, 355, 365], [20, 130], "1. Identitas dan status", [40, 95], [120, 111]),
        (2, [35, 396, 355, 526], [20, 414], "2. Line jasa angkutan", [40, 378], [140, 397]),
        (3, [35, 630, 355, 843], [20, 650], "3. Total tagihan", [40, 610], [140, 631]),
    )},
    "05F-bonus-form-desktop": {"title": "Panduan input Bonus Ayak", "description": "Nomor 1 relasi sopir/unit/Project. Nomor 2 trip dan bonus otomatis. Nomor 3 simpan.", "items": _items(
        (1, [270, 215, 1230, 286], [282, 235], "1. Pilih sopir, unit, dan Project", [330, 205], [480, 216]),
        (2, [270, 303, 636, 360], [282, 320], "2. Isi Trip; Bonus dihitung otomatis", [330, 293], [500, 304]),
        (3, [661, 303, 734, 360], [650, 320], "3. Simpan", [700, 293], [700, 304]),
    )},
    "05F-bonus-form-mobile": {"title": "Panduan input Bonus Ayak dari ponsel", "description": "Nomor 1 relasi sopir/unit/Project. Nomor 2 isi Trip dan lihat Bonus otomatis.", "items": _items(
        (1, [15, 537, 375, 652], [24, 552], "1. Relasikan sopir, unit, dan Project", [45, 526], [190, 538]),
        (2, [15, 773, 375, 843], [24, 792], "2. Isi Trip; lihat Bonus otomatis", [45, 762], [190, 774]),
    )},
    "05F-bonus-edit-desktop": {"title": "Panduan mengubah Bonus Ayak", "description": "Nomor 1 relasi. Nomor 2 jumlah Trip dan bonus hasil hitung. Nomor 3 simpan perubahan.", "items": _items(
        (1, [294, 190, 1402, 326], [282, 210], "1. Periksa relasi data", [340, 180], [520, 191]),
        (2, [489, 241, 833, 276], [470, 258], "2. Ubah Trip; Bonus dihitung ulang", [520, 229], [650, 242]),
        (3, [1258, 385, 1367, 418], [1240, 401], "3. Simpan perubahan", [1110, 375], [1258, 386]),
    )},
    "05G-kasbon-form-desktop": {"title": "Panduan membuat Kasbon", "description": "Nomor 1 tanggal dan sopir. Nomor 2 nominal Kasbon. Nomor 3 daftar Kasbon yang tersimpan.", "items": _items(
        (1, [270, 123, 900, 202], [258, 145], "1. Pilih tanggal dan sopir", [330, 113], [270, 133]),
        (2, [953, 145, 1216, 202], [930, 172], "2. Isi nominal Kasbon", [950, 113], [953, 153]),
        (3, [270, 219, 1230, 344], [258, 245], "3. Cek daftar dan status", [330, 209], [270, 229]),
    )},
    "05G-kasbon-form-mobile": {"title": "Panduan membuat Kasbon pada ponsel", "description": "Nomor 1 isi tanggal, sopir, dan nominal. Nomor 2 cek baris yang tersimpan.", "items": _items(
        (1, [15, 208, 375, 453], [10, 240], "1. Isi tanggal, sopir, dan nominal", [30, 198], [15, 218]),
        (2, [15, 468, 375, 605], [10, 535], "2. Cek Kasbon yang tersimpan", [30, 458], [15, 478]),
    )},
    "05G-kasbon-edit-mobile": {"title": "Panduan mengubah Kasbon pada ponsel", "description": "Nomor 1 ubah nominal lalu Update. Nomor 2 pastikan daftar ikut berubah.", "items": _items(
        (1, [15, 180, 375, 483], [10, 220], "1. Ubah nominal lalu Update", [30, 170], [15, 190]),
        (2, [15, 501, 375, 635], [10, 570], "2. Cek hasil pada daftar", [30, 491], [15, 511]),
    )},
    "05G-kasbon-locked-desktop": {"title": "Panduan mengenali Kasbon terkunci", "description": "Nomor 1 menunjukkan Kasbon yang sudah terkunci payroll. Nomor 2 menunjukkan data lain yang masih dapat diedit.", "items": _items(
        (1, [277, 311, 1418, 410], [260, 365], "1. Terkunci payroll tidak boleh diubah", [300, 301], [277, 321]),
        (2, [277, 411, 1418, 495], [260, 450], "2. Data yang belum terkunci memiliki Edit", [300, 401], [277, 421]),
    )},
    "05H-spb-form-filled": {"title": "Panduan membuat SPB untuk settlement", "description": "Nomor 1 mengisi identitas perjalanan dan relasi. Nomor 2 mengisi tonase. Nomor 3 menyimpan SPB.", "items": _items(
        (1, [285, 343, 1402, 754], [276, 300], "1. Isi tanggal, rute, unit, sopir, Project, dan customer", [300, 328], [470, 344]),
        (2, [285, 511, 1402, 648], [276, 492], "2. Isi tonase; Ton tagihan menjadi dasar nilai", [300, 496], [520, 512]),
        (3, [294, 768, 394, 805], [276, 747], "3. Simpan SPB", [410, 751], [345, 769]),
    )},
    "05H-spb-detail-created": {"title": "Panduan memeriksa SPB sebelum settlement", "description": "Nomor 1 memeriksa relasi. Nomor 2 memeriksa tonase dan Harga/Ton. Nomor 3 memeriksa nilai dan status.", "items": _items(
        (1, [285, 342, 1400, 420], [276, 313], "1. Pastikan rute, unit, sopir, Project, dan customer benar", [300, 326], [520, 343]),
        (2, [285, 443, 1400, 500], [276, 428], "2. Cocokkan netto, ton diterima, ton tagihan, dan Harga/Ton", [300, 433], [520, 444]),
        (3, [285, 514, 1400, 641], [276, 501], "3. Cocokkan nilai akhir, total upah, lalu lanjutkan status", [300, 503], [520, 515]),
    )},
    "05H-settlement-review": {"title": "Panduan memilih sumber settlement", "description": "Nomor 1 adalah ringkasan. Nomor 2 memilih SPB. Nomor 3 memilih Bonus Ayak. Nomor 4 memilih Kasbon.", "items": _items(
        (1, [294, 359, 1400, 419], [276, 337], "1. Periksa upah trip, Bonus Ayak, bruto, dan netto", [300, 345], [550, 360]),
        (2, [294, 479, 1400, 559], [276, 462], "2. Pilih SPB verified yang akan dibayar", [300, 464], [520, 480]),
        (3, [294, 619, 1400, 738], [276, 602], "3. Pilih Bonus Ayak yang masuk periode ini", [300, 603], [520, 620]),
        (4, [294, 798, 1400, 879], [276, 781], "4. Pilih Kasbon sebagai potongan", [300, 782], [520, 799]),
    )},
    "05H-settlement-draft-list": {"title": "Panduan memeriksa settlement draft", "description": "Nomor 1 menunjukkan settlement yang baru dibuat. Draft masih dapat dibatalkan sebelum diposting.", "items": _items(
        (1, [295, 599, 1400, 668], [276, 580], "1. Cek SPB, Bonus Ayak, Kasbon, bruto, dan netto", [300, 574], [520, 600]),
    )},
    "05H-settlement-cancelled": {"title": "Panduan setelah settlement dibatalkan", "description": "Nomor 1 adalah pesan sukses. Sumber payroll tersedia kembali untuk dipilih ulang.", "items": _items(
        (1, [270, 366, 1249, 418], [250, 345], "1. Settlement dibatalkan; sumber tersedia kembali", [280, 342], [520, 367]),
        (2, [295, 599, 1400, 668], [276, 580], "2. Status berubah menjadi CANCELLED", [300, 574], [520, 600]),
    )},
    "05H-zero-net-cancelled": {"title": "Panduan membatalkan settlement netto nol", "description": "Nomor 1 mengonfirmasi sumber yang dipilih. Nomor 2 pesan sukses setelah draft dibatalkan.", "items": _items(
        (1, [294, 0, 1224, 282], [276, 18], "1. Kasbon dapat sama dengan bruto; netto menjadi Rp 0", [300, 4], [520, 1]),
        (2, [270, 366, 1249, 418], [250, 345], "2. Setelah dibatalkan, sumber dapat dipilih kembali", [280, 342], [520, 367]),
    )},
    "05H-overlimit-inline-error": {"title": "Panduan menangani Kasbon melebihi hak", "description": "Nomor 1 menunjukkan peringatan. Sistem menolak settlement dan tidak membuat payroll baru.", "items": _items(
        (1, [270, 366, 1249, 418], [250, 345], "1. Kasbon melebihi hak; kurangi atau pindahkan ke periode lain", [280, 342], [520, 367]),
    )},
    "05I-settlement-posted-list": {"title": "Panduan hasil posting settlement ke Payroll", "description": "Nomor 1 mengonfirmasi proses posting. Detail Payroll menjadi bukti pembayaran gabungan.", "items": _items(
        (1, [270, 366, 1249, 418], [250, 345], "1. Settlement berhasil diposting ke Payroll Essentials", [280, 342], [520, 367]),
    )},
    "05I-settlement-posted-row": {"title": "Panduan memeriksa settlement yang sudah diposting", "description": "Nomor 1 menunjukkan settlement POSTED. Nomor 2 membuka Payroll untuk rincian allowance dan deduction.", "items": _items(
        (1, [295, 134, 1224, 249], [278, 119], "1. Pastikan status POSTED, bruto, netto, dan Kasbon sesuai", [300, 112], [570, 135]),
        (2, [1135, 181, 1218, 205], [1118, 166], "2. Buka Lihat Payroll", [1040, 168], [1135, 182]),
    )},
    "05I-payroll-detail": {"title": "Panduan memeriksa Payroll sopir", "description": "Nomor 1 adalah pendapatan trip dan Bonus Ayak. Nomor 2 adalah Kasbon. Nomor 3 adalah total netto.", "items": _items(
        (1, [10, 452, 620, 593], [8, 438], "1. Upah trip + Bonus Ayak masuk earnings", [15, 438], [300, 453]),
        (2, [628, 452, 1240, 593], [625, 438], "2. Kasbon masuk deductions", [640, 438], [900, 453]),
        (3, [10, 596, 1240, 624], [8, 612], "3. Total earnings dan deductions direkonsiliasi", [15, 595], [500, 597]),
    )},
    "05I-spb-after-payroll": {"title": "Panduan memeriksa SPB setelah Payroll", "description": "Nomor 1 adalah snapshot tarif dan nilai. Detail SPB tetap sama setelah settlement diposting.", "items": _items(
        (1, [295, 360, 1224, 551], [276, 340], "1. SPB tetap menyimpan tonase, Harga/Ton, dan nilai historis", [300, 345], [520, 361]),
    )},
    "05J-rekap-with-bonus": {"title": "Panduan membaca Rekap Alat dan Sopir", "description": "Nomor 1 filter. Nomor 2 pendapatan dan upah. Nomor 3 Bonus Ayak. Nomor 4 kontribusi setelah bonus.", "items": _items(
        (1, [294, 410, 1225, 489], [276, 392], "1. Gunakan filter tanggal, unit, sopir, dan Project", [300, 392], [500, 411]),
        (2, [270, 539, 913, 625], [252, 522], "2. Pendapatan pelanggan dan Upah Sopir dipisahkan", [280, 522], [540, 540]),
        (3, [925, 539, 1082, 625], [913, 522], "3. Bonus Ayak masuk biaya", [940, 522], [1000, 540]),
        (4, [1110, 539, 1250, 625], [1098, 522], "4. Kontribusi setelah Bonus", [1115, 522], [1200, 540]),
    )},
    "05J-asset-ledger-with-manual-bonus": {"title": "Panduan membaca Ledger unit", "description": "Nomor 1 ringkasan unit. Nomor 2 rincian trip. Nomor 3 Bonus Ayak manual yang tetap masuk biaya.", "items": _items(
        (1, [270, 366, 1250, 462], [252, 345], "1. Ringkasan trip, tonase, pendapatan, upah, bonus, kontribusi", [280, 342], [540, 367]),
        (2, [270, 502, 1250, 624], [252, 482], "2. Cek status invoice dan settlement per trip", [280, 476], [540, 503]),
    )},
    "05J-project-trip-tab-with-bonus-contribution": {"title": "Panduan membaca Trip Angkutan pada Project", "description": "Nomor 1 ringkasan Project. Nomor 2 tindakan invoice. Nomor 3 daftar trip verified. Bonus Ayak tetap biaya, bukan line invoice.", "items": _items(
        (1, [281, 224, 1000, 269], [270, 203], "1. Pendapatan, Upah Sopir, Bonus Ayak, dan kontribusi terlihat terpisah", [290, 200], [600, 225]),
        (2, [281, 285, 919, 322], [270, 270], "2. Pilih SPB untuk Invoice hanya dari Project ini", [290, 272], [570, 286]),
        (3, [281, 340, 1238, 605], [270, 325], "3. Daftar trip verified menjadi dasar penagihan", [290, 325], [570, 341]),
    )},
    "05K-change-log-overview": {"title": "Panduan membuka Change Log", "description": "Nomor 1 ikon ! di navbar. Nomor 2 ringkasan rilis. Nomor 3 tautan detail dan cara pakai.", "items": _items(
        (1, [1102, 14, 1131, 57], [1085, 15], "1. Klik ikon ! untuk melihat perubahan", [950, 8], [1102, 15]),
        (2, [270, 136, 1249, 338], [252, 118], "2. Baca manfaat rilis terbaru", [280, 116], [560, 137]),
        (3, [294, 283, 514, 319], [276, 268], "3. Buka penjelasan dan cara pakai", [530, 268], [514, 284]),
    )},
    "05K-change-log-detail": {"title": "Panduan membaca detail Change Log", "description": "Nomor 1 manfaat. Nomor 2 istilah penting. Nomor 3 persiapan sebelum memakai fitur.", "items": _items(
        (1, [294, 158, 1225, 290], [276, 144], "1. Pahami perubahan dan manfaatnya", [300, 143], [520, 159]),
        (2, [294, 345, 1225, 508], [276, 331], "2. Baca arti SPB, Verifikasi, dan Settlement", [300, 330], [560, 346]),
        (3, [294, 531, 1225, 624], [276, 518], "3. Siapkan master dan data perjalanan", [300, 514], [560, 532]),
    )},
    "05K-change-log-overview-mobile": {"title": "Panduan Change Log pada ponsel", "description": "Nomor 1 ikon !. Nomor 2 ringkasan. Nomor 3 link detail.", "items": _items(
        (1, [326, 67, 371, 112], [310, 61], "1. Ikon ! tetap mudah ditemukan", [170, 8], [326, 68]),
        (2, [15, 213, 375, 434], [8, 194], "2. Baca ringkasan perubahan", [20, 188], [100, 214]),
        (3, [27, 435, 363, 469], [10, 419], "3. Buka detail cara pakai", [35, 411], [180, 436]),
    )},
    "05K-change-log-detail-mobile": {"title": "Panduan detail Change Log pada ponsel", "description": "Nomor 1 kembali. Nomor 2 manfaat. Nomor 3 istilah penting.", "items": _items(
        (1, [15, 132, 375, 166], [8, 117], "1. Kembali ke overview bila selesai", [20, 110], [170, 133]),
        (2, [15, 267, 375, 494], [8, 251], "2. Baca manfaat pada kartu panduan", [20, 245], [150, 268]),
        (3, [15, 568, 375, 843], [8, 550], "3. Istilah penting dijelaskan sebelum langkah", [20, 542], [160, 569]),
    )},
    "06D-payroll-form-filled": {"title": "Panduan payroll bulanan biasa", "description": "Nomor 1 memilih karyawan dan periode. Nomor 2 mengisi gaji pokok. Nomor 3 menyimpan payroll.", "items": _items(
        (1, [330, 30, 930, 375], [315, 12], "1. Pastikan karyawan dan periode payroll benar", [330, 10], [470, 31]),
        (2, [350, 180, 910, 275], [335, 162], "2. Isi gaji pokok atau komponen sesuai payroll biasa", [335, 155], [500, 181]),
        (3, [770, 320, 850, 360], [755, 302], "3. Simpan setelah total terhitung", [650, 302], [770, 321]),
    )},
    "06D-payroll-detail-modal": {"title": "Panduan memeriksa payroll bulanan", "description": "Nomor 1 memeriksa periode dan karyawan. Nomor 2 earnings. Nomor 3 deductions dan net pay.", "items": _items(
        (1, [181, 30, 1083, 220], [165, 12], "1. Cocokkan karyawan dan periode", [185, 10], [470, 31]),
        (2, [198, 390, 630, 505], [180, 370], "2. Earnings berisi gaji pokok", [200, 371], [380, 391]),
        (3, [632, 390, 1067, 580], [610, 568], "3. Periksa deductions dan net pay", [650, 566], [850, 581]),
    )},
    "06E-maintenance-edit-open": {"title": "Panduan mengenali maintenance terkunci", "description": "Nomor 1 status maintenance. Nomor 2 catatan yang tidak dapat diubah ketika status Completed.", "items": _items(
        (1, [330, 30, 930, 375], [315, 12], "1. Periksa status dan nomor maintenance", [335, 10], [470, 31]),
        (2, [345, 245, 918, 330], [330, 225], "2. Status Completed mengunci catatan; gunakan koreksi resmi", [335, 220], [530, 246]),
    )},
    "06E-notification-dropdown-clean": {"title": "Panduan membuka notifikasi lama", "description": "Nomor 1 membuka dropdown notifikasi. Nomor 2 memilih notifikasi untuk menuju fitur terkait. Ikon ! Change Log tetap terpisah.", "items": _items(
        (1, [1035, 16, 1099, 58], [1018, 8], "1. Klik lonceng untuk notifikasi lama", [860, 8], [1035, 17]),
        (2, [763, 59, 1083, 350], [745, 42], "2. Pilih notifikasi untuk membuka fitur terkait", [760, 42], [900, 60]),
        (3, [1100, 16, 1134, 58], [1085, 62], "3. Ikon ! membuka Change Log", [1138, 64], [1115, 58]),
    )},
    "06E-notification-mobile": {"title": "Panduan notifikasi pada ponsel", "description": "Nomor 1 notifikasi lama terbuka. Nomor 2 setiap item dapat dipilih. Ikon ! tetap terlihat di navbar.", "items": _items(
        (1, [0, 58, 314, 819], [5, 40], "1. Dropdown notifikasi terbuka tanpa menutup konteks halaman", [10, 30], [20, 59]),
        (2, [15, 75, 300, 310], [8, 330], "2. Pilih item untuk membuka tujuan", [20, 325], [100, 76]),
        (3, [326, 12, 371, 58], [310, 2], "3. Ikon ! mudah ditemukan", [165, 2], [326, 13]),
    )},
    "06F-spb-detail-bottom": {"title": "Panduan merekonsiliasi nilai SPB", "description": "Nomor 1 nilai tagihan. Nomor 2 tarif dan Uang Jalan. Nomor 3 status settlement.", "items": _items(
        (1, [295, 10, 1223, 181], [276, 2], "1. Cocokkan ton tagihan dan nilai akhir", [300, 2], [520, 11]),
        (2, [295, 182, 1000, 282], [276, 165], "2. Pisahkan pendapatan pelanggan dari upah sopir", [300, 164], [520, 183]),
        (3, [295, 228, 1000, 282], [276, 210], "3. Pastikan status VERIFIED dan settlement benar", [300, 209], [750, 229]),
    )},
    "06F-project-trip-tab": {"title": "Panduan merekonsiliasi Project", "description": "Nomor 1 ringkasan Project. Nomor 2 daftar trip verified. Bonus Ayak tetap biaya, bukan invoice pelanggan.", "items": _items(
        (1, [280, 223, 1000, 270], [267, 202], "1. Cocokkan ton, pendapatan, upah, bonus, dan kontribusi", [290, 198], [580, 224]),
        (2, [280, 340, 1238, 605], [267, 322], "2. Pastikan semua SPB Project tampil sekali dan verified", [290, 320], [560, 341]),
    )},
    "06F-sell-detail": {"title": "Panduan merekonsiliasi Sell angkutan", "description": "Nomor 1 line menyimpan rute dan unit. Nomor 2 ton dan Harga/Ton. Nomor 3 total tagihan pelanggan.", "items": _items(
        (1, [270, 340, 1220, 430], [255, 322], "1. Cocokkan rute, unit, sopir, dan tonase", [280, 320], [500, 341]),
        (2, [270, 430, 1220, 510], [255, 412], "2. Cocokkan quantity dan Harga/Ton", [280, 410], [600, 431]),
        (3, [270, 510, 1220, 610], [255, 492], "3. Total payable adalah tagihan pelanggan", [280, 490], [620, 511]),
    )},
    "06F-settlement-payroll-detail": {"title": "Panduan merekonsiliasi Payroll angkutan", "description": "Nomor 1 earnings berisi upah trip dan Bonus Ayak. Nomor 2 Kasbon. Nomor 3 net pay.", "items": _items(
        (1, [0, 155, 630, 335], [8, 138], "1. Earnings: upah trip + Bonus Ayak", [15, 135], [300, 156]),
        (2, [632, 155, 1260, 335], [620, 138], "2. Deductions: Kasbon", [640, 135], [900, 156]),
        (3, [0, 335, 1260, 445], [8, 464], "3. Net pay = bruto - Kasbon", [15, 445], [650, 336]),
    )},
    "06F-spb-list": {"title": "Panduan mencocokkan daftar SPB", "description": "Nomor 1 identitas SPB. Nomor 2 pendapatan dan upah. Nomor 3 status billing.", "items": _items(
        (1, [270, 380, 480, 625], [252, 360], "1. Cocokkan nomor SPB, tiket, rute, unit, dan sopir", [280, 350], [450, 381]),
        (2, [480, 380, 935, 625], [465, 360], "2. Cocokkan tonase, pendapatan, dan upah", [500, 350], [700, 381]),
        (3, [935, 380, 1240, 625], [918, 360], "3. Bedakan Verified billed dan Verified unbilled", [900, 350], [1080, 381]),
    )},
    "06F-bonus-list": {"title": "Panduan mencocokkan Bonus Ayak", "description": "Nomor 1 relasi sopir/unit/Project. Nomor 2 sumber SPB dan bonus. Bonus bukan invoice pelanggan.", "items": _items(
        (1, [270, 400, 780, 625], [252, 382], "1. Cocokkan sopir, unit, dan Project", [280, 370], [500, 401]),
        (2, [780, 400, 1235, 625], [762, 382], "2. Cocokkan SPB sumber dan nominal Bonus", [790, 370], [1000, 401]),
    )},
    "06F-kasbon-list": {"title": "Panduan mencocokkan Kasbon", "description": "Nomor 1 sopir dan nominal. Nomor 2 status terkunci payroll. Kasbon menjadi deduction settlement.", "items": _items(
        (1, [270, 400, 900, 625], [252, 382], "1. Cocokkan tanggal, sopir, dan nominal Kasbon", [280, 370], [520, 401]),
        (2, [900, 400, 1235, 625], [882, 382], "2. Jangan mengubah Kasbon yang terkunci payroll", [900, 370], [1050, 401]),
    )},
    "06H-kasbon-after-cleanup": {"title": "Panduan memeriksa cleanup Kasbon", "description": "Nomor 1 sumber posted tetap ada. Nomor 2 Kasbon uji yang sudah dibersihkan tidak lagi muncul.", "items": _items(
        (1, [270, 400, 900, 625], [252, 382], "1. Sumber Kasbon posted tetap dipertahankan", [280, 370], [520, 401]),
        (2, [900, 400, 1235, 625], [882, 382], "2. Kasbon uji yang dihapus tidak muncul setelah reload", [900, 370], [1050, 401]),
    )},
}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--preset", choices=sorted(PRESETS), help="Use an audited coordinate preset")
    parser.add_argument("--spec", type=Path, help="JSON list of annotation items for a new screenshot")
    parser.add_argument("--input", required=True, type=Path, help="Raw PNG captured from agent-browser")
    parser.add_argument("--output", required=True, type=Path, help="Raster PNG output")
    parser.add_argument("--title", default="Annotated Change Log screenshot")
    parser.add_argument("--description", default="Screenshot annotated from the raw browser capture")
    args = parser.parse_args()

    if bool(args.preset) == bool(args.spec):
        parser.error("choose exactly one of --preset or --spec")
    if args.preset:
        preset = PRESETS[args.preset]
        title = preset["title"]
        description = preset["description"]
        items = preset["items"]
    else:
        items = json.loads(args.spec.read_text(encoding="utf-8"))
        title = args.title
        description = args.description
    annotate(args.input, args.output, title, description, items)
    print(f"Wrote raster annotation: {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
