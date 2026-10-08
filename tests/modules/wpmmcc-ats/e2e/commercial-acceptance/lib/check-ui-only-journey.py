#!/usr/bin/env python3
"""Fail if a claimed UI journey relies on API bindSite(request).

Under COMMERCIAL_UI_ONLY / --strict:
  any bindSite(request) in listed files is a failure (API may only assert/cleanup).

Without --strict (legacy soft mode):
  fail only when bindSite(request) appears with zero DOM fill/click/getBy*.

Usage:
  check-ui-only-journey.py --file path/to/spec.ts [--strict]
  check-ui-only-journey.py --dir path/to/specs --glob 'sim-*.spec.ts' --strict
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

FILL_RE = re.compile(
    r"\.(fill|click|selectOption)\(|getByTestId\(|getByRole\(|getByLabel\(|"
    r"locator\(|bindSiteViaUi\(|fillSites"
)
API_BIND_RE = re.compile(r"\bbindSite\s*\(\s*request")
COMMENT_RE = re.compile(
    r"//.*?$|/\*.*?\*/",
    re.MULTILINE | re.DOTALL,
)


def strip_comments(text: str) -> str:
    return COMMENT_RE.sub("", text)


def check_file(path: Path, *, strict: bool) -> list[str]:
    raw = path.read_text(encoding="utf-8", errors="ignore")
    text = strip_comments(raw)
    errors: list[str] = []
    has_fill = bool(FILL_RE.search(text))
    api_binds = len(API_BIND_RE.findall(text))
    if strict and api_binds:
        errors.append(
            f"{path}: bindSite(request) ×{api_binds} forbidden under "
            "COMMERCIAL_UI_ONLY / --strict (use bindSiteViaUi or assert-only API)"
        )
        return errors
    if api_binds and not has_fill:
        errors.append(
            f"{path}: has bindSite(request) but no DOM fill/click/getBy* — "
            "forbidden under commercial UI journeys"
        )
    return errors


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", type=Path, action="append", default=[])
    ap.add_argument("--dir", type=Path, default=None)
    ap.add_argument("--glob", default="*.spec.ts")
    ap.add_argument(
        "--strict",
        action="store_true",
        help="Fail on any bindSite(request) in listed files",
    )
    args = ap.parse_args()
    files: list[Path] = list(args.file)
    if args.dir:
        files.extend(sorted(args.dir.glob(args.glob)))
    if not files:
        print("no files", file=sys.stderr)
        return 2
    errs: list[str] = []
    for f in files:
        if f.is_file():
            errs.extend(check_file(f, strict=args.strict))
    for e in errs:
        print(e, file=sys.stderr)
    if errs:
        print(f"COMMERCIAL_UI_ONLY violations: {len(errs)}", file=sys.stderr)
        return 1
    mode = "strict" if args.strict else "soft"
    print(f"ok: {len(files)} journey file(s) UI-clean ({mode})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
