#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${RUNTIME_DIR}"

python3 - <<'PY' "${ROOT_DIR}" "${RUNTIME_DIR}"
import datetime
import hashlib
import json
import math
import pathlib
import re
import sys

repo_root = pathlib.Path(sys.argv[1]).resolve()
runtime_dir = pathlib.Path(sys.argv[2]).resolve()
timestamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
report_path = runtime_dir / f"secrets-audit-{timestamp}.json"
latest_path = runtime_dir / "secrets-audit-latest.json"

IGNORE_DIRS = {
    ".git",
    "node_modules",
    "target",
    "dist",
    "build",
    "coverage",
    "vendor",
    ".next",
    ".svelte-kit",
}

# Files containing intentional test/example keys that are not real credentials.
# These are audited false positives — document the reason when adding entries.
KNOWN_FALSE_POSITIVE_FILES = {
    # Mock translate API test infrastructure: hardcoded RSA key is used only
    # by the local mock server for signing test responses, never deployed.
    "tests/infra/mock-api/src/config.rs",
    # AWS SDK signing implementation: uses the well-known AWS documentation
    # example key (prefix AKIA + 16 uppercase chars) as a test vector for
    # HMAC-SHA256 signing — not a real credential.
    "tests/infra/tools/sign-plugin/src/category_b.rs",
    "tests/infra/tools/sign-plugin/tests/algorithm_tests.rs",
}

SCAN_ROOTS = [
    (".factory", "factory"),
    ("tests/modules/wpmmcc-ats", "tests/modules/wpmmcc-ats"),
    ("tests/infra", "tests/infra"),
    ("task", "task"),
]

HISTORICAL_MARKERS = [
    "status: historical",
    "historical",
    "archived",
    "legacy",
    "superseded",
]

