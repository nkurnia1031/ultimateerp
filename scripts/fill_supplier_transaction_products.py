#!/usr/bin/env python3
"""Fill blank product cells from matching supplier purchase history.

The source workbook is never modified. The script reads purchase history from
the local Laravel MySQL database, creates a separate completed workbook, and
writes an audit CSV for every blank product row.

Dependencies: python -m pip install openpyxl mysql-connector-python
"""

from __future__ import annotations

import argparse
import csv
import difflib
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
from datetime import date, datetime, time, timedelta
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Any

from openpyxl import load_workbook
from openpyxl.styles import Alignment

CENT = Decimal("0.01")
DEFAULT_SHEET = "Rekap Transaksi"
DEFAULT_HEADER_ROW = 3
REQUIRED_HEADERS = {
    "no": "No",
    "supplier": "Supplier Asli",
    "product": "Nama Produk / Keterangan",
    "source_product": "Nama Produk Asli",
    "reference": "No. Invoice / Referensi",
    "amount": "Jumlah (Rp)",
    "tax": "Tax (Rp)",
}
GENERIC_WORDS = {
    "sparepart", "operasional", "belanja", "ex", "rehab", "stok", "stock",
    "gudang", "juni", "juli", "agustus", "september", "oktober", "november",
    "desember", "januari", "februari", "maret", "april", "mei", "2022",
    "2023", "2024", "2025", "2026",
}
CONFIDENCE_RANK = {"high": 3, "medium": 2, "low": 1, "very_low": 0, "review": -1}
CONFIDENCE_LABELS = {
    "high": "Tinggi",
    "medium": "Sedang",
    "low": "Rendah",
    "very_low": "Sangat rendah",
}


@dataclass(frozen=True)
class PurchaseLine:
    contact_id: int
    transaction_id: int
    transaction_date: datetime
    transaction_ref: str
    transaction_total: Decimal
    contact_label: str
    product: str
    product_category: str
    quantity: Decimal
    unit_price: Decimal
    unit_price_inc_tax: Decimal
    unit: str


@dataclass
class Suggestion:
    product: str = ""
    quantity: Decimal | str | None = None
    unit: str = ""
    confidence: str = "review"
    method: str = ""
    history: PurchaseLine | None = None
    candidate_count: int = 0
    alternatives: list[dict[str, str]] | None = None
    reason: str = ""
    value_items: list[dict[str, Any]] | None = None
    value_total: Decimal | None = None
    value_difference: Decimal | None = None
    value_basis: str = ""


def normalize(value: Any) -> str:
    return " ".join(str(value or "").split()).casefold()


def normalize_key(value: Any) -> str:
    return "".join(re.findall(r"[a-z0-9]+", str(value or "").casefold()))


def to_decimal(value: Any, *, field: str, row_number: int) -> Decimal:
    if value is None or str(value).strip() == "":
        raise ValueError(f"Nilai {field} kosong pada baris sheet {row_number}.")
    raw = str(value).strip().replace(",", "")
    try:
        return Decimal(raw).quantize(CENT, rounding=ROUND_HALF_UP)
    except (InvalidOperation, ValueError) as exc:
        raise ValueError(
            f"Nilai {field} tidak valid pada baris sheet {row_number}: {value!r}."
        ) from exc


def is_blank(value: Any) -> bool:
    return value is None or (isinstance(value, str) and not value.strip())


def find_headers(sheet, header_row: int) -> dict[str, int]:
    wanted = {normalize(label): key for key, label in REQUIRED_HEADERS.items()}
    found: dict[str, int] = {}
    for cell in sheet[header_row]:
        key = wanted.get(normalize(cell.value))
        if key:
            found[key] = cell.column
    missing = [REQUIRED_HEADERS[key] for key in REQUIRED_HEADERS if key not in found]
    if missing:
        raise ValueError(
            f"Header sheet {sheet.title!r} tidak ditemukan pada baris "
            f"{header_row}: {', '.join(missing)}."
        )
    return found


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        values[key.strip()] = value
    return values


def connect_database(env_file: Path, allow_remote: bool):
    try:
        import mysql.connector
    except ImportError as exc:
        raise RuntimeError(
            "mysql-connector-python belum terpasang. Jalankan: "
            "python -m pip install mysql-connector-python"
        ) from exc

    env = load_env(env_file)
    host = env.get("DB_HOST", "127.0.0.1")
    if host.casefold() not in {"localhost", "127.0.0.1", "::1"} and not allow_remote:
        raise ValueError(
            f"DB_HOST mengarah ke {host!r}. Skrip dibatasi ke database lokal; "
            "gunakan --allow-remote-database hanya jika memang diperlukan."
        )
    if not env.get("DB_DATABASE"):
        raise ValueError(f"DB_DATABASE tidak ditemukan di {env_file}.")

    connection = mysql.connector.connect(
        host=host,
        port=int(env.get("DB_PORT", "3306")),
        user=env.get("DB_USERNAME", "root"),
        password=env.get("DB_PASSWORD", ""),
        database=env["DB_DATABASE"],
        connection_timeout=10,
        autocommit=False,
    )
    return connection, env["DB_DATABASE"], host


def load_suppliers(cursor, business_id: int) -> tuple[str, dict[str, set[int]], dict[int, str]]:
    cursor.execute(
        "SELECT id, name FROM business WHERE id = %s",
        (business_id,),
    )
    business = cursor.fetchone()
    if not business:
        raise ValueError(f"Business ID {business_id} tidak ditemukan.")

    cursor.execute(
        """
        SELECT id, supplier_business_name, name, first_name
        FROM contacts
        WHERE business_id = %s
          AND type IN ('supplier', 'both')
        """,
        (business_id,),
    )
    supplier_ids: dict[str, set[int]] = defaultdict(set)
    labels: dict[int, str] = {}
    for row in cursor.fetchall():
        label = str(row["supplier_business_name"] or row["name"] or row["first_name"] or "").strip()
        labels[int(row["id"])] = label
        for field in ("supplier_business_name", "name", "first_name"):
            key = normalize_key(row[field])
            if key:
                supplier_ids[key].add(int(row["id"]))
    return str(business["name"]), supplier_ids, labels


def supplier_alias_key(value: Any) -> str:
    ignored = {"toko", "bangunan", "pt", "cv", "tb", "vendor", "ppn", "ud"}
    tokens = [
        token for token in re.findall(r"[a-z0-9]+", normalize(value))
        if token not in ignored
    ]
    return "".join(tokens)


def resolve_supplier_ids(
    supplier: str,
    supplier_ids_by_name: dict[str, set[int]],
    contact_labels: dict[int, str],
    contacts_with_product_history: set[int],
) -> tuple[set[int], str]:
    exact = supplier_ids_by_name.get(normalize_key(supplier), set())
    if exact.intersection(contacts_with_product_history):
        return set(exact), "exact"

    target_key = supplier_alias_key(supplier)
    if not target_key:
        return set(exact), "exact_no_history"

    candidates: list[tuple[float, int]] = []
    for contact_id, label in contact_labels.items():
        if contact_id not in contacts_with_product_history:
            continue
        candidate_key = supplier_alias_key(label)
        if not candidate_key:
            continue
        score = difflib.SequenceMatcher(None, target_key, candidate_key).ratio()
        if target_key in candidate_key or candidate_key in target_key:
            score = max(score, 0.92)
        if score >= 0.82:
            candidates.append((score, contact_id))

    if not candidates:
        return set(exact), "exact_no_history"

    best_score = max(score for score, _ in candidates)
    best_ids = {contact_id for score, contact_id in candidates if score == best_score}
    return best_ids, "similar_supplier_name"


def load_purchase_lines(cursor, business_id: int, history_limit: datetime) -> list[PurchaseLine]:
    cursor.execute(
        """
        SELECT
            c.id AS contact_id,
            c.supplier_business_name,
            c.name AS contact_name,
            t.id AS transaction_id,
            t.transaction_date,
            t.ref_no AS transaction_ref,
            t.final_total AS transaction_total,
            p.name AS product,
            COALESCE(cat.name, '') AS product_category,
            pl.quantity,
            pl.purchase_price,
            pl.purchase_price_inc_tax,
            COALESCE(u.actual_name, '') AS unit
        FROM transactions t
        JOIN contacts c ON c.id = t.contact_id
        JOIN purchase_lines pl ON pl.transaction_id = t.id
        JOIN products p ON p.id = pl.product_id
        LEFT JOIN categories cat ON cat.id = p.category_id
        LEFT JOIN units u ON u.id = p.unit_id
        WHERE t.business_id = %s
          AND t.type = 'purchase'
          AND t.status = 'received'
          AND t.transaction_date < %s
        ORDER BY t.transaction_date, t.id, pl.id
        """,
        (business_id, history_limit),
    )
    result = []
    for row in cursor.fetchall():
        transaction_date = row["transaction_date"]
        if not isinstance(transaction_date, datetime):
            continue
        result.append(
            PurchaseLine(
                contact_id=int(row["contact_id"]),
                transaction_id=int(row["transaction_id"]),
                transaction_date=transaction_date,
                transaction_ref=str(row["transaction_ref"] or ""),
                transaction_total=Decimal(str(row["transaction_total"] or 0)),
                contact_label=str(row["supplier_business_name"] or row["contact_name"] or ""),
                product=str(row["product"] or "").strip(),
                product_category=str(row["product_category"] or "").strip(),
                quantity=Decimal(str(row["quantity"] or 0)),
                unit_price=Decimal(str(row["purchase_price"] or 0)),
                unit_price_inc_tax=Decimal(str(row["purchase_price_inc_tax"] or 0)),
                unit=str(row["unit"] or "").strip(),
            )
        )
    return result


