#!/usr/bin/env python3
"""Fail when a public snapshot contains secrets or private deployment data."""

from __future__ import annotations

import argparse
import ipaddress
import os
import pathlib
import re
import subprocess
import sys
from dataclasses import dataclass


ROOT = pathlib.Path(__file__).resolve().parents[2]
ALLOWED_PRIVATE_NETWORKS = (
    ipaddress.ip_network("192.168.88.0/24"),
    ipaddress.ip_network("172.30.10.0/24"),
    ipaddress.ip_network("172.30.20.0/24"),
)
IPV4_RE = re.compile(r"(?<![0-9])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9])")
IPV6_RE = re.compile(r"(?<![0-9A-Fa-f:])(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?![0-9A-Fa-f:])")
MAC_RE = re.compile(r"(?<![0-9A-Fa-f])(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}(?![0-9A-Fa-f])")
EMAIL_RE = re.compile(r"[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})")
ALLOWED_EMAIL_DOMAINS = {"example.com", "example.test", "laravel.com"}
ALLOWED_SYSTEMD_INSTANCES = {
    "movie-model-tunnel@deepseek.service",
    "movie-model-tunnel@qwen.service",
}
PERSONAL_PATH_RE = re.compile(re.escape("/" + "Users" + "/") + r"[A-Za-z0-9._-]+")
ENV_CREDENTIAL_RE = re.compile(
    r"^\s*(?:export\s+)?[A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|APP_KEY)\s*=\s*([^\s#]+)"
)
CREDENTIAL_VALUE_REFERENCES = (
    "$",
    "config(",
    "env(",
    "getenv(",
    "os.environ",
    "read_secret(",
)

SECRET_PATTERNS = {
    "private key": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----"),
    "AWS access key": re.compile(r"\b(?:AKIA|ASIA)[A-Z0-9]{16}\b"),
    "GitHub token": re.compile(r"\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b"),
    "OpenAI-style key": re.compile(r"\bsk-[A-Za-z0-9_-]{20,}\b"),
    "Slack token": re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{20,}\b"),
    "Google API key": re.compile(r"\bAIza[0-9A-Za-z_-]{30,}\b"),
    "Hugging Face token": re.compile(r"\bhf_[A-Za-z0-9]{20,}\b"),
    "Civitai token": re.compile(r"\bcivitai_[A-Za-z0-9_-]{20,}\b", re.IGNORECASE),
    "JWT": re.compile(r"\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b"),
    "SSH public key": re.compile(r"\b(?:ssh-(?:rsa|ed25519)|ecdsa-sha2-nistp\d+)\s+[A-Za-z0-9+/]{32,}={0,3}"),
    "PGP key block": re.compile(r"-----BEGIN PGP (?:PRIVATE|PUBLIC) KEY BLOCK-----"),
    "Google service account": re.compile(r'"type"\s*:\s*"service_account"'),
    "Laravel application key": re.compile(r"\bAPP_KEY=base64:[A-Za-z0-9+/]{40,}={0,2}"),
}

FORBIDDEN_PATH_PARTS = {
    "auth.json",
    ".env",
    "known_hosts",
    "id_rsa",
    "id_ed25519",
}
FORBIDDEN_SUFFIXES = {".key", ".pem", ".p12", ".pfx", ".token"}
FORBIDDEN_DATABASE_PATH_PARTS = {"pgdata", "postgres-data", "postgresql-data"}
FORBIDDEN_DATABASE_SUFFIXES = {
    ".backup",
    ".db",
    ".dump",
    ".sqlite",
    ".sqlite3",
    ".sql",
}
DEPENDENCY_METADATA_FILES = {"composer.lock", "package-lock.json"}
GENERATED_DEPENDENCY_PREFIXES = (
    "app/public/fonts/filament/",
    "app/public/js/filament/",
)


@dataclass(frozen=True)
class Finding:
    source: str
    line: int
    category: str


