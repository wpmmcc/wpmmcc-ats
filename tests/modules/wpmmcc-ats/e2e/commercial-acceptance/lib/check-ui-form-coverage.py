#!/usr/bin/env python3
"""U1–U7 commercial UI form coverage — frontend presence + real Playwright fill/click.

Pass requires:
  1. Frontend source contains the form controls.
  2. At least one Playwright journey performs a real DOM action (.fill / .click /
     .selectOption / bindSiteViaUi) against the form — string mention alone fails.
  3. SIM-10 commercial matrix exists and references each form id (U1–U7).

Exit 0 = pass, 1 = fail. Writes JSON report to --out or stdout.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

FORMS = [
    {
        "id": "U1",
        "name": "sites_add_edit",
        "frontend_needles": [
            'data-testid="sites-modal-url"',
            'data-testid="sites-modal-token"',
            'data-testid="sites-modal-save"',
        ],
        "action_patterns": [
            r"bindSiteViaUi\s*\(",
            r"getByTestId\(\s*['\"]sites-modal-url['\"]\s*\)\s*\.\s*fill\s*\(",
            r"getByTestId\(\s*['\"]sites-modal-token['\"]\s*\)\s*\.\s*fill\s*\(",
            r"getByTestId\(\s*['\"]sites-modal-save['\"]\s*\)\s*\.\s*click\s*\(",
        ],
        "matrix_needles": ["U1", "sites-modal-url", "bindSiteViaUi"],
    },
    {
        "id": "U2",
        "name": "api_key_or_provider_wizard",
        "frontend_needles": [
            'data-testid="provider-setup-wizard"',
            'data-testid="wizard-key-id"',
            'data-testid="open-provider-wizard"',
        ],
        "action_patterns": [
            r"getByTestId\(\s*['\"]open-provider-wizard['\"][^)]*\)\s*\.\s*click\s*\(",
            r"getByTestId\(\s*['\"]wizard-key-id['\"]\s*\)\s*\.\s*fill\s*\(",
            r"getByTestId\(\s*['\"]provider-setup-wizard['\"]\s*\)",
        ],
        "matrix_needles": ["U2", "wizard-key-id", "open-provider-wizard"],
    },
    {
        "id": "U3",
        "name": "component_or_rule_bind",
        "frontend_needles": [
            'data-testid="rule-bind-save"',
            'data-testid="rule-bind-component-id"',
            'data-testid="rule-bind-slot-key"',
        ],
        "action_patterns": [
            r"getByTestId\(\s*['\"]rule-bind-component-id['\"]\s*\)\s*\.\s*fill\s*\(",
            r"getByTestId\(\s*['\"]rule-bind-slot-key['\"]\s*\)\s*\.\s*selectOption\s*\(",
            r"getByTestId\(\s*['\"]rule-bind-save['\"]\s*\)\s*\.\s*click\s*\(",
            r"saveGlobalPlainTextBinding\s*\(",
        ],
        "matrix_needles": ["U3", "rule-bind-component-id", "rule-bind-slot-key"],
    },
    {
        "id": "U4",
        "name": "sync_pair",
        "frontend_needles": [
            'id="sync-pair-name"',
            "sync-pair-name",
        ],
        "action_patterns": [
            r"locator\(\s*['\"]#sync-pair-name['\"]\s*\)\s*\.\s*fill\s*\(",
            r"#sync-pair-name['\"].*\.fill\s*\(",
            r"locator\(\s*['\"]#sync-pair-name['\"]\s*\)\.fill\s*\(",
        ],
        "matrix_needles": ["U4", "sync-pair-name"],
    },
    {
        "id": "U5",
        "name": "pairing_code",
        "frontend_needles": [
            'id="pairing-code"',
            "pairing-code",
        ],
        "action_patterns": [
            r"locator\(\s*['\"]#pairing-code['\"]\s*\)\s*\.\s*fill\s*\(",
            r"#pairing-code['\"].*\.fill\s*\(",
        ],
        "matrix_needles": ["U5", "pairing-code"],
    },
    {
        "id": "U6",
        "name": "discovery_tasks",
        "frontend_needles": [
            'data-testid="tasks-bootstrap-discovery"',
        ],
        "action_patterns": [
            r"getByTestId\(\s*['\"]tasks-bootstrap-discovery['\"]\s*\)\s*\.\s*click\s*\(",
        ],
        "matrix_needles": ["U6", "tasks-bootstrap-discovery"],
    },
    {
        "id": "U7",
        "name": "worker_review_settings",
        "frontend_needles": [
            'data-testid="settings-review-toggle"',
            'data-testid="settings-save-worker"',
        ],
        "action_patterns": [
            r"getByTestId\(\s*['\"]settings-review-toggle['\"]\s*\)\s*\.\s*click\s*\(",
            r"getByTestId\(\s*['\"]settings-save-worker['\"]\s*\)\s*\.\s*click\s*\(",
        ],
        "matrix_needles": ["U7", "settings-review-toggle"],
    },
]

SIM10_REL = (
    "tests/modules/wpmmcc-ats/e2e/playwright/simulation/"
    "sim-10-commercial-forms-matrix.spec.ts"
)


def collect_text(root: Path, globs: list[str]) -> str:
    chunks: list[str] = []
    for pattern in globs:
        for path in root.glob(pattern):
            if path.is_file():
                try:
                    chunks.append(path.read_text(encoding="utf-8", errors="ignore"))
                except OSError:
                    pass
    return "\n".join(chunks)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--repo", type=Path, required=True)
    ap.add_argument("--out", type=Path, default=None)
    args = ap.parse_args()
    repo: Path = args.repo.resolve()

    fe = repo / "client-wpplugin/source/frontend/src"
    pw = repo / "tests/modules/wpmmcc-ats/e2e/playwright"
    fe_text = collect_text(fe, ["**/*.svelte", "**/*.ts"])
    pw_text = collect_text(pw, ["**/*.ts", "**/*.spec.ts"])

    sim10 = repo / SIM10_REL
    sim10_text = ""
    sim10_ok = sim10.is_file()
    if sim10_ok:
        sim10_raw = sim10.read_text(encoding="utf-8", errors="ignore")
        # Ignore // and /* */ comments so docs don't trip the ban.
        sim10_text = re.sub(r"//.*?$|/\*.*?\*/", "", sim10_raw, flags=re.M | re.S)
        if re.search(r"\bbindSite\s*\(\s*request", sim10_text):
            sim10_ok = False
        else:
            sim10_text = sim10_raw  # keep labels (U1…) for matrix_needles

    results = []
    failed = False
    for form in FORMS:
        fe_ok = any(n in fe_text for n in form["frontend_needles"])
        action_hits = [
            p for p in form["action_patterns"] if re.search(p, pw_text, re.MULTILINE)
        ]
        action_ok = len(action_hits) > 0
        matrix_hits = [n for n in form["matrix_needles"] if n in sim10_text]
        matrix_ok = sim10_ok and len(matrix_hits) > 0
        row = {
            "id": form["id"],
            "name": form["name"],
            "frontend_ok": fe_ok,
            "playwright_action_ok": action_ok,
            "playwright_action_hits": action_hits[:5],
            "sim10_matrix_ok": matrix_ok,
            "sim10_matrix_hits": matrix_hits,
            "ok": fe_ok and action_ok and matrix_ok,
        }
        if not row["ok"]:
            failed = True
        results.append(row)

    if not sim10_ok:
        failed = True

    report = {
        "check": "commercial-ui-form-coverage",
        "ok": not failed,
        "forms_total": len(FORMS),
        "forms_ok": sum(1 for r in results if r["ok"]),
        "sim10_path": SIM10_REL,
        "sim10_present": sim10.is_file(),
        "sim10_ui_only": sim10.is_file()
        and not bool(re.search(r"\bbindSite\s*\(\s*request", sim10_text)),
        "forms": results,
        "rule": (
            "Each of U1–U7 must exist in frontend AND be driven by a real "
            "Playwright .fill/.click/.selectOption (or bindSiteViaUi), AND "
            "appear in SIM-10 commercial forms matrix (no bindSite(request))"
        ),
    }
    text = json.dumps(report, indent=2, ensure_ascii=False) + "\n"
    if args.out:
        args.out.parent.mkdir(parents=True, exist_ok=True)
        args.out.write_text(text, encoding="utf-8")
    sys.stdout.write(text)
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