HIGH_CONF_PATTERNS = [
    ("private-key-block", re.compile(r"-----BEGIN(?: RSA| OPENSSH| EC| DSA| PGP)? PRIVATE KEY-----")),
    ("github-token", re.compile(r"\bgh[pousr]_[A-Za-z0-9]{20,}\b")),
    ("github-pat", re.compile(r"\bgithub_pat_[A-Za-z0-9_]{20,}\b")),
    ("aws-access-key-id", re.compile(r"\bAKIA[0-9A-Z]{16}\b")),
    ("slack-token", re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{20,}\b")),
]

ASSIGN_RE = re.compile(
    r"""(?x)
    (?:
      ["'](?P<key1>api[_-]?key|token|secret|password|passwd|client[_-]?secret|access[_-]?key|private[_-]?key)["']\s*(?:=>|:)\s*
      |
      (?P<key2>api[_-]?key|token|secret|password|passwd|client[_-]?secret|access[_-]?key|private[_-]?key)\s*=\s*
    )
    (?P<quote>["'])
    (?P<value>[^"'\n]{1,240})
    (?P=quote)
    """,
    re.IGNORECASE,
)

ENV_ASSIGN_RE = re.compile(
    r"""(?x)
    \b(?:export\s+)?
    (?P<key>[A-Z][A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|PASSWD|API_KEY|ACCESS_KEY|PRIVATE_KEY))
    \s*=\s*
    (?P<value>[^\s#;]+)
    """
)

SSHPASS_RE = re.compile(r"""(?i)sshpass\s+-p\s+(['"])(?P<value>[^'"]+)\1""")
SUDO_PIPE_RE = re.compile(r"""(?i)echo\s+(['"]?)(?P<value>[^'"|\s]+)\1\s*\|\s*sudo\s+-S""")

seen = set()
findings = []


def is_probably_text(path: pathlib.Path) -> bool:
    try:
        data = path.read_bytes()
    except Exception:
        return False
    if b"\x00" in data[:8192]:
        return False
    return True


def read_text(path: pathlib.Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def rel(path: pathlib.Path) -> str:
    return path.relative_to(repo_root).as_posix()


def should_ignore(path: pathlib.Path) -> bool:
    path_rel = rel(path)
    parts = set(path.parts)
    if any(part in IGNORE_DIRS for part in parts):
        return True
    if path_rel.startswith("tests/modules/wpmmcc-ats/e2e/runtime/"):
        return True
    if path_rel in KNOWN_FALSE_POSITIVE_FILES:
        return True
    return False


def discover_files():
    files = set()
    covered = []

    for root, scope_kind in SCAN_ROOTS:
        abs_root = repo_root / root
        exists = abs_root.exists()
        discovered = 0
        if exists:
            for path in abs_root.rglob("*"):
                if not path.is_file():
                    continue
                if should_ignore(path):
                    continue
                if not is_probably_text(path):
                    continue
                files.add(path)
                discovered += 1
        covered.append(
            {
                "scope": root,
                "kind": scope_kind,
                "exists": exists,
                "files": discovered,
            }
        )

    for pattern, scope_kind in [("client/**/deploy", "deploy"), ("web/**/deploy", "deploy")]:
        matches = 0
        for deploy_dir in repo_root.glob(pattern):
            if not deploy_dir.exists():
                continue
            if deploy_dir.is_file():
                candidate_files = [deploy_dir]
            else:
                candidate_files = [p for p in deploy_dir.rglob("*") if p.is_file()]
            for path in candidate_files:
                if should_ignore(path):
                    continue
                if not is_probably_text(path):
                    continue
                files.add(path)
                matches += 1
        covered.append(
            {
                "scope": pattern,
                "kind": scope_kind,
                "exists": matches > 0,
                "files": matches,
            }
        )

    return sorted(files), covered


def is_historical_file(path: pathlib.Path, text: str) -> bool:
    p = rel(path).lower()
    if p.startswith("as-docs/") or "/archive/" in p or "/archived/" in p:
        return True
    head = "\n".join(text.splitlines()[:30]).lower()
    return any(marker in head for marker in HISTORICAL_MARKERS)


def mask_value(value: str) -> str:
    if not value:
        return "<empty>"
    if len(value) <= 6:
        return f"<len:{len(value)}>"
    return f"{value[:3]}...{value[-2:]} (len={len(value)})"


def looks_placeholder(value: str) -> bool:
    v = value.strip().strip("'\"")
    lower = v.lower()
    if not v:
        return True
    if v.startswith("${") or v.startswith("$("):
        return True
    if "<" in v and ">" in v:
        return True
    placeholder_tokens = [
        "example",
        "sample",
        "changeme",
        "change-me",
        "replace_me",
        "replace",
        "placeholder",
        "demo",
        "dummy",
        "mock",
        "test",
        "redacted",
        "redact",
        "your_",
        "xxxx",
        "todo",
    ]
    return any(token in lower for token in placeholder_tokens)


def looks_weak_password(value: str) -> bool:
    weak_values = {
        "123456",
        "12345678",
        "password",
        "passwd",
        "admin",
        "root",
        "qwerty",
    }
    v = value.strip().strip("'\"").lower()
    return v in weak_values


def entropy(value: str) -> float:
    if not value:
        return 0.0
    total = len(value)
    freq = {}
    for ch in value:
        freq[ch] = freq.get(ch, 0) + 1
    score = 0.0
    for count in freq.values():
        p = count / total
        score -= p * math.log2(p)
    return score


def add_finding(
    finding_id,
    severity,
    path,
    line_no,
    excerpt,
    message,
    suggestion,
    value_hint=None,
    historical=False,
):
    key = (finding_id, severity, path, line_no, excerpt.strip())
    if key in seen:
        return
    seen.add(key)
    findings.append(
        {
            "id": finding_id,
            "severity": severity,
            "path": path,
            "line": line_no,
            "excerpt": excerpt.strip()[:260],
            "value_hint": value_hint,
            "message": message,
            "suggestion": suggestion,
            "historical": historical,
        }
    )


def maybe_downgrade_historical(severity, historical):
    if historical and severity != "blocking":
        return "info"
    return severity


def scan_file(path):
    text = read_text(path)
    historical = is_historical_file(path, text)
    path_rel = rel(path)
    lines = text.splitlines()

    for idx, line in enumerate(lines, start=1):
        for finding_id, pattern in HIGH_CONF_PATTERNS:
            if pattern.search(line):
                add_finding(
                    finding_id=finding_id,
                    severity="blocking",
                    path=path_rel,
                    line_no=idx,
                    excerpt=line,
                    message="High-confidence secret pattern detected.",
                    suggestion="Remove the real credential/material and replace with placeholder or runtime env reference.",
                    value_hint=None,
                    historical=historical,
                )

        for match in ASSIGN_RE.finditer(line):
            key = match.group("key1") or match.group("key2") or ""
            value = match.group("value")
            key_lower = key.lower()
            value_hint = mask_value(value)

            severity = "warning"
            message = "Sensitive field appears to be hard-coded in source."
            suggestion = "Use placeholder + environment/runtime injection; do not commit real values."

            if "password" in key_lower or "passwd" in key_lower:
                if looks_placeholder(value):
                    message = "Password-like field uses placeholder/demo value."
                elif looks_weak_password(value):
                    message = "Password-like field uses weak literal value."
            elif "secret" in key_lower or "token" in key_lower or "api" in key_lower:
                if looks_placeholder(value):
                    message = "Secret/token field uses placeholder/demo value."

            severity = maybe_downgrade_historical(severity, historical)
            add_finding(
                finding_id="hardcoded-sensitive-assignment",
                severity=severity,
                path=path_rel,
                line_no=idx,
                excerpt=line,
                message=message,
                suggestion=suggestion,
                value_hint=value_hint,
                historical=historical,
            )

        for match in ENV_ASSIGN_RE.finditer(line):
            key = match.group("key")
            raw_value = match.group("value").strip()
            value = raw_value.strip("'\"")
            severity = "warning"
            message = "Sensitive environment variable is assigned inline."
            if looks_placeholder(value):
                message = "Sensitive env variable uses placeholder/demo value."
            severity = maybe_downgrade_historical(severity, historical)
            add_finding(
                finding_id="inline-sensitive-env",
                severity=severity,
                path=path_rel,
                line_no=idx,
                excerpt=line,
                message=message,
                suggestion="Move real values to untracked env files or secret store; keep repository values as placeholders.",
                value_hint=mask_value(value),
                historical=historical,
            )

        sshpass_match = SSHPASS_RE.search(line)
        if sshpass_match:
            value = sshpass_match.group("value")
            message = "sshpass with inline password detected."
            severity = "warning"
            severity = maybe_downgrade_historical(severity, historical)
            add_finding(
                finding_id="sshpass-inline-password",
                severity=severity,
                path=path_rel,
                line_no=idx,
                excerpt=line,
                message=message,
                suggestion="Use SSH keys or environment-based secret injection; remove inline passwords.",
                value_hint=mask_value(value),
                historical=historical,
            )

        sudo_match = SUDO_PIPE_RE.search(line)
        if sudo_match:
            value = sudo_match.group("value")
            severity = "warning"
            message = "Inline sudo password pipeline detected."
            severity = maybe_downgrade_historical(severity, historical)
            add_finding(
                finding_id="sudo-inline-password",
                severity=severity,
                path=path_rel,
                line_no=idx,
                excerpt=line,
                message=message,
                suggestion="Use least-privilege service units or secret prompts; do not embed sudo passwords in scripts/config.",
                value_hint=mask_value(value),
                historical=historical,
            )


files, covered_scopes = discover_files()
for file_path in files:
    scan_file(file_path)

summary = {"blocking": 0, "warning": 0, "info": 0}
for finding in findings:
    summary[finding["severity"]] = summary.get(finding["severity"], 0) + 1

if summary["blocking"] > 0:
    status = "failed"
elif summary["warning"] > 0:
    status = "warning"
else:
    status = "passed"

report = {
    "status": status,
    "generated_at": datetime.datetime.now().isoformat(timespec="seconds"),
    "scanned_file_count": len(files),
    "covered_scopes": covered_scopes,
    "summary": summary,
    "findings": findings,
    "report_path": str(report_path),
}

payload = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
report_path.write_text(payload, encoding="utf-8")
latest_path.write_text(payload, encoding="utf-8")

digest = hashlib.sha256(payload.encode("utf-8")).hexdigest()
print(f"[secrets-audit] status={status}")
print(f"[secrets-audit] summary blocking={summary['blocking']} warning={summary['warning']} info={summary['info']}")
print(f"[secrets-audit] scanned_files={len(files)}")
print(f"[secrets-audit] report={report_path}")
print(f"[secrets-audit] latest={latest_path}")
print(f"[secrets-audit] report_sha256={digest}")

if summary["blocking"] > 0:
    sys.exit(2)
PY
