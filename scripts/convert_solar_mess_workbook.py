#!/usr/bin/env python3
"""Convert a Solar MESS ledger workbook into Solar-importable workbooks.

The application parses transaction ledgers and equipment-usage matrices through
separate import paths, so this script writes two XLSX files and a row-level CSV
audit. Equipment and usage are derived only when a supported equipment code is
present in the description; usage quantity comes from the MESS OUT column.
"""

from __future__ import annotations

import argparse
import calendar
import csv
import re
import sys
from collections import defaultdict
from datetime import date, datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any

from openpyxl import Workbook, load_workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter
from openpyxl.utils.datetime import from_excel


MESS_HEADERS = [
    "TANGGAL",
    "IN",
    "OUT",
    "CURRENT",
    "KETERANGAN",
    "NAMA KAPAL",
    "UNIT / ASSET",
    "OPERATOR",
    "CUSTOMER",
    "LOKASI",
]

# Equipment prefixes observed in this workbook. The first recognized equipment
# code is used so a parenthetical mention such as "Titip DT 03" cannot replace
# the unit performing the work (for example, MD 02).
EQUIPMENT_RE = re.compile(
    r"(?<![A-Z0-9])(?P<prefix>EX|MD|WL)\s*0*(?P<number>\d{1,2})(?!\d)",
    re.IGNORECASE,
)

MONTH_NAMES_ID = [
    "Januari",
    "Februari",
    "Maret",
    "April",
    "Mei",
    "Juni",
    "Juli",
    "Agustus",
    "September",
    "Oktober",
    "November",
    "Desember",
]

HEADER_FILL = PatternFill("solid", fgColor="1F9D74")
HEADER_FONT = Font(color="FFFFFF", bold=True)
THIN_GREY = Side(style="thin", color="D9E2F3")


def parse_decimal(value: Any, *, row: int, column: str) -> Decimal:
    if value is None or (isinstance(value, str) and value.strip() == ""):
        return Decimal("0")
    if isinstance(value, bool):
        raise ValueError(f"Baris {row}, kolom {column}: nilai boolean bukan angka.")
    if isinstance(value, str):
        text = value.strip().replace(" ", "")
        if "," in text and "." in text:
            if text.rfind(",") > text.rfind("."):
                text = text.replace(".", "").replace(",", ".")
            else:
                text = text.replace(",", "")
        elif "," in text:
            text = text.replace(",", ".")
    else:
        text = str(value)
    try:
        result = Decimal(text)
    except InvalidOperation as error:
        raise ValueError(f"Baris {row}, kolom {column}: {value!r} bukan angka yang valid.") from error
    if not result.is_finite():
        raise ValueError(f"Baris {row}, kolom {column}: angka harus finite.")
    return result


def as_excel_number(value: Decimal) -> int | float:
    if value == value.to_integral_value():
        return int(value)
    return float(value)


def parse_date(value: Any, *, epoch: datetime, row: int) -> date:
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        converted = from_excel(value, epoch=epoch)
        return converted.date() if isinstance(converted, datetime) else converted
    if isinstance(value, str):
        text = value.strip()
        for fmt in ("%d/%m/%Y", "%Y-%m-%d", "%d-%m-%Y", "%m/%d/%Y"):
            try:
                return datetime.strptime(text, fmt).date()
            except ValueError:
                pass
    raise ValueError(f"Baris {row}: tanggal tidak valid atau kosong ({value!r}).")


def equipment_from_description(description: str) -> str | None:
    match = EQUIPMENT_RE.search(description)
    if not match:
        return None
    return f"{match.group('prefix').upper()} {int(match.group('number')):02d}"


def clean_text(value: Any) -> str | None:
    if value is None:
        return None
    text = str(value).strip()
    return text or None


