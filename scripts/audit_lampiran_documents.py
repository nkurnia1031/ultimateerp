#!/usr/bin/env python3
"""Audit and optionally restore files referenced by import-legacy/json/lampiran.json.

Dry-run is the default and never makes network requests. Pass --download explicitly
to fetch files that are not present in the destination directory.
"""

from __future__ import annotations

import argparse
import csv
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
from urllib.parse import unquote, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_MANIFEST = Path("import-legacy/json/lampiran.json")
DEFAULT_DESTINATION = Path("public/uploads/documents")
DEFAULT_ALLOWED_HOSTS = {"jurnal-quickbook.jurnal.id"}
CHUNK_SIZE = 1024 * 1024
INVALID_FILENAME_CHARS = re.compile(r'[<>:"/\\|?*\x00-\x1f]')
WINDOWS_RESERVED_NAMES = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{i}" for i in range(1, 10)),
    *(f"LPT{i}" for i in range(1, 10)),
}


@dataclass
class Attachment:
    row_number: int
    attachment_id: str
    transaction_id: str
    url: str
    host: str
    original_name: str
    output_name: str = ""
    collision_count: int = 1
    status: str = ""
    detail: str = ""


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
        description="Periksa lampiran di folder documents dan unduh file yang hilang."
    )
    parser.add_argument("--manifest", default=str(DEFAULT_MANIFEST), help="Lokasi lampiran.json")
    parser.add_argument("--destination", default=str(DEFAULT_DESTINATION), help="Folder tujuan file")
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
        attachments.append(
            Attachment(
                row_number=row_number,
                attachment_id=str(row.get("id") or row_number),
                transaction_id=str(row.get("transaction_id") or ""),
                url=url,
                host=host,
                original_name=filename,
            )
        )
    return attachments, invalid


def plan_output_names(attachments: list[Attachment]) -> list[Attachment]:
    by_url: dict[str, list[Attachment]] = defaultdict(list)
    for attachment in attachments:
        by_url[attachment.url].append(attachment)

    by_name: dict[str, list[str]] = defaultdict(list)
    url_name: dict[str, str] = {}
    for url, records in by_url.items():
        name = records[0].original_name
        url_name[url] = name
        key = name.casefold()
        if url not in by_name[key]:
            by_name[key].append(url)

    reserved_names = set(by_name)
    allocated_names: set[str] = set()
    output_by_url: dict[str, tuple[str, int]] = {}
    for key, urls in by_name.items():
        collision_count = len(urls)
        for index, url in enumerate(urls):
            original_name = url_name[url]
            if index == 0:
                output_name = original_name
            else:
                path = Path(original_name)
                suffix = path.suffix
                stem = path.name[: -len(suffix)] if suffix else path.name
                attachment_id = re.sub(r"[^A-Za-z0-9_-]", "_", by_url[url][0].attachment_id)[:40]
                marker = f"__lampiran_{attachment_id}"
                max_stem_length = max(1, 180 - len(marker) - len(suffix))
                output_name = f"{stem[:max_stem_length]}{marker}{suffix}"
                counter = 2
                while output_name.casefold() in reserved_names or output_name.casefold() in allocated_names:
                    marker_with_counter = f"{marker}_{counter}"
                    max_stem_length = max(1, 180 - len(marker_with_counter) - len(suffix))
                    output_name = f"{stem[:max_stem_length]}{marker_with_counter}{suffix}"
                    counter += 1
            allocated_names.add(output_name.casefold())
            output_by_url[url] = (output_name, collision_count)

    for attachment in attachments:
        attachment.output_name, attachment.collision_count = output_by_url[attachment.url]
    return attachments


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
                "mode", "manifest_row", "attachment_id", "transaction_id", "expected_filename",
                "output_filename", "collision_count", "source_host", "status", "detail",
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
                    "expected_filename": item.original_name,
                    "output_filename": item.output_name,
                    "collision_count": item.collision_count,
                    "source_host": item.host,
                    "status": item.status,
                    "detail": item.detail,
                }
            )
        for message in invalid:
            writer.writerow({"mode": mode, "status": "invalid", "detail": message})


def unique_download_items(attachments: list[Attachment], destination: Path) -> list[Attachment]:
    unique: dict[str, Attachment] = {}
    for item in attachments:
        unique.setdefault(item.url, item)
    for item in unique.values():
        target = destination / item.output_name
        if target.is_file():
            item.status = "already_present"
            item.detail = "File dengan nama tujuan sudah ada; tidak ditimpa."
        else:
            item.status = "would_download"
            item.detail = "File tidak ditemukan di folder tujuan."
    return list(unique.values())


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


def main() -> int:
    args = parse_args()
    manifest = resolve_path(args.manifest)
    destination = resolve_path(args.destination)
    mode = "download" if args.download else "dry-run"
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
    items = unique_download_items(attachments, destination)
    status_by_url = {item.url: (item.status, item.detail) for item in items}
    for attachment in attachments:
        attachment.status, attachment.detail = status_by_url[attachment.url]
    collisions = {item.original_name.casefold() for item in attachments if item.collision_count > 1}
    present_count = sum(item.status == "already_present" for item in items)
    missing_count = sum(item.status == "would_download" for item in items)
    rows_with_collisions = sum(item.collision_count > 1 for item in attachments)
    existing_files = sum(path.is_file() for path in destination.iterdir()) if destination.is_dir() else 0

    print(f"Mode: {mode.upper()}")
    print(f"Manifest rows: {len(source_rows)}")
    print(f"Valid attachment rows: {len(attachments)}")
    print(f"Unique source files: {len(items)}")
    print(f"Files currently in destination: {existing_files}")
    print(f"Already present (by planned filename): {present_count}")
    print(f"Missing / planned downloads: {missing_count}")
    print(f"Distinct filenames with source collisions: {len(collisions)}")
    print(f"Manifest rows in collision groups: {rows_with_collisions}")
    print(f"Invalid rows or URLs: {len(invalid)}")
    if collisions:
        print("Collision policy: first source keeps the original name; additional files get __lampiran_<id>.")
    if not args.download:
        print("Dry-run makes no network requests and does not create or change files in the destination.")
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
            status_by_url = {item.url: (item.status, item.detail) for item in items}
            for attachment in attachments:
                attachment.status, attachment.detail = status_by_url[attachment.url]
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
        result_by_url = {item.url: (item.status, item.detail) for item in items}
        for attachment in attachments:
            attachment.status, attachment.detail = result_by_url[attachment.url]

    write_report(report, attachments, invalid, mode)
    print(f"Report CSV: {report}")
    if args.download:
        downloaded = sum(item.status == "downloaded" for item in items)
        failed = sum(item.status == "failed" for item in items)
        capacity_limited = sum(item.status == "capacity_limit" for item in items)
        print(f"Download result: {downloaded} downloaded, {failed} failed, {capacity_limited} stopped by disk reserve, {present_count} already present.")
        return 1 if failed or capacity_limited or invalid else 0
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