def workbook_formula_map(path: Path) -> dict[str, dict[str, str]]:
    workbook = load_workbook(path, read_only=True, data_only=False)
    try:
        return {
            sheet.title: {
                cell.coordinate: cell.value
                for row in sheet.iter_rows()
                for cell in row
                if isinstance(cell.value, str) and cell.value.startswith("=")
            }
            for sheet in workbook.worksheets
        }
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
        path = target.lstrip("/") if target.startswith("/") else posixpath.normpath(
            posixpath.join("xl", target)
        )
        paths[sheet.attrib["name"]] = path
    return paths


def register_xml_namespaces(xml_data: bytes) -> None:
    for _, (prefix, uri) in ET.iterparse(io.BytesIO(xml_data), events=("start-ns",)):
        if not prefix.startswith("ns") or not prefix[2:].isdigit():
            ET.register_namespace(prefix, uri)


def restore_formula_cached_values(source_path: Path, output_path: Path) -> int:
    """Restore cached values for unchanged formulas after openpyxl saves the copy."""
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

    with zipfile.ZipFile(source_path, "r") as source_archive, zipfile.ZipFile(
        output_path, "r"
    ) as output_archive:
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


def history_cutoff(reference: Any, fallback_through: date) -> datetime:
    """Return an exclusive cutoff; references with month/year use that month."""
    text = str(reference or "").strip()
    match = re.search(r"/(0?[1-9]|1[0-2])/(20\d{2}|\d{2})(?:\D|$)", text)
    if match:
        month = int(match.group(1))
        year = int(match.group(2))
        year = year + 2000 if year < 100 else year
        return datetime.combine(date(year, month, 1), time.min)

    # Internal references such as K2408*0620 encode YYMM.
    match = re.match(r"^[A-Za-z](\d{2})(0[1-9]|1[0-2])(?:\D|$)", text)
    if match:
        year = 2000 + int(match.group(1))
        month = int(match.group(2))
        return datetime.combine(date(year, month, 1), time.min)

    return datetime.combine(fallback_through + timedelta(days=1), time.min)


def meaningful_product(product: str) -> bool:
    return bool(normalize_key(product)) and normalize_key(product) not in {
        "noname", "unknown", "produk", "sparepart", "operasional",
    }


def source_hint_tokens(source_product: Any, supplier: str) -> set[str]:
    tokens = set(re.findall(r"[a-z0-9]+", normalize(source_product)))
    tokens -= GENERIC_WORDS
    supplier_tokens = set(re.findall(r"[a-z0-9]+", normalize(supplier)))
    tokens -= supplier_tokens
    return {token for token in tokens if len(token) > 1 and not token.isdigit()}


def source_matches_candidate(source_product: Any, supplier: str, line: PurchaseLine) -> bool:
    source_text = normalize(source_product)
    if source_text.startswith("sparepart") and normalize_key(source_text) in {
        "sparepart", "sparepartcincangan",
    }:
        return normalize_key(line.product_category) == "sparepart"

    hints = source_hint_tokens(source_product, supplier)
    if not hints:
        return False
    product_tokens = set(re.findall(r"[a-z0-9]+", normalize(line.product)))
    compact_product = normalize_key(line.product)
    return all(token in product_tokens or token in compact_product for token in hints)


def product_code(value: str) -> set[str]:
    return {
        (match.group(1) + match.group(2)).casefold()
        for match in re.finditer(r"\b([A-Za-z]{1,3})[\s-]*(\d{2,3})\b", str(value or ""))
    }


def quantity_from_product_history(
    target_product: str,
    supplier_lines: list[PurchaseLine],
) -> tuple[Decimal | None, str, PurchaseLine | None, int]:
    target_key = normalize_key(target_product)
    codes = product_code(target_product)
    matching = [
        line for line in supplier_lines
        if meaningful_product(line.product)
        and (
            normalize_key(line.product) == target_key
            or (codes and codes.intersection(product_code(line.product)))
        )
    ]
    if not matching:
        return None, "", None, 0

    quantity_counts = Counter(
        (line.quantity.normalize(), normalize_key(line.unit), line.unit)
        for line in matching
        if line.quantity > 0 and line.unit
    )
    if not quantity_counts:
        return None, "", None, len(matching)
    ranked = quantity_counts.most_common()
    if len(ranked) > 1 and ranked[0][1] == ranked[1][1]:
        return None, "", None, len(matching)

    selected_quantity, _, selected_unit = ranked[0][0]
    provenance = next(
        line for line in reversed(matching)
        if line.quantity.normalize() == selected_quantity
        and normalize_key(line.unit) == normalize_key(selected_unit)
    )
    return selected_quantity, selected_unit, provenance, ranked[0][1]


def money_key(value: Any) -> Decimal | None:
    try:
        return Decimal(str(value)).quantize(CENT, rounding=ROUND_HALF_UP)
    except (InvalidOperation, TypeError, ValueError):
        return None


def candidate_json(lines: list[tuple[PurchaseLine, Decimal]]) -> list[dict[str, str]]:
    return [
        {
            "product": line.product,
            "quantity": format_quantity(quantity),
            "unit": line.unit,
            "unit_price": format_quantity(line.unit_price),
            "history_date": line.transaction_date.date().isoformat(),
            "history_ref": line.transaction_ref,
        }
        for line, quantity in lines[:20]
    ]


def format_quantity(value: Decimal | str | None) -> str:
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    return format(value.normalize(), "f")


def purchase_manifest(lines: list[PurchaseLine]) -> tuple[
    tuple[tuple[str, str, str], ...], str, str, str, PurchaseLine
] | None:
    if not lines or any(not meaningful_product(line.product) or line.quantity <= 0 for line in lines):
        return None

    grouped: dict[tuple[str, str], tuple[str, str, Decimal]] = {}
    for line in lines:
        key = (normalize_key(line.product), normalize_key(line.unit))
        previous = grouped.get(key)
        if previous:
            grouped[key] = (previous[0], previous[1], previous[2] + line.quantity)
        else:
            grouped[key] = (line.product, line.unit, line.quantity)

    components = sorted(grouped.values(), key=lambda item: (normalize_key(item[0]), normalize_key(item[1])))
    manifest_key = tuple(
        (normalize_key(product), format_quantity(quantity), normalize_key(unit))
        for product, unit, quantity in components
    )
    products = "; ".join(product for product, _, _ in components)
    quantities = "; ".join(format_quantity(quantity) for _, _, quantity in components)
    units = "; ".join(unit for _, unit, _ in components)
    provenance = max(lines, key=lambda line: line.transaction_date)
    return manifest_key, products, quantities, units, provenance


def net_total_match(
    amount: Decimal,
    supplier_lines: list[PurchaseLine],
) -> Suggestion | None:
    by_transaction: dict[int, list[PurchaseLine]] = defaultdict(list)
    for line in supplier_lines:
        by_transaction[line.transaction_id].append(line)

    manifests: dict[tuple[tuple[str, str, str], ...], dict[str, Any]] = {}
    incomplete_matches = 0
    for transaction_lines in by_transaction.values():
        transaction_total = transaction_lines[0].transaction_total
        if transaction_total <= 0:
            continue
        net_total = (transaction_total * Decimal("0.98")).quantize(
            CENT, rounding=ROUND_HALF_UP
        )
        if abs(net_total - amount) > Decimal("1"):
            continue
        manifest = purchase_manifest(transaction_lines)
        if manifest is None:
            incomplete_matches += 1
            continue
        key, product, quantity, unit, provenance = manifest
        group = manifests.setdefault(
            key,
            {
                "product": product,
                "quantity": quantity,
                "unit": unit,
                "support": 0,
                "provenance": provenance,
            },
        )
        group["support"] += 1
        if provenance.transaction_date > group["provenance"].transaction_date:
            group["provenance"] = provenance

    if not manifests:
        return None

    product_frequency = Counter(
        normalize_key(line.product)
        for line in supplier_lines
        if meaningful_product(line.product)
    )
    ranked = sorted(
        manifests.items(),
        key=lambda item: (
            -item[1]["support"],
            -sum(product_frequency[normalize_key(name)] for name in item[1]["product"].split("; ")),
            -item[1]["provenance"].transaction_date.timestamp(),
        ),
    )
    top_key, top = ranked[0]
    top_support = top["support"]
    second_support = ranked[1][1]["support"] if len(ranked) > 1 else 0
    if len(ranked) == 1:
        confidence = "high" if top_support >= 2 and not incomplete_matches else "medium"
    elif top_support > second_support and top_support >= 2:
        confidence = "medium"
    else:
        confidence = "low"
    if incomplete_matches:
        confidence = {"high": "medium", "medium": "low", "low": "very_low"}[confidence]

    alternatives = [
        {
            "product": item["product"],
            "quantity": item["quantity"],
            "unit": item["unit"],
            "supporting_transactions": str(item["support"]),
        }
        for _, item in ranked[:20]
    ]
    return Suggestion(
        product=top["product"],
        quantity=top["quantity"],
        unit=top["unit"],
        confidence=confidence,
        method="supplier_purchase_total_adjusted_2_percent",
        history=top["provenance"],
        candidate_count=len(ranked) + incomplete_matches,
        alternatives=alternatives,
        reason=(
            f"Jumlah cocok dengan 98% total pembelian supplier pada "
            f"{top_support} transaksi; ada {len(ranked)} susunan barang bernama"
            + (f" dan {incomplete_matches} histori dengan nama barang kosong." if incomplete_matches else ".")
        ),
    )