def read_source(input_path: Path, *, sheet_name: str | None = None) -> list[dict[str, Any]]:
    workbook = load_workbook(input_path, data_only=True, read_only=True)
    try:
        if sheet_name:
            if sheet_name not in workbook.sheetnames:
                raise ValueError(
                    f"Sheet {sheet_name!r} tidak ditemukan. Tersedia: {', '.join(workbook.sheetnames)}"
                )
            sheet = workbook[sheet_name]
        else:
            sheet = workbook[workbook.sheetnames[0]]

        headers = [clean_text(sheet.cell(1, column).value) for column in range(1, 7)]
        expected = ["TANGGAL", "IN", "OUT", "CURRENT", "KETERANGAN"]
        normalized = [(value or "").strip().upper() for value in headers]
        if normalized[:5] != expected:
            raise ValueError(
                "Header sumber harus dimulai dengan TANGGAL, IN, OUT, CURRENT, KETERANGAN; "
                f"ditemukan {headers!r}."
            )

        records: list[dict[str, Any]] = []
        for source_row in range(2, sheet.max_row + 1):
            raw = [sheet.cell(source_row, column).value for column in range(1, 7)]
            if all(value is None or (isinstance(value, str) and value.strip() == "") for value in raw):
                continue

            tx_date = parse_date(raw[0], epoch=workbook.epoch, row=source_row)
            qty_in = parse_decimal(raw[1], row=source_row, column="IN")
            qty_out = parse_decimal(raw[2], row=source_row, column="OUT")
            if qty_in < 0 or qty_out < 0:
                raise ValueError(f"Baris {source_row}: IN dan OUT tidak boleh negatif.")

            description = clean_text(raw[4]) or ""
            unit = equipment_from_description(description)
            current = parse_decimal(raw[3], row=source_row, column="CURRENT") if raw[3] not in (None, "") else None
            records.append(
                {
                    "source_row": source_row,
                    "tanggal": tx_date,
                    "in": qty_in,
                    "out": qty_out,
                    "current": current,
                    "keterangan": description,
                    "nama_kapal": clean_text(raw[5]),
                    "unit": unit,
                }
            )
        return records
    finally:
        workbook.close()


def style_headers(sheet, row: int, first_column: int, last_column: int) -> None:
    for column in range(first_column, last_column + 1):
        cell = sheet.cell(row, column)
        cell.fill = HEADER_FILL
        cell.font = HEADER_FONT
        cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
        cell.border = Border(bottom=THIN_GREY)


def build_mess_workbook(records: list[dict[str, Any]]) -> Workbook:
    workbook = Workbook()
    sheet = workbook.active
    sheet.title = "MESS"
    sheet.merge_cells("A2:J2")
    sheet["A2"] = "LAPORAN SOLAR MESS"
    sheet["A2"].font = Font(size=14, bold=True, color="1F1F1F")
    sheet["A2"].alignment = Alignment(horizontal="center")
    for column, header in enumerate(MESS_HEADERS, start=1):
        sheet.cell(4, column, header)
    style_headers(sheet, 4, 1, len(MESS_HEADERS))

    for row_number, record in enumerate(records, start=5):
        row = [
            record["tanggal"],
            as_excel_number(record["in"]) if record["in"] != 0 else None,
            as_excel_number(record["out"]) if record["out"] != 0 else None,
            as_excel_number(record["current"]) if record["current"] is not None else None,
            record["keterangan"] or None,
            record["nama_kapal"],
            record["unit"],
            None,  # Operator is not filled from free-text descriptions.
            None,  # Customer is absent from the source.
            None,  # Location is absent from the source.
        ]
        for column, value in enumerate(row, start=1):
            sheet.cell(row_number, column, value)
        sheet.cell(row_number, 1).number_format = "dd/mm/yyyy"

    sheet.freeze_panes = "A5"
    sheet.auto_filter.ref = f"A4:J{max(4, 4 + len(records))}"
    widths = [14, 12, 12, 12, 52, 22, 18, 22, 24, 22]
    for column, width in enumerate(widths, start=1):
        sheet.column_dimensions[get_column_letter(column)].width = width
    for row in sheet.iter_rows(min_row=5, max_row=4 + len(records), min_col=1, max_col=10):
        for cell in row:
            cell.alignment = Alignment(vertical="top", wrap_text=cell.column in (5, 6))
    workbook.properties.title = "Solar MESS Import"
    workbook.properties.subject = "Data MESS yang dikonversi dari workbook sumber"
    return workbook


def month_key(day: date) -> tuple[int, int]:
    return day.year, day.month


def month_title(year: int, month: int) -> str:
    return f"{MONTH_NAMES_ID[month - 1]} {year}"


