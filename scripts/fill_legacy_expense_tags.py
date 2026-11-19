#!/usr/bin/env python3
"""Fill explicit vehicle/equipment tags in the legacy expense worksheet.

Dependencies:
    python -m pip install openpyxl mysql-connector-python

Example (preview only):
    python scripts/fill_legacy_expense_tags.py "BUKU BESAR 2023 06112026.xlsx" --business-id 2

Example (write a separate workbook and review CSV):
    python scripts/fill_legacy_expense_tags.py "BUKU BESAR 2023 06112026.xlsx" --business-id 2 --apply

The source workbook is never modified. Exact business tag phrases in descriptions
are written, including non-vehicle tags. A unique asset name/model can also resolve
to its existing tag. Unmatched rows stay blank and are listed in the audit CSV.
"""

from __future__ import annotations

import argparse
import csv
import io
import os
import posixpath
import re
import sys
import tempfile
import zipfile
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


DEFAULT_SHEET = "BIAYA 2023"
HEADER_ROW = 1
PLATE_PREFIXES = {"B", "BM", "BK", "DP"}
EQUIPMENT_PREFIXES = {"EX", "LOADER", "DOZER", "WL", "MD", "FL", "TK"}
ATTACHMENT_CODES = {"DT", "TK", "SL", "MD"}


@dataclass(frozen=True)
class TagLabel:
    tag_id: int
    name: str
    tokens: tuple[str, ...]
    transaction_uses: int
    asset_links: int


@dataclass(frozen=True)
class AssetLabel:
    name: str
    name_tokens: tuple[str, ...]
    model: str
    model_tokens: tuple[str, ...]


def normalized_tokens(value: object) -> tuple[str, ...]:
    """Normalize spacing/case and split compact identifiers such as DT12."""
    return tuple(re.findall(r"[A-Z]+|\d+", str(value or "").upper()))


def is_vehicle_or_equipment_tag(tokens: tuple[str, ...]) -> bool:
    if len(tokens) >= 2 and tokens[0] in PLATE_PREFIXES and tokens[1].isdigit():
        return True
    return len(tokens) >= 2 and tokens[0] in EQUIPMENT_PREFIXES and tokens[1].isdigit()


def contains_tokens(haystack: tuple[str, ...], needle: tuple[str, ...]) -> bool:
    if not needle or len(needle) > len(haystack):
        return False
    size = len(needle)
    return any(haystack[index : index + size] == needle for index in range(len(haystack) - size + 1))


def read_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw_line in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        values[key.strip()] = value
    return values


def database_config(env_file: Path) -> dict[str, object]:
    file_values = read_env_file(env_file)

    def value(name: str, default: str = "") -> str:
        return os.environ.get(name, file_values.get(name, default)).strip()

    required = ["DB_DATABASE", "DB_USERNAME"]
    missing = [name for name in required if not value(name)]
    if missing:
        raise ValueError(f"Konfigurasi database belum lengkap di environment/.env: {', '.join(missing)}")

    return {
        "host": value("DB_HOST", "127.0.0.1"),
        "port": int(value("DB_PORT", "3306")),
        "database": value("DB_DATABASE"),
        "user": value("DB_USERNAME"),
        "password": value("DB_PASSWORD"),
        "connection_timeout": 10,
    }


def load_business_vocabulary(env_file: Path, business_id: int) -> tuple[str, list[TagLabel], list[AssetLabel]]:
    try:
        import mysql.connector
    except ImportError as exc:
        raise RuntimeError("Paket mysql-connector-python belum terpasang.") from exc

    connection = mysql.connector.connect(**database_config(env_file))
    try:
        cursor = connection.cursor(dictionary=True)
        try:
            cursor.execute("SET SESSION TRANSACTION READ ONLY")
            cursor.execute("SELECT name FROM business WHERE id = %s", (business_id,))
            business = cursor.fetchone()
            if not business:
                raise ValueError(f"Business #{business_id} tidak ditemukan pada database di {env_file}.")

            cursor.execute(
                """
                SELECT t.id, t.name,
                       COUNT(DISTINCT tr.id) AS transaction_uses,
                       COUNT(DISTINCT a.id) AS asset_links
                FROM tags AS t
                LEFT JOIN transaction_tags AS tt ON tt.tag_id = t.id
                LEFT JOIN transactions AS tr ON tr.id = tt.transaction_id AND tr.business_id = t.business_id
                LEFT JOIN asset_tags AS at ON at.tag_id = t.id
                LEFT JOIN assets AS a ON a.id = at.asset_id AND a.business_id = t.business_id
                WHERE t.business_id = %s
                GROUP BY t.id, t.name
                ORDER BY t.id
                """,
                (business_id,),
            )
            tag_rows = cursor.fetchall()
            tags = [
                TagLabel(
                    tag_id=int(row["id"]),
                    name=str(row["name"]),
                    tokens=normalized_tokens(row["name"]),
                    transaction_uses=int(row["transaction_uses"] or 0),
                    asset_links=int(row["asset_links"] or 0),
                )
                for row in tag_rows
            ]

            cursor.execute("SELECT name, model FROM assets WHERE business_id = %s", (business_id,))
            assets = [
                AssetLabel(
                    name=str(row["name"]),
                    name_tokens=normalized_tokens(row["name"]),
                    model=str(row["model"] or "").strip(),
                    model_tokens=normalized_tokens(row["model"]),
                )
                for row in cursor.fetchall()
                if row["name"]
            ]
            return str(business["name"]), tags, assets
        finally:
            cursor.close()
    finally:
        connection.rollback()
        connection.close()