def fallback_description(source_product: Any, supplier: str = "") -> Suggestion:
    source = str(source_product or "").strip()
    source = re.sub(r"(?i)\bK\d{4}\*\d{3,4}\b", "", source)
    source = re.sub(r"\b\d{4,}/[A-Z0-9*./-]+\b", "", source, flags=re.IGNORECASE).strip(" ,;/")
    hints = source_hint_tokens(source, supplier)
    if hints:
        product = " ".join(sorted(hints)).title()
    elif normalize(source).startswith("sparepart"):
        product = "Sparepart (jenis tidak teridentifikasi)"
    elif "operasional" in normalize(source):
        product = "Operasional (rincian barang tidak teridentifikasi)"
    elif source:
        product = f"{source} (rekomendasi dari keterangan asli)"
    else:
        product = "Produk tidak teridentifikasi dari data yang tersedia"
    return Suggestion(
        product=product,
        confidence="very_low",
        method="original_description_fallback",
        reason="Histori supplier tidak menyediakan nama barang yang cukup; keterangan asli dipakai sebagai pengganti.",
    )


def nearest_transaction_suggestion(
    amount: Decimal,
    supplier_lines: list[PurchaseLine],
) -> Suggestion | None:
    by_transaction: dict[int, list[PurchaseLine]] = defaultdict(list)
    for line in supplier_lines:
        by_transaction[line.transaction_id].append(line)

    manifests: dict[tuple[tuple[str, str, str], ...], dict[str, Any]] = {}
    for transaction_lines in by_transaction.values():
        total = transaction_lines[0].transaction_total
        manifest = purchase_manifest(transaction_lines)
        if total <= 0 or manifest is None:
            continue
        net_total = (total * Decimal("0.98")).quantize(CENT, rounding=ROUND_HALF_UP)
        difference = abs(net_total - amount)
        key, product, quantity, unit, provenance = manifest
        candidate = manifests.setdefault(
            key,
            {
                "product": product,
                "quantity": quantity,
                "unit": unit,
                "difference": difference,
                "support": 0,
                "provenance": provenance,
                "matched_total": net_total,
            },
        )
        candidate["support"] += 1
        if difference < candidate["difference"] or (
            difference == candidate["difference"]
            and provenance.transaction_date > candidate["provenance"].transaction_date
        ):
            candidate["difference"] = difference
            candidate["provenance"] = provenance
            candidate["matched_total"] = net_total

    if not manifests:
        return None
    ranked = sorted(
        manifests.items(),
        key=lambda item: (item[1]["difference"], -item[1]["support"]),
    )
    _, chosen = ranked[0]
    relative_difference = chosen["difference"] / amount if amount else Decimal("1")
    confidence = "low" if relative_difference <= Decimal("0.05") else "very_low"
    return Suggestion(
        product=chosen["product"],
        quantity=chosen["quantity"],
        unit=chosen["unit"],
        confidence=confidence,
        method="nearest_supplier_purchase_total",
        history=chosen["provenance"],
        candidate_count=len(ranked),
        alternatives=[
            {
                "product": candidate["product"],
                "quantity": candidate["quantity"],
                "unit": candidate["unit"],
                "histori_net_rp": format_quantity(candidate["matched_total"]),
                "selisih_rp": format_quantity(candidate["difference"]),
            }
            for _, candidate in ranked[:20]
        ],
        reason=(
            "Dipilih dari transaksi pembelian supplier dengan total net historis terdekat "
            f"(selisih {relative_difference:.1%} dari jumlah sumber)."
        ),
    )


