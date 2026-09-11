"""Raster audit evidence. Run from repository root; no application writes."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

root = Path('storage/app/changelog-evidence/REV01-AUDIT-20260911')
dest = root / 'annotations'
dest.mkdir(exist_ok=True)
source = root / 'raw/change-log-detail.png'
with Image.open(source) as raw:
    canvas = Image.new('RGB', (raw.width, raw.height + 100), 'white')
    canvas.paste(raw, (0, 100))
font = ImageFont.truetype('C:/Windows/Fonts/arial.ttf', 22)
draw = ImageDraw.Draw(canvas)
draw.text((20, 12), 'AUDIT 01: artikel berisi teks; tidak ada gambar panduan (DOM img = 0).', font=font, fill='#172b4d')
draw.text((20, 48), '1: langkah ringkas tanpa screenshot. 2: tautan hanya ke fitur, bukan tutorial per topik.', font=font, fill='#172b4d')
for n, box in [(1, (290, 620, 1405, 1320)), (2, (290, 1940, 700, 2050))]:
    draw.rectangle(box, outline='#c2410c', width=4)
    x, y = box[:2]
    draw.ellipse((x-34, y, x-4, y+30), fill='#c2410c')
    draw.text((x-28, y+2), str(n), font=font, fill='white')
canvas.save(dest / 'detail-audit-annotated.png')
print(f'{canvas.width}x{canvas.height}: {dest / "detail-audit-annotated.png"}')