def canonicalize_tags(tags: Iterable[TagLabel]) -> dict[tuple[str, ...], TagLabel]:
    grouped: dict[tuple[str, ...], list[TagLabel]] = defaultdict(list)
    for tag in tags:
        grouped[tag.tokens].append(tag)
    canonical: dict[tuple[str, ...], TagLabel] = {}
    for tokens, variants in grouped.items():
        # Prefer the spelling already used most often by transactions; break ties
        # with asset links and then a stable label order.
        canonical[tokens] = max(
            variants,
            key=lambda tag: (tag.transaction_uses, tag.asset_links, tag.name.casefold(), tag.tag_id),
        )
    return canonical


def build_asset_aliases(
    assets: Iterable[AssetLabel],
    canonical_tags: dict[tuple[str, ...], TagLabel],
) -> tuple[dict[tuple[str, ...], tuple[tuple[str, ...], str]], set[tuple[str, ...]]]:
    """Map exact asset names/models to a unique existing tag."""
    aliases: dict[tuple[str, ...], tuple[tuple[str, ...], str]] = {}
    ambiguous: set[tuple[str, ...]] = set()
    direct_tag_tokens = set(canonical_tags)

    def tag_for_asset(asset_tokens: tuple[str, ...]) -> tuple[str, ...] | None:
        if asset_tokens in direct_tag_tokens:
            return asset_tokens
        targets = set()
        for tag_tokens in canonical_tags:
            if len(tag_tokens) <= len(asset_tokens) or tag_tokens[: len(asset_tokens)] != asset_tokens:
                continue
            suffix = tag_tokens[len(asset_tokens) :]
            if len(suffix) == 2 and suffix[0] in ATTACHMENT_CODES and suffix[1].isdigit():
                targets.add(tag_tokens)
        if len(targets) == 1:
            return next(iter(targets))
        return None

    def add_alias(alias_tokens: tuple[str, ...], tag_tokens: tuple[str, ...] | None, method: str) -> None:
        if not alias_tokens or alias_tokens in direct_tag_tokens:
            return
        if tag_tokens is None:
            ambiguous.add(alias_tokens)
            return
        existing = aliases.get(alias_tokens)
        if existing is not None and existing[0] != tag_tokens:
            aliases.pop(alias_tokens, None)
            ambiguous.add(alias_tokens)
        elif alias_tokens not in ambiguous:
            aliases[alias_tokens] = (tag_tokens, method)

    asset_records = list(assets)
    for asset in asset_records:
        add_alias(asset.name_tokens, tag_for_asset(asset.name_tokens), "exact_asset_name_to_unique_tag")

    models: dict[tuple[str, ...], list[AssetLabel]] = defaultdict(list)
    for asset in asset_records:
        if asset.model_tokens:
            models[asset.model_tokens].append(asset)
    for model_tokens, matching_assets in models.items():
        if len(matching_assets) != 1:
            ambiguous.add(model_tokens)
            continue
        asset = matching_assets[0]
        add_alias(
            model_tokens,
            tag_for_asset(asset.name_tokens),
            "exact_asset_model_to_unique_tag",
        )
    return aliases, ambiguous


