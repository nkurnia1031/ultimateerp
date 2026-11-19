#!/usr/bin/env python3
"""Read-only filesystem audit for suspected webshells and persistence files.

Run this script directly on the server. With --store-in-app, it saves the report
to the Laravel private audit directory so the Security Audit page can read it
without an upload. The Laravel application does not launch this process.

Examples:
  python3 scripts/security_audit.py --json --output=/tmp/security-audit.json
  python3 scripts/security_audit.py --root=/srv/ultimateerp --since="7 days ago" --json --output=/tmp/security-audit.json
  python3 scripts/security_audit.py --store-in-app --business-id=2

Exit codes:
  0 = no findings
  1 = findings were reported (the report is still written)
  2 = invalid arguments or audit failure
"""

from __future__ import annotations

import argparse
import getpass
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
import uuid
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any


EXECUTABLE_EXTENSIONS = {
    "php", "php3", "php4", "php5", "php7", "php74", "php8", "phtml", "phar",
    "cgi", "pl", "py", "sh", "bash", "asp", "aspx", "jsp",
}
PERSISTENCE_FILES = {".htaccess", ".user.ini", "web.config", "php.ini"}
KNOWN_PUBLIC_ENTRYPOINTS = {"public/index.php", "public/install/index.php"}
SKIPPED_DIRECTORIES = {
    ".git", "node_modules", "graphify-out", "storage/framework/cache",
    "storage/framework/sessions", "storage/framework/views", "storage/app/security-audit",
}
SEVERITY_ORDER = {"critical": 0, "high": 1, "medium": 2, "low": 3}
MAX_REPORT_BYTES = 15_728_640
MAX_FINDINGS = 50_000

SIGNAL_PATTERNS = {
    "obfuscated_payload": re.compile(
        r"\b(?:eval|assert)\s*\(\s*(?:base64_decode|gzinflate|gzdecode|gzuncompress|str_rot13|hex2bin)\s*\(", re.I
    ),
    "direct_code_execution": re.compile(r"\b(?:eval|assert)\s*\(", re.I),
    "encoded_payload": re.compile(r"\b(?:base64_decode|gzinflate|gzdecode|gzuncompress|str_rot13|hex2bin)\s*\(", re.I),
    "command_execution": re.compile(r"\b(?:shell_exec|passthru|proc_open|popen|system|exec)\s*\(", re.I),
    "request_input": re.compile(r"\$_(?:GET|POST|REQUEST|COOKIE|FILES)\b", re.I),
    "file_write": re.compile(r"\b(?:file_put_contents|fwrite|move_uploaded_file|chmod)\s*\(", re.I),
    "remote_include": re.compile(r"\b(?:include|include_once|require|require_once)\s*\(?\s*['\"]https?://", re.I),
    "preg_replace_e": re.compile(r"preg_replace\s*\(\s*['\"][^'\"]*/e['\"]", re.I),
    "auto_prepend": re.compile(r"\bauto_(?:prepend|append)_file\b", re.I),
}


