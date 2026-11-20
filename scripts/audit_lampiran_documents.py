#!/usr/bin/env python3
"""Audit and optionally restore legacy attachments and match them to operations.

    Dry-run is the default and never makes network requests or writes to the database.
    Pass --business-id to inspect local operational records. --export-missing-xlsx
    inventories every database attachment for that business without requiring the
    legacy manifest. --apply-links is a separate explicit action.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile
import threading
import time
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any, Iterable
from urllib.error import HTTPError, URLError
from urllib.parse import quote, unquote, urlsplit, urlunsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_MANIFEST = Path("import-legacy/json/lampiran.json")
DEFAULT_DESTINATION = Path("public/uploads/documents")
DEFAULT_ALLOWED_HOSTS = {"jurnal-quickbook.jurnal.id"}
CHUNK_SIZE = 1024 * 1024
INVALID_FILENAME_CHARS = re.compile(r'[<>:"/\\|?*\x00-\x1f]')
SOURCE_NUMBER_PATTERN = re.compile(
    r"^\s*(Expense|Purchase Invoice|Sales Invoice|Purchase Order|Purchase Payment|Receive Payment|Bank Transfer|Bank Withdrawal|Bank Deposit)\s*#\s*(.*?)\s*$",
    re.IGNORECASE,
)
TIMESTAMPED_FILENAME_PATTERN = re.compile(r"^\d{9,13}_(?:[A-Za-z0-9]{20}_)?(.+)$")
WINDOWS_RESERVED_NAMES = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{i}" for i in range(1, 10)),
    *(f"LPT{i}" for i in range(1, 10)),
}


@dataclass
class Attachment:
    row_number: int
    attachment_id: str
    source_id_present: bool
    transaction_id: str
    source_type: str
    source_number: str
    url: str
    host: str
    original_name: str
    output_name: str = ""
    collision_count: int = 1
    status: str = ""
    detail: str = ""
    database_status: str = "not_checked"
    database_match_method: str = ""
    database_target_type: str = ""
    database_target_id: str = ""
    database_media_model_type: str = ""
    database_media_model_id: str = ""
    database_reference: str = ""
    database_transaction_date: Any = ""
    database_attachment_source: str = ""
    database_detail: str = ""
    existing_media_id: str = ""
    database_link_action: str = "not_requested"
    local_file_name: str = ""


@dataclass
class OperationalAttachment:
    transaction_id: int
    reference: str
    transaction_date: Any
    transaction_type: str
    file_name: str
    storage_location: str = "documents"


class CapacityLimitError(Exception):
    """Raised when a download would use the reserved free disk space."""


class DownloadStopped(Exception):
    """Raised when the user interrupts an active download batch."""


class DownloadBudget:
    def __init__(self, max_bytes: int) -> None:
        self.max_bytes = max_bytes
        self.used_bytes = 0
        self.exhausted = max_bytes <= 0
        self._lock = threading.Lock()

    def reserve(self, amount: int) -> None:
        with self._lock:
            if self.exhausted or self.used_bytes + amount > self.max_bytes:
                self.exhausted = True
                raise CapacityLimitError("cadangan ruang disk 1 GiB tercapai")
            self.used_bytes += amount

    def release(self, amount: int) -> None:
        with self._lock:
            self.used_bytes = max(0, self.used_bytes - amount)


def resolve_path(value: str | Path) -> Path:
    path = Path(value).expanduser()
    return path.resolve() if path.is_absolute() else (PROJECT_ROOT / path).resolve()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Periksa lampiran, cocokkan dengan database operasional, dan unduh file yang hilang."
    )
    parser.add_argument("--manifest", default=str(DEFAULT_MANIFEST), help="Lokasi lampiran.json")
    parser.add_argument("--destination", default=str(DEFAULT_DESTINATION), help="Folder tujuan file")
    parser.add_argument("--business-id", type=int, help="Cocokkan lampiran dengan operasional pada business ini memakai .env")
    parser.add_argument("--run-id", type=int, help="Gunakan crosswalk legacy import run ini sebagai pencocokan utama")
    parser.add_argument(
        "--apply-links",
        action="store_true",
        help="Tulis Media untuk lampiran yang cocok, tidak ambigu, dan file lokalnya sudah tersedia.",
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--download",
        action="store_true",
        help="Unduh file yang hilang. Tanpa opsi ini, skrip hanya melakukan dry-run.",
    )
    parser.add_argument(
        "--limit",
        type=int,
        help="Batas jumlah file per batch unduhan; berguna untuk uji coba kecil.",
    )
    mode.add_argument(
        "--dry-run",
        action="store_true",
        help="Tampilkan rencana tanpa request jaringan dan tanpa mengubah folder tujuan (default).",
    )
    parser.add_argument(
        "--report-csv",
        help="Lokasi laporan CSV. Default: storage/app/lampiran-dry-run-report.csv atau lampiran-download-report.csv.",
    )
    parser.add_argument(
        "--export-missing-xlsx",
        action="store_true",
        help="Buat Excel di public/exports untuk semua lampiran database business ini yang hilang dari documents/media.",
    )
    parser.add_argument(
        "--public-base-url",
        help="Awalan URL aplikasi untuk tautan unduh. Default memakai APP_URL; otomatis mengenali subfolder Laragon lokal.",
    )
    parser.add_argument(
        "--allow-host",
        action="append",
        default=[],
        help="Host HTTPS tambahan yang diizinkan. Opsi dapat diulang.",
    )
    parser.add_argument("--workers", type=int, default=2, help="Unduhan paralel (default: 2, maksimum: 8)")
    parser.add_argument("--delay", type=float, default=0.1, help="Jeda antarpermintaan per worker dalam detik")
    parser.add_argument("--timeout", type=float, default=30, help="Timeout tiap permintaan dalam detik")
    parser.add_argument("--retries", type=int, default=2, help="Jumlah percobaan ulang jika gagal")
    parser.add_argument(
        "--max-mib-per-file",
        type=float,
        default=100,
        help="Batas ukuran setiap file untuk mencegah respons tak terduga (default: 100 MiB)",
    )
    args = parser.parse_args()
    if not 1 <= args.workers <= 8:
        parser.error("--workers harus antara 1 dan 8")
    if args.limit is not None and args.limit < 1:
        parser.error("--limit harus minimal 1")
    if args.business_id is not None and args.business_id < 1:
        parser.error("--business-id harus minimal 1")
    if args.run_id is not None and args.run_id < 1:
        parser.error("--run-id harus minimal 1")
    if args.run_id is not None and args.business_id is None:
        parser.error("--run-id memerlukan --business-id")
    if args.apply_links and args.business_id is None:
        parser.error("--apply-links memerlukan --business-id")
    if args.apply_links and args.dry_run:
        parser.error("--apply-links menulis ke database; jangan gabungkan dengan --dry-run")
    if args.export_missing_xlsx and args.business_id is None:
        parser.error("--export-missing-xlsx memerlukan --business-id untuk menginventarisasi transaksi business tersebut.")
    if args.public_base_url and not args.export_missing_xlsx:
        parser.error("--public-base-url hanya dapat dipakai bersama --export-missing-xlsx.")
    if args.export_missing_xlsx and (args.download or args.apply_links or args.run_id is not None):
        parser.error("--export-missing-xlsx adalah inventaris baca-saja; jangan gabungkan dengan --download, --apply-links, atau --run-id.")
    if args.delay < 0 or args.timeout <= 0 or args.retries < 0 or args.max_mib_per_file <= 0:
        parser.error("delay/retries tidak boleh negatif; timeout dan batas ukuran harus lebih dari nol")
    return args


def load_rows(manifest: Path) -> list[dict[str, Any]]:
    try:
        data = json.loads(manifest.read_text(encoding="utf-8-sig"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"Gagal membaca manifest: {exc}") from exc

    if isinstance(data, list):
        rows = data
    elif isinstance(data, dict):
        rows = next(
            (value for key, value in data.items() if key.lower() in {"attachments", "lampiran", "data"} and isinstance(value, list)),
            None,
        )
    else:
        rows = None

    if not isinstance(rows, list):
        raise ValueError("Format JSON tidak dikenali: manifest harus berupa daftar lampiran")
    return [row for row in rows if isinstance(row, dict)]


def safe_filename_from_url(url: str) -> str:
    raw_name = unquote(PurePosixPath(urlsplit(url).path).name).strip()
    name = INVALID_FILENAME_CHARS.sub("_", raw_name).rstrip(" .")
    if not name or name in {".", ".."}:
        raise ValueError("nama file kosong atau tidak aman")
    stem = Path(name).stem
    suffix = Path(name).suffix
    if stem.upper() in WINDOWS_RESERVED_NAMES:
        name = f"_{name}"
    if len(name) > 180:
        name = name[: 180 - len(suffix)] + suffix
    return name


def validated_host(url: str, allowed_hosts: set[str]) -> str:
    parts = urlsplit(url)
    if parts.scheme.lower() != "https":
        raise ValueError("URL bukan HTTPS")
    if parts.username or parts.password or not parts.hostname:
        raise ValueError("URL tidak memiliki host yang valid")
    host = parts.hostname.lower().rstrip(".")
    if host not in allowed_hosts:
        raise ValueError(f"host tidak diizinkan: {host}")
    return host


def source_identity(row: dict[str, Any]) -> tuple[str, str]:
    entry = row.get("entry") if isinstance(row.get("entry"), dict) else {}
    fields = ("number", "noref", "no_ref", "ref_no", "reference_no", "reference_number", "source_number", "reference")
    reference = ""
    for source in (entry, row):
        for field in fields:
            value = source.get(field)
            if isinstance(value, (str, int, float)) and str(value).strip():
                reference = str(value).strip()
                break
        if reference:
            break

    number_match = SOURCE_NUMBER_PATTERN.match(reference)
    if number_match:
        source_type = {
            "expense": "Expense",
            "purchase invoice": "Purchase Invoice",
            "sales invoice": "Sales Invoice",
            "purchase order": "Purchase Order",
            "purchase payment": "Purchase Payment",
            "receive payment": "Receive Payment",
            "bank transfer": "Bank Transfer",
            "bank withdrawal": "Bank Withdrawal",
            "bank deposit": "Bank Deposit",
        }.get(number_match.group(1).casefold(), number_match.group(1).strip())
        return source_type, number_match.group(2).strip()

    type_value = entry.get("type") or row.get("source_type") or row.get("transaction_type") or row.get("type") or ""
    source_type = {
        "expense": "Expense",
        "expense_refund": "Expense",
        "purchase": "Purchase Invoice",
        "purchase_invoice": "Purchase Invoice",
        "purchase_order": "Purchase Order",
        "purchase payment": "Purchase Payment",
        "receive payment": "Receive Payment",
        "sell": "Sales Invoice",
        "sales_invoice": "Sales Invoice",
        "bank transfer": "Bank Transfer",
        "bank withdrawal": "Bank Withdrawal",
        "bank deposit": "Bank Deposit",
    }.get(str(type_value).strip().casefold(), "")
    return source_type, reference


def extract_attachments(rows: Iterable[dict[str, Any]], allowed_hosts: set[str]) -> tuple[list[Attachment], list[str]]:
    attachments: list[Attachment] = []
    invalid: list[str] = []
    for row_number, row in enumerate(rows, start=1):
        file_info = row.get("file")
        url = file_info.get("url") if isinstance(file_info, dict) else None
        if not isinstance(url, str) or not url.strip():
            invalid.append(f"baris {row_number}: URL lampiran kosong")
            continue
        try:
            host = validated_host(url, allowed_hosts)
            filename = safe_filename_from_url(url)
        except ValueError as exc:
            invalid.append(f"baris {row_number}: {exc}")
            continue
        entry = row.get("entry") if isinstance(row.get("entry"), dict) else {}
        source_type, source_number = source_identity(row)
        attachments.append(
            Attachment(
                row_number=row_number,
                attachment_id=str(row.get("id") or row_number),
                source_id_present=bool(str(row.get("id") or "").strip()),
                transaction_id=str(row.get("transaction_id") or entry.get("id") or ""),
                source_type=source_type,
                source_number=source_number,
                url=url,
                host=host,
                original_name=filename,
            )
        )
    return attachments, invalid


def plan_output_names(attachments: list[Attachment]) -> list[Attachment]:
    counts: dict[str, int] = defaultdict(int)
    for attachment in attachments:
        counts[attachment.original_name.casefold()] += 1

    for attachment in attachments:
        collision_count = counts[attachment.original_name.casefold()]
        attachment.collision_count = collision_count
        attachment.output_name = legacy_import_destination_name(
            attachment.original_name,
            attachment.attachment_id,
            collision_count > 1,
        )
    return attachments


def legacy_import_destination_name(original_name: str, attachment_id: str, is_collision: bool) -> str:
    """Mirror LegacyAttachmentImporter::destinationName for DB-linked files."""
    if not is_collision:
        return original_name

    safe_id = re.sub(r"[^A-Za-z0-9_-]+", "-", attachment_id)
    if not safe_id:
        safe_id = hashlib.sha256(attachment_id.encode("utf-8")).hexdigest()[:16]

    path = Path(original_name)
    suffix = path.suffix
    stem = path.name[: -len(suffix)] if suffix else path.name
    max_stem_length = max(1, 220 - len(safe_id.encode("utf-8")) - len(suffix.encode("utf-8")))
    stem_bytes = stem.encode("utf-8")[:max_stem_length]
    stem = stem_bytes.decode("utf-8", errors="ignore") or stem[:1]

    return f"legacy-{safe_id}__{stem}{suffix}"


def load_database_config() -> dict[str, str]:
    env_path = PROJECT_ROOT / ".env"
    if not env_path.is_file():
        raise ValueError("File .env tidak ditemukan; koneksi database tidak dapat dibuka.")

    values: dict[str, str] = {}
    for line in env_path.read_text(encoding="utf-8-sig").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].lstrip()
        match = re.match(r"^([A-Z_]+)=(.*)$", line)
        if not match:
            continue
        value = match.group(2).strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        else:
            value = re.split(r"\s+#", value, maxsplit=1)[0].strip()
        values[match.group(1)] = value

    if values.get("DB_CONNECTION", "mysql").casefold() != "mysql":
        raise ValueError("Pencocokan database Python saat ini memerlukan DB_CONNECTION=mysql.")
    return values


def connect_database(read_only: bool):
    try:
        import mysql.connector
    except ImportError as exc:
        raise ValueError(
            "Driver MySQL Python belum terpasang. Jalankan: python -m pip install -r scripts/requirements-audit-lampiran.txt"
        ) from exc

    config = load_database_config()
    options: dict[str, Any] = {
        "host": config.get("DB_HOST", "127.0.0.1"),
        "port": int(config.get("DB_PORT", "3306") or "3306"),
        "user": config.get("DB_USERNAME", "root"),
        "password": config.get("DB_PASSWORD", ""),
        "database": config.get("DB_DATABASE", ""),
        "connection_timeout": 10,
        "autocommit": False,
        "charset": "utf8mb4",
    }
    if config.get("DB_SOCKET"):
        options.pop("host", None)
        options.pop("port", None)
        options["unix_socket"] = config["DB_SOCKET"]
    try:
        connection = mysql.connector.connect(**options)
    except Exception as exc:
        raise ValueError(f"Koneksi database gagal: {str(exc).splitlines()[0][:240]}") from exc
    if read_only:
        cursor = connection.cursor()
        try:
            cursor.execute("SET SESSION TRANSACTION READ ONLY")
        finally:
            cursor.close()
    return connection


def normalize_database_number(value: str) -> str:
    return re.sub(r"\s+", " ", value.strip()).upper()


def database_filename_keys(value: str) -> list[str]:
    name = Path(value.replace("\\", "/").strip()).name
    if not name:
        return []
    keys = [name.casefold()]
    timestamp = TIMESTAMPED_FILENAME_PATTERN.match(name)
    if timestamp:
        keys.append(timestamp.group(1).casefold())
    return list(dict.fromkeys(keys))


def database_candidate_identity(candidate: dict[str, Any]) -> tuple[str, str, str, str]:
    return (
        candidate["target_type"],
        str(candidate["target_id"]),
        candidate["media_model_type"],
        str(candidate["media_model_id"]),
    )


class OperationalDatabaseMatcher:
    """Read-only lookup of legacy attachment references in business-scoped records."""

    OPERATIONAL_TYPES = {
        "Expense": ("expense", "expense_refund"),
        "Purchase Invoice": ("purchase",),
        "Sales Invoice": ("sell",),
        "Purchase Order": ("purchase_order",),
    }
    PAYMENT_TYPES = {"Purchase Payment": "purchase", "Receive Payment": "sell"}
    ACCOUNT_TYPES = {
        "Bank Deposit": ("credit", "deposit"),
        "Bank Transfer": ("debit", "fund_transfer"),
        "Bank Withdrawal": ("debit", "withdrawal"),
    }
    TRANSACTION_MODEL = "App\\Transaction"
    PAYMENT_MODEL = "App\\TransactionPayment"
    ACCOUNT_MODEL_TYPES = ("App\\AccountTransaction", "Modules\\Accounting\\Entities\\AccountTransaction")

    def __init__(
        self,
        connection: Any,
        business_id: int,
        run_id: int | None,
        attachments: list[Attachment],
        build_indexes: bool = True,
    ) -> None:
        self.connection = connection
        self.business_id = business_id
        self.cursor = connection.cursor(dictionary=True, buffered=True)
        self.columns = self._load_columns()
        self.number_index: dict[tuple[str, str], list[dict[str, Any]]] = defaultdict(list)
        self.file_index: dict[str, list[dict[str, Any]]] = defaultdict(list)
        self.crosswalk_index: dict[tuple[str, str], list[dict[str, Any]]] = defaultdict(list)
        self.media_by_attachment_id: dict[str, dict[str, Any]] = {}
        self.source_media_candidates: dict[str, dict[str, Any]] = {}
        if build_indexes:
            self._build_indexes(attachments, run_id)

    def close(self) -> None:
        self.cursor.close()

    def _load_columns(self) -> dict[str, set[str]]:
        self.cursor.execute(
            "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name "
            "FROM information_schema.columns WHERE table_schema = DATABASE()"
        )
        columns: dict[str, set[str]] = defaultdict(set)
        for row in self.cursor.fetchall():
            columns[row["table_name"]].add(row["column_name"])
        return columns

    def has_table(self, name: str) -> bool:
        return name in self.columns

    def has_column(self, table: str, column: str) -> bool:
        return column in self.columns.get(table, set())

    @staticmethod
    def placeholders(count: int) -> str:
        return ",".join(["%s"] * count)

    def _rows(self, sql: str, params: Iterable[Any] = ()) -> list[dict[str, Any]]:
        self.cursor.execute(sql, tuple(params))
        return list(self.cursor.fetchall())

    def _add_number(self, source_type: str, number: str, candidate: dict[str, Any]) -> None:
        key = (source_type, normalize_database_number(number))
        if key[1]:
            self.number_index[key].append(candidate)

    def _add_file(self, filename: str, candidate: dict[str, Any]) -> None:
        for key in database_filename_keys(filename):
            self.file_index[key].append(candidate)

    @staticmethod
    def _unique(candidates: Iterable[dict[str, Any]]) -> list[dict[str, Any]]:
        unique: dict[tuple[str, str, str, str], dict[str, Any]] = {}
        for candidate in candidates:
            unique.setdefault(database_candidate_identity(candidate), candidate)
        return list(unique.values())

    def _transaction_candidate(self, row: dict[str, Any], field: str = "transactions.ref_no") -> dict[str, Any]:
        transaction_id = int(row["id"])
        return {
            "target_type": self.TRANSACTION_MODEL,
            "target_id": transaction_id,
            "media_model_type": self.TRANSACTION_MODEL,
            "media_model_id": transaction_id,
            "transaction_id": transaction_id,
            "parent_type": str(row.get("type") or ""),
            "reference": str(row.get("ref_no") or ""),
            "attachment_source": field,
        }

    def _build_indexes(self, attachments: list[Attachment], run_id: int | None) -> None:
        numbers_by_type: dict[str, dict[str, str]] = defaultdict(dict)
        filename_keys: set[str] = set()
        legacy_ids: set[str] = set()
        for attachment in attachments:
            if attachment.source_type and attachment.source_number:
                numbers_by_type[attachment.source_type][normalize_database_number(attachment.source_number)] = attachment.source_number
            filename_keys.update(database_filename_keys(attachment.original_name))
            if attachment.transaction_id:
                legacy_ids.add(attachment.transaction_id)

        if self.has_table("transactions") and {"id", "business_id", "type", "ref_no"}.issubset(self.columns["transactions"]):
            for source_type, transaction_types in self.OPERATIONAL_TYPES.items():
                numbers = list(numbers_by_type.get(source_type, {}).values())
                for offset in range(0, len(numbers), 400):
                    chunk = numbers[offset : offset + 400]
                    if not chunk:
                        continue
                    params: list[Any] = [self.business_id, *transaction_types, *chunk]
                    sql = (
                        "SELECT id, type, ref_no"
                        + (", document" if self.has_column("transactions", "document") else "")
                        + " FROM transactions WHERE business_id=%s AND type IN ("
                        + self.placeholders(len(transaction_types))
                        + ") AND ref_no IN ("
                        + self.placeholders(len(chunk))
                        + ") AND ref_no IS NOT NULL"
                    )
                    if self.has_column("transactions", "deleted_at"):
                        sql += " AND deleted_at IS NULL"
                    for row in self._rows(sql, params):
                        candidate = self._transaction_candidate(row)
                        self._add_number(source_type, str(row.get("ref_no") or ""), candidate)
                        if str(row.get("document") or "").strip():
                            candidate = dict(candidate, attachment_source="transactions.document")
                            self._add_file(str(row["document"]), candidate)

        self._index_payment_references(numbers_by_type)
        self._index_bank_references(numbers_by_type)
        self._index_document_fields(filename_keys)
        self._index_existing_media(filename_keys)
        self._index_crosswalks(run_id, legacy_ids)
        self._load_media_for_attachment_ids(attachments)
        self._index_source_media_owners()

    def _index_payment_references(self, numbers_by_type: dict[str, dict[str, str]]) -> None:
        if not (self.has_table("transaction_payments") and self.has_table("transactions")):
            return
        required = {"id", "transaction_id", "payment_ref_no"}
        if not required.issubset(self.columns["transaction_payments"]) or not {"id", "business_id", "type"}.issubset(self.columns["transactions"]):
            return
        has_document = self.has_column("transaction_payments", "document")
        has_ref = self.has_column("transactions", "ref_no")
        for source_type, parent_type in self.PAYMENT_TYPES.items():
            numbers = list(numbers_by_type.get(source_type, {}).values())
            for offset in range(0, len(numbers), 400):
                chunk = numbers[offset : offset + 400]
                if not chunk:
                    continue
                select = ["payment.id AS payment_id", "payment.payment_ref_no", "payment.transaction_id", "parent.type AS parent_type"]
                if has_document:
                    select.append("payment.document")
                if has_ref:
                    select.append("parent.ref_no AS parent_ref_no")
                sql = (
                    "SELECT " + ", ".join(select) + " FROM transaction_payments AS payment "
                    "JOIN transactions AS parent ON parent.id=payment.transaction_id "
                    "WHERE parent.business_id=%s AND parent.type=%s AND payment.payment_ref_no IN ("
                    + self.placeholders(len(chunk)) + ") AND payment.payment_ref_no IS NOT NULL"
                )
                if self.has_column("transactions", "deleted_at"):
                    sql += " AND parent.deleted_at IS NULL"
                for row in self._rows(sql, [self.business_id, parent_type, *chunk]):
                    candidate = {
                        "target_type": self.PAYMENT_MODEL,
                        "target_id": int(row["payment_id"]),
                        "media_model_type": self.TRANSACTION_MODEL,
                        "media_model_id": int(row["transaction_id"]),
                        "transaction_id": int(row["transaction_id"]),
                        "parent_type": str(row.get("parent_type") or ""),
                        "reference": str(row.get("parent_ref_no") or ""),
                        "attachment_source": "transaction_payments.payment_ref_no",
                    }
                    self._add_number(source_type, str(row.get("payment_ref_no") or ""), candidate)
                    if str(row.get("document") or "").strip():
                        self._add_file(str(row["document"]), dict(candidate, attachment_source="transaction_payments.document"))

    def _index_bank_references(self, numbers_by_type: dict[str, dict[str, str]]) -> None:
        if not (self.has_table("account_transactions") and self.has_table("accounts")):
            return
        if not {"id", "account_id", "type", "sub_type", "reff_no"}.issubset(self.columns["account_transactions"]):
            return
        if not {"id", "business_id"}.issubset(self.columns["accounts"]):
            return
        has_transaction_id = self.has_column("account_transactions", "transaction_id")
        for source_type, (account_type, subtype) in self.ACCOUNT_TYPES.items():
            numbers = list(numbers_by_type.get(source_type, {}).values())
            for offset in range(0, len(numbers), 400):
                chunk = numbers[offset : offset + 400]
                if not chunk:
                    continue
                select = ["entry.id", "entry.reff_no"]
                if has_transaction_id:
                    select.append("entry.transaction_id")
                sql = (
                    "SELECT " + ", ".join(select) + " FROM account_transactions AS entry "
                    "JOIN accounts AS account ON account.id=entry.account_id "
                    "WHERE account.business_id=%s AND entry.type=%s AND entry.sub_type=%s "
                    "AND entry.reff_no IN (" + self.placeholders(len(chunk)) + ") AND entry.reff_no IS NOT NULL"
                )
                if self.has_column("account_transactions", "deleted_at"):
                    sql += " AND entry.deleted_at IS NULL"
                if self.has_column("accounts", "deleted_at"):
                    sql += " AND account.deleted_at IS NULL"
                for row in self._rows(sql, [self.business_id, account_type, subtype, *chunk]):
                    entry_id = int(row["id"])
                    candidate = {
                        "target_type": "App\\AccountTransaction",
                        "target_id": entry_id,
                        "media_model_type": "App\\AccountTransaction",
                        "media_model_id": entry_id,
                        "transaction_id": int(row.get("transaction_id") or 0),
                        "parent_type": "",
                        "reference": str(row.get("reff_no") or ""),
                        "attachment_source": "account_transactions.reff_no",
                    }
                    self._add_number(source_type, str(row.get("reff_no") or ""), candidate)

    def _index_document_fields(self, filename_keys: set[str]) -> None:
        del filename_keys  # Index only non-empty attachment fields, then compare locally to normalized keys.
        transaction_types = sorted({value for values in self.OPERATIONAL_TYPES.values() for value in values})
        if self.has_table("transactions") and self.has_column("transactions", "document") and self.has_column("transactions", "business_id"):
            select = ["id", "type", "document"]
            if self.has_column("transactions", "ref_no"):
                select.append("ref_no")
            sql = "SELECT " + ", ".join(select) + " FROM transactions WHERE business_id=%s AND type IN (" + self.placeholders(len(transaction_types)) + ") AND document IS NOT NULL AND document<>''"
            if self.has_column("transactions", "deleted_at"):
                sql += " AND deleted_at IS NULL"
            for row in self._rows(sql, [self.business_id, *transaction_types]):
                self._add_file(str(row["document"]), dict(self._transaction_candidate(row, "transactions.document")))

        if self.has_table("expense_lines") and self.has_table("transactions") and {"id", "transaction_id", "document"}.issubset(self.columns["expense_lines"]):
            expense_types = self.OPERATIONAL_TYPES["Expense"]
            sql = (
                "SELECT line.id AS line_id, line.transaction_id, line.document, parent.type AS parent_type, parent.ref_no AS parent_ref_no "
                "FROM expense_lines AS line JOIN transactions AS parent ON parent.id=line.transaction_id "
                "WHERE parent.business_id=%s AND parent.type IN (" + self.placeholders(len(expense_types)) + ") "
                "AND line.document IS NOT NULL AND line.document<>''"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            for row in self._rows(sql, [self.business_id, *expense_types]):
                transaction_id = int(row["transaction_id"])
                candidate = {
                    "target_type": "expense_lines",
                    "target_id": int(row["line_id"]),
                    "media_model_type": self.TRANSACTION_MODEL,
                    "media_model_id": transaction_id,
                    "transaction_id": transaction_id,
                    "parent_type": str(row.get("parent_type") or ""),
                    "reference": str(row.get("parent_ref_no") or ""),
                    "attachment_source": "expense_lines.document",
                }
                self._add_file(str(row["document"]), candidate)

        if self.has_table("transaction_payments") and self.has_table("transactions") and {"id", "transaction_id", "document"}.issubset(self.columns["transaction_payments"]):
            if {"id", "business_id", "type"}.issubset(self.columns["transactions"]):
                select = ["payment.id AS payment_id", "payment.transaction_id", "payment.document", "parent.type AS parent_type"]
                if self.has_column("transactions", "ref_no"):
                    select.append("parent.ref_no AS parent_ref_no")
                sql = (
                    "SELECT " + ", ".join(select) + " FROM transaction_payments AS payment "
                    "JOIN transactions AS parent ON parent.id=payment.transaction_id "
                    "WHERE parent.business_id=%s AND parent.type IN (" + self.placeholders(len(transaction_types)) + ") "
                    "AND payment.document IS NOT NULL AND payment.document<>''"
                )
                if self.has_column("transactions", "deleted_at"):
                    sql += " AND parent.deleted_at IS NULL"
                for row in self._rows(sql, [self.business_id, *transaction_types]):
                    transaction_id = int(row["transaction_id"])
                    candidate = {
                        "target_type": self.PAYMENT_MODEL,
                        "target_id": int(row["payment_id"]),
                        "media_model_type": self.TRANSACTION_MODEL,
                        "media_model_id": transaction_id,
                        "transaction_id": transaction_id,
                        "parent_type": str(row.get("parent_type") or ""),
                        "reference": str(row.get("parent_ref_no") or ""),
                        "attachment_source": "transaction_payments.document",
                    }
                    self._add_file(str(row["document"]), candidate)

    def _index_existing_media(self, filename_keys: set[str]) -> None:
        if not self.has_table("media") or not {"id", "business_id", "file_name", "model_type", "model_id"}.issubset(self.columns["media"]):
            return
        transaction_types = sorted({value for values in self.OPERATIONAL_TYPES.values() for value in values})
        has_original = self.has_column("media", "original_name")
        media_names = ["media.file_name"] + (["media.original_name"] if has_original else [])
        if self.has_table("transactions"):
            select = ["media.model_id", "media.file_name", "parent.type AS parent_type"]
            if has_original:
                select.append("media.original_name")
            if self.has_column("transactions", "ref_no"):
                select.append("parent.ref_no AS parent_ref_no")
            sql = (
                "SELECT " + ", ".join(select) + " FROM media JOIN transactions AS parent ON parent.id=media.model_id "
                "WHERE media.business_id=%s AND media.model_type=%s AND parent.business_id=%s AND parent.type IN ("
                + self.placeholders(len(transaction_types)) + ")"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            for row in self._rows(sql, [self.business_id, self.TRANSACTION_MODEL, self.business_id, *transaction_types]):
                transaction_id = int(row["model_id"])
                candidate = {
                    "target_type": self.TRANSACTION_MODEL,
                    "target_id": transaction_id,
                    "media_model_type": self.TRANSACTION_MODEL,
                    "media_model_id": transaction_id,
                    "transaction_id": transaction_id,
                    "parent_type": str(row.get("parent_type") or ""),
                    "reference": str(row.get("parent_ref_no") or ""),
                    "attachment_source": "media.file_name",
                }
                for media_name in media_names:
                    name = row.get(media_name.split(".")[-1])
                    if str(name or "").strip():
                        self._add_file(str(name), dict(candidate, attachment_source=media_name))

        if not (self.has_table("account_transactions") and self.has_table("accounts")):
            return
        required = {"id", "account_id"}
        if not required.issubset(self.columns["account_transactions"]) or not {"id", "business_id"}.issubset(self.columns["accounts"]):
            return
        account_sql = (
            "SELECT media.model_type, media.model_id, media.file_name"
            + (", media.original_name" if has_original else "")
            + (", entry.transaction_id" if self.has_column("account_transactions", "transaction_id") else "")
            + " FROM media JOIN account_transactions AS entry ON entry.id=media.model_id "
            + "JOIN accounts AS account ON account.id=entry.account_id WHERE media.business_id=%s "
            + "AND media.model_type IN (" + self.placeholders(len(self.ACCOUNT_MODEL_TYPES))
            + ") AND account.business_id=%s"
        )
        for row in self._rows(account_sql, [self.business_id, *self.ACCOUNT_MODEL_TYPES, self.business_id]):
            entry_id = int(row["model_id"])
            candidate = {
                "target_type": str(row["model_type"]),
                "target_id": entry_id,
                "media_model_type": str(row["model_type"]),
                "media_model_id": entry_id,
                "transaction_id": int(row.get("transaction_id") or 0),
                "parent_type": "",
                "reference": "",
                "attachment_source": "media.file_name",
            }
            for name in [row.get("file_name"), row.get("original_name")]:
                if str(name or "").strip():
                    self._add_file(str(name), candidate)

    def _index_crosswalks(self, run_id: int | None, legacy_ids: set[str]) -> None:
        if (
            run_id is None
            or not legacy_ids
            or not self.has_table("accounting_legacy_import_crosswalks")
            or not self.has_table("transactions")
            or not {"id", "business_id", "type", "ref_no"}.issubset(self.columns["transactions"])
        ):
            return
        if not {"legacy_import_run_id", "business_id", "source_type", "legacy_record_id", "target_record_type", "target_record_id", "status"}.issubset(self.columns["accounting_legacy_import_crosswalks"]):
            return
        ids = sorted(legacy_ids)
        for offset in range(0, len(ids), 400):
            chunk = ids[offset : offset + 400]
            sql = (
                "SELECT crosswalk.source_type, crosswalk.legacy_record_id, crosswalk.target_record_id, parent.type AS parent_type, parent.ref_no "
                "FROM accounting_legacy_import_crosswalks AS crosswalk "
                "JOIN transactions AS parent ON parent.id=crosswalk.target_record_id "
                "WHERE crosswalk.legacy_import_run_id=%s AND crosswalk.business_id=%s AND crosswalk.target_record_type=%s "
                "AND crosswalk.target_record_id IS NOT NULL AND crosswalk.status IN ('matched','linked','posted','review') "
                "AND crosswalk.legacy_record_id IN (" + self.placeholders(len(chunk)) + ") "
                "AND parent.business_id=%s"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            for row in self._rows(sql, [run_id, self.business_id, self.TRANSACTION_MODEL, *chunk, self.business_id]):
                source_type = str(row.get("source_type") or "")
                if source_type not in self.OPERATIONAL_TYPES or row.get("parent_type") not in self.OPERATIONAL_TYPES[source_type]:
                    continue
                transaction_id = int(row["target_record_id"])
                candidate = {
                    "target_type": self.TRANSACTION_MODEL,
                    "target_id": transaction_id,
                    "media_model_type": self.TRANSACTION_MODEL,
                    "media_model_id": transaction_id,
                    "transaction_id": transaction_id,
                    "parent_type": str(row.get("parent_type") or ""),
                    "reference": str(row.get("ref_no") or ""),
                    "attachment_source": "accounting_legacy_import_crosswalks.legacy_record_id",
                }
                self.crosswalk_index[(source_type, str(row["legacy_record_id"]))].append(candidate)

    def _load_media_for_attachment_ids(self, attachments: list[Attachment]) -> None:
        if not self.has_table("media") or not {"business_id", "legacy_source_attachment_id", "id", "model_type", "model_id"}.issubset(self.columns["media"]):
            return
        ids = sorted({item.attachment_id for item in attachments})
        for offset in range(0, len(ids), 400):
            chunk = ids[offset : offset + 400]
            sql = (
                "SELECT id, legacy_source_attachment_id, model_type, model_id, file_name FROM media "
                "WHERE business_id=%s AND legacy_source_attachment_id IN (" + self.placeholders(len(chunk)) + ")"
            )
            for row in self._rows(sql, [self.business_id, *chunk]):
                self.media_by_attachment_id[str(row["legacy_source_attachment_id"])] = row

    def _index_source_media_owners(self) -> None:
        """Use an existing source-ID Media link as a fallback after validating its owner."""
        transaction_ids = sorted({
            int(row["model_id"])
            for row in self.media_by_attachment_id.values()
            if row["model_type"] == self.TRANSACTION_MODEL
        })
        account_ids = sorted({
            int(row["model_id"])
            for row in self.media_by_attachment_id.values()
            if row["model_type"] in self.ACCOUNT_MODEL_TYPES
        })
        transaction_owners: dict[int, dict[str, Any]] = {}
        for offset in range(0, len(transaction_ids), 400):
            chunk = transaction_ids[offset : offset + 400]
            if not chunk or not self.has_table("transactions"):
                continue
            select = ["id", "type"]
            if self.has_column("transactions", "ref_no"):
                select.append("ref_no")
            allowed_types = sorted({value for group in self.OPERATIONAL_TYPES.values() for value in group})
            sql = (
                "SELECT " + ", ".join(select) + " FROM transactions WHERE business_id=%s AND type IN ("
                + self.placeholders(len(allowed_types))
                + ") AND id IN (" + self.placeholders(len(chunk)) + ")"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND deleted_at IS NULL"
            for row in self._rows(sql, [self.business_id, *allowed_types, *chunk]):
                transaction_owners[int(row["id"])] = row

        account_owners: dict[int, dict[str, Any]] = {}
        if account_ids and self.has_table("account_transactions") and self.has_table("accounts"):
            for offset in range(0, len(account_ids), 400):
                chunk = account_ids[offset : offset + 400]
                if not chunk:
                    continue
                select = ["entry.id"]
                if self.has_column("account_transactions", "transaction_id"):
                    select.append("entry.transaction_id")
                sql = (
                    "SELECT " + ", ".join(select) + " FROM account_transactions AS entry "
                    "JOIN accounts AS account ON account.id=entry.account_id "
                    "WHERE account.business_id=%s AND entry.id IN (" + self.placeholders(len(chunk)) + ")"
                )
                if self.has_column("account_transactions", "deleted_at"):
                    sql += " AND entry.deleted_at IS NULL"
                if self.has_column("accounts", "deleted_at"):
                    sql += " AND account.deleted_at IS NULL"
                for row in self._rows(sql, [self.business_id, *chunk]):
                    account_owners[int(row["id"])] = row

        for attachment_id, media in self.media_by_attachment_id.items():
            model_type = str(media["model_type"])
            model_id = int(media["model_id"])
            if model_type == self.TRANSACTION_MODEL:
                owner = transaction_owners.get(model_id)
                if not owner:
                    continue
                candidate = {
                    "target_type": self.TRANSACTION_MODEL,
                    "target_id": model_id,
                    "media_model_type": self.TRANSACTION_MODEL,
                    "media_model_id": model_id,
                    "transaction_id": model_id,
                    "parent_type": str(owner.get("type") or ""),
                    "reference": str(owner.get("ref_no") or ""),
                    "attachment_source": "media.legacy_source_attachment_id",
                }
            elif model_type in self.ACCOUNT_MODEL_TYPES and model_id in account_owners:
                owner = account_owners[model_id]
                candidate = {
                    "target_type": model_type,
                    "target_id": model_id,
                    "media_model_type": model_type,
                    "media_model_id": model_id,
                    "transaction_id": int(owner.get("transaction_id") or 0),
                    "parent_type": "",
                    "reference": "",
                    "attachment_source": "media.legacy_source_attachment_id",
                }
            else:
                continue
            self.source_media_candidates[attachment_id] = candidate

    @staticmethod
    def _file_matches_number(number_candidate: dict[str, Any], file_candidates: list[dict[str, Any]]) -> bool:
        for file_candidate in file_candidates:
            if (
                number_candidate["target_type"] == file_candidate["target_type"]
                and str(number_candidate["target_id"]) == str(file_candidate["target_id"])
            ):
                return True
            if (
                file_candidate["target_type"] == "expense_lines"
                and number_candidate.get("parent_type") in {"expense", "expense_refund"}
                and int(file_candidate.get("transaction_id") or 0) == int(number_candidate.get("transaction_id") or 0)
            ):
                return True
        return False

    @staticmethod
    def _same_media_owner(left: dict[str, Any], right: dict[str, Any]) -> bool:
        return left["media_model_type"] == right["media_model_type"] and str(left["media_model_id"]) == str(right["media_model_id"])

    def _file_candidates(self, attachment: Attachment) -> list[dict[str, Any]]:
        candidates: list[dict[str, Any]] = []
        for key in database_filename_keys(attachment.original_name):
            candidates.extend(self.file_index.get(key, []))
        return self._unique(candidates)

    def _resolve(self, attachment: Attachment) -> tuple[dict[str, Any] | None, str, str]:
        file_candidates = self._file_candidates(attachment)
        legacy_id = attachment.transaction_id
        crosswalk_candidates: list[dict[str, Any]] = []
        if legacy_id:
            if attachment.source_type:
                crosswalk_candidates.extend(self.crosswalk_index.get((attachment.source_type, legacy_id), []))
            else:
                for (source_type, record_id), candidates in self.crosswalk_index.items():
                    if record_id == legacy_id:
                        crosswalk_candidates.extend(candidates)
        crosswalk_candidates = self._unique(crosswalk_candidates)
        if len(crosswalk_candidates) > 1:
            narrowed = [candidate for candidate in crosswalk_candidates if self._file_matches_number(candidate, file_candidates)]
            if len(narrowed) == 1:
                return narrowed[0], "legacy_record_id_file_name", "Crosswalk dan nama file menunjuk ke satu transaksi."
            return None, "legacy_record_id", "ID transaksi legacy memiliki lebih dari satu target crosswalk."
        if len(crosswalk_candidates) == 1:
            target = crosswalk_candidates[0]
            related = [candidate for candidate in file_candidates if self._same_media_owner(candidate, target)]
            if len(related) == 1:
                return related[0], "legacy_record_id", "Target crosswalk cocok; nama file juga ditemukan pada record terkait."
            return target, "legacy_record_id", "Target ditemukan lewat crosswalk legacy."

        if attachment.source_type and attachment.source_number:
            key = (attachment.source_type, normalize_database_number(attachment.source_number))
            number_candidates = self._unique(self.number_index.get(key, []))
            if len(number_candidates) > 1:
                narrowed = [candidate for candidate in number_candidates if self._file_matches_number(candidate, file_candidates)]
                if len(narrowed) == 1:
                    return narrowed[0], "source_number_file_name", "Noref memiliki beberapa kandidat; nama file memilih satu record."
                return None, "source_number", "Noref ditemukan pada beberapa record; nama file belum memilih satu record."
            if len(number_candidates) == 1:
                target = number_candidates[0]
                related = [candidate for candidate in file_candidates if self._same_media_owner(candidate, target)]
                if len(related) == 1:
                    return related[0], "source_number", "Noref cocok; record terkait juga memakai nama file ini."
                return target, "source_number", "Noref cocok dengan satu record operasional."

        existing_source_candidate = self.source_media_candidates.get(attachment.attachment_id)
        if existing_source_candidate:
            parent_type = existing_source_candidate.get("parent_type")
            valid_transaction_type = (
                attachment.source_type in self.OPERATIONAL_TYPES
                and parent_type in self.OPERATIONAL_TYPES[attachment.source_type]
            )
            valid_payment_type = (
                attachment.source_type in self.PAYMENT_TYPES
                and parent_type == self.PAYMENT_TYPES[attachment.source_type]
            )
            valid_account_type = (
                attachment.source_type in self.ACCOUNT_TYPES
                and existing_source_candidate["media_model_type"] in self.ACCOUNT_MODEL_TYPES
            )
            if not attachment.source_type or valid_transaction_type or valid_payment_type or valid_account_type:
                return existing_source_candidate, "source_attachment_id", "Media existing untuk ID sumber terhubung ke record aktif business ini."

        if len(file_candidates) == 1:
            return file_candidates[0], "file_name", "Tidak ada noref yang cocok; nama file unik pada database business ini."
        if len(file_candidates) > 1:
            return None, "file_name", "Nama file ditemukan pada beberapa record; perlu ditinjau."
        return None, "", "Record tidak ditemukan melalui crosswalk, noref, atau kolom attachment database."

    def match(self, attachments: list[Attachment]) -> None:
        for item in attachments:
            target, method, detail = self._resolve(item)
            item.database_match_method = method
            item.database_detail = detail
            existing_media = self.media_by_attachment_id.get(item.attachment_id)
            if target is None:
                item.database_status = (
                    "ambiguous"
                    if method and ("beberapa" in detail or "lebih dari satu" in detail)
                    else "not_found"
                )
                if existing_media:
                    item.existing_media_id = str(existing_media["id"])
                    item.database_status = "existing_media_review"
                    item.database_detail += " ID lampiran sudah memiliki Media di database."
                continue

            item.database_target_type = str(target["target_type"])
            item.database_target_id = str(target["target_id"])
            item.database_media_model_type = str(target["media_model_type"])
            item.database_media_model_id = str(target["media_model_id"])
            item.database_reference = str(target.get("reference") or item.source_number or "")
            item.database_attachment_source = str(target.get("attachment_source") or "")
            item.database_status = "matched"
            if existing_media:
                item.existing_media_id = str(existing_media["id"])
                same_target = (
                    str(existing_media["model_type"]) == item.database_media_model_type
                    and str(existing_media["model_id"]) == item.database_media_model_id
                )
                if same_target:
                    item.database_status = "already_linked"
                    item.database_detail += " Media untuk ID lampiran ini sudah tertaut ke target yang sama."
                else:
                    item.database_status = "existing_media_conflict"
                    item.database_detail = "ID lampiran sudah tertaut ke Media target lain; tautan tidak diubah."

    def load_matched_transaction_details(self, attachments: list[Attachment]) -> None:
        """Load the current reference and date for each uniquely matched media owner."""
        transaction_ids = sorted({
            int(item.database_media_model_id)
            for item in attachments
            if item.database_media_model_type == self.TRANSACTION_MODEL
            and item.database_media_model_id.isdigit()
        })
        account_transaction_ids = sorted({
            int(item.database_media_model_id)
            for item in attachments
            if item.database_media_model_type in self.ACCOUNT_MODEL_TYPES
            and item.database_media_model_id.isdigit()
        })

        transaction_details: dict[int, dict[str, Any]] = {}
        if transaction_ids and self.has_table("transactions") and {"id", "business_id"}.issubset(self.columns["transactions"]):
            allowed_types = sorted({value for values in self.OPERATIONAL_TYPES.values() for value in values})
            for offset in range(0, len(transaction_ids), 400):
                chunk = transaction_ids[offset : offset + 400]
                select = ["id"]
                if self.has_column("transactions", "ref_no"):
                    select.append("ref_no")
                if self.has_column("transactions", "transaction_date"):
                    select.append("transaction_date")
                type_filter = ""
                params: list[Any] = [self.business_id]
                if self.has_column("transactions", "type"):
                    type_filter = " AND type IN (" + self.placeholders(len(allowed_types)) + ")"
                    params.extend(allowed_types)
                params.extend(chunk)
                sql = (
                    "SELECT " + ", ".join(select) + " FROM transactions WHERE business_id=%s"
                    + type_filter + " AND id IN (" + self.placeholders(len(chunk)) + ")"
                )
                if self.has_column("transactions", "deleted_at"):
                    sql += " AND deleted_at IS NULL"
                for row in self._rows(sql, params):
                    transaction_details[int(row["id"])] = row

        account_details: dict[int, dict[str, Any]] = {}
        if (
            account_transaction_ids
            and self.has_table("account_transactions")
            and self.has_table("accounts")
            and {"id", "account_id"}.issubset(self.columns["account_transactions"])
            and {"id", "business_id"}.issubset(self.columns["accounts"])
        ):
            for offset in range(0, len(account_transaction_ids), 400):
                chunk = account_transaction_ids[offset : offset + 400]
                select = ["entry.id"]
                if self.has_column("account_transactions", "reff_no"):
                    select.append("entry.reff_no AS ref_no")
                if self.has_column("account_transactions", "operation_date"):
                    select.append("entry.operation_date AS transaction_date")
                sql = (
                    "SELECT " + ", ".join(select) + " FROM account_transactions AS entry "
                    "JOIN accounts AS account ON account.id=entry.account_id "
                    "WHERE account.business_id=%s AND entry.id IN (" + self.placeholders(len(chunk)) + ")"
                )
                params = [self.business_id, *chunk]
                if self.has_column("account_transactions", "deleted_at"):
                    sql += " AND entry.deleted_at IS NULL"
                if self.has_column("accounts", "deleted_at"):
                    sql += " AND account.deleted_at IS NULL"
                for row in self._rows(sql, params):
                    account_details[int(row["id"])] = row

        for item in attachments:
            model_id = item.database_media_model_id
            if not model_id.isdigit():
                continue
            if item.database_media_model_type == self.TRANSACTION_MODEL:
                row = transaction_details.get(int(model_id))
            elif item.database_media_model_type in self.ACCOUNT_MODEL_TYPES:
                row = account_details.get(int(model_id))
            else:
                row = None
            if row is None:
                continue
            item.database_reference = str(row.get("ref_no") or item.database_reference or "")
            item.database_transaction_date = row.get("transaction_date") or ""

    def load_all_business_attachments(self) -> list[OperationalAttachment]:
        """Inventory attachment references on current operations, independent of legacy files."""
        records: list[OperationalAttachment] = []
        seen: set[tuple[int, str, str]] = set()

        def transaction_columns(alias: str, reference_expression: str | None = None) -> list[str]:
            return [
                f"{alias}.id AS transaction_id",
                reference_expression
                or (f"{alias}.ref_no AS reference" if self.has_column("transactions", "ref_no") else "'' AS reference"),
                f"{alias}.transaction_date AS transaction_date" if self.has_column("transactions", "transaction_date") else "NULL AS transaction_date",
                f"{alias}.type AS transaction_type" if self.has_column("transactions", "type") else "'' AS transaction_type",
            ]

        def add_rows(
            rows: list[dict[str, Any]],
            default_location: str,
            type_suffix: str = "",
            account_operation: bool = False,
        ) -> None:
            account_labels = {
                ("credit", "deposit"): "Bank Deposit",
                ("debit", "fund_transfer"): "Bank Transfer",
                ("debit", "withdrawal"): "Bank Withdrawal",
            }
            for row in rows:
                raw_name = str(row.get("file_name") or "").strip()
                file_name = PurePosixPath(raw_name.replace("\\", "/")).name
                try:
                    transaction_id = int(row.get("transaction_id") or 0)
                except (TypeError, ValueError):
                    continue
                if not file_name or transaction_id < 1:
                    continue

                if account_operation:
                    transaction_type = account_labels.get(
                        (str(row.get("account_type") or "").casefold(), str(row.get("account_sub_type") or "").casefold()),
                        "Account Transaction",
                    )
                else:
                    raw_type = str(row.get("transaction_type") or "").strip()
                    transaction_type = raw_type.replace("_", " ").title() if raw_type else "Transaction"
                    transaction_type = transaction_type.removesuffix(" Payment")
                    transaction_type += type_suffix

                storage_location = str(row.get("storage_location") or default_location).casefold()
                if storage_location not in {"documents", "media"}:
                    storage_location = default_location
                key = (transaction_id, transaction_type.casefold(), file_name.casefold())
                if key in seen:
                    continue
                seen.add(key)
                records.append(
                    OperationalAttachment(
                        transaction_id=transaction_id,
                        reference=str(row.get("reference") or "").strip(),
                        transaction_date=row.get("transaction_date") or "",
                        transaction_type=transaction_type,
                        file_name=file_name,
                        storage_location=storage_location,
                    )
                )

        if self.has_table("transactions") and {"id", "business_id", "document"}.issubset(self.columns["transactions"]):
            sql = (
                "SELECT " + ", ".join([*transaction_columns("transactions"), "transactions.document AS file_name"])
                + " FROM transactions WHERE transactions.business_id=%s AND transactions.document IS NOT NULL "
                "AND TRIM(transactions.document)<>''"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND transactions.deleted_at IS NULL"
            add_rows(self._rows(sql, [self.business_id]), "documents")

        if (
            self.has_table("expense_lines")
            and self.has_table("transactions")
            and {"transaction_id", "document"}.issubset(self.columns["expense_lines"])
            and {"id", "business_id"}.issubset(self.columns["transactions"])
        ):
            sql = (
                "SELECT " + ", ".join([*transaction_columns("parent"), "line.document AS file_name"])
                + " FROM expense_lines AS line JOIN transactions AS parent ON parent.id=line.transaction_id "
                "WHERE parent.business_id=%s AND line.document IS NOT NULL AND TRIM(line.document)<>''"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            add_rows(self._rows(sql, [self.business_id]), "documents")

        if (
            self.has_table("transaction_payments")
            and self.has_table("transactions")
            and {"transaction_id", "document"}.issubset(self.columns["transaction_payments"])
            and {"id", "business_id"}.issubset(self.columns["transactions"])
        ):
            payment_reference = None
            if self.has_column("transaction_payments", "payment_ref_no"):
                payment_reference = (
                    "COALESCE(NULLIF(payment.payment_ref_no, ''), parent.ref_no) AS reference"
                    if self.has_column("transactions", "ref_no")
                    else "payment.payment_ref_no AS reference"
                )
            sql = (
                "SELECT " + ", ".join([*transaction_columns("parent", payment_reference), "payment.document AS file_name"])
                + " FROM transaction_payments AS payment JOIN transactions AS parent ON parent.id=payment.transaction_id "
                "WHERE parent.business_id=%s AND payment.document IS NOT NULL AND TRIM(payment.document)<>''"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            add_rows(self._rows(sql, [self.business_id]), "documents", " Payment")

        if (
            self.has_table("media")
            and {"business_id", "model_type", "model_id", "file_name"}.issubset(self.columns["media"])
            and self.has_table("transactions")
            and {"id", "business_id"}.issubset(self.columns["transactions"])
        ):
            model_types = [self.TRANSACTION_MODEL]
            select = ["parent.id AS transaction_id", "media.file_name"]
            select.extend(transaction_columns("parent")[1:])
            select.append("media.storage_location" if self.has_column("media", "storage_location") else "'media' AS storage_location")
            sql = (
                "SELECT " + ", ".join(select) + " FROM media JOIN transactions AS parent ON parent.id=media.model_id "
                "WHERE media.business_id=%s AND parent.business_id=%s AND media.model_type IN ("
                + self.placeholders(len(model_types)) + ") AND media.file_name IS NOT NULL AND TRIM(media.file_name)<>''"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            add_rows(self._rows(sql, [self.business_id, self.business_id, *model_types]), "media")

        if (
            self.has_table("media")
            and self.has_table("transaction_payments")
            and self.has_table("transactions")
            and {"business_id", "model_type", "model_id", "file_name"}.issubset(self.columns["media"])
            and {"id", "transaction_id"}.issubset(self.columns["transaction_payments"])
            and {"id", "business_id"}.issubset(self.columns["transactions"])
        ):
            model_types = [self.PAYMENT_MODEL]
            payment_reference = None
            if self.has_column("transaction_payments", "payment_ref_no"):
                payment_reference = (
                    "COALESCE(NULLIF(payment.payment_ref_no, ''), parent.ref_no) AS reference"
                    if self.has_column("transactions", "ref_no")
                    else "payment.payment_ref_no AS reference"
                )
            select = ["parent.id AS transaction_id", "media.file_name"]
            select.extend(transaction_columns("parent", payment_reference)[1:])
            select.append("media.storage_location" if self.has_column("media", "storage_location") else "'media' AS storage_location")
            sql = (
                "SELECT " + ", ".join(select) + " FROM media "
                "JOIN transaction_payments AS payment ON payment.id=media.model_id "
                "JOIN transactions AS parent ON parent.id=payment.transaction_id "
                "WHERE media.business_id=%s AND parent.business_id=%s AND media.model_type IN ("
                + self.placeholders(len(model_types)) + ") AND media.file_name IS NOT NULL AND TRIM(media.file_name)<>''"
            )
            if self.has_column("transactions", "deleted_at"):
                sql += " AND parent.deleted_at IS NULL"
            add_rows(self._rows(sql, [self.business_id, self.business_id, *model_types]), "media", " Payment")

        if (
            self.has_table("media")
            and self.has_table("account_transactions")
            and self.has_table("accounts")
            and {"business_id", "model_type", "model_id", "file_name"}.issubset(self.columns["media"])
            and {"id", "account_id"}.issubset(self.columns["account_transactions"])
            and {"id", "business_id"}.issubset(self.columns["accounts"])
        ):
            model_types = list(self.ACCOUNT_MODEL_TYPES)
            select = ["entry.id AS transaction_id", "media.file_name"]
            select.append("entry.reff_no AS reference" if self.has_column("account_transactions", "reff_no") else "'' AS reference")
            select.append("entry.operation_date AS transaction_date" if self.has_column("account_transactions", "operation_date") else "NULL AS transaction_date")
            select.append("entry.type AS account_type" if self.has_column("account_transactions", "type") else "'' AS account_type")
            select.append("entry.sub_type AS account_sub_type" if self.has_column("account_transactions", "sub_type") else "'' AS account_sub_type")
            select.append("media.storage_location" if self.has_column("media", "storage_location") else "'media' AS storage_location")
            sql = (
                "SELECT " + ", ".join(select) + " FROM media "
                "JOIN account_transactions AS entry ON entry.id=media.model_id "
                "JOIN accounts AS account ON account.id=entry.account_id "
                "WHERE media.business_id=%s AND account.business_id=%s AND media.model_type IN ("
                + self.placeholders(len(model_types)) + ") AND media.file_name IS NOT NULL AND TRIM(media.file_name)<>''"
            )
            if self.has_column("account_transactions", "deleted_at"):
                sql += " AND entry.deleted_at IS NULL"
            if self.has_column("accounts", "deleted_at"):
                sql += " AND account.deleted_at IS NULL"
            add_rows(self._rows(sql, [self.business_id, self.business_id, *model_types]), "media", account_operation=True)

        return records


class HostCheckedRedirectHandler(HTTPRedirectHandler):
    def __init__(self, allowed_hosts: set[str]) -> None:
        super().__init__()
        self.allowed_hosts = allowed_hosts

    def redirect_request(self, req: Request, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> Request | None:
        validated_host(newurl, self.allowed_hosts)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def write_report(path: Path, rows: list[Attachment], invalid: list[str], mode: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "mode", "manifest_row", "attachment_id", "transaction_id", "source_type", "source_number", "expected_filename",
                "output_filename", "collision_count", "source_host", "status", "detail", "database_status",
                "database_match_method", "database_target_type", "database_target_id", "database_media_model_type",
                "database_media_model_id", "database_reference", "database_transaction_date", "database_attachment_source", "existing_media_id",
                "database_link_action", "database_detail", "local_file_name",
            ],
        )
        writer.writeheader()
        for item in rows:
            writer.writerow(
                {
                    "mode": mode,
                    "manifest_row": item.row_number,
                    "attachment_id": item.attachment_id,
                    "transaction_id": item.transaction_id,
                    "source_type": item.source_type,
                    "source_number": item.source_number,
                    "expected_filename": item.original_name,
                    "output_filename": item.output_name,
                    "collision_count": item.collision_count,
                    "source_host": item.host,
                    "status": item.status,
                    "detail": item.detail,
                    "database_status": item.database_status,
                    "database_match_method": item.database_match_method,
                    "database_target_type": item.database_target_type,
                    "database_target_id": item.database_target_id,
                    "database_media_model_type": item.database_media_model_type,
                    "database_media_model_id": item.database_media_model_id,
                    "database_reference": item.database_reference,
                    "database_transaction_date": item.database_transaction_date,
                    "database_attachment_source": item.database_attachment_source,
                    "existing_media_id": item.existing_media_id,
                    "database_link_action": item.database_link_action,
                    "database_detail": item.database_detail,
                    "local_file_name": item.local_file_name,
                }
            )
        for message in invalid:
            writer.writerow({"mode": mode, "status": "invalid", "detail": message})


def resolve_public_base_url(override: str | None = None) -> str:
    config = load_database_config()
    base_url = (override or config.get("APP_URL") or "").strip().rstrip("/")
    if not base_url:
        raise ValueError("APP_URL tidak ditemukan di .env; berikan --public-base-url untuk membuat tautan unduh.")

    parsed = urlsplit(base_url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc or parsed.query or parsed.fragment:
        raise ValueError("URL dasar harus berupa URL http(s) tanpa query atau fragment.")

    # Laragon commonly serves this repository from www/<subfolder>/public while
    # its local .env still has APP_URL=http://localhost.
    if (
        override is None
        and parsed.hostname in {"localhost", "127.0.0.1"}
        and parsed.path in {"", "/"}
    ):
        document_root = next(
            (parent for parent in (PROJECT_ROOT, *PROJECT_ROOT.parents) if parent.name.casefold() in {"www", "htdocs", "public_html"}),
            None,
        )
        if document_root is not None:
            app_path = PROJECT_ROOT.relative_to(document_root).as_posix().strip("/")
            if app_path:
                parsed = parsed._replace(path=f"/{app_path}/public")

    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path.rstrip("/"), "", ""))


def excel_safe_text(value: Any) -> str:
    text = str(value or "")
    if text.startswith(("=", "+", "-", "@", "\t", "\r")):
        return "'" + text
    return text


def excel_date(value: Any) -> dt.date | str | None:
    if isinstance(value, dt.datetime):
        return value.date()
    if isinstance(value, dt.date):
        return value
    text = str(value or "").strip()
    if not text:
        return None
    try:
        return dt.datetime.fromisoformat(text.replace("Z", "+00:00")).date()
    except ValueError:
        return text


def existing_storage_filenames(destination: Path) -> set[str]:
    directories = [
        destination,
        PROJECT_ROOT / "public" / "uploads" / "documents",
        PROJECT_ROOT / "public" / "uploads" / "media",
        PROJECT_ROOT / "public" / "documents",
        PROJECT_ROOT / "public" / "media",
    ]
    existing_names: set[str] = set()
    visited: set[Path] = set()
    for directory in directories:
        resolved = directory.resolve()
        if resolved in visited or not resolved.is_dir():
            continue
        visited.add(resolved)
        for path in resolved.iterdir():
            if path.is_symlink() or not path.is_file():
                continue
            existing_names.add(path.name.casefold())
    return existing_names


def write_missing_attachments_xlsx(
    path: Path,
    attachments: list[OperationalAttachment],
    destination: Path,
    business_id: int,
    public_base_url: str,
) -> tuple[int, str]:
    try:
        from openpyxl import Workbook
        from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
    except ImportError as exc:
        raise ValueError(
            "Paket openpyxl belum terpasang. Jalankan: python -m pip install -r scripts/requirements-audit-lampiran.txt"
        ) from exc

    existing_names = existing_storage_filenames(destination)
    rows = [
        item
        for item in attachments
        if item.file_name.casefold() not in existing_names
    ]

    public_root = (PROJECT_ROOT / "public").resolve()
    path.parent.mkdir(parents=True, exist_ok=True)
    path = path.parent.resolve() / path.name
    try:
        path.relative_to(public_root)
    except ValueError as exc:
        raise ValueError("File Excel harus tetap berada di dalam folder public.") from exc

    workbook = Workbook()
    sheet = workbook.active
    sheet.title = "Lampiran hilang"
    sheet.sheet_view.showGridLines = False
    sheet.freeze_panes = "A5"
    sheet.merge_cells("A1:E1")
    sheet["A1"] = "DAFTAR LAMPIRAN HILANG"
    sheet["A1"].font = Font(name="Aptos Display", size=16, bold=True, color="FFFFFF")
    sheet["A1"].fill = PatternFill("solid", fgColor="176B3A")
    sheet["A1"].alignment = Alignment(vertical="center")
    sheet.row_dimensions[1].height = 30
    sheet.merge_cells("A2:E2")
    sheet["A2"] = f"Business ID: {business_id}   •   Dibuat: {dt.datetime.now():%d/%m/%Y %H:%M}   •   Jumlah: {len(rows)}"
    sheet["A2"].font = Font(name="Aptos", size=10, color="52645A", italic=True)
    sheet["A2"].alignment = Alignment(vertical="center")
    sheet.row_dimensions[2].height = 22

    headers = ["Noref", "ID Transaksi", "Tanggal", "Jenis Transaksi", "Lampiran"]
    header_row = 4
    for column, value in enumerate(headers, start=1):
        cell = sheet.cell(row=header_row, column=column, value=value)
        cell.fill = PatternFill("solid", fgColor="217346")
        cell.font = Font(name="Aptos", bold=True, color="FFFFFF")
        cell.alignment = Alignment(horizontal="left", vertical="center", wrap_text=True)
    sheet.row_dimensions[header_row].height = 26

    bottom_border = Side(style="hair", color="D9E5DC")
    for row_number, item in enumerate(rows, start=header_row + 1):
        values = [
            excel_safe_text(item.reference),
            item.transaction_id,
            excel_date(item.transaction_date),
            excel_safe_text(item.transaction_type),
            excel_safe_text(f"public/uploads/{item.storage_location}/{item.file_name}"),
        ]
        for column, value in enumerate(values, start=1):
            cell = sheet.cell(row=row_number, column=column, value=value)
            cell.font = Font(name="Aptos", size=10, color="26352B")
            cell.alignment = Alignment(vertical="top", wrap_text=(column in {1, 4, 5}))
            cell.border = Border(bottom=bottom_border)
            if row_number % 2:
                cell.fill = PatternFill("solid", fgColor="F2F7F3")
            if column == 2:
                cell.number_format = "0"
            elif column == 3:
                cell.number_format = "dd/mm/yyyy"
        sheet.row_dimensions[row_number].height = 30

    widths = {"A": 24, "B": 17, "C": 15, "D": 23, "E": 64}
    for column, width in widths.items():
        sheet.column_dimensions[column].width = width
    last_row = max(header_row, header_row + len(rows))
    sheet.auto_filter.ref = f"A{header_row}:E{last_row}"
    workbook.properties.title = "Daftar Lampiran Hilang"
    workbook.properties.subject = "Lampiran yang belum tersedia dan cocok ke transaksi operasional"
    workbook.save(path)

    relative_path = path.relative_to(public_root).as_posix()
    download_url = f"{public_base_url.rstrip('/')}/{quote(relative_path, safe='/')}"
    return len(rows), download_url


def unique_download_items(attachments: list[Attachment], destination: Path) -> list[Attachment]:
    unique: dict[str, Attachment] = {}
    for item in attachments:
        unique.setdefault(item.output_name.casefold(), item)

    timestamped_files: dict[str, list[str]] = defaultdict(list)
    if destination.is_dir():
        for path in destination.iterdir():
            if path.is_symlink() or not path.is_file():
                continue
            match = TIMESTAMPED_FILENAME_PATTERN.match(path.name)
            if match:
                timestamped_files[match.group(1).casefold()].append(path.name)

    for item in unique.values():
        target = destination / item.output_name
        if target.is_file():
            item.status = "already_present"
            item.local_file_name = item.output_name
            item.detail = "File dengan nama tujuan sudah ada; tidak ditimpa."
        elif item.collision_count == 1 and len(timestamped_files[item.original_name.casefold()]) == 1:
            existing_name = timestamped_files[item.original_name.casefold()][0]
            item.status = "already_present"
            item.local_file_name = existing_name
            item.detail = f"File tersedia dengan prefix timestamp: {existing_name}; tidak diunduh ulang."
        elif item.collision_count == 1 and len(timestamped_files[item.original_name.casefold()]) > 1:
            item.status = "review_existing_ambiguous"
            item.detail = "Ada beberapa file ber-prefix timestamp dengan nama dasar yang sama; tidak dipilih otomatis."
        elif item.collision_count > 1 and timestamped_files[item.original_name.casefold()]:
            item.status = "review_existing_collision"
            item.detail = "Nama ini bertabrakan pada manifest dan memiliki file ber-prefix timestamp; perlu pencocokan record/noref sebelum dipakai."
        else:
            item.status = "would_download"
            item.detail = "File tidak ditemukan di folder tujuan."
    return list(unique.values())


def apply_media_links(attachments: list[Attachment], destination: Path, business_id: int) -> tuple[int, int, int, int]:
    """Create or repair Media rows only for exact, unambiguous matches with a local file."""
    expected_documents = (PROJECT_ROOT / DEFAULT_DESTINATION).resolve()
    if destination.resolve() != expected_documents:
        raise ValueError("--apply-links hanya dapat memakai folder public/uploads/documents agar link Media tidak rusak.")

    connection = connect_database(read_only=False)
    cursor = connection.cursor(dictionary=True, buffered=True)
    try:
        cursor.execute(
            "SELECT COLUMN_NAME AS column_name FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'media'"
        )
        columns = {row["column_name"] for row in cursor.fetchall()}
        required = {"business_id", "file_name", "storage_location", "legacy_source_attachment_id", "model_type", "model_id"}
        if not required.issubset(columns):
            raise ValueError("Skema media belum mendukung link lampiran legacy; jalankan migrasi Accounting terlebih dahulu.")

        linked = updated = skipped = conflicts = 0
        for item in attachments:
            if item.database_status not in {"matched", "already_linked"}:
                item.database_link_action = "skipped_unmatched"
                skipped += 1
                continue
            if not item.source_id_present:
                item.database_link_action = "skipped_missing_source_id"
                item.database_detail += " ID attachment sumber kosong; Media tidak dibuat."
                skipped += 1
                continue

            local_name = item.local_file_name or item.output_name
            file_path = destination / local_name
            if file_path.is_symlink() or not file_path.is_file():
                item.database_link_action = "skipped_file_missing"
                item.database_detail += " File lokal belum tersedia; Media tidak dibuat."
                skipped += 1
                continue

            cursor.execute(
                "SELECT id, model_type, model_id, file_name, storage_location FROM media "
                "WHERE business_id=%s AND legacy_source_attachment_id=%s LIMIT 1",
                (business_id, item.attachment_id),
            )
            current = cursor.fetchone()
            if current:
                same_target = (
                    str(current["model_type"]) == item.database_media_model_type
                    and str(current["model_id"]) == item.database_media_model_id
                )
                if same_target:
                    item.existing_media_id = str(current["id"])
                    item.database_status = "already_linked"
                    changes = []
                    values = []
                    if str(current.get("file_name") or "") != local_name:
                        changes.append("file_name=%s")
                        values.append(local_name)
                    if str(current.get("storage_location") or "") != "documents":
                        changes.append("storage_location=%s")
                        values.append("documents")
                    if changes:
                        if "updated_at" in columns:
                            changes.append("updated_at=%s")
                            values.append(dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
                        values.append(int(current["id"]))
                        cursor.execute("UPDATE media SET " + ", ".join(changes) + " WHERE id=%s", values)
                        connection.commit()
                        item.database_link_action = "repointed"
                        item.database_detail += " Media diperbarui ke file lokal yang tersedia."
                        updated += 1
                    else:
                        item.database_link_action = "already_linked"
                else:
                    item.database_status = "existing_media_conflict"
                    item.database_link_action = "skipped_conflict"
                    item.database_detail = "ID lampiran sudah tertaut ke Media target lain; tautan tidak diubah."
                    conflicts += 1
                continue

            now = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            values: dict[str, Any] = {
                "business_id": business_id,
                "file_name": local_name,
                "storage_location": "documents",
                "legacy_source_attachment_id": item.attachment_id,
                "model_type": item.database_media_model_type,
                "model_id": int(item.database_media_model_id),
            }
            optional_values = {
                "original_name": item.original_name,
                "model_media_type": "legacy_document",
                "uploaded_by": None,
                "created_at": now,
                "updated_at": now,
            }
            values.update({key: value for key, value in optional_values.items() if key in columns})
            field_list = list(values)
            sql = (
                "INSERT INTO media (" + ", ".join(f"`{field}`" for field in field_list) + ") VALUES ("
                + ", ".join(["%s"] * len(field_list)) + ")"
            )
            try:
                cursor.execute(sql, [values[field] for field in field_list])
                connection.commit()
                item.existing_media_id = str(cursor.lastrowid or "")
                item.database_link_action = "linked"
                item.database_status = "linked"
                item.database_detail += " Media dibuat dan diarahkan ke file lokal."
                linked += 1
            except Exception as exc:
                connection.rollback()
                cursor.execute(
                    "SELECT id, model_type, model_id FROM media "
                    "WHERE business_id=%s AND legacy_source_attachment_id=%s LIMIT 1",
                    (business_id, item.attachment_id),
                )
                current = cursor.fetchone()
                if current and str(current["model_type"]) == item.database_media_model_type and str(current["model_id"]) == item.database_media_model_id:
                    item.existing_media_id = str(current["id"])
                    item.database_status = "already_linked"
                    item.database_link_action = "already_linked"
                    continue
                item.database_link_action = "failed"
                item.database_detail = f"Media tidak dapat disimpan: {str(exc).splitlines()[0][:200]}"
                conflicts += 1
        return linked, updated, skipped, conflicts
    finally:
        cursor.close()
        connection.close()


def download_one(
    item: Attachment,
    destination: Path,
    opener: Any,
    timeout: float,
    retries: int,
    max_bytes: int,
    delay: float,
    budget: DownloadBudget,
    stop_event: threading.Event,
) -> tuple[str, str]:
    target = destination / item.output_name
    for attempt in range(retries + 1):
        temp_path: Path | None = None
        reserved_bytes = 0
        try:
            if stop_event.is_set():
                return "not_completed", "Batch dihentikan sebelum file ini diproses."
            if budget.exhausted:
                return "capacity_limit", "Cadangan ruang disk tercapai; file belum diunduh."
            if target.is_file():
                return "already_present", "File sudah ada; tidak ditimpa."
            if delay:
                time.sleep(delay)
            if stop_event.is_set():
                return "not_completed", "Batch dihentikan sebelum request dikirim."
            request = Request(item.url, headers={"User-Agent": "UltimateERP-Attachment-Audit/1.0"})
            with opener.open(request, timeout=timeout) as response:
                validated_host(response.geturl(), {item.host})
                content_type = response.headers.get_content_type().lower()
                if content_type in {"text/html", "application/json", "text/json"}:
                    raise ValueError(f"server mengembalikan {content_type}, bukan file lampiran")
                content_length = response.headers.get("Content-Length")
                if content_length and int(content_length) > max_bytes:
                    raise ValueError(f"ukuran file melebihi batas {max_bytes} byte")
                destination.mkdir(parents=True, exist_ok=True)
                with tempfile.NamedTemporaryFile(
                    mode="wb", dir=destination, prefix=f".{target.name}.", suffix=".part", delete=False
                ) as temp_file:
                    temp_path = Path(temp_file.name)
                    total = 0
                    while True:
                        chunk = response.read(CHUNK_SIZE)
                        if not chunk:
                            break
                        if stop_event.is_set():
                            raise DownloadStopped("Batch dihentikan oleh pengguna.")
                        total += len(chunk)
                        if total > max_bytes:
                            raise ValueError(f"ukuran file melebihi batas {max_bytes} byte")
                        budget.reserve(len(chunk))
                        reserved_bytes += len(chunk)
                        temp_file.write(chunk)
                if total == 0:
                    raise ValueError("file yang diterima kosong")
                if target.exists():
                    temp_path.unlink(missing_ok=True)
                    budget.release(reserved_bytes)
                    reserved_bytes = 0
                    return "already_present", "File dibuat proses lain; file lama dipertahankan."
                os.replace(temp_path, target)
                return "downloaded", f"{total} byte"
        except DownloadStopped as exc:
            if temp_path:
                temp_path.unlink(missing_ok=True)
            budget.release(reserved_bytes)
            return "not_completed", str(exc)
        except CapacityLimitError as exc:
            if temp_path:
                temp_path.unlink(missing_ok=True)
            budget.release(reserved_bytes)
            return "capacity_limit", str(exc)
        except (HTTPError, URLError, OSError, ValueError, TimeoutError) as exc:
            if temp_path:
                temp_path.unlink(missing_ok=True)
            budget.release(reserved_bytes)
            if isinstance(exc, HTTPError):
                message = f"HTTP {exc.code}: {exc.reason}"
            elif isinstance(exc, URLError):
                message = f"network error: {exc.reason}"
            else:
                message = str(exc).splitlines()[0][:240]
            if attempt >= retries:
                return "failed", message
            time.sleep(min(2 ** attempt, 8))
    return "failed", "Gagal tanpa pesan error."


def export_business_attachment_inventory(args: argparse.Namespace) -> int:
    destination = resolve_path(args.destination)
    try:
        public_base_url = resolve_public_base_url(args.public_base_url)
        connection = connect_database(read_only=True)
        matcher: OperationalDatabaseMatcher | None = None
        try:
            matcher = OperationalDatabaseMatcher(
                connection,
                args.business_id,
                None,
                [],
                build_indexes=False,
            )
            attachments = matcher.load_all_business_attachments()
        finally:
            if matcher is not None:
                matcher.close()
            connection.close()

        timestamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S-%f")
        xlsx_path = PROJECT_ROOT / "public" / "exports" / f"lampiran-missing-business-{args.business_id}-{timestamp}.xlsx"
        missing_count, download_url = write_missing_attachments_xlsx(
            xlsx_path,
            attachments,
            destination,
            args.business_id,
            public_base_url,
        )
        print(f"Business ID: {args.business_id}")
        print(f"Lampiran transaksi dari database: {len(attachments)}")
        print(f"Lampiran hilang dari documents/media: {missing_count}")
        print(f"File Excel: {xlsx_path}")
        print(f"Link unduh: {download_url}")
        return 0
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    except Exception as exc:
        print(f"Export Excel gagal: {str(exc).splitlines()[0][:240]}", file=sys.stderr)
        return 2


def main() -> int:
    args = parse_args()
    if args.export_missing_xlsx:
        return export_business_attachment_inventory(args)

    manifest = resolve_path(args.manifest)
    destination = resolve_path(args.destination)
    mode = "download" if args.download else "dry-run"
    if args.apply_links:
        mode = "download+apply-links" if args.download else "apply-links"
    report = resolve_path(args.report_csv) if args.report_csv else resolve_path(
        "storage/app/lampiran-download-report.csv" if args.download else "storage/app/lampiran-dry-run-report.csv"
    )
    allowed_hosts = DEFAULT_ALLOWED_HOSTS | {host.lower().strip().rstrip(".") for host in args.allow_host}

    try:
        source_rows = load_rows(manifest)
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        return 2

    attachments, invalid = extract_attachments(source_rows, allowed_hosts)
    plan_output_names(attachments)
    if args.business_id is not None:
        try:
            connection = connect_database(read_only=True)
            matcher = OperationalDatabaseMatcher(connection, args.business_id, args.run_id, attachments)
            try:
                matcher.match(attachments)
                matcher.load_matched_transaction_details(attachments)
            finally:
                matcher.close()
                connection.close()
        except ValueError as exc:
            print(str(exc), file=sys.stderr)
            return 2
        except Exception as exc:
            print(f"Pencocokan database gagal: {str(exc).splitlines()[0][:240]}", file=sys.stderr)
            return 2
    items = unique_download_items(attachments, destination)
    status_by_name = {item.output_name.casefold(): (item.status, item.detail) for item in items}
    for attachment in attachments:
        attachment.status, attachment.detail = status_by_name[attachment.output_name.casefold()]
    collisions = {item.original_name.casefold() for item in attachments if item.collision_count > 1}
    present_count = sum(item.status == "already_present" for item in items)
    missing_count = sum(item.status == "would_download" for item in items)
    rows_with_collisions = sum(item.collision_count > 1 for item in attachments)
    ambiguous_existing_count = sum(
        item.status in {"review_existing_ambiguous", "review_existing_collision"} for item in items
    )
    existing_files = sum(path.is_file() for path in destination.iterdir()) if destination.is_dir() else 0

    print(f"Mode: {mode.upper()}")
    print(f"Manifest rows: {len(source_rows)}")
    print(f"Valid attachment rows: {len(attachments)}")
    print(f"Unique destination files: {len(items)}")
    print(f"Files currently in destination: {existing_files}")
    print(f"Already present (exact or unambiguous timestamp prefix): {present_count}")
    print(f"Ambiguous timestamp-prefix matches: {ambiguous_existing_count}")
    print(f"Missing from download destination / planned downloads: {missing_count}")
    print(f"Distinct filenames with source collisions: {len(collisions)}")
    print(f"Manifest rows in collision groups: {rows_with_collisions}")
    print(f"Invalid rows or URLs: {len(invalid)}")
    if args.business_id is None:
        print("Database matching: dilewati; gunakan --business-id <ID> untuk mencocokkan data operasional.")
    else:
        db_counts: dict[str, int] = defaultdict(int)
        for attachment in attachments:
            db_counts[attachment.database_status] += 1
        print(f"Database business ID: {args.business_id}; run ID: {args.run_id or 'tidak dipakai'}")
        print("Database match status: " + ", ".join(f"{status}={count}" for status, count in sorted(db_counts.items())))
        print("`already_linked` berarti baris Media sudah ada di database; status file lokal dihitung terpisah.")
        if args.apply_links:
            print("Media akan ditulis hanya untuk target unik dengan file lokal yang tersedia.")
    if collisions:
        print("Collision policy: colliding files use legacy-<id>__<filename>, matching the Laravel Media importer.")
    if not args.download:
        if args.apply_links:
            print("No network requests; --apply-links may write Media rows for matched files already present locally.")
        else:
            print("Dry-run makes no network requests, writes no Media rows, and does not change files in the destination.")
    else:
        print(f"Download limits: {args.workers} workers, {args.timeout:g}s timeout, max {args.max_mib_per_file:g} MiB/file.")

    if args.download and missing_count:
        disk_check_path = destination
        while not disk_check_path.exists() and disk_check_path != disk_check_path.parent:
            disk_check_path = disk_check_path.parent
        free_bytes = shutil.disk_usage(disk_check_path).free
        reserve_bytes = 1024 * 1024 * 1024
        budget = DownloadBudget(max(0, free_bytes - reserve_bytes))
        print(f"Free space: {free_bytes / (1024 ** 3):.2f} GiB; downloads keep 1 GiB free.")
        if budget.max_bytes <= 0:
            print("Unduhan tidak dimulai: ruang kosong tidak cukup untuk menjaga cadangan 1 GiB.", file=sys.stderr)
            for item in items:
                if item.status == "would_download":
                    item.status = "capacity_limit"
                    item.detail = "Ruang kosong di bawah cadangan 1 GiB."
            status_by_name = {item.output_name.casefold(): (item.status, item.detail) for item in items}
            for attachment in attachments:
                attachment.status, attachment.detail = status_by_name[attachment.output_name.casefold()]
            write_report(report, attachments, invalid, mode)
            print(f"Report CSV: {report}")
            return 1
        opener = build_opener(HostCheckedRedirectHandler(allowed_hosts))
        pending_all = [item for item in items if item.status == "would_download"]
        pending = pending_all[: args.limit] if args.limit else pending_all
        deferred = pending_all[len(pending) :]
        for item in deferred:
            item.status = "not_attempted_limit"
            item.detail = "Belum dicoba karena batas --limit pada batch ini."
        if args.limit:
            print(f"Batch unduhan: {len(pending)} dari {len(pending_all)} file hilang; sisanya dicatat untuk batch berikutnya.", flush=True)
        successes = 0
        failures = 0
        stop_event = threading.Event()
        executor = ThreadPoolExecutor(max_workers=args.workers)
        future_map = {
            executor.submit(
                download_one,
                item,
                destination,
                opener,
                args.timeout,
                args.retries,
                int(args.max_mib_per_file * 1024 * 1024),
                args.delay,
                budget,
                stop_event,
            ): item
            for item in pending
        }
        try:
            for completed, future in enumerate(as_completed(future_map), start=1):
                item = future_map[future]
                try:
                    item.status, item.detail = future.result()
                    if item.status in {"downloaded", "already_present"}:
                        item.local_file_name = item.output_name
                except Exception as exc:  # Keep the batch reportable if one worker fails unexpectedly.
                    item.status, item.detail = "failed", str(exc).splitlines()[0][:240]
                if item.status == "downloaded":
                    successes += 1
                elif item.status == "failed":
                    failures += 1
                if completed % 100 == 0 or completed == len(pending):
                    print(f"Progress: {completed}/{len(pending)}; downloaded {successes}; failed {failures}", flush=True)
        except KeyboardInterrupt:
            stop_event.set()
            for future in future_map:
                future.cancel()
            executor.shutdown(wait=True, cancel_futures=True)
            for future, item in future_map.items():
                if future.cancelled():
                    item.status, item.detail = "not_completed", "Dibatalkan karena batch dihentikan."
                else:
                    try:
                        item.status, item.detail = future.result()
                        if item.status in {"downloaded", "already_present"}:
                            item.local_file_name = item.output_name
                    except Exception as exc:
                        item.status, item.detail = "failed", str(exc).splitlines()[0][:240]
            print("Download dihentikan; menulis laporan hasil parsial.", file=sys.stderr)
            result_by_url = {item.url: (item.status, item.detail) for item in items}
            for attachment in attachments:
                attachment.status, attachment.detail = result_by_url[attachment.url]
            write_report(report, attachments, invalid, mode)
            return 130
        else:
            executor.shutdown(wait=True)

    if args.download:
        result_by_name = {
            item.output_name.casefold(): (item.status, item.detail, item.local_file_name) for item in items
        }
        for attachment in attachments:
            attachment.status, attachment.detail, attachment.local_file_name = result_by_name[attachment.output_name.casefold()]

    if args.apply_links:
        try:
            linked, updated, skipped, conflicts = apply_media_links(attachments, destination, args.business_id)
            print(f"Database link result: {linked} dibuat, {updated} diperbarui, {skipped} dilewati, {conflicts} konflik/gagal.")
        except ValueError as exc:
            print(str(exc), file=sys.stderr)
            write_report(report, attachments, invalid, mode)
            print(f"Report CSV: {report}")
            return 2
        except Exception as exc:
            print(f"Penulisan Media gagal: {str(exc).splitlines()[0][:240]}", file=sys.stderr)
            write_report(report, attachments, invalid, mode)
            print(f"Report CSV: {report}")
            return 2

    write_report(report, attachments, invalid, mode)
    print(f"Report CSV: {report}")
    if args.download:
        downloaded = sum(item.status == "downloaded" for item in items)
        failed = sum(item.status == "failed" for item in items)
        capacity_limited = sum(item.status == "capacity_limit" for item in items)
        print(f"Download result: {downloaded} downloaded, {failed} failed, {capacity_limited} stopped by disk reserve, {present_count} already present.")
        return 1 if failed or capacity_limited or invalid or ambiguous_existing_count else 0
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