def build_usage_workbook(records: list[dict[str, Any]]) -> tuple[Workbook, dict[tuple[int, int], int], Decimal]:
    monthly: dict[tuple[int, int], dict[str, dict[int, Decimal]]] = defaultdict(
        lambda: defaultdict(lambda: defaultdict(Decimal))
    )
    total_usage = Decimal("0")
    for record in records:
        quantity = record["out"]
        if record["unit"] and quantity > 0:
            monthly[month_key(record["tanggal"])][record["unit"]][record["tanggal"].day] += quantity
            total_usage += quantity

    source_months = sorted({month_key(record["tanggal"]) for record in records})
    workbook = Workbook()
    workbook.remove(workbook.active)
    equipment_row_counts: dict[tuple[int, int], int] = {}

    for year, month in source_months:
        sheet = workbook.create_sheet(month_title(year, month))
        sheet.merge_cells("A2:AI2")
        sheet["A2"] = f"DATA PEMAKAIAN SOLAR MESS - {month_title(year, month)}"
        sheet["A2"].font = Font(size=14, bold=True, color="FFFFFF")
        sheet["A2"].fill = PatternFill("solid", fgColor="1F4E78")
        sheet["A2"].alignment = Alignment(horizontal="center")

        sheet["A4"] = "No"
        sheet["B4"] = "Alat Berat"
        sheet.merge_cells("C4:AG4")
        sheet["C4"] = "Tanggal"
        sheet["AH4"] = "JUMLAH (Gln)"
        sheet["AI4"] = "LITER (Gln x 30)"
        style_headers(sheet, 4, 1, 35)

        last_day = calendar.monthrange(year, month)[1]
        for day in range(1, 32):
            column = day + 2
            if day <= last_day:
                sheet.cell(5, column, date(year, month, day))
                sheet.cell(5, column).number_format = "d/m"
            sheet.cell(5, column).alignment = Alignment(horizontal="center")
        for cell in sheet[5][:35]:
            cell.border = Border(bottom=THIN_GREY)

        equipment = sorted(monthly[(year, month)])
        equipment_row_counts[(year, month)] = len(equipment)
        for index, name in enumerate(equipment, start=1):
            row = index + 5
            sheet.cell(row, 1, index)
            sheet.cell(row, 2, name)
            row_total = Decimal("0")
            for day, quantity in monthly[(year, month)][name].items():
                column = day + 2
                sheet.cell(row, column, as_excel_number(quantity))
                row_total += quantity
            sheet.cell(row, 34, as_excel_number(row_total))
            sheet.cell(row, 35, as_excel_number(row_total * Decimal("30")))
            for cell in sheet[row][:35]:
                cell.border = Border(bottom=THIN_GREY)
                if cell.column >= 3:
                    cell.number_format = "#,##0.00"

        sheet.freeze_panes = "C6"
        sheet.auto_filter.ref = f"A4:AI{max(5, 5 + len(equipment))}"
        sheet.column_dimensions["A"].width = 7
        sheet.column_dimensions["B"].width = 22
        for column in range(3, 34):
            sheet.column_dimensions[get_column_letter(column)].width = 9
        sheet.column_dimensions["AH"].width = 15
        sheet.column_dimensions["AI"].width = 18

    workbook.properties.title = "Solar Equipment Usage Import"
    workbook.properties.subject = "Pemakaian alat dari OUT pada transaksi MESS"
    return workbook, equipment_row_counts, total_usage