class SecurityAudit:
    def __init__(self, root: str, since: int, max_read_bytes: int, include_vendor: bool) -> None:
        try:
            resolved_root = Path(root).expanduser().resolve(strict=True)
        except OSError as exc:
            raise ValueError(f"Root directory does not exist: {root}") from exc
        if not resolved_root.is_dir():
            raise ValueError(f"Root directory does not exist: {root}")

        self.root = resolved_root
        self.since = since
        self.max_read_bytes = max(65536, max_read_bytes)
        self.include_vendor = include_vendor
        self.findings: dict[str, dict[str, Any]] = {}
        self.git_changes: list[str] = []
        self.skipped: list[str] = []
        self.files_scanned = 0

    def run(self) -> dict[str, Any]:
        self.collect_git_changes()
        self.scan_filesystem()
        self.scan_special_files()
        findings = sorted(
            self.findings.values(),
            key=lambda finding: (SEVERITY_ORDER.get(finding["severity"], 9), finding["mtime"] or 0),
        )

        return {
            "generated_at": datetime.now().astimezone().isoformat(timespec="seconds"),
            "root": str(self.root),
            "since": datetime.fromtimestamp(self.since).astimezone().isoformat(timespec="seconds"),
            "files_scanned": self.files_scanned,
            "git_changes": self.git_changes,
            "findings": findings,
            "skipped": self.skipped,
        }

    def scan_filesystem(self) -> None:
        def on_walk_error(error: OSError) -> None:
            self.skipped.append(f"A directory could not be traversed: {error}")

        for current, directories, files in os.walk(self.root, topdown=True, followlinks=False, onerror=on_walk_error):
            current_path = Path(current)
            retained: list[str] = []
            for directory in directories:
                absolute_directory = current_path / directory
                relative_directory = self.relative_path(absolute_directory)
                if self.should_skip(relative_directory):
                    continue
                if absolute_directory.is_symlink():
                    self.skipped.append(f"Symbolic-link directory was not followed: {relative_directory}")
                    continue
                retained.append(directory)
            directories[:] = retained

            for file_name in files:
                absolute_path = current_path / file_name
                relative_path = self.relative_path(absolute_path)
                if self.should_skip(relative_path):
                    continue
                if absolute_path.is_symlink():
                    self.skipped.append(f"Symbolic-link file was not read: {relative_path}")
                    continue
                try:
                    metadata = os.stat(absolute_path, follow_symlinks=False)
                except OSError as exc:
                    self.skipped.append(f"Unable to inspect {relative_path}: {exc}")
                    continue
                if not stat.S_ISREG(metadata.st_mode):
                    continue

                self.files_scanned += 1
                self.inspect_file(absolute_path, relative_path, int(metadata.st_mtime), int(metadata.st_size))

    def inspect_file(self, absolute_path: Path, relative_path: str, mtime: int, size: int) -> None:
        extension = Path(relative_path).suffix.lower().lstrip(".")
        lower_path = relative_path.lower().replace("\\", "/")
        is_public_upload = lower_path.startswith("public/uploads/")
        is_storage = lower_path.startswith("storage/app/")
        is_storage_log = lower_path.startswith("storage/logs/")
        is_public = lower_path.startswith("public/")
        is_writable_area = is_public_upload or is_storage or (lower_path.startswith("storage/") and not is_storage_log)
        is_executable = extension in EXECUTABLE_EXTENSIONS
        is_persistence_file = absolute_path.name.lower() in PERSISTENCE_FILES
        recent = mtime >= self.since
        reasons: list[str] = []
        signals: list[str] = []
        severity: str | None = None

        if is_public_upload and is_executable:
            severity = "critical"
            reasons.append("executable file inside public/uploads")
        elif is_storage and is_executable:
            severity = "high"
            reasons.append("executable file inside storage/app")
        elif is_public and is_executable and lower_path not in KNOWN_PUBLIC_ENTRYPOINTS:
            severity = "high"
            reasons.append("executable file inside public web root")

        if is_persistence_file and is_writable_area:
            severity = self.highest_severity(severity, "high")
            reasons.append("web-server/PHP persistence file inside writable area")

        should_read = is_executable or is_persistence_file or is_writable_area or self.looks_like_code(extension)
        sample = self.read_sample(absolute_path, size) if should_read else None
        scan_signals = is_executable or is_persistence_file or is_writable_area
        if scan_signals and sample:
            try:
                sample_text = sample.decode("latin-1")
            except UnicodeDecodeError:
                sample_text = ""
            if sample_text and "\x00" not in sample_text:
                signals, signal_reasons = self.detect_signals(sample_text, extension, is_persistence_file)
                reasons.extend(signal_reasons)

                has_obfuscation = "obfuscated_payload" in signals
                has_direct_code_execution = "direct_code_execution" in signals
                has_remote_include = "remote_include" in signals
                has_input = "request_input" in signals
                has_command = "command_execution" in signals
                has_write = "file_write" in signals

                if has_obfuscation or has_remote_include or "preg_replace_e" in signals:
                    severity = self.highest_severity(severity, "critical" if is_writable_area else "high")
                elif has_direct_code_execution:
                    severity = self.highest_severity(severity, "critical" if is_writable_area else "high")
                elif (has_input and (has_command or has_write)) or (has_command and is_writable_area):
                    severity = self.highest_severity(severity, "critical" if is_writable_area else "high")
                elif signals and is_writable_area:
                    severity = self.highest_severity(severity, "medium")

        if recent and is_writable_area and (is_executable or is_persistence_file or signals):
            reasons.append("modified after the audit time window")
            severity = self.highest_severity(severity, "medium")

        if severity is None:
            return

        self.add_finding({
            "severity": severity,
            "kind": "upload_webshell_candidate" if is_public_upload and is_executable else "suspicious_file",
            "path": relative_path,
            "mtime": mtime,
            "modified_at": datetime.fromtimestamp(mtime).astimezone().isoformat(timespec="seconds"),
            "recent": recent,
            "size": size,
            "sha256": self.sha256_file(absolute_path),
            "extension": extension,
            "signals": list(dict.fromkeys(signals)),
            "reasons": list(dict.fromkeys(reasons)),
        })

    def detect_signals(self, sample: str, extension: str, is_persistence_file: bool) -> tuple[list[str], list[str]]:
        signals: list[str] = []
        reasons: list[str] = []
        for signal, pattern in SIGNAL_PATTERNS.items():
            if pattern.search(sample):
                signals.append(signal)
                reasons.append(f"content signal: {signal}")

        if is_persistence_file and re.search(r"(?:AddHandler|SetHandler|php_value|RewriteRule|auto_prepend_file)", sample, re.I):
            signals.append("server_handler_override")
            reasons.append("handler or PHP override directive detected")

        if extension != "php" and re.search(r"<\?(?:php|=)?", sample, re.I):
            signals.append("php_code_in_non_php_file")
            reasons.append("PHP opening tag in a non-PHP extension")

        return signals, reasons

    def scan_special_files(self) -> None:
        public_env = self.root / "public" / ".env"
        if public_env.is_file():
            self.add_finding(self.file_finding(public_env, "critical", "exposed_environment_file", [
                "public/.env is directly reachable if the web server serves dot-files",
            ]))

        root_env = self.root / ".env"
        try:
            root_env_mode = stat.S_IMODE(root_env.stat().st_mode) if root_env.is_file() else 0
        except OSError:
            root_env_mode = 0
        if root_env.is_file() and root_env_mode & 0o077:
            self.add_finding(self.file_finding(root_env, "high", "permissive_environment_permissions", [
                ".env is readable or writable by group/other users",
            ]))

        for relative_directory in ("public/uploads", "storage/app", "bootstrap/cache"):
            directory = self.root / relative_directory
            if not directory.is_dir():
                continue
            try:
                metadata = directory.stat()
            except OSError:
                continue
            if stat.S_IMODE(metadata.st_mode) & 0o002:
                self.add_finding({
                    "severity": "high",
                    "kind": "world_writable_directory",
                    "path": relative_directory,
                    "mtime": int(metadata.st_mtime),
                    "modified_at": datetime.fromtimestamp(metadata.st_mtime).astimezone().isoformat(timespec="seconds"),
                    "recent": False,
                    "size": None,
                    "sha256": None,
                    "extension": None,
                    "signals": [],
                    "reasons": ["directory is writable by all users"],
                })

    def collect_git_changes(self) -> None:
        if not (self.root / ".git").is_dir():
            self.skipped.append("No .git directory; tracked-file comparison was unavailable.")
            return
        try:
            result = subprocess.run(
                ["git", "-C", str(self.root), "status", "--porcelain=v1", "--untracked-files=all"],
                capture_output=True,
                text=True,
                check=False,
                timeout=20,
            )
        except (OSError, subprocess.TimeoutExpired):
            self.skipped.append("git status could not be executed.")
            return
        if result.returncode != 0:
            self.skipped.append("git status could not be executed.")
            return
        self.git_changes = [line for line in result.stdout.splitlines() if line]

    def read_sample(self, path: Path, size: int) -> bytes | None:
        if not os.access(path, os.R_OK):
            return None
        length = min(size, self.max_read_bytes)
        if length <= 0:
            return None
        try:
            with path.open("rb") as handle:
                return handle.read(length)
        except OSError:
            return None

    def file_finding(self, absolute_path: Path, severity: str, kind: str, reasons: list[str]) -> dict[str, Any]:
        metadata = absolute_path.stat()
        mtime = int(metadata.st_mtime)
        return {
            "severity": severity,
            "kind": kind,
            "path": self.relative_path(absolute_path),
            "mtime": mtime,
            "modified_at": datetime.fromtimestamp(mtime).astimezone().isoformat(timespec="seconds"),
            "recent": mtime >= self.since,
            "size": int(metadata.st_size),
            "sha256": self.sha256_file(absolute_path),
            "extension": absolute_path.suffix.lower().lstrip("."),
            "signals": [],
            "reasons": reasons,
        }

    def add_finding(self, finding: dict[str, Any]) -> None:
        self.findings[f"{finding['path']}|{finding['kind']}"] = finding

    def should_skip(self, relative_path: str) -> bool:
        normalized = relative_path.lower().replace("\\", "/")
        if normalized == "scripts/security_audit.py":
            return True
        if any(normalized.startswith(directory + "/") for directory in SKIPPED_DIRECTORIES):
            return True
        return not self.include_vendor and (normalized == "vendor" or normalized.startswith("vendor/"))

    def relative_path(self, path: Path) -> str:
        return path.relative_to(self.root).as_posix()

    @staticmethod
    def looks_like_code(extension: str) -> bool:
        return extension in EXECUTABLE_EXTENSIONS | {"js", "json", "xml", "ini", "conf", "txt"}

    @staticmethod
    def highest_severity(current: str | None, candidate: str) -> str:
        return candidate if current is None or SEVERITY_ORDER.get(candidate, 0) < SEVERITY_ORDER.get(current, 0) else current

    @staticmethod
    def sha256_file(path: Path) -> str | None:
        try:
            digest = hashlib.sha256()
            with path.open("rb") as handle:
                for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                    digest.update(chunk)
            return digest.hexdigest()
        except OSError:
            return None