def choose_suggestion(
    *,
    row_number: int,
    product_value: Any,
    supplier: str,
    source_product: Any,
    reference: Any,
    amount: Decimal | None,
    tax: Decimal,
    supplier_ids: set[int],
    history: list[PurchaseLine],
    fallback_through: date,
    sibling_products: dict[tuple[str, Decimal], set[tuple[str, int]]],
    fill_unresolved: bool = False,
) -> Suggestion:
    if amount is None or not supplier:
        return Suggestion(reason="supplier atau jumlah tidak tersedia.")

    key = (normalize_key(supplier), amount)
    siblings = sibling_products.get(key, set())
    sibling_names = {name for name, source_row in siblings if source_row != row_number}
    if len(sibling_names) == 1:
        product = next(iter(sibling_names))
        cutoff = history_cutoff(reference, fallback_through)
        eligible = [
            line for line in history
            if line.contact_id in supplier_ids and line.transaction_date < cutoff
        ]
        quantity, unit, provenance, support = quantity_from_product_history(product, eligible)
        return Suggestion(
            product=product,
            quantity=quantity,
            unit=unit,
            confidence="high",
            method="same_supplier_same_amount_existing_product",
            history=provenance,
            candidate_count=1,
            reason=(
                f"Produk sama tercatat pada baris sheet "
                f"{', '.join(str(source_row) for name, source_row in sorted(siblings) if name == product)}."
                + (f" Kuantitas/satuan histori konsisten pada {support} transaksi." if quantity is not None else "")
            ),
        )
    if len(sibling_names) > 1:
        return Suggestion(
            candidate_count=len(sibling_names),
            alternatives=[{"product": name} for name in sorted(sibling_names)],
            reason="Baris dengan supplier dan jumlah sama memiliki nama produk berbeda.",
        )

    cutoff = history_cutoff(reference, fallback_through)
    supplier_history = [
        line for line in history
        if line.contact_id in supplier_ids
        and line.transaction_date < cutoff
    ]
    eligible = [
        line for line in supplier_history
        if meaningful_product(line.product) and line.quantity > 0
    ]
    if not supplier_history:
        return fallback_description(source_product, supplier) if fill_unresolved else Suggestion(
            reason="tidak ada histori pembelian supplier sebelum tanggal batas."
        )
    if not eligible:
        return fallback_description(source_product, supplier) if fill_unresolved else Suggestion(
            reason="histori supplier tidak memiliki nama produk yang dapat digunakan."
        )

    # Exact historical line total is the strongest database match.
    exact: dict[tuple[str, Decimal, str], PurchaseLine] = {}
    price_field = "unit_price_inc_tax" if tax > 0 else "unit_price"
    for line in eligible:
        price = getattr(line, price_field)
        if price <= 0:
            continue
        line_total = (line.quantity * price).quantize(CENT, rounding=ROUND_HALF_UP)
        if abs(line_total - amount) <= Decimal("1"):
            exact[(normalize_key(line.product), line.quantity.normalize(), normalize_key(line.unit))] = line
    if len(exact) == 1:
        line = next(iter(exact.values()))
        return Suggestion(
            product=line.product,
            quantity=line.quantity,
            unit=line.unit,
            confidence="high",
            method="exact_supplier_purchase_line_total",
            history=line,
            candidate_count=1,
            reason="Jumlah baris sama dengan total satu baris pembelian supplier di histori.",
        )
    exact_alternatives = [
        {
            "product": line.product,
            "quantity": format_quantity(line.quantity),
            "unit": line.unit,
            "history_date": line.transaction_date.date().isoformat(),
        }
        for line in list(exact.values())[:20]
    ]
    price_frequency = Counter(
        (
            normalize_key(line.product),
            getattr(line, price_field).normalize(),
            normalize_key(line.unit),
        )
        for line in eligible
        if getattr(line, price_field) > 0
    )

    # A repeated 2% net-total pattern can identify the historical invoice and
    # its complete item/quantity manifest.
    adjusted_match = net_total_match(amount, supplier_history)
    if adjusted_match:
        return adjusted_match

    if len(exact) > 1 and not fill_unresolved:
        return Suggestion(
            candidate_count=len(exact),
            alternatives=exact_alternatives,
            reason="Lebih dari satu produk/kuantitas historis memiliki total yang sama.",
        )
    if len(exact) > 1 and fill_unresolved:
        frequency = Counter(normalize_key(line.product) for line in eligible)
        line = max(
            exact.values(),
            key=lambda candidate: (frequency[normalize_key(candidate.product)], candidate.transaction_date),
        )
        return Suggestion(
            product=line.product,
            quantity=line.quantity,
            unit=line.unit,
            confidence="low",
            method="most_frequent_exact_total_candidate",
            history=line,
            candidate_count=len(exact),
            alternatives=exact_alternatives,
            reason="Ada beberapa kecocokan total; dipilih produk yang paling sering dibeli dari supplier ini.",
        )

    # Infer quantity only from a unique supplier product price that divides the
    # amount exactly. Generic expense labels do not count as product evidence.
    divisor_candidates: dict[tuple[str, Decimal, str], tuple[PurchaseLine, Decimal]] = {}
    for line in eligible:
        if not source_matches_candidate(source_product, supplier, line):
            continue
        price = getattr(line, price_field)
        if price <= 0:
            continue
        quantity = amount / price
        if quantity <= 0 or quantity != quantity.to_integral_value():
            continue
        candidate_key = (normalize_key(line.product), price.normalize(), normalize_key(line.unit))
        previous = divisor_candidates.get(candidate_key)
        if previous is None or line.transaction_date > previous[0].transaction_date:
            divisor_candidates[candidate_key] = (line, quantity)

    if len(divisor_candidates) == 1:
        line, quantity = next(iter(divisor_candidates.values()))
        return Suggestion(
            product=line.product,
            quantity=quantity,
            unit=line.unit,
            confidence="medium",
            method="unique_supplier_unit_price_divisor",
            history=line,
            candidate_count=1,
            reason=(
                "Satu produk supplier dengan kategori/nama sumber yang cocok memiliki "
                "harga satuan historis yang membagi jumlah secara tepat."
            ),
        )

    if divisor_candidates:
        candidates = sorted(
            divisor_candidates.values(),
            key=lambda item: (-sum(
                [price_frequency[
                    (normalize_key(item[0].product), getattr(item[0], price_field).normalize(), normalize_key(item[0].unit))
                ]]
            ), -item[0].transaction_date.timestamp()),
        )
        if fill_unresolved:
            line, quantity = candidates[0]
            return Suggestion(
                product=line.product,
                quantity=quantity,
                unit=line.unit,
                confidence="low",
                method="most_supported_supplier_price_divisor",
                history=line,
                candidate_count=len(candidates),
                alternatives=candidate_json(candidates),
                reason="Beberapa kandidat harga cocok; dipilih produk dengan dukungan histori supplier terbanyak.",
            )
        return Suggestion(
            candidate_count=len(candidates),
            alternatives=candidate_json(candidates),
            reason="Beberapa produk/harga supplier dapat menghasilkan jumlah tersebut.",
        )

    all_prices: dict[tuple[str, Decimal, str], tuple[PurchaseLine, Decimal]] = {}
    for line in eligible:
        price = getattr(line, price_field)
        if price <= 0:
            continue
        quantity = amount / price
        if quantity > 0 and quantity == quantity.to_integral_value():
            all_prices[(normalize_key(line.product), price.normalize(), normalize_key(line.unit))] = (
                line, quantity
            )
    reason = (
        "Tidak ada kandidat yang lolos kecocokan nama/kategori sumber."
        if all_prices else "Tidak ada harga satuan historis yang membagi jumlah secara tepat."
    )
    if fill_unresolved and all_prices:
        candidates = sorted(
            all_prices.values(),
            key=lambda item: (
                -price_frequency[
                    (normalize_key(item[0].product), getattr(item[0], price_field).normalize(), normalize_key(item[0].unit))
                ],
                -item[0].transaction_date.timestamp(),
            ),
        )
        line, quantity = candidates[0]
        return Suggestion(
            product=line.product,
            quantity=quantity,
            unit=line.unit,
            confidence="low",
            method="most_supported_supplier_price_divisor",
            history=line,
            candidate_count=len(all_prices),
            alternatives=candidate_json(candidates),
            reason="Harga satuan membagi jumlah secara tepat; dipilih kandidat dengan frekuensi histori supplier tertinggi.",
        )

    if fill_unresolved:
        nearest = nearest_transaction_suggestion(amount, supplier_history)
        if nearest:
            return nearest

        # No close invoice total: use the most common meaningful product for
        # this supplier and estimate quantity from its most common unit price.
        preferred = [
            line for line in eligible
            if source_matches_candidate(source_product, supplier, line)
        ]
        pool = preferred or eligible
        product_counts = Counter(normalize_key(line.product) for line in pool)
        product_key = max(
            product_counts,
            key=lambda key: (
                product_counts[key],
                max(line.transaction_date.timestamp() for line in pool if normalize_key(line.product) == key),
            ),
        )
        product_lines = [line for line in pool if normalize_key(line.product) == product_key]
        latest = max(product_lines, key=lambda line: line.transaction_date)
        price_counts = Counter(
            getattr(line, price_field).normalize()
            for line in product_lines
            if getattr(line, price_field) > 0
        )
        if price_counts:
            prices = sorted(
                price_counts,
                key=lambda price: (
                    -price_counts[price],
                    -max(
                        line.transaction_date.timestamp()
                        for line in product_lines
                        if getattr(line, price_field).normalize() == price
                    ),
                ),
            )
            price = prices[0]
            raw_quantity = amount / price
            quantity: Decimal | str
            if raw_quantity == raw_quantity.to_integral_value():
                quantity = raw_quantity
            else:
                estimated = raw_quantity.quantize(Decimal("1"), rounding=ROUND_HALF_UP)
                quantity = f"≈{format_quantity(estimated)}"
            latest = max(
                (line for line in product_lines if getattr(line, price_field).normalize() == price),
                key=lambda line: line.transaction_date,
            )
            return Suggestion(
                product=latest.product,
                quantity=quantity,
                unit=latest.unit,
                confidence="very_low",
                method="most_frequent_supplier_product_estimate",
                history=latest,
                candidate_count=len(product_counts),
                reason=(
                    "Tidak ada kecocokan total/harga tunggal; dipilih barang paling sering dibeli "
                    "dari supplier dan kuantitas dihitung dari harga satuan paling umum."
                ),
            )
        return fallback_description(source_product, supplier)

    return Suggestion(
        candidate_count=len(all_prices),
        alternatives=candidate_json(list(all_prices.values())) if all_prices else [],
        reason=reason,
    )


def format_rupiah(value: Decimal | None) -> str:
    if value is None:
        return "tidak dapat dihitung"
    rounded = int(value.quantize(Decimal("1"), rounding=ROUND_HALF_UP))
    return f"Rp {rounded:,}".replace(",", ".")


def split_components(value: str) -> list[str]:
    return [part.strip() for part in str(value or "").split(";") if part.strip()]


def number_component(value: str) -> Decimal | None:
    cleaned = re.sub(r"(?i)^\s*[≈~]\s*", "", str(value or "")).replace(",", "")
    try:
        number = Decimal(cleaned)
        return number if number > 0 else None
    except InvalidOperation:
        return None


def price_mode(lines: list[PurchaseLine], price_field: str) -> tuple[Decimal, PurchaseLine, int] | None:
    priced = [line for line in lines if getattr(line, price_field) > 0]
    if not priced:
        return None
    counts = Counter(getattr(line, price_field).quantize(CENT, rounding=ROUND_HALF_UP) for line in priced)
    latest = {
        price: max(
            line.transaction_date
            for line in priced
            if getattr(line, price_field).quantize(CENT, rounding=ROUND_HALF_UP) == price
        )
        for price in counts
    }
    selected_price = max(counts, key=lambda price: (counts[price], latest[price]))
    provenance = max(
        (line for line in priced if getattr(line, price_field).quantize(CENT, rounding=ROUND_HALF_UP) == selected_price),
        key=lambda line: line.transaction_date,
    )
    return selected_price, provenance, counts[selected_price]


def transaction_value_items(
    lines: list[PurchaseLine],
) -> tuple[list[dict[str, Any]], Decimal, str]:
    """Use the transaction's own prices and select the price basis nearest its header total."""
    usable = [line for line in lines if meaningful_product(line.product) and line.quantity > 0]
    if not usable:
        return [], Decimal("0"), ""
    totals = {}
    for field in ("unit_price", "unit_price_inc_tax"):
        totals[field] = sum(
            (line.quantity * getattr(line, field) for line in usable), Decimal("0")
        ).quantize(CENT, rounding=ROUND_HALF_UP)
    header_total = usable[0].transaction_total
    selected_field = min(totals, key=lambda field: abs(totals[field] - header_total))
    grouped: dict[tuple[str, str, Decimal], dict[str, Any]] = {}
    for line in usable:
        unit_price = getattr(line, selected_field).quantize(CENT, rounding=ROUND_HALF_UP)
        key = (normalize_key(line.product), normalize_key(line.unit), unit_price)
        item = grouped.setdefault(
            key,
            {
                "product": line.product,
                "quantity": Decimal("0"),
                "unit": line.unit,
                "unit_price": unit_price,
                "effective_unit_price": unit_price,
                "history_date": line.transaction_date.date().isoformat(),
                "history_ref": line.transaction_ref,
                "price_basis": selected_field,
                "frequency": 1,
                "max_observed_quantity": line.quantity,
            },
        )
        item["quantity"] += line.quantity
        item["max_observed_quantity"] = max(item["max_observed_quantity"], line.quantity)
        item["frequency"] = max(item["frequency"], 1)
    items = list(grouped.values())
    total = sum(
        (item["quantity"] * item["unit_price"] for item in items), Decimal("0")
    ).quantize(CENT, rounding=ROUND_HALF_UP)
    return items, total, selected_field