def match_description(
    description: object,
    canonical_tags: dict[tuple[str, ...], TagLabel],
    asset_aliases: dict[tuple[str, ...], tuple[tuple[str, ...], str]],
    ambiguous_asset_aliases: set[tuple[str, ...]],
) -> tuple[list[str], str]:
    description_tokens = normalized_tokens(description)
    direct_matches = [tokens for tokens in canonical_tags if contains_tokens(description_tokens, tokens)]
    matched = list(direct_matches)
    alias_methods: set[str] = set()
    for alias, (tag_tokens, method) in asset_aliases.items():
        if contains_tokens(description_tokens, alias):
            matched.append(tag_tokens)
            alias_methods.add(method)

    matched = list(set(matched))
    if not matched:
        has_ambiguous_alias = any(contains_tokens(description_tokens, alias) for alias in ambiguous_asset_aliases)
        return [], "ambiguous_asset_tag_variant" if has_ambiguous_alias else "no_exact_business_tag_or_unique_asset_alias"

    # Prefer the full tag when it contains a shorter matched tag. Independent
    # unit identifiers remain separate and can both be applied to one expense.
    selected: list[tuple[str, ...]] = []
    for tokens in sorted(matched, key=lambda item: (-len(item), item)):
        if any(contains_tokens(existing, tokens) for existing in selected):
            continue
        selected.append(tokens)

    labels = [canonical_tags[tokens].name for tokens in selected]
    if len(labels) > 1:
        return labels, "multiple_explicit_tags_in_description"
    if alias_methods and not direct_matches:
        return labels, sorted(alias_methods)[0]
    return labels, "exact_tag_in_description"


def find_headers(sheet, header_row: int) -> dict[str, int]:
    wanted = {"description": "Description", "tags": "Tags", "reference": "Reference No", "category": "Expense Category"}
    found: dict[str, int] = {}
    for column in range(1, sheet.max_column + 1):
        value = sheet.cell(header_row, column).value
        if value is None:
            continue
        normalized = " ".join(str(value).strip().casefold().split())
        for key, label in wanted.items():
            if normalized == label.casefold():
                found[key] = column
    missing = [wanted[key] for key in wanted if key not in found]
    if missing:
        raise ValueError(f"Header tidak ditemukan pada sheet {sheet.title}: {', '.join(missing)}")
    return found


def default_output_paths(input_path: Path) -> tuple[Path, Path]:
    output = input_path.with_name(f"{input_path.stem}_tags_completed{input_path.suffix}")
    audit = input_path.with_name(f"{input_path.stem}_tag_audit.csv")
    return output, audit


def _worksheet_paths(archive: zipfile.ZipFile) -> dict[str, str]:
    main_ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    rel_ns = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    relationships = ET.fromstring(archive.read("xl/_rels/workbook.xml.rels"))
    targets = {item.attrib["Id"]: item.attrib["Target"] for item in relationships}
    workbook_xml = ET.fromstring(archive.read("xl/workbook.xml"))
    paths = {}
    for sheet in workbook_xml.findall(f"{{{main_ns}}}sheets/{{{main_ns}}}sheet"):
        target = targets[sheet.attrib[f"{{{rel_ns}}}id"]]
        path = target.lstrip("/") if target.startswith("/") else posixpath.normpath(posixpath.join("xl", target))
        paths[sheet.attrib["name"]] = path
    return paths


def _restore_sheet_formula_caches(source_xml: bytes, output_xml: bytes) -> tuple[bytes, int]:
    namespaces = set()
    for _, (prefix, uri) in ET.iterparse(io.BytesIO(output_xml), events=("start-ns",)):
        namespaces.add((prefix, uri))
    for prefix, uri in namespaces:
        if not prefix.startswith("ns") or not prefix[2:].isdigit():
            ET.register_namespace(prefix, uri)

    main_ns = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    cell_tag = f"{{{main_ns}}}c"
    formula_tag = f"{{{main_ns}}}f"
    value_tag = f"{{{main_ns}}}v"
    source_root = ET.fromstring(source_xml)
    output_root = ET.fromstring(output_xml)

    source_cells = {}
    for cell in source_root.iter(cell_tag):
        formula = cell.find(formula_tag)
        value = cell.find(value_tag)
        if formula is not None and value is not None and value.text is not None:
            source_cells[cell.get("r")] = (
                formula.text,
                dict(formula.attrib),
                value.text,
                dict(value.attrib),
                cell.get("t"),
            )

    restored = 0
    for cell in output_root.iter(cell_tag):
        source = source_cells.get(cell.get("r"))
        formula = cell.find(formula_tag)
        if source is None or formula is None or formula.text != source[0] or dict(formula.attrib) != source[1]:
            continue
        value = cell.find(value_tag)
        if value is None:
            value = ET.SubElement(cell, value_tag)
        value.text = source[2]
        value.attrib.clear()
        value.attrib.update(source[3])
        if source[4] is not None:
            cell.set("t", source[4])
        restored += 1

    if not restored:
        return output_xml, 0
    return ET.tostring(output_root, encoding="utf-8", xml_declaration=True), restored