def write_audit(
    output_path: Path,
    records: list[dict[str, Any]],
) -> None:
    with output_path.open("w", newline="", encoding="utf-8-sig") as file:
        writer = csv.DictWriter(
            file,
            fieldnames=[
                "source_row",
                "tanggal",
                "keterangan",
                "in_gln",
                "out_gln",
                "current_gln",
                "unit_from_description",
                "usage_imported_gln",
                "status",
            ],
        )
        writer.writeheader()
        for record in records:
            quantity = record["out"]
            if record["unit"] and quantity > 0:
                status = "pemakaian dibuat; unit dan OUT terdeteksi"
                imported_usage: str | int = as_excel_number(quantity)
            elif record["unit"]:
                status = "unit terdeteksi; OUT nol, tidak membuat pemakaian"
                imported_usage = ""
            elif quantity > 0:
                status = "OUT positif tanpa kode alat; perlu pemeriksaan manual"
                imported_usage = ""
            else:
                status = "tidak ada pemakaian alat pada baris ini"
                imported_usage = ""
            writer.writerow(
                {
                    "source_row": record["source_row"],
                    "tanggal": record["tanggal"].isoformat(),
                    "keterangan": record["keterangan"],
                    "in_gln": as_excel_number(record["in"]),
                    "out_gln": as_excel_number(quantity),
                    "current_gln": "" if record["current"] is None else as_excel_number(record["current"]),
                    "unit_from_description": record["unit"] or "",
                    "usage_imported_gln": imported_usage,
                    "status": status,
                }
            )


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Buat workbook Solar MESS dan pemakaian alat yang dapat diimpor melalui halaman Solar."
    )
    parser.add_argument("--input", type=Path, default=Path("solar.xlsx"), help="Workbook sumber (default: solar.xlsx).")
    parser.add_argument("--sheet", help="Nama sheet sumber. Default: sheet pertama.")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("storage/app/solar-mess-import"),
        help="Folder output (default: storage/app/solar-mess-import).",
    )
    parser.add_argument("--prefix", default="solar", help="Awalan nama file output (default: solar).")
    parser.add_argument("--overwrite", action="store_true", help="Izinkan mengganti file output yang sudah ada.")
    args = parser.parse_args()

    input_path = args.input.expanduser().resolve()
    if not input_path.is_file():
        parser.error(f"Workbook sumber tidak ditemukan: {input_path}")

    try:
        records = read_source(input_path, sheet_name=args.sheet)
    except (ValueError, OSError) as error:
        parser.error(str(error))
    if not records:
        parser.error("Tidak ada baris MESS berisi data pada workbook sumber.")

    output_dir = args.output_dir.expanduser().resolve()
    prefix = re.sub(r"[^A-Za-z0-9_-]+", "_", args.prefix.strip()).strip("_") or "solar"
    outputs = {
        "mess": output_dir / f"{prefix}_mess_import.xlsx",
        "usage": output_dir / f"{prefix}_equipment_usage_import.xlsx",
        "audit": output_dir / f"{prefix}_equipment_usage_audit.csv",
    }
    existing = [path for path in outputs.values() if path.exists()]
    if existing and not args.overwrite:
        parser.error(
            "File output sudah ada; gunakan --overwrite bila memang ingin menggantinya: "
            + ", ".join(str(path) for path in existing)
        )

    # Build the same aggregate used by the workbook so CSV and XLSX summaries
    # remain traceable to the original MESS rows.
    usage_monthly: dict[tuple[int, int], dict[str, dict[int, Decimal]]] = defaultdict(
        lambda: defaultdict(lambda: defaultdict(Decimal))
    )
    for record in records:
        if record["unit"] and record["out"] > 0:
            usage_monthly[month_key(record["tanggal"])][record["unit"]][record["tanggal"].day] += record["out"]

    try:
        output_dir.mkdir(parents=True, exist_ok=True)
        mess_workbook = build_mess_workbook(records)
        usage_workbook, equipment_row_counts, total_usage = build_usage_workbook(records)
        mess_workbook.save(outputs["mess"])
        usage_workbook.save(outputs["usage"])
        write_audit(outputs["audit"], records)
    except OSError as error:
        print(f"Gagal menulis hasil: {error}", file=sys.stderr)
        return 1

    source_positive_out = sum(1 for record in records if record["out"] > 0)
    imported_usage_rows = sum(equipment_row_counts.values())
    usage_day_cells = sum(
        len(days)
        for equipment_by_month in usage_monthly.values()
        for days in equipment_by_month.values()
    )
    unrecognized_positive_out = sum(
        1 for record in records if record["out"] > 0 and not record["unit"]
    )
    print(f"Sumber: {input_path}")
    print(f"Baris MESS: {len(records)}")
    print(f"Baris dengan OUT positif: {source_positive_out}")
    print(f"Baris alat pada matriks: {imported_usage_rows}")
    print(f"Kombinasi alat-tanggal pada matriks: {usage_day_cells}")
    print(f"Total pemakaian terdeteksi: {as_excel_number(total_usage)} Gln ({as_excel_number(total_usage * Decimal('30'))} liter)")
    print(f"OUT positif tanpa kode alat: {unrecognized_positive_out}")
    print("Output:")
    for path in outputs.values():
        print(f"  {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