def component_value_items(
    suggestion: Suggestion,
    supplier_history: list[PurchaseLine],
    price_field: str,
) -> list[dict[str, Any]]:
    products = split_components(suggestion.product)
    quantities = split_components(format_quantity(suggestion.quantity))
    units = split_components(suggestion.unit)
    if not products or len(products) != len(quantities) or len(products) != len(units):
        return []
    result = []
    for product, raw_quantity, unit in zip(products, quantities, units):
        quantity = number_component(raw_quantity)
        if quantity is None or not meaningful_product(product):
            return []
        matching = [
            line for line in supplier_history
            if normalize_key(line.product) == normalize_key(product)
            and normalize_key(line.unit) == normalize_key(unit)
        ]
        selected = price_mode(matching, price_field)
        if selected is None:
            return []
        unit_price, provenance, frequency = selected
        result.append(
            {
                "product": product,
                "quantity": quantity,
                "unit": unit,
                "unit_price": unit_price,
                "effective_unit_price": unit_price,
                "history_date": provenance.transaction_date.date().isoformat(),
                "history_ref": provenance.transaction_ref,
                "price_basis": price_field,
                "frequency": frequency,
                "max_observed_quantity": max(line.quantity for line in matching),
            }
        )
    return result


def apply_net98_catalog_prices(
    items: list[dict[str, Any]],
    supplier_history: list[PurchaseLine],
    amount: Decimal,
) -> bool:
    """Apply a supplier-supported 2% net-price basis when it exactly fits the source amount."""
    if not items:
        return False
    for price_field in ("unit_price", "unit_price_inc_tax"):
        selected_prices: list[tuple[Decimal, PurchaseLine, int, Decimal]] = []
        valid = True
        for item in items:
            matching = [
                line for line in supplier_history
                if normalize_key(line.product) == normalize_key(item["product"])
                and normalize_key(line.unit) == normalize_key(item["unit"])
            ]
            selected = price_mode(matching, price_field)
            if selected is None:
                valid = False
                break
            unit_price, provenance, frequency = selected
            selected_prices.append((unit_price, provenance, frequency, max(line.quantity for line in matching)))
        if not valid:
            continue
        gross_total = sum(
            (item["quantity"] * selected[0] for item, selected in zip(items, selected_prices)),
            Decimal("0"),
        ).quantize(CENT, rounding=ROUND_HALF_UP)
        net_total = (gross_total * Decimal("0.98")).quantize(CENT, rounding=ROUND_HALF_UP)
        if abs(net_total - amount) > Decimal("1"):
            continue
        for item, (unit_price, provenance, frequency, maximum_quantity) in zip(items, selected_prices):
            item["unit_price"] = unit_price
            item["effective_unit_price"] = (unit_price * Decimal("0.98")).quantize(
                CENT, rounding=ROUND_HALF_UP
            )
            item["history_date"] = provenance.transaction_date.date().isoformat()
            item["history_ref"] = provenance.transaction_ref
            item["price_basis"] = f"{price_field}_net_98_percent"
            item["frequency"] = frequency
            item["max_observed_quantity"] = maximum_quantity
        return True
    return False


