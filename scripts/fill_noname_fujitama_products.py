#!/usr/bin/env python3
"""Replace No Name products in the Fujitama purchase list using the closest legacy price.

The source workbooks are never modified. Run without --apply to preview matches,
then use --apply to create a separate completed workbook and an Excel-friendly audit CSV.

Dependency: python -m pip install openpyxl
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import os
import posixpath
import re
import sys
import tempfile
import zipfile
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from copy import copy
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Any

from openpyxl import load_workbook

TARGET_SHEET = "TAMBAH FUJITAMA MANDIRI"
SOURCE_SHEET = "PEMBELIAN 2023"
SUPPLIER = "CV. Fujitama Mandiri (PPN)"
CENT = Decimal("0.01")
NO_NAME_PATTERN = re.compile(r"\bno\s*name\b", re.IGNORECASE)
QUANTITY_UNIT_PATTERN = re.compile(
    r"(?:\u00d7|\bx\b)\s*(?P<quantity>\d+(?:[.,]\d+)?)\s*(?P<unit>[A-Za-z]+)",
    re.IGNORECASE,
)


@dataclass(frozen=True)
class ProductCandidate:
    source_row: int
    reference_no: str
    date: str
    product: str
    quantity: Decimal
    unit: str
    tag: str
    unit_price_before_tax: Decimal


@dataclass(frozen=True)
class Match:
    target_row: int
    target_price: Decimal
    target_tag: str
    original_product: str
    quantity_to_restore: Decimal | None
    unit_to_restore: str | None
    selected: ProductCandidate
    nearest_candidates: tuple[ProductCandidate, ...]
    candidates_used_for_tiebreak: tuple[ProductCandidate, ...]
    difference: Decimal
    difference_percent: Decimal
    confidence: str
    selection_method: str


@dataclass(frozen=True)
class QuantityUnitUpdate:
    target_row: int
    product: str
    original_quantity: Any
    original_unit: Any
    parsed_components: tuple[tuple[Decimal, str], ...]
    new_quantity: float | str | None
    new_unit: str | None
    aggregation_method: str
    fill_quantity: bool
    fill_unit: bool


def normalize(value: Any) -> str:
    return " ".join(str(value or "").split()).casefold()


def normalize_tag(value: Any) -> str:
    return " ".join(re.findall(r"[A-Z0-9]+", str(value or "").upper()))


def decimal_value(value: Any, *, field: str, row_number: int) -> Decimal:
    if value is None or value == "":
        raise ValueError(f"Nilai {field} kosong pada baris {row_number}.")
    try:
        return Decimal(str(value)).quantize(CENT, rounding=ROUND_HALF_UP)
    except (InvalidOperation, ValueError) as exc:
        raise ValueError(f"Nilai {field} tidak valid pada baris {row_number}: {value!r}.") from exc


def header_columns(sheet, required: set[str], header_row: int = 1) -> dict[str, int]:
    columns = {
        normalize(cell.value): cell.column
        for cell in sheet[header_row]
        if cell.value is not None
    }
    missing = required - columns.keys()
    if missing:
        raise ValueError(f"Header sheet {sheet.title!r} tidak ditemukan: {', '.join(sorted(missing))}.")
    return columns


def find_sheet(workbook, expected_name: str):
    for name in workbook.sheetnames:
        if normalize(name) == normalize(expected_name):
            return workbook[name]
    raise ValueError(
        f"Sheet {expected_name!r} tidak ditemukan. Sheet tersedia: {', '.join(workbook.sheetnames)}."
    )


def workbook_formula_map(path: Path) -> dict[str, dict[str, str]]:
    workbook = load_workbook(path, read_only=True, data_only=False)
    try:
        formulas: dict[str, dict[str, str]] = {}
        for sheet in workbook.worksheets:
            formulas[sheet.title] = {
                cell.coordinate: cell.value
                for row in sheet.iter_rows()
                for cell in row
                if isinstance(cell.value, str) and cell.value.startswith("=")
            }
        return formulas
    finally:
        workbook.close()


def worksheet_paths(archive: zipfile.ZipFile) -> dict[str, str]:
    main_ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    rel_ns = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    package_rel_ns = "http://schemas.openxmlformats.org/package/2006/relationships"
    relationships = ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))
    targets = {
        item.attrib["Id"]: item.attrib["Target"]
        for item in relationships.findall(f"{{{package_rel_ns}}}Relationship")
    }
    workbook_xml = ET.fromstring(archive.read("xl/workbook.xml"))
    paths = {}
    for sheet in workbook_xml.findall(f"{{{main_ns}}}sheets/{{{main_ns}}}sheet"):
        target = targets[sheet.attrib[f"{{{rel_ns}}}id"]]
        path = target.lstrip("/") if target.startswith("/") else posixpath.normpath(posixpath.join("xl", target))
        paths[sheet.attrib["name"]] = path
    return paths


def register_xml_namespaces(xml_data: bytes) -> None:
    for _, (prefix, uri) in ET.iterparse(io.BytesIO(xml_data), events=("start-ns",)):
        if not prefix.startswith("ns") or not prefix[2:].isdigit():
            ET.register_namespace(prefix, uri)


def restore_formula_cached_values(source_path: Path, output_path: Path) -> int:
    """Copy original cached formula values for every unchanged formula cell."""
    source_formulas = workbook_formula_map(source_path)
    output_formulas = workbook_formula_map(output_path)
    unchanged = {
        sheet_name: {
            coordinate
            for coordinate, formula in formulas.items()
            if output_formulas.get(sheet_name, {}).get(coordinate) == formula
        }
        for sheet_name, formulas in source_formulas.items()
    }

    main_ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    cell_tag = f"{{{main_ns}}}c"
    formula_tag = f"{{{main_ns}}}f"
    value_tag = f"{{{main_ns}}}v"
    replacements: dict[str, bytes] = {}
    restored_count = 0

    with zipfile.ZipFile(source_path, "r") as source_archive, zipfile.ZipFile(output_path, "r") as output_archive:
        source_sheets = worksheet_paths(source_archive)
        output_sheets = worksheet_paths(output_archive)
        for sheet_name in source_sheets.keys() & output_sheets.keys():
            source_root = ET.fromstring(source_archive.read(source_sheets[sheet_name]))
            output_xml = output_archive.read(output_sheets[sheet_name])
            register_xml_namespaces(output_xml)
            output_root = ET.fromstring(output_xml)
            source_cells = {
                cell.get("r"): cell
                for cell in source_root.iter(cell_tag)
                if cell.find(formula_tag) is not None
                and cell.find(value_tag) is not None
                and cell.find(value_tag).text is not None
                and cell.get("r") in unchanged.get(sheet_name, set())
            }

            restored_on_sheet = 0
            for output_cell in output_root.iter(cell_tag):
                source_cell = source_cells.get(output_cell.get("r"))
                if source_cell is None or output_cell.find(formula_tag) is None:
                    continue
                source_value = source_cell.find(value_tag)
                output_value = output_cell.find(value_tag)
                if output_value is None:
                    output_value = ET.SubElement(output_cell, value_tag)
                output_value.text = source_value.text
                output_value.attrib.clear()
                output_value.attrib.update(source_value.attrib)

                source_type = source_cell.get("t")
                if source_type is None:
                    output_cell.attrib.pop("t", None)
                else:
                    output_cell.set("t", source_type)
                restored_on_sheet += 1

            if restored_on_sheet:
                replacements[output_sheets[sheet_name]] = ET.tostring(
                    output_root, encoding="utf-8", xml_declaration=True
                )
                restored_count += restored_on_sheet

        archive_comment = output_archive.comment

    if not replacements:
        return 0

    with tempfile.NamedTemporaryFile(
        prefix=f"{output_path.stem}_", suffix=".xlsx", dir=output_path.parent, delete=False
    ) as handle:
        temporary_path = Path(handle.name)
    try:
        with zipfile.ZipFile(output_path, "r") as output_archive, zipfile.ZipFile(
            temporary_path, "w"
        ) as rewritten_archive:
            rewritten_archive.comment = archive_comment
            for item in output_archive.infolist():
                data = replacements.get(item.filename)
                if data is None:
                    data = output_archive.read(item.filename)
                rewritten_archive.writestr(item, data)
        os.replace(temporary_path, output_path)
    finally:
        temporary_path.unlink(missing_ok=True)

    return restored_count


def read_candidates(source_path: Path, source_sheet_name: str) -> list[ProductCandidate]:
    workbook = load_workbook(source_path, read_only=True, data_only=True)
    try:
        sheet = find_sheet(workbook, source_sheet_name)
        columns = header_columns(
            sheet,
            {"supplier", "products", "kuantitas", "satuan", "tags", "total sebelum pajak", "reference no", "date"},
        )
        candidates = []
        for row_number, row in enumerate(sheet.iter_rows(min_row=2, values_only=True), start=2):
            supplier = row[columns["supplier"] - 1]
            if normalize(supplier) != normalize(SUPPLIER):
                continue

            product = str(row[columns["products"] - 1] or "").strip()
            if not product or NO_NAME_PATTERN.search(product):
                continue

            unit = str(row[columns["satuan"] - 1] or "").strip()
            if normalize(unit) != "pcs":
                continue

            try:
                quantity = Decimal(str(row[columns["kuantitas"] - 1]))
                total_before_tax = Decimal(str(row[columns["total sebelum pajak"] - 1]))
            except (InvalidOperation, TypeError, ValueError):
                continue
            if quantity <= 0 or total_before_tax <= 0:
                continue

            unit_price = (total_before_tax / quantity).quantize(CENT, rounding=ROUND_HALF_UP)
            reference_no = str(row[columns["reference no"] - 1] or "").strip()
            source_date = row[columns["date"] - 1]
            candidates.append(
                ProductCandidate(
                    source_row=row_number,
                    reference_no=reference_no,
                    date=str(source_date or "").strip(),
                    product=product,
                    quantity=quantity,
                    unit=unit,
                    tag=str(row[columns["tags"] - 1] or "").strip(),
                    unit_price_before_tax=unit_price,
                )
            )
        return candidates
    finally:
        workbook.close()


def parse_embedded_quantity_and_unit(product: str) -> tuple[Decimal | None, str | None]:
    match = QUANTITY_UNIT_PATTERN.search(product.replace("\n", " "))
    if not match:
        return None, None
    quantity_text = match.group("quantity").replace(",", ".")
    return Decimal(quantity_text), match.group("unit").strip()


def parse_embedded_quantities_and_units(product: str) -> tuple[tuple[Decimal, str], ...]:
    components = []
    for match in QUANTITY_UNIT_PATTERN.finditer(product.replace("\n", " ")):
        quantity_text = match.group("quantity").replace(",", ".")
        components.append((Decimal(quantity_text), match.group("unit").strip()))
    return tuple(components)


def quantity_unit_summary(components: tuple[tuple[Decimal, str], ...]) -> tuple[float | str, str, str]:
    """Combine quantities by unit while preserving mixed units as readable text."""
    totals: dict[str, Decimal] = {}
    display_units: dict[str, str] = {}
    for quantity, unit in components:
        key = unit.casefold()
        totals[key] = totals.get(key, Decimal("0")) + quantity
        display_units.setdefault(key, unit)

    if len(totals) == 1:
        key, total = next(iter(totals.items()))
        return float(total), display_units[key], "jumlah_digabung_satuan_sama"

    details = [f"{format_quantity(totals[key])} {display_units[key]}" for key in totals]
    units = ", ".join(display_units[key] for key in totals)
    return "; ".join(details), units, "rincian_dipertahankan_satuan_berbeda"


def format_quantity(quantity: Decimal) -> str:
    normalized = quantity.normalize()
    return format(normalized, "f")


def quantity_unit_audit_row(update: QuantityUnitUpdate) -> dict[str, Any]:
    return {
        "baris_sheet_target": update.target_row,
        "produk": update.product,
        "kuantitas_sebelumnya": update.original_quantity if update.original_quantity not in (None, "") else "",
        "satuan_sebelumnya": update.original_unit if update.original_unit not in (None, "") else "",
        "kuantitas_usulan": update.new_quantity if update.fill_quantity else "",
        "satuan_usulan": update.new_unit if update.fill_unit else "",
        "rincian_kuantitas_terbaca": "; ".join(
            f"{format_quantity(quantity)} {unit}" for quantity, unit in update.parsed_components
        ),
        "metode": update.aggregation_method,
        "kuantitas_diisi": "ya" if update.fill_quantity else "tidak",
        "satuan_diisi": "ya" if update.fill_unit else "tidak",
    }


def match_target_row(
    row_number: int,
    product: str,
    tag: str,
    price: Decimal,
    candidates: list[ProductCandidate],
) -> Match:
    differences = [(abs(candidate.unit_price_before_tax - price), candidate) for candidate in candidates]
    minimum_difference = min(difference for difference, _ in differences)
    nearest = tuple(candidate for difference, candidate in differences if difference == minimum_difference)

    tag_key = normalize_tag(tag)
    same_tag = tuple(
        candidate for candidate in nearest if tag_key and normalize_tag(candidate.tag) == tag_key
    )
    tiebreak_pool = same_tag or nearest

    tied_products = {normalize(candidate.product) for candidate in tiebreak_pool}
    product_frequency = Counter(normalize(candidate.product) for candidate in tiebreak_pool)
    most_frequent = max(product_frequency.values())
    selected_product_key = min(
        (product_key for product_key, frequency in product_frequency.items() if frequency == most_frequent)
    )
    selected_rows = [candidate for candidate in tiebreak_pool if normalize(candidate.product) == selected_product_key]
    selected = min(selected_rows, key=lambda candidate: candidate.source_row)

    if len({normalize(candidate.product) for candidate in nearest}) == 1:
        selection_method = "harga_terdekat_produk_sama"
    elif same_tag:
        selection_method = "harga_terdekat_lalu_tag_sama"
    elif most_frequent > 1:
        selection_method = "harga_terdekat_lalu_frekuensi"
    else:
        selection_method = "harga_terdekat_lalu_urut_nama"

    difference_percent = (minimum_difference / price * Decimal("100")) if price else Decimal("0")
    confidence = "rendah" if difference_percent > Decimal("25") else "sedang" if difference_percent > Decimal("5") else "tinggi"
    embedded_quantity, embedded_unit = parse_embedded_quantity_and_unit(product)

    return Match(
        target_row=row_number,
        target_price=price,
        target_tag=tag,
        original_product=product,
        quantity_to_restore=embedded_quantity,
        unit_to_restore=embedded_unit,
        selected=selected,
        nearest_candidates=nearest,
        candidates_used_for_tiebreak=tiebreak_pool,
        difference=minimum_difference,
        difference_percent=difference_percent,
        confidence=confidence,
        selection_method=selection_method,
    )


def alternatives_json(match: Match) -> str:
    grouped: dict[str, dict[str, Any]] = defaultdict(lambda: {"rows": [], "tags": set(), "price": None})
    for candidate in match.nearest_candidates:
        key = candidate.product
        grouped[key]["rows"].append(candidate.source_row)
        if candidate.tag:
            grouped[key]["tags"].add(candidate.tag)
        grouped[key]["price"] = str(candidate.unit_price_before_tax)

    alternatives = []
    for product, details in sorted(grouped.items(), key=lambda item: item[0].casefold()):
        alternatives.append(
            {
                "product": product,
                "harga_satuan_sebelum_pajak": details["price"],
                "tags": sorted(details["tags"]),
                "baris_sumber": sorted(details["rows"]),
            }
        )
    return json.dumps(alternatives, ensure_ascii=False)


def audit_row(match: Match, restored_quantity: bool, restored_unit: bool) -> dict[str, Any]:
    return {
        "baris_sheet_target": match.target_row,
        "supplier": SUPPLIER,
        "nama_produk_sebelumnya": match.original_product,
        "nama_produk_usulan": match.selected.product,
        "tag_target": match.target_tag,
        "kuantitas_dipulihkan": str(match.quantity_to_restore or "") if restored_quantity else "",
        "satuan_dipulihkan": match.unit_to_restore or "" if restored_unit else "",
        "harga_target": str(match.target_price),
        "harga_satuan_sumber_sebelum_pajak": str(match.selected.unit_price_before_tax),
        "selisih_harga": str(match.difference),
        "selisih_persen": f"{match.difference_percent:.2f}",
        "tingkat_kecocokan": match.confidence,
        "metode_pemilihan": match.selection_method,
        "baris_sumber": match.selected.source_row,
        "nomor_referensi_sumber": match.selected.reference_no,
        "tanggal_sumber": match.selected.date,
        "tag_sumber_terpilih": match.selected.tag,
        "jumlah_nama_produk_seri": len({normalize(candidate.product) for candidate in match.nearest_candidates}),
        "alternatif_harga_terdekat_json": alternatives_json(match),
    }


def default_paths(target_path: Path) -> tuple[Path, Path, Path]:
    return (
        target_path.with_name(f"{target_path.stem}_products_completed{target_path.suffix}"),
        target_path.with_name(f"{target_path.stem}_product_match_audit.csv"),
        target_path.with_name(f"{target_path.stem}_quantity_unit_audit.csv"),
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("target", type=Path, help="Workbook dengan baris No Name")
    parser.add_argument("source", type=Path, help="Workbook pembelian Fujitama sebagai referensi")
    parser.add_argument("--target-sheet", default=TARGET_SHEET)
    parser.add_argument("--source-sheet", default=SOURCE_SHEET)
    parser.add_argument("--output", type=Path, help="File workbook hasil; default menambahkan _products_completed")
    parser.add_argument("--audit-csv", type=Path, help="File audit CSV; default menambahkan _product_match_audit")
    parser.add_argument("--quantity-audit-csv", type=Path, help="File audit kuantitas/satuan; default menambahkan _quantity_unit_audit")
    parser.add_argument("--apply", action="store_true", help="Tulis workbook hasil dan audit CSV; default hanya preview")
    parser.add_argument("--overwrite-output", action="store_true", help="Izinkan mengganti file output/audit yang sudah ada")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    target_path = args.target.expanduser().resolve()
    source_path = args.source.expanduser().resolve()
    if not target_path.is_file() or not source_path.is_file():
        print("File target atau sumber tidak ditemukan.", file=sys.stderr)
        return 2
    if target_path.suffix.lower() != ".xlsx" or source_path.suffix.lower() != ".xlsx":
        print("File target dan sumber harus berekstensi .xlsx.", file=sys.stderr)
        return 2

    output_default, audit_default, quantity_audit_default = default_paths(target_path)
    output_path = (args.output or output_default).expanduser().resolve()
    audit_path = (args.audit_csv or audit_default).expanduser().resolve()
    quantity_audit_path = (args.quantity_audit_csv or quantity_audit_default).expanduser().resolve()
    protected_paths = {target_path, source_path}
    requested_paths = {output_path, audit_path, quantity_audit_path}
    if output_path in protected_paths or len(requested_paths) != 3 or requested_paths & protected_paths:
        print("File output/audit harus terpisah dari kedua workbook sumber dan satu sama lain.", file=sys.stderr)
        return 2
    if not args.overwrite_output and any(path.exists() for path in requested_paths):
        print("File output/audit sudah ada. Pilih nama lain atau gunakan --overwrite-output.", file=sys.stderr)
        return 2

    try:
        candidates = read_candidates(source_path, args.source_sheet)
        if not candidates:
            raise ValueError("Tidak ditemukan produk bernama Pcs dari supplier Fujitama pada sheet sumber.")

        workbook = load_workbook(target_path)
        try:
            sheet = find_sheet(workbook, args.target_sheet)
            columns = header_columns(
                sheet,
                {"tanggal", "suplier", "nama produk", "tag", "kuantitas", "satuan", "harga per unit"},
            )
            matches = []
            quantity_unit_updates = []
            for row_number in range(2, sheet.max_row + 1):
                product_value = sheet.cell(row_number, columns["nama produk"]).value
                product = str(product_value or "").strip()
                if not product:
                    continue

                quantity_cell = sheet.cell(row_number, columns["kuantitas"])
                unit_cell = sheet.cell(row_number, columns["satuan"])
                is_no_name = bool(NO_NAME_PATTERN.search(product))
                if not is_no_name:
                    if quantity_cell.value not in (None, "") and unit_cell.value not in (None, ""):
                        continue
                    components = parse_embedded_quantities_and_units(product)
                    if not components:
                        continue
                    summary_quantity, summary_unit, aggregation_method = quantity_unit_summary(components)
                    quantity_unit_updates.append(
                        QuantityUnitUpdate(
                            target_row=row_number,
                            product=product,
                            original_quantity=quantity_cell.value,
                            original_unit=unit_cell.value,
                            parsed_components=components,
                            new_quantity=summary_quantity,
                            new_unit=summary_unit,
                            aggregation_method=aggregation_method,
                            fill_quantity=quantity_cell.value in (None, ""),
                            fill_unit=unit_cell.value in (None, ""),
                        )
                    )
                    continue

                supplier = sheet.cell(row_number, columns["suplier"]).value
                if normalize(supplier) != normalize(SUPPLIER):
                    raise ValueError(
                        f"Baris target {row_number} berisi No Name tetapi supplier berbeda: {supplier!r}."
                    )

                price = decimal_value(
                    sheet.cell(row_number, columns["harga per unit"]).value,
                    field="Harga per Unit",
                    row_number=row_number,
                )
                if price <= 0:
                    raise ValueError(f"Harga per Unit harus positif pada baris {row_number}.")

                match = match_target_row(
                    row_number,
                    product,
                    str(sheet.cell(row_number, columns["tag"]).value or "").strip(),
                    price,
                    candidates,
                )
                restored_quantity = quantity_cell.value in (None, "") and match.quantity_to_restore is not None
                restored_unit = unit_cell.value in (None, "") and match.unit_to_restore is not None
                matches.append((match, restored_quantity, restored_unit))

            if not matches and not quantity_unit_updates:
                raise ValueError("Tidak ada baris No Name yang perlu dilengkapi.")

            print(f"Workbook target: {target_path.name} | Sheet: {sheet.title}")
            print(f"Workbook referensi: {source_path.name} | Sheet: {args.source_sheet}")
            print(f"Produk sumber bernama Pcs dari {SUPPLIER}: {len(candidates)}")
            print(f"Baris No Name ditemukan: {len(matches)}")
            print(f"Baris kuantitas/satuan kosong yang bisa dilengkapi dari nama produk: {len(quantity_unit_updates)}")
            print(
                "Rincian campuran satuan yang dipertahankan: "
                f"{sum(update.aggregation_method == 'rincian_dipertahankan_satuan_berbeda' for update in quantity_unit_updates)}"
            )
            print(
                "Baris kuantitas/satuan kosong tanpa rincian yang terbaca: "
                f"{sum(not parse_embedded_quantities_and_units(str(sheet.cell(row_number, columns['nama produk']).value or '')) for row_number in range(2, sheet.max_row + 1) if sheet.cell(row_number, columns['nama produk']).value and (sheet.cell(row_number, columns['kuantitas']).value in (None, '') or sheet.cell(row_number, columns['satuan']).value in (None, '')))}"
            )
            print(f"Harga satuan sebelum pajak cocok persis: {sum(match.difference == 0 for match, _, _ in matches)}")
            print(f"Kecocokan selisih >25%: {sum(match.confidence == 'rendah' for match, _, _ in matches)}")
            print(f"Baris dengan seri nama produk pada harga terdekat: {sum(len({normalize(c.product) for c in match.nearest_candidates}) > 1 for match, _, _ in matches)}")
            print(f"Kuantitas/satuan yang akan dipulihkan ke kolom khusus: {sum(qty for _, qty, _ in matches)} / {sum(unit for _, _, unit in matches)}")
            print("Contoh usulan:")
            for match, _, _ in matches[:12]:
                print(
                    f"  baris {match.target_row} | Rp{match.target_price:,.2f} | "
                    f"{match.original_product.splitlines()[0]} -> {match.selected.product} | "
                    f"sumber Rp{match.selected.unit_price_before_tax:,.2f} | "
                    f"selisih {match.difference_percent:.2f}% | {match.confidence}"
                )
            print(f"Mode: {'APPLY' if args.apply else 'DRY-RUN'}")

            if args.apply:
                audit_rows = []
                quantity_audit_rows = []
                for match, restored_quantity, restored_unit in matches:
                    sheet.cell(match.target_row, columns["nama produk"]).value = match.selected.product
                    if restored_quantity:
                        sheet.cell(match.target_row, columns["kuantitas"]).value = float(match.quantity_to_restore)
                    if restored_unit:
                        sheet.cell(match.target_row, columns["satuan"]).value = match.unit_to_restore
                    audit_rows.append(audit_row(match, restored_quantity, restored_unit))

                for update in quantity_unit_updates:
                    if update.fill_quantity:
                        quantity_cell = sheet.cell(update.target_row, columns["kuantitas"])
                        quantity_cell.value = update.new_quantity
                        if isinstance(update.new_quantity, float):
                            quantity_cell.number_format = "0.00"
                        else:
                            quantity_cell.number_format = "@"
                            quantity_alignment = copy(quantity_cell.alignment)
                            quantity_alignment.wrap_text = True
                            quantity_alignment.vertical = "top"
                            quantity_cell.alignment = quantity_alignment
                    if update.fill_unit:
                        sheet.cell(update.target_row, columns["satuan"]).value = update.new_unit
                    quantity_audit_rows.append(quantity_unit_audit_row(update))

                output_path.parent.mkdir(parents=True, exist_ok=True)
                audit_path.parent.mkdir(parents=True, exist_ok=True)
                quantity_audit_path.parent.mkdir(parents=True, exist_ok=True)
                with tempfile.NamedTemporaryFile(
                    prefix=f"{output_path.stem}_", suffix=".xlsx", dir=output_path.parent, delete=False
                ) as handle:
                    temporary_output = Path(handle.name)
                try:
                    workbook.save(temporary_output)
                    restored_formulas = restore_formula_cached_values(target_path, temporary_output)
                    os.replace(temporary_output, output_path)
                finally:
                    temporary_output.unlink(missing_ok=True)
                fieldnames = list(audit_rows[0].keys())
                with audit_path.open("w", newline="", encoding="utf-8-sig") as handle:
                    if audit_rows:
                        writer = csv.DictWriter(handle, fieldnames=list(audit_rows[0].keys()))
                        writer.writeheader()
                        writer.writerows(audit_rows)
                    else:
                        handle.write("baris_sheet_target,supplier,nama_produk_sebelumnya,nama_produk_usulan\n")
                with quantity_audit_path.open("w", newline="", encoding="utf-8-sig") as handle:
                    if quantity_audit_rows:
                        writer = csv.DictWriter(handle, fieldnames=list(quantity_audit_rows[0].keys()))
                        writer.writeheader()
                        writer.writerows(quantity_audit_rows)
                    else:
                        handle.write("baris_sheet_target,produk,kuantitas_usulan,satuan_usulan\n")

                print(f"Workbook hasil: {output_path}")
                print(f"Audit pencocokan: {audit_path}")
                print(f"Audit kuantitas/satuan: {quantity_audit_path}")
                print(f"Cache formula dari workbook asli dipertahankan: {restored_formulas}")
        finally:
            workbook.close()
    except Exception as exc:
        print(f"Gagal memproses workbook: {exc}", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
