#!/usr/bin/env python3
"""Resolve FULL_CHAIN_PROJECTS against project-specs.json."""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path

SPECS = Path(__file__).resolve().parents[2] / "project-specs.json"
DEFAULT = ["wptsall-content", "woocommerce-content"]


def all_projects() -> list[str]:
    data = json.loads(SPECS.read_text())
    return list((data.get("plugin_projects") or {}).keys())


def resolve(raw: str | None) -> list[str]:
    raw = (raw or "").strip()
    if not raw or raw.lower() == "default":
        return list(DEFAULT)
    if raw.lower() == "all":
        return all_projects()
    return [p.strip() for p in raw.split(",") if p.strip()]


def main() -> int:
    mode = sys.argv[1] if len(sys.argv) > 1 else "list"
    projects = resolve(os.environ.get("FULL_CHAIN_PROJECTS"))
    if mode == "json":
        print(json.dumps({"count": len(projects), "projects": projects}, indent=2))
    else:
        for p in projects:
            print(p)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