def optimize_amount_combo(
    amount: Decimal,
    source_product: Any,
    supplier: str,
    supplier_history: list[PurchaseLine],
    price_field: str,
    price_factor: Decimal = Decimal("1"),
) -> list[dict[str, Any]] | None:
    """Find the closest one- or two-product integer quantity mix from supplier prices."""
    target_amount = (amount / price_factor).quantize(CENT, rounding=ROUND_HALF_UP)
    grouped: dict[tuple[str, str, Decimal], list[PurchaseLine]] = defaultdict(list)
    for line in supplier_history:
        price = getattr(line, price_field).quantize(CENT, rounding=ROUND_HALF_UP)
        if meaningful_product(line.product) and line.unit and line.quantity > 0 and price > 0:
            grouped[(normalize_key(line.product), normalize_key(line.unit), price)].append(line)
    candidates: list[dict[str, Any]] = []
    for rows in grouped.values():
        provenance = max(rows, key=lambda line: line.transaction_date)
        unit_price = getattr(provenance, price_field).quantize(CENT, rounding=ROUND_HALF_UP)
        candidates.append(
            {
                "product": provenance.product,
                "unit": provenance.unit,
                "unit_price": unit_price,
                "effective_unit_price": unit_price,
                "history_date": provenance.transaction_date.date().isoformat(),
                "history_ref": provenance.transaction_ref,
                "price_basis": price_field,
                "frequency": len(rows),
                "max_observed_quantity": max(line.quantity for line in rows),
            }
        )
    if not candidates:
        return None

    category_keys = {
        (
            normalize_key(line.product),
            normalize_key(line.unit),
            getattr(line, price_field).quantize(CENT, rounding=ROUND_HALF_UP),
        )
        for line in supplier_history
        if meaningful_product(line.product)
        and source_matches_candidate(source_product, supplier, line)
    }
    category_matches = [
        candidate for candidate in candidates
        if (normalize_key(candidate["product"]), normalize_key(candidate["unit"]), candidate["unit_price"])
        in category_keys
    ]
    if source_hint_tokens(source_product, supplier) and category_matches and (
        len(category_matches) >= 3 or len(category_matches) * 4 >= len(candidates)
    ):
        candidates = category_matches

    def rank(candidate: dict[str, Any]) -> tuple[Any, ...]:
        price = candidate["unit_price"]
        estimated_quantity = target_amount / price
        quantity_range_penalty = 0 if Decimal("1") <= estimated_quantity <= Decimal("200") else 1
        if estimated_quantity < 1:
            distance = 1 / float(estimated_quantity)
        elif estimated_quantity > 200:
            distance = float(estimated_quantity - Decimal("200"))
        else:
            distance = 0
        return (
            quantity_range_penalty,
            distance,
            -candidate["frequency"],
            -int(candidate["history_date"].replace("-", "")),
        )

    # Keep frequent supplier items and prices that make ordinary quantities plausible.
    by_frequency = sorted(candidates, key=lambda item: (-item["frequency"], item["product"].casefold()))
    selected_keys = {
        (normalize_key(item["product"]), normalize_key(item["unit"]), item["unit_price"])
        for item in by_frequency[:24]
    }
    for desired_quantity in (1, 2, 5, 10, 20, 50, 100, 200):
        closest = sorted(
            candidates,
            key=lambda item: (
                abs(target_amount / item["unit_price"] - Decimal(desired_quantity)),
                -item["frequency"],
            ),
        )[:2]
        selected_keys.update(
            (normalize_key(item["product"]), normalize_key(item["unit"]), item["unit_price"])
            for item in closest
        )
    candidates = [
        item for item in candidates
        if (normalize_key(item["product"]), normalize_key(item["unit"]), item["unit_price"]) in selected_keys
        and item["unit_price"] <= amount
    ]
    candidates = sorted(candidates, key=rank)[:24]
    if not candidates:
        return None

    target_cents = int((target_amount * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))
    for candidate in candidates:
        candidate["price_cents"] = int(
            (candidate["unit_price"] * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP)
        )
        candidate["usual_quantity_cap"] = max(
            3, int(candidate["max_observed_quantity"] * 3)
        )
    best: tuple[tuple[Any, ...], tuple[int, int, int, int, int, int]] | None = None

    def consider(
        first_index: int,
        first_quantity: int,
        second_index: int = -1,
        second_quantity: int = 0,
        third_index: int = -1,
        third_quantity: int = 0,
    ) -> None:
        nonlocal best
        if first_quantity <= 0:
            return
        first = candidates[first_index]
        total_cents = first["price_cents"] * first_quantity
        unsupported_quantity = max(0, first_quantity - first["usual_quantity_cap"])
        item_count = 1
        total_quantity = first_quantity
        frequency = first["frequency"]
        if second_index >= 0 and second_quantity > 0:
            second = candidates[second_index]
            total_cents += second["price_cents"] * second_quantity
            unsupported_quantity += max(0, second_quantity - second["usual_quantity_cap"])
            item_count += 1
            total_quantity += second_quantity
            frequency += second["frequency"]
        if third_index >= 0 and third_quantity > 0:
            third = candidates[third_index]
            total_cents += third["price_cents"] * third_quantity
            unsupported_quantity += max(0, third_quantity - third["usual_quantity_cap"])
            item_count += 1
            total_quantity += third_quantity
            frequency += third["frequency"]
        score = (
            abs(target_cents - total_cents),
            unsupported_quantity,
            item_count,
            total_quantity,
            -frequency,
        )
        if best is None or score < best[0]:
            best = (
                score,
                (first_index, first_quantity, second_index, second_quantity, third_index, third_quantity),
            )

    for index, candidate in enumerate(candidates):
        price_cents = int((candidate["unit_price"] * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))
        quotient, remainder = divmod(target_cents, price_cents)
        for quantity in {max(1, quotient), max(1, quotient + (1 if remainder else 0))}:
            if quantity <= 500:
                consider(index, quantity)

    for first_index, first in enumerate(candidates):
        first_price = int((first["unit_price"] * 100).quantize(Decimal("1"), rounding=ROUND_HALF_UP))
        first_limit = min(250, target_cents // first_price)
        if first_limit < 1:
            continue
        for second_index in range(first_index + 1, len(candidates)):
            second = candidates[second_index]
            if normalize_key(first["product"]) == normalize_key(second["product"]):
                continue
            second_price = second["price_cents"]
            for first_quantity in range(1, first_limit + 1):
                remaining = target_cents - first_price * first_quantity
                second_floor, second_remainder = divmod(remaining, second_price)
                for second_quantity in {second_floor, second_floor + (1 if second_remainder else 0)}:
                    if 0 <= second_quantity <= 500:
                        consider(first_index, first_quantity, second_index, second_quantity)

    # If a one- or two-item total is still far off, check small three-item mixes.
    if best is not None and best[0][0] > max(100, int(target_cents * 0.005)):
        for first_index in range(len(candidates)):
            first = candidates[first_index]
            for second_index in range(first_index + 1, len(candidates)):
                second = candidates[second_index]
                if normalize_key(first["product"]) == normalize_key(second["product"]):
                    continue
                first_limit = min(10, target_cents // first["price_cents"])
                second_limit = min(10, target_cents // second["price_cents"])
                for first_quantity in range(1, first_limit + 1):
                    remaining_after_first = target_cents - first["price_cents"] * first_quantity
                    second_limit_for_remainder = min(
                        second_limit,
                        remaining_after_first // second["price_cents"],
                    )
                    for second_quantity in range(1, second_limit_for_remainder + 1):
                        remaining = remaining_after_first - second["price_cents"] * second_quantity
                        for third_index, third in enumerate(candidates):
                            if third_index in {first_index, second_index}:
                                continue
                            if normalize_key(third["product"]) in {
                                normalize_key(first["product"]),
                                normalize_key(second["product"]),
                            }:
                                continue
                            third_floor, third_remainder = divmod(remaining, third["price_cents"])
                            for third_quantity in {
                                third_floor,
                                third_floor + (1 if third_remainder else 0),
                            }:
                                if 1 <= third_quantity <= 10:
                                    consider(
                                        first_index,
                                        first_quantity,
                                        second_index,
                                        second_quantity,
                                        third_index,
                                        third_quantity,
                                    )

    if best is None:
        return None
    first_index, first_quantity, second_index, second_quantity, third_index, third_quantity = best[1]
    result = []
    for index, quantity in (
        (first_index, first_quantity),
        (second_index, second_quantity),
        (third_index, third_quantity),
    ):
        if index < 0 or quantity <= 0:
            continue
        item = {key: value for key, value in candidates[index].items() if key not in {"price_cents", "usual_quantity_cap"}}
        item["quantity"] = Decimal(quantity)
        item["optimization_factor"] = price_factor
        result.append(item)
    return result


def reconcile_suggestion_value(
    suggestion: Suggestion,
    amount: Decimal,
    source_product: Any,
    supplier: str,
    supplier_history: list[PurchaseLine],
    tax: Decimal,
    combo_cache: dict[tuple[Any, ...], list[dict[str, Any]] | None] | None = None,
    history_cutoff_value: datetime | None = None,
) -> None:
    price_field = "unit_price_inc_tax" if tax > 0 else "unit_price"
    transaction_lines = []
    if suggestion.history:
        transaction_lines = [
            line for line in supplier_history
            if line.transaction_id == suggestion.history.transaction_id
        ]

    items: list[dict[str, Any]] = []
    raw_total = Decimal("0")
    if suggestion.method in {
        "supplier_purchase_total_adjusted_2_percent",
        "nearest_supplier_purchase_total",
    } and transaction_lines:
        items, raw_total, transaction_price_field = transaction_value_items(transaction_lines)
        if items:
            price_field = transaction_price_field
    if not items:
        items = component_value_items(suggestion, supplier_history, price_field)
        raw_total = sum(
            (item["quantity"] * item["unit_price"] for item in items), Decimal("0")
        ).quantize(CENT, rounding=ROUND_HALF_UP)

    is_net_invoice_match = suggestion.method == "supplier_purchase_total_adjusted_2_percent"
    if is_net_invoice_match and items:
        for item in items:
            item["effective_unit_price"] = (
                item["unit_price"] * Decimal("0.98")
            ).quantize(CENT, rounding=ROUND_HALF_UP)
            item["price_basis"] = f"{item['price_basis']}_net_98_percent"
        current_total = sum(
            (item["quantity"] * item["effective_unit_price"] for item in items), Decimal("0")
        ).quantize(CENT, rounding=ROUND_HALF_UP)
        value_basis = "harga efektif 98% dari transaksi historis"
    elif suggestion.method == "nearest_supplier_purchase_total" and items:
        for item in items:
            item["effective_unit_price"] = (
                item["unit_price"] * Decimal("0.98")
            ).quantize(CENT, rounding=ROUND_HALF_UP)
            item["price_basis"] = f"{item['price_basis']}_net_98_percent"
        current_total = sum(
            (item["quantity"] * item["effective_unit_price"] for item in items), Decimal("0")
        ).quantize(CENT, rounding=ROUND_HALF_UP)
        value_basis = "harga efektif 98% dari transaksi historis terdekat"
    else:
        current_total = raw_total
        value_basis = "harga satuan historis supplier"

    if items and apply_net98_catalog_prices(items, supplier_history, amount):
        current_total = sum(
            (item["quantity"] * item["effective_unit_price"] for item in items), Decimal("0")
        ).quantize(CENT, rounding=ROUND_HALF_UP)
        value_basis = "harga net 98% dari harga historis supplier yang cocok dengan jumlah sumber"
        if suggestion.method not in {
            "supplier_purchase_total_adjusted_2_percent",
            "historical_product_total_net_98_percent",
        }:
            suggestion.method = "historical_product_total_net_98_percent"

    net_pattern_total = (raw_total * Decimal("0.98")).quantize(CENT, rounding=ROUND_HALF_UP)
    if (
        items
        and not is_net_invoice_match
        and suggestion.method != "nearest_supplier_purchase_total"
        and abs(amount - net_pattern_total) <= Decimal("1")
    ):
        for item in items:
            item["effective_unit_price"] = (
                item["unit_price"] * Decimal("0.98")
            ).quantize(CENT, rounding=ROUND_HALF_UP)
            item["price_basis"] = f"{item['price_basis']}_net_98_percent"
        current_total = net_pattern_total
        value_basis = "harga net 98% dari harga historis yang cocok dengan jumlah sumber"
        suggestion.method = "historical_product_total_net_98_percent"

    current_difference = abs(amount - current_total) if items else None
    current_quantity_plausible = bool(items) and all(
        item["quantity"] <= max(Decimal("25"), item.get("max_observed_quantity", Decimal("0")) * 5)
        for item in items
    )
    keep_exact_evidence = bool(
        current_difference is not None
        and current_difference <= Decimal("1")
        and current_quantity_plausible
        and suggestion.method in {
            "supplier_purchase_total_adjusted_2_percent",
            "exact_supplier_purchase_line_total",
            "unique_supplier_unit_price_divisor",
            "same_supplier_same_amount_existing_product",
            "most_supported_supplier_price_divisor",
            "historical_product_total_net_98_percent",
        }
    )
    optimized = None
    if not keep_exact_evidence and (current_difference is None or current_difference > Decimal("1")):
        cache_key = (
            tuple(sorted({line.contact_id for line in supplier_history})),
            history_cutoff_value,
            amount,
            normalize_key(source_product),
            price_field,
        )
        if combo_cache is not None and cache_key in combo_cache:
            cached = combo_cache[cache_key]
            optimized = [dict(item) for item in cached] if cached else None
        else:
            options = []
            for factor in (Decimal("1"), Decimal("0.98")):
                candidate = optimize_amount_combo(
                    amount,
                    source_product,
                    supplier,
                    supplier_history,
                    price_field,
                    factor,
                )
                if not candidate:
                    continue
                gross = sum(
                    (item["quantity"] * item["unit_price"] for item in candidate), Decimal("0")
                ).quantize(CENT, rounding=ROUND_HALF_UP)
                delivered = (gross * factor).quantize(CENT, rounding=ROUND_HALF_UP)
                plausible = all(
                    item["quantity"] <= max(
                        Decimal("25"), item.get("max_observed_quantity", Decimal("0")) * 5
                    )
                    for item in candidate
                )
                score = (
                    abs(amount - delivered),
                    0 if plausible else 1,
                    len(candidate),
                    sum(item["quantity"] for item in candidate),
                    0 if factor == Decimal("1") else 1,
                )
                options.append((score, candidate))
            optimized = min(options, key=lambda option: option[0])[1] if options else None
            if combo_cache is not None:
                combo_cache[cache_key] = [dict(item) for item in optimized] if optimized else None
    optimized_gross_total = (
        sum((item["quantity"] * item["unit_price"] for item in optimized), Decimal("0")).quantize(CENT, rounding=ROUND_HALF_UP)
        if optimized else None
    )
    optimized_factor = optimized[0].get("optimization_factor", Decimal("1")) if optimized else Decimal("1")
    optimized_total = (
        (optimized_gross_total * optimized_factor).quantize(CENT, rounding=ROUND_HALF_UP)
        if optimized_gross_total is not None else None
    )
    optimized_difference = abs(amount - optimized_total) if optimized_total is not None else None
    optimized_quantity_plausible = bool(optimized) and all(
        item["quantity"] <= max(Decimal("25"), item.get("max_observed_quantity", Decimal("0")) * 5)
        for item in optimized
    )

    should_use_optimized = bool(
        optimized
        and not keep_exact_evidence
        and (
            current_difference is None
            or optimized_difference < current_difference
            or (
                not current_quantity_plausible
                and optimized_quantity_plausible
                and optimized_difference <= max(current_difference, amount * Decimal("0.005"))
            )
        )
    )
    if should_use_optimized:
        items = optimized
        current_total = optimized_total
        current_difference = optimized_difference
        suggestion.product = "; ".join(item["product"] for item in items)
        suggestion.quantity = "; ".join(format_quantity(item["quantity"]) for item in items)
        suggestion.unit = "; ".join(item["unit"] for item in items)
        if optimized_factor != Decimal("1"):
            for item in items:
                item["effective_unit_price"] = (
                    item["unit_price"] * optimized_factor
                ).quantize(CENT, rounding=ROUND_HALF_UP)
                item["price_basis"] = f"{item['price_basis']}_net_{format_quantity(optimized_factor * 100)}_percent"
            suggestion.method = "optimized_supplier_combo_net_98_percent"
        else:
            suggestion.method = "optimized_supplier_price_combo"
        suggestion.confidence = "low" if current_difference <= max(Decimal("1000"), amount * Decimal("0.005")) else "very_low"
        suggestion.reason = (
            "Kombinasi dan kuantitas dihitung dari harga satuan pada histori supplier; "
            f"nilai terdekat {format_rupiah(current_total)} dengan selisih {format_rupiah(amount - current_total)}. "
            "Kuantitas merupakan estimasi untuk transaksi ini."
        )
        value_basis = (
            "harga net 98% dari kombinasi supplier hasil optimasi"
            if optimized_factor != Decimal("1")
            else "harga satuan historis supplier; kombinasi kuantitas hasil optimasi"
        )
        if optimized_factor == Decimal("1") and apply_net98_catalog_prices(items, supplier_history, amount):
            current_total = sum(
                (item["quantity"] * item["effective_unit_price"] for item in items), Decimal("0")
            ).quantize(CENT, rounding=ROUND_HALF_UP)
            suggestion.method = "optimized_supplier_combo_net_98_percent"
            suggestion.reason = (
                "Kombinasi dan kuantitas dihitung dari histori supplier; nilai cocok setelah pola net 98% "
                "yang juga terlihat pada transaksi supplier terdahulu. Kuantitas tetap estimasi."
            )
            value_basis = "harga net 98% dari kombinasi supplier hasil optimasi"

    if items:
        suggestion.value_items = items
        suggestion.value_total = current_total.quantize(CENT, rounding=ROUND_HALF_UP)
        suggestion.value_difference = (amount - suggestion.value_total).quantize(CENT, rounding=ROUND_HALF_UP)
        suggestion.value_basis = value_basis
        if suggestion.method in {
            "supplier_purchase_total_adjusted_2_percent",
            "historical_product_total_net_98_percent",
            "optimized_supplier_combo_net_98_percent",
        }:
            suggestion.reason += (
                f" Nilai barang efektif {format_rupiah(suggestion.value_total)} menggunakan pola net 98% dari harga historis."
            )
        if not current_quantity_plausible and not should_use_optimized:
            suggestion.reason += " Kuantitas jauh di atas jumlah pembelian per transaksi yang terlihat di histori supplier."
    else:
        suggestion.value_items = []
        suggestion.value_total = None
        suggestion.value_difference = None
        suggestion.value_basis = "harga historis tidak tersedia"


def workbook_reason(suggestion: Suggestion) -> str:
    if suggestion.value_total is None:
        return "Riwayat harga supplier tidak cukup untuk memvalidasi nilai rekomendasi."
    difference = suggestion.value_difference or Decimal("0")
    if suggestion.method == "optimized_supplier_price_combo":
        return (
            "Kombinasi/kuantitas dihitung dari harga supplier terdahulu; "
            f"nilai {format_rupiah(suggestion.value_total)}, selisih {format_rupiah(difference)}."
        )
    if suggestion.method in {
        "supplier_purchase_total_adjusted_2_percent",
        "historical_product_total_net_98_percent",
        "optimized_supplier_combo_net_98_percent",
    }:
        return "Harga dan kuantitas mengacu pada histori supplier; nilai cocok dengan jumlah sumber setelah pola net 98%."
    if any(
        item["quantity"] > max(Decimal("25"), item.get("max_observed_quantity", Decimal("0")) * 5)
        for item in (suggestion.value_items or [])
    ):
        return (
            "Nilai mengikuti harga satuan supplier, tetapi kuantitas jauh di atas pola pembelian historis; "
            f"nilai {format_rupiah(suggestion.value_total)}, selisih {format_rupiah(difference)}."
        )
    if abs(difference) <= Decimal("1"):
        return "Harga satuan supplier terdahulu dan kuantitas menghasilkan nilai yang sama dengan jumlah sumber."
    return (
        f"Dipilih dari histori supplier; nilai barang {format_rupiah(suggestion.value_total)}, "
        f"selisih dari jumlah sumber {format_rupiah(difference)}."
    )


def add_output_columns(sheet, header_row: int) -> tuple[int, int, int, int, int, int]:
    headers = {
        normalize(sheet.cell(header_row, column).value): column
        for column in range(1, sheet.max_column + 1)
    }
    titles = (
        "Kuantitas Usulan",
        "Satuan Usulan",
        "Kecocokan",
        "Nilai Produk Usulan (Rp)",
        "Selisih dari Jumlah (Rp)",
        "Alasan Rekomendasi",
    )
    columns = [headers.get(normalize(title)) for title in titles]
    next_column = sheet.max_column + 1
    source_header = sheet.cell(header_row, sheet.max_column)
    for index, title in enumerate(titles):
        if columns[index] is None:
            columns[index] = next_column
            next_column += 1
        column = columns[index]
        cell = sheet.cell(header_row, column, title)
        if source_header.has_style:
            cell._style = copy(source_header._style)
        if source_header.number_format:
            cell.number_format = source_header.number_format
        sheet.column_dimensions[cell.column_letter].width = 18
    sheet.column_dimensions[sheet.cell(header_row, columns[3]).column_letter].width = 22
    sheet.column_dimensions[sheet.cell(header_row, columns[4]).column_letter].width = 22
    sheet.column_dimensions[sheet.cell(header_row, columns[5]).column_letter].width = 64
    return columns[0], columns[1], columns[2], columns[3], columns[4], columns[5]


def default_output_paths(input_path: Path) -> tuple[Path, Path]:
    return (
        input_path.with_name(f"{input_path.stem}_products_completed{input_path.suffix}"),
        input_path.with_name(f"{input_path.stem}_product_fill_audit.csv"),
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("input", type=Path, help="Workbook sumber .xlsx")
    parser.add_argument("--business-id", type=int, required=True, help="Business ID transaksi pembelian historis")
    parser.add_argument("--sheet", default=DEFAULT_SHEET, help=f"Nama sheet (default: {DEFAULT_SHEET})")
    parser.add_argument("--header-row", type=int, default=DEFAULT_HEADER_ROW)
    parser.add_argument(
        "--env-file",
        type=Path,
        default=Path(__file__).resolve().parents[1] / ".env",
        help="File .env Laravel untuk koneksi database",
    )
    parser.add_argument(
        "--history-through",
        type=date.fromisoformat,
        default=date(2024, 12, 31),
        metavar="YYYY-MM-DD",
        help="Batas akhir histori default jika referensi tidak memuat tanggal (default: 2024-12-31)",
    )
    parser.add_argument("--output", type=Path, help="Nama workbook hasil")
    parser.add_argument("--audit-csv", type=Path, help="Nama file audit CSV")
    parser.add_argument(
        "--min-confidence",
        choices=("high", "medium", "low", "very_low"),
        default="medium",
    )
    parser.add_argument(
        "--fill-unresolved",
        action="store_true",
        help="Isi juga baris tanpa kecocokan kuat dengan rekomendasi terbaik berkeyakinan rendah.",
    )
    parser.add_argument("--allow-remote-database", action="store_true", help="Izinkan DB_HOST non-lokal")
    parser.add_argument("--apply", action="store_true", help="Simpan workbook dan audit; tanpa opsi ini hanya preview")
    parser.add_argument("--overwrite-output", action="store_true", help="Izinkan menimpa file hasil/audit")
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
        print("File output harus berbeda dari workbook sumber.", file=sys.stderr)
        return 2
    if output_path.suffix.lower() != ".xlsx" or audit_path.suffix.lower() != ".csv":
        print("File output harus .xlsx dan file audit harus .csv.", file=sys.stderr)
        return 2
    if audit_path in {input_path, output_path}:
        print("File audit harus berbeda dari workbook sumber dan hasil.", file=sys.stderr)
        return 2
    if args.apply and not args.overwrite_output and (output_path.exists() or audit_path.exists()):
        print("File output/audit sudah ada; pilih nama lain atau gunakan --overwrite-output.", file=sys.stderr)
        return 2

    try:
        workbook = load_workbook(input_path)
        if args.sheet not in workbook.sheetnames:
            raise ValueError(
                f"Sheet {args.sheet!r} tidak ditemukan. Sheet tersedia: {', '.join(workbook.sheetnames)}."
            )
        sheet = workbook[args.sheet]
        columns = find_headers(sheet, args.header_row)

        connection, database_name, host = connect_database(
            args.env_file.expanduser().resolve(), args.allow_remote_database
        )
        cursor = connection.cursor(dictionary=True)
        business_name, supplier_ids_by_name, contact_labels = load_suppliers(cursor, args.business_id)
        history_limit = datetime.combine(args.history_through + timedelta(days=1), time.min)
        purchase_lines = load_purchase_lines(cursor, args.business_id, history_limit)
        cursor.close()
        connection.rollback()
        connection.close()

        rows = []
        contacts_with_product_history = {
            line.contact_id for line in purchase_lines if meaningful_product(line.product)
        }
        sibling_products: dict[tuple[str, Decimal], set[tuple[str, int]]] = defaultdict(set)
        blank_targets = []
        for row_number in range(args.header_row + 1, sheet.max_row + 1):
            supplier = str(sheet.cell(row_number, columns["supplier"]).value or "").strip()
            product_value = sheet.cell(row_number, columns["product"]).value
            amount = money_key(sheet.cell(row_number, columns["amount"]).value)
            if not is_blank(product_value) and supplier and amount is not None:
                sibling_products[(normalize_key(supplier), amount)].add((str(product_value).strip(), row_number))
            if is_blank(product_value):
                blank_targets.append(row_number)

        audit_rows = []
        filled = Counter()
        combo_cache: dict[tuple[Any, ...], list[dict[str, Any]] | None] = {}
        output_columns = add_output_columns(sheet, args.header_row)
        quantity_column, unit_column, confidence_column, value_column, difference_column, reason_column = output_columns
        for row_number in blank_targets:
            supplier = str(sheet.cell(row_number, columns["supplier"]).value or "").strip()
            source_product = sheet.cell(row_number, columns["source_product"]).value
            reference = sheet.cell(row_number, columns["reference"]).value
            amount = money_key(sheet.cell(row_number, columns["amount"]).value)
            tax = money_key(sheet.cell(row_number, columns["tax"]).value) or Decimal("0")
            supplier_ids, supplier_match_type = resolve_supplier_ids(
                supplier,
                supplier_ids_by_name,
                contact_labels,
                contacts_with_product_history,
            )
            suggestion = choose_suggestion(
                row_number=row_number,
                product_value=sheet.cell(row_number, columns["product"]).value,
                supplier=supplier,
                source_product=source_product,
                reference=reference,
                amount=amount,
                tax=tax,
                supplier_ids=supplier_ids,
                history=purchase_lines,
                fallback_through=args.history_through,
                sibling_products=sibling_products,
                fill_unresolved=args.fill_unresolved,
            )
            if supplier_match_type == "similar_supplier_name" and suggestion.product:
                suggestion.confidence = {
                    "high": "medium",
                    "medium": "low",
                    "low": "very_low",
                    "very_low": "very_low",
                    "review": "review",
                }[suggestion.confidence]
                suggestion.reason = (
                    "Supplier dicocokkan melalui nama database yang mirip. " + suggestion.reason
                )
            supplier_history = [
                line for line in purchase_lines
                if line.contact_id in supplier_ids
                and line.transaction_date < history_cutoff(reference, args.history_through)
            ]
            reconcile_suggestion_value(
                suggestion,
                amount,
                source_product,
                supplier,
                supplier_history,
                tax,
                combo_cache,
                history_cutoff(reference, args.history_through),
            )
            if supplier_match_type == "similar_supplier_name" and suggestion.method == "optimized_supplier_price_combo":
                suggestion.confidence = "very_low"
            allowed = args.fill_unresolved or (
                CONFIDENCE_RANK[suggestion.confidence] >= CONFIDENCE_RANK[args.min_confidence]
            )
            status = "filled" if suggestion.product and allowed else "review"
            if status == "filled":
                sheet.cell(row_number, columns["product"]).value = suggestion.product
                if suggestion.quantity is not None:
                    quantity_value = (
                        float(suggestion.quantity)
                        if isinstance(suggestion.quantity, Decimal)
                        else str(suggestion.quantity)
                    )
                    sheet.cell(row_number, quantity_column).value = quantity_value
                if suggestion.unit:
                    sheet.cell(row_number, unit_column).value = suggestion.unit
                sheet.cell(
                    row_number,
                    confidence_column,
                    CONFIDENCE_LABELS.get(suggestion.confidence, "Perlu review"),
                )
                sheet.cell(
                    row_number,
                    value_column,
                    float(suggestion.value_total) if suggestion.value_total is not None else None,
                )
                sheet.cell(
                    row_number,
                    difference_column,
                    float(suggestion.value_difference) if suggestion.value_difference is not None else None,
                )
                sheet.cell(row_number, value_column).number_format = "#,##0.00;[Red]-#,##0.00;-"
                sheet.cell(row_number, difference_column).number_format = "#,##0.00;[Red]-#,##0.00;-"
                reason_cell = sheet.cell(row_number, reason_column, workbook_reason(suggestion))
                reason_cell.alignment = copy(reason_cell.alignment)
                reason_cell.alignment = Alignment(
                    horizontal=reason_cell.alignment.horizontal,
                    vertical="top",
                    wrap_text=True,
                )
                filled[suggestion.confidence] += 1

            history = suggestion.history
            value_items_audit = [
                {
                    key: format_quantity(value) if isinstance(value, Decimal) else value
                    for key, value in item.items()
                }
                for item in (suggestion.value_items or [])
            ]
            audit_rows.append(
                {
                    "sheet_row": row_number,
                    "record_no": sheet.cell(row_number, columns["no"]).value or "",
                    "supplier_asli": supplier,
                    "supplier_contact_ids": ",".join(str(value) for value in sorted(supplier_ids)),
                    "supplier_match_type": supplier_match_type,
                    "produk_asli": str(source_product or "").strip(),
                    "referensi": str(reference or "").strip(),
                    "jumlah_rp": str(amount or ""),
                    "batas_histori_sebelum": (
                        history_cutoff(reference, args.history_through).isoformat(sep=" ")
                    ),
                    "status": status,
                    "confidence": suggestion.confidence,
                    "metode": suggestion.method,
                    "produk_usulan": suggestion.product,
                    "kuantitas_usulan": format_quantity(suggestion.quantity),
                    "satuan_usulan": suggestion.unit,
                    "nilai_produk_usulan_rp": format_quantity(suggestion.value_total),
                    "selisih_nilai_ke_jumlah_rp": format_quantity(suggestion.value_difference),
                    "basis_harga_usulan": suggestion.value_basis,
                    "rincian_nilai_json": json.dumps(value_items_audit, ensure_ascii=False),
                    "alasan_ringkas_workbook": workbook_reason(suggestion),
                    "candidate_count": suggestion.candidate_count,
                    "produk_histori": history.product if history else "",
                    "harga_satuan_histori": (
                        format_quantity(history.unit_price) if history else ""
                    ),
                    "tanggal_histori": (
                        history.transaction_date.strftime("%Y-%m-%d %H:%M:%S") if history else ""
                    ),
                    "referensi_histori": history.transaction_ref if history else "",
                    "alternatives_json": json.dumps(suggestion.alternatives or [], ensure_ascii=False),
                    "alasan": suggestion.reason,
                }
            )

        review_count = len(blank_targets) - sum(filled.values())
        print(f"Database lokal: {database_name} @ {host}")
        print(f"Business: {business_name} (#{args.business_id})")
        print(f"Workbook: {input_path.name} | Sheet: {sheet.title}")
        print(f"Histori pembelian yang dibaca: {len(purchase_lines)} baris")
        print(f"Produk kosong: {len(blank_targets)}")
        print(
            "Usulan isi: "
            f"{sum(filled.values())} (high={filled['high']}, medium={filled['medium']}, "
            f"low={filled['low']}, very_low={filled['very_low']})"
        )
        print(f"Perlu review: {review_count}")
        print(f"Mode: {'APPLY' if args.apply else 'DRY-RUN'}")
        shown = 0
        for row in audit_rows:
            if row["status"] == "filled" and shown < 40:
                print(
                    f"  baris {row['sheet_row']} | {row['supplier_asli']} | Rp {row['jumlah_rp']} "
                    f"-> {row['produk_usulan']} | {row['kuantitas_usulan']} {row['satuan_usulan']} "
                    f"[{row['confidence']}]"
                )
                shown += 1
        if sum(filled.values()) > shown:
            print(f"  ... {sum(filled.values()) - shown} rekomendasi lainnya tercatat di audit.")

        if args.apply:
            output_path.parent.mkdir(parents=True, exist_ok=True)
            audit_path.parent.mkdir(parents=True, exist_ok=True)
            workbook.save(output_path)
            restored_formula_count = restore_formula_cached_values(input_path, output_path)
            fieldnames = list(audit_rows[0].keys()) if audit_rows else [
                "sheet_row", "record_no", "supplier_asli", "status", "alasan"
            ]
            with audit_path.open("w", newline="", encoding="utf-8-sig") as handle:
                writer = csv.DictWriter(handle, fieldnames=fieldnames)
                writer.writeheader()
                writer.writerows(audit_rows)
            print(f"Workbook hasil: {output_path}")
            print(f"Audit CSV: {audit_path}")
            print(f"Cache formula yang dipertahankan: {restored_formula_count}")
        else:
            print("Preview saja; tambahkan --apply untuk menyimpan file hasil dan audit.")

        workbook.close()
        return 0
    except Exception as exc:
        print(f"Gagal memproses workbook: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