def restore_formula_cached_values(source_path: Path, output_path: Path) -> int:
    """Restore cached formula values that openpyxl does not retain when saving."""
    replacements: dict[str, bytes] = {}
    restored = 0
    with zipfile.ZipFile(source_path, "r") as source_archive, zipfile.ZipFile(output_path, "r") as output_archive:
        source_sheets = _worksheet_paths(source_archive)
        output_sheets = _worksheet_paths(output_archive)
        for sheet_name in source_sheets.keys() & output_sheets.keys():
            source_xml = source_archive.read(source_sheets[sheet_name])
            output_xml = output_archive.read(output_sheets[sheet_name])
            patched, count = _restore_sheet_formula_caches(source_xml, output_xml)
            if count:
                replacements[output_sheets[sheet_name]] = patched
                restored += count

        archive_comment = output_archive.comment

    if not replacements:
        return 0

    with tempfile.NamedTemporaryFile(prefix=f"{output_path.stem}_", suffix=".xlsx", dir=output_path.parent, delete=False) as handle:
        temporary_path = Path(handle.name)
    try:
        with zipfile.ZipFile(output_path, "r") as output_archive, zipfile.ZipFile(temporary_path, "w") as rewritten_archive:
            rewritten_archive.comment = archive_comment
            for item in output_archive.infolist():
                data = replacements.get(item.filename)
                if data is None:
                    data = output_archive.read(item.filename)
                rewritten_archive.writestr(item, data)
        os.replace(temporary_path, output_path)
    finally:
        temporary_path.unlink(missing_ok=True)

    return restored


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path, help="Workbook .xlsx sumber")
    parser.add_argument("--business-id", type=int, required=True, help="Business ID sumber asset/tag pada database lokal")
    parser.add_argument("--sheet", default=DEFAULT_SHEET, help=f"Nama sheet biaya (default: {DEFAULT_SHEET})")
    parser.add_argument("--env-file", type=Path, default=Path(__file__).resolve().parents[1] / ".env", help="File .env Laravel untuk koneksi database")
    parser.add_argument("--output", type=Path, help="Workbook hasil; default membuat salinan *_tags_completed.xlsx")
    parser.add_argument("--audit-csv", type=Path, help="CSV audit; default *_tag_audit.csv di sebelah workbook hasil")
    parser.add_argument("--apply", action="store_true", help="Simpan workbook hasil dan audit CSV; tanpa opsi ini hanya preview")
    parser.add_argument("--overwrite-output", action="store_true", help="Izinkan menimpa file output yang sudah ada")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    input_path = args.input.expanduser().resolve()
    if not input_path.is_file():
        print(f"Workbook tidak ditemukan: {input_path}", file=sys.stderr)
        return 2
    if input_path.suffix.lower() != ".xlsx":
        print("Input harus file .xlsx.", file=sys.stderr)
        return 2
    if args.business_id < 1:
        print("--business-id harus bilangan positif.", file=sys.stderr)
        return 2

    output_default, audit_default = default_output_paths(input_path)
    output_path = (args.output or output_default).expanduser().resolve()
    audit_path = (args.audit_csv or audit_default).expanduser().resolve()
    if output_path == input_path:
        print("File output harus berbeda dari workbook sumber; sumber tidak pernah ditimpa.", file=sys.stderr)
        return 2
    if output_path.suffix.lower() != ".xlsx" or audit_path.suffix.lower() != ".csv":
        print("File output harus .xlsx dan audit harus .csv.", file=sys.stderr)
        return 2
    if audit_path in {input_path, output_path}:
        print("File audit harus berbeda dari workbook sumber dan file output.", file=sys.stderr)
        return 2
    if args.apply and not args.overwrite_output and (output_path.exists() or audit_path.exists()):
        print("File output/audit sudah ada. Pilih nama lain atau sertakan --overwrite-output.", file=sys.stderr)
        return 2

    try:
        from openpyxl import load_workbook
    except ImportError:
        print("Paket openpyxl belum terpasang. Jalankan: python -m pip install openpyxl mysql-connector-python", file=sys.stderr)
        return 2

    try:
        business_name, tags, assets = load_business_vocabulary(args.env_file.expanduser().resolve(), args.business_id)
        canonical_tags = canonicalize_tags(tags)
        vehicle_tags = {
            tokens: tag for tokens, tag in canonical_tags.items()
            if is_vehicle_or_equipment_tag(tokens)
        }
        asset_aliases, ambiguous_asset_aliases = build_asset_aliases(assets, vehicle_tags)

        workbook = load_workbook(input_path)
        if args.sheet not in workbook.sheetnames:
            raise ValueError(f"Sheet '{args.sheet}' tidak ditemukan. Sheet tersedia: {', '.join(workbook.sheetnames)}")
        sheet = workbook[args.sheet]
        headers = find_headers(sheet, HEADER_ROW)

        matched_rows = []
        review_rows = []
        already_tagged = 0
        blank_descriptions = 0
        for row_number in range(HEADER_ROW + 1, sheet.max_row + 1):
            tags_cell = sheet.cell(row_number, headers["tags"])
            current_tags = str(tags_cell.value or "").strip()
            if current_tags:
                already_tagged += 1
                continue

            description = sheet.cell(row_number, headers["description"]).value
            if not str(description or "").strip():
                blank_descriptions += 1
                continue

            suggested_tags, method = match_description(description, canonical_tags, asset_aliases, ambiguous_asset_aliases)
            audit_row = {
                "sheet_row": row_number,
                "reference_no": sheet.cell(row_number, headers["reference"]).value,
                "expense_category": sheet.cell(row_number, headers["category"]).value,
                "description": description,
                "current_tags": current_tags,
                "suggested_tags": ", ".join(suggested_tags),
                "status": "matched" if suggested_tags else "review",
                "match_method": method,
            }
            if suggested_tags:
                matched_rows.append((row_number, headers["tags"], audit_row["suggested_tags"], audit_row))
            else:
                review_rows.append(audit_row)

        print(f"Business: {business_name} (#{args.business_id})")
        print(f"Workbook: {input_path.name} | Sheet: {sheet.title}")
        print(f"Tag kanonis database business: {len(canonical_tags)} nama")
        print(f"  Tag kendaraan/alat: {len(vehicle_tags)}")
        print(f"Asset database untuk alias: {len(assets)}")
        print(f"Baris sudah memiliki Tags: {already_tagged}")
        print(f"Baris kosong dengan deskripsi: {len(matched_rows) + len(review_rows)}")
        print(f"Baris cocok: {len(matched_rows)}")
        print(f"Baris perlu review (tetap kosong): {len(review_rows)}")
        print(f"Baris tanpa deskripsi: {blank_descriptions}")
        if matched_rows:
            method_counts = Counter(row[3]["match_method"] for row in matched_rows)
            print("Kecocokan berdasarkan metode:")
            for method, count in sorted(method_counts.items()):
                print(f"  {method}: {count}")
            print("Contoh usulan:")
            for row_number, _, suggested, audit_row in matched_rows[:12]:
                print(
                    f"  baris {row_number} | ref {audit_row['reference_no']} | "
                    f"{audit_row['description']} -> {suggested}"
                )
        print(f"Mode: {'APPLY' if args.apply else 'DRY-RUN'}")

        if args.apply:
            for row_number, column, value, _ in matched_rows:
                sheet.cell(row_number, column).value = value
            output_path.parent.mkdir(parents=True, exist_ok=True)
            audit_path.parent.mkdir(parents=True, exist_ok=True)
            workbook.save(output_path)
            restored_formula_caches = restore_formula_cached_values(input_path, output_path)
            fieldnames = [
                "sheet_row", "reference_no", "expense_category", "description",
                "current_tags", "suggested_tags", "status", "match_method",
            ]
            with audit_path.open("w", newline="", encoding="utf-8-sig") as handle:
                writer = csv.DictWriter(handle, fieldnames=fieldnames)
                writer.writeheader()
                for _, _, _, audit_row in matched_rows:
                    writer.writerow(audit_row)
                writer.writerows(review_rows)
            print(f"Workbook hasil: {output_path}")
            print(f"Audit CSV: {audit_path}")
            print(f"Cache hasil formula yang dipertahankan: {restored_formula_caches}")
        else:
            print("Preview saja; tambahkan --apply untuk menulis salinan workbook dan audit CSV.")
        return 0
    except Exception as exc:
        print(f"Gagal memproses workbook: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