def parse_since(value: str) -> int:
    value = value.strip() or "30 days ago"
    if value.isdigit():
        timestamp = int(value)
        if timestamp > 0:
            return timestamp
        raise ValueError(f"Invalid --since value: {value}")

    relative = re.fullmatch(r"(\d+)\s+(days?|hours?|minutes?)\s+ago", value, re.I)
    if relative:
        amount = int(relative.group(1))
        unit = relative.group(2).lower().rstrip("s")
        duration = {
            "day": timedelta(days=amount),
            "hour": timedelta(hours=amount),
            "minute": timedelta(minutes=amount),
        }[unit]
        return int((datetime.now().astimezone() - duration).timestamp())

    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        try:
            parsed_date = date.fromisoformat(value)
        except ValueError as exc:
            raise ValueError(f"Invalid --since value: {value}") from exc
        parsed = datetime.combine(parsed_date, datetime.min.time()).astimezone()
    if parsed.tzinfo is None:
        parsed = parsed.astimezone()
    timestamp = int(parsed.timestamp())
    if timestamp <= 0:
        raise ValueError(f"Invalid --since value: {value}")
    return timestamp


def text_report(report: dict[str, Any]) -> str:
    counts = {severity: 0 for severity in ("critical", "high", "medium", "low")}
    for finding in report["findings"]:
        counts[finding["severity"]] = counts.get(finding["severity"], 0) + 1

    lines = [
        "Security audit (read-only)",
        f"Generated: {report['generated_at']}",
        f"Root: {report['root']}",
        f"Recent window: since {report['since']}",
        f"Files scanned: {report['files_scanned']}",
        f"Findings: critical={counts['critical']} high={counts['high']} medium={counts['medium']} low={counts['low']}",
    ]
    if report["git_changes"]:
        lines.extend(["", "Git working tree changes (review independently):"])
        lines.extend(f"  {change}" for change in report["git_changes"])

    for finding in report["findings"]:
        lines.extend([
            "",
            f"[{finding['severity'].upper()}] {finding['kind']}: {finding['path']} ({finding['modified_at']}, {finding['size'] or 0} bytes)",
        ])
        if finding["sha256"]:
            lines.append(f"  sha256: {finding['sha256']}")
        if finding["signals"]:
            lines.append(f"  signals: {', '.join(finding['signals'])}")
        lines.extend(f"  reason: {reason}" for reason in finding["reasons"])

    if report["skipped"]:
        lines.extend(["", "Skipped/limitations:"])
        lines.extend(f"  - {item}" for item in report["skipped"])
    lines.extend([
        "",
        "Do not delete evidence before preserving the report, hashes, server logs, and a database/filesystem backup.",
    ])
    return "\n".join(lines) + "\n"