def command(*args: str) -> bytes:
    return subprocess.run(
        args,
        cwd=ROOT,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    ).stdout


def tree_files() -> list[tuple[str, bytes]]:
    raw = command(
        "git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"
    )
    files: list[tuple[str, bytes]] = []
    for value in raw.split(b"\0"):
        if not value:
            continue
        relative = value.decode("utf-8", errors="strict")
        path = ROOT / relative
        if path.is_symlink():
            files.append((relative, os.readlink(path).encode("utf-8", errors="strict")))
        elif path.is_file():
            files.append((relative, path.read_bytes()))
    return files


def history_files() -> list[tuple[str, bytes]]:
    commits = command("git", "rev-list", "--all").decode().splitlines()
    object_paths: dict[str, str] = {}
    files: list[tuple[str, bytes]] = []
    for commit in commits:
        metadata = command(
            "git", "show", "-s", "--format=%H%n%an%n%ae%n%cn%n%ce%n%B", commit
        )
        files.append((f"history-metadata:{commit}", metadata))
        listing = command("git", "ls-tree", "-r", "--full-tree", commit)
        for line in listing.decode("utf-8", errors="replace").splitlines():
            metadata, separator, path = line.partition("\t")
            if not separator:
                continue
            parts = metadata.split()
            if len(parts) == 3 and parts[1] == "blob":
                object_paths.setdefault(parts[2], path)

    objects = command(
        "git", "cat-file", "--batch-all-objects",
        "--batch-check=%(objectname) %(objecttype)",
    ).decode().splitlines()
    for entry in objects:
        object_id, separator, object_type = entry.partition(" ")
        if not separator:
            continue
        if object_type == "blob":
            path = object_paths.get(object_id, "<unreachable-object>")
            files.append((f"history:{object_id}:{path}", command("git", "cat-file", "blob", object_id)))
        elif object_type == "tree":
            listing = command("git", "ls-tree", "-r", "--full-tree", object_id)
            for line in listing.decode("utf-8", errors="replace").splitlines():
                _, separator, path = line.partition("\t")
                if separator:
                    files.append((f"history:{object_id}:{path}", b""))
        elif object_type in {"commit", "tag"}:
            files.append((f"history-metadata-object:{object_id}", command("git", "cat-file", object_type, object_id)))
    return files


def path_findings(source: str) -> list[Finding]:
    path = logical_path(source)
    pure_path = pathlib.PurePosixPath(path)
    name = pure_path.name
    suffix = pure_path.suffix.lower()
    findings: list[Finding] = []
    if name in FORBIDDEN_PATH_PARTS and not name.endswith(".example"):
        findings.append(Finding(source, 0, "forbidden secret/identity filename"))
    if suffix in FORBIDDEN_SUFFIXES:
        findings.append(Finding(source, 0, "forbidden secret filename suffix"))
    if suffix in FORBIDDEN_DATABASE_SUFFIXES:
        findings.append(Finding(source, 0, "forbidden database export filename suffix"))
    if any(part.lower() in FORBIDDEN_DATABASE_PATH_PARTS for part in pure_path.parts):
        findings.append(Finding(source, 0, "forbidden database runtime path"))
    return findings


def logical_path(source: str) -> str:
    return source.split(":", 2)[-1] if source.startswith("history:") else source