def store_report_in_application(root: Path, report: dict[str, Any], business_id: int, operator: str | None) -> Path:
    """Write a private wrapper understood by SecurityAuditService::listReports()."""
    root = root.resolve()
    if not (root / "artisan").is_file() or not (root / "app" / "Services" / "SecurityAuditService.php").is_file():
        raise ValueError(f"Application root does not look like UltimateERP: {root}")
    findings = report.get("findings")
    if not isinstance(findings, list) or len(findings) > MAX_FINDINGS:
        raise ValueError(f"Audit findings must be a list with at most {MAX_FINDINGS:,} entries.")

    report_directory = root / "storage" / "app" / "security-audit" / "reports"
    report_directory.mkdir(mode=0o770, parents=True, exist_ok=True)
    os.chmod(report_directory, 0o770)
    if not report_directory.is_dir():
        raise ValueError("Security audit report path is not a directory.")

    report_id = str(uuid.uuid4())
    now = datetime.now().astimezone().isoformat(timespec="seconds")
    wrapper = {
        "id": report_id,
        "business_id": business_id,
        "source": "python_cli",
        "original_name": f"security-audit-{datetime.now().astimezone().strftime('%Y%m%d-%H%M%S')}.json",
        "imported_at": now,
        "imported_by": {"id": None, "name": (operator or getpass.getuser() or "Python CLI")[:150]},
        "cleanup_runs": [],
        "report": report,
    }
    destination = report_directory / f"{report_id}.json"
    temporary = report_directory / f".{report_id}.tmp"
    payload = json.dumps(wrapper, ensure_ascii=False, indent=2) + "\n"
    if len(payload.encode("utf-8")) > MAX_REPORT_BYTES:
        raise ValueError("Audit report exceeds the 15 MB storage limit.")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o660)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, destination)
        os.chmod(destination, 0o660)
    finally:
        temporary.unlink(missing_ok=True)

    return destination


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Read-only filesystem audit for suspected webshells and persistence files.")
    parser.add_argument("--root", default=str(Path(__file__).resolve().parent.parent), help="Application root; defaults to this script's project root.")
    parser.add_argument("--since", default="30 days ago", help='Recent-file window, e.g. "30 days ago" or "2026-08-01".')
    parser.add_argument("--include-vendor", action="store_true", help="Also scan vendor/ (slower, recommended for an incident).")
    parser.add_argument("--max-read", type=int, default=2097152, help="Maximum bytes read from one file (default: 2097152).")
    parser.add_argument("--json", action="store_true", help="Emit machine-readable JSON instead of a text report.")
    parser.add_argument("--output", help="Write report to a file; otherwise print it to stdout.")
    parser.add_argument("--store-in-app", action="store_true", help="Save directly to the private Laravel Security Audit report history.")
    parser.add_argument("--business-id", type=int, help="Business that can view the report; required with --store-in-app.")
    parser.add_argument("--operator", help="Name recorded as the SSH/CLI operator; defaults to the current OS user.")
    args = parser.parse_args()
    if args.store_in_app and not args.business_id:
        parser.error("--store-in-app requires --business-id")
    if args.business_id is not None and args.business_id < 1:
        parser.error("--business-id must be greater than zero")
    if args.business_id is not None and not args.store_in_app:
        parser.error("--business-id can only be used with --store-in-app")
    return args


def main() -> int:
    args = parse_args()
    try:
        audit = SecurityAudit(args.root, parse_since(args.since), args.max_read, args.include_vendor)
        report = audit.run()
        output = json.dumps(report, ensure_ascii=False, indent=2) + "\n" if args.json else text_report(report)
        if args.store_in_app:
            stored_path = store_report_in_application(audit.root, report, args.business_id, args.operator)
            print(f"Report registered for Business #{args.business_id}: {stored_path}")
        if args.output:
            output_path = Path(args.output).expanduser()
            output_path.write_text(output, encoding="utf-8")
            print(f"Report written to {output_path}")
        elif not args.store_in_app:
            sys.stdout.write(output)
        return 1 if report["findings"] or report["git_changes"] else 0
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print(f"Audit failed: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