def text_findings(
    source: str,
    data: bytes,
    denied_identifiers: tuple[str, ...] = (),
) -> list[Finding]:
    findings = path_findings(source)
    text = data.decode("utf-8", errors="replace")
    folded_text = text.casefold()
    path = logical_path(source)
    name = pathlib.PurePosixPath(path).name
    dependency_metadata = name in DEPENDENCY_METADATA_FILES or any(
        path.startswith(prefix) for prefix in GENERATED_DEPENDENCY_PREFIXES
    )
    scan_emails = not dependency_metadata
    for identifier in denied_identifiers:
        if identifier.casefold() in path.casefold():
            findings.append(Finding(source, 0, "operator-provided private identifier in path"))
        offset = folded_text.find(identifier.casefold())
        if offset >= 0:
            findings.append(Finding(source, text.count("\n", 0, offset) + 1, "operator-provided private identifier"))
    for line_number, line in enumerate(text.splitlines(), start=1):
        if PERSONAL_PATH_RE.search(line):
            findings.append(Finding(source, line_number, "personal filesystem path"))
        for category, pattern in SECRET_PATTERNS.items():
            if pattern.search(line):
                findings.append(Finding(source, line_number, category))
        credential = ENV_CREDENTIAL_RE.search(line)
        if credential:
            credential_value = credential.group(1).strip('"\'')
            if credential_value not in {
                "", "changeme", "change-me", "example", "placeholder", "null",
            } and not credential_value.startswith(CREDENTIAL_VALUE_REFERENCES):
                findings.append(Finding(source, line_number, "nonempty credential assignment"))
        if scan_emails:
            for match in EMAIL_RE.finditer(line):
                domain = match.group(1).lower()
                full_match = match.group(0)
                is_systemd_instance = full_match in ALLOWED_SYSTEMD_INSTANCES
                if domain not in ALLOWED_EMAIL_DOMAINS and not is_systemd_instance:
                    findings.append(Finding(source, line_number, "non-example email"))
        for raw_ip in (() if dependency_metadata else IPV4_RE.findall(line)):
            try:
                address = ipaddress.ip_address(raw_ip)
            except ValueError:
                continue
            if address.is_unspecified or address.is_loopback:
                continue
            if any(address in network for network in ALLOWED_PRIVATE_NETWORKS):
                continue
            category = "public IPv4 address" if address.is_global else "unapproved private/reserved IPv4 address"
            findings.append(Finding(source, line_number, category))
        for raw_ip in (() if dependency_metadata else IPV6_RE.findall(line)):
            if not any(character.isdigit() for character in raw_ip):
                continue
            try:
                address = ipaddress.ip_address(raw_ip)
            except ValueError:
                continue
            if address.is_global:
                findings.append(Finding(source, line_number, "public IPv6 address"))
        if MAC_RE.search(line):
            findings.append(Finding(source, line_number, "hardware MAC address"))
    return findings


def denied_identifiers(path: pathlib.Path | None) -> tuple[str, ...]:
    if path is None:
        return ()
    values = []
    for line in path.read_text(encoding="utf-8").splitlines():
        value = line.strip()
        if value and not value.startswith("#"):
            if len(value) < 2:
                raise ValueError("private identifiers must contain at least two characters")
            values.append(value)
    return tuple(values)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--tree", action="store_true", help="scan tracked and untracked public files")
    parser.add_argument("--history", action="store_true", help="scan every Git object, including unreachable objects")
    parser.add_argument(
        "--deny-identifiers-file",
        type=pathlib.Path,
        help="untracked newline-delimited private identifiers; values are never printed",
    )
    args = parser.parse_args()
    if not args.tree and not args.history:
        parser.error("select --tree and/or --history")

    sources: list[tuple[str, bytes]] = []
    if args.tree:
        sources.extend(tree_files())
    if args.history:
        sources.extend(history_files())

    private_identifiers = denied_identifiers(args.deny_identifiers_file)
    findings: set[Finding] = set()
    for source, data in sources:
        findings.update(text_findings(source, data, private_identifiers))

    if findings:
        for finding in sorted(findings, key=lambda item: (item.source, item.line, item.category)):
            location = f"{finding.source}:{finding.line}" if finding.line else finding.source
            print(f"{location}: {finding.category}", file=sys.stderr)
        print(f"public release scan failed with {len(findings)} finding(s)", file=sys.stderr)
        return 1

    scopes = ", ".join(scope for scope, enabled in (("tree", args.tree), ("history", args.history)) if enabled)
    print(f"public release scan passed: {scopes}; {len(sources)} file/blob source(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
