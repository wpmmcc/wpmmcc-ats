#!/usr/bin/env bash
set -euo pipefail

_SD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [[ "$_SD" != "/" && ! -f "$_SD/scripts/wptsall.sh" ]]; do _SD="$(dirname "$_SD")"; done
ROOT_DIR="$_SD"
CONTRACTS_DIR="${ROOT_DIR}/docs/architecture/current/contracts"
INDEX_FILE="${CONTRACTS_DIR}/index.json"
RUNTIME_DIR="${ROOT_DIR}/tests/modules/wpmmcc-ats/e2e/runtime"
mkdir -p "${RUNTIME_DIR}"

TS="$(date +%Y%m%d-%H%M%S)"
REPORT_JSON="${RUNTIME_DIR}/contract-schema-verify-${TS}.json"
LATEST_JSON="${RUNTIME_DIR}/contract-schema-verify-latest.json"
TEST_ID="TEST-WEB-CONTROL-PLANE-CONTRACT-001"
REQUIREMENT_ID="REQ-WEB-CONTROL-PLANE-CONTRACT-001"
SCENARIO_ID="SCN-WEB-CONTROL-PLANE-CONTRACT-SCHEMA"

python3 - <<'PY' "${CONTRACTS_DIR}" "${INDEX_FILE}" "${REPORT_JSON}" "${LATEST_JSON}" "${TEST_ID}" "${REQUIREMENT_ID}" "${SCENARIO_ID}" "${TS}"
import json
import pathlib
import sys
from datetime import datetime

contracts_dir = pathlib.Path(sys.argv[1]).resolve()
index_file = pathlib.Path(sys.argv[2]).resolve()
report_json = pathlib.Path(sys.argv[3]).resolve()
latest_json = pathlib.Path(sys.argv[4]).resolve()
test_id = sys.argv[5]
requirement_id = sys.argv[6]
scenario_id = sys.argv[7]
run_id = sys.argv[8]


def load_json(path: pathlib.Path):
    return json.loads(path.read_text(encoding="utf-8", errors="replace"))


def json_type_name(value):
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, int) and not isinstance(value, bool):
        return "integer"
    if isinstance(value, float):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    if isinstance(value, dict):
        return "object"
    return type(value).__name__


def type_matches(value, expected_type):
    if expected_type == "null":
        return value is None
    if expected_type == "boolean":
        return isinstance(value, bool)
    if expected_type == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected_type == "number":
        return (isinstance(value, int) and not isinstance(value, bool)) or isinstance(value, float)
    if expected_type == "string":
        return isinstance(value, str)
    if expected_type == "array":
        return isinstance(value, list)
    if expected_type == "object":
        return isinstance(value, dict)
    return False


def ptr_join(base, token):
    if base == "":
        return f"/{token}"
    return f"{base}/{token}"


def validate(value, schema, path, errors):
    if not isinstance(schema, dict):
        return

    if "anyOf" in schema and isinstance(schema["anyOf"], list):
        any_of_errors = []
        for candidate in schema["anyOf"]:
            sub_errors = []
            validate(value, candidate, path, sub_errors)
            if not sub_errors:
                any_of_errors = []
                break
            any_of_errors.append(sub_errors)
        if any_of_errors:
            first = any_of_errors[0][0] if any_of_errors[0] else {"message": "anyOf mismatch"}
            errors.append(
                {
                    "path": path or "/",
                    "message": f"anyOf mismatch: {first.get('message', 'no matching schema')}",
                }
            )
            return

    expected_types = schema.get("type")
    if expected_types is not None:
        if isinstance(expected_types, str):
            expected_types = [expected_types]
        if not isinstance(expected_types, list) or not expected_types:
            errors.append({"path": path or "/", "message": "invalid schema.type definition"})
            return
        if not any(type_matches(value, t) for t in expected_types):
            errors.append(
                {
                    "path": path or "/",
                    "message": f"type mismatch: expected {expected_types}, got {json_type_name(value)}",
                }
            )
            return

    if "const" in schema and value != schema["const"]:
        errors.append(
            {
                "path": path or "/",
                "message": f"const mismatch: expected {schema['const']!r}, got {value!r}",
            }
        )

    if "enum" in schema and value not in schema["enum"]:
        errors.append(
            {
                "path": path or "/",
                "message": f"enum mismatch: expected one of {schema['enum']!r}, got {value!r}",
            }
        )

    if isinstance(value, dict):
        required = schema.get("required", [])
        if isinstance(required, list):
            for key in required:
                if key not in value:
                    errors.append(
                        {
                            "path": ptr_join(path, key) if path else f"/{key}",
                            "message": "missing required key",
                        }
                    )

        properties = schema.get("properties", {})
        if isinstance(properties, dict):
            for key, sub_schema in properties.items():
                if key in value:
                    validate(value[key], sub_schema, ptr_join(path, key), errors)

        if schema.get("additionalProperties") is False and isinstance(properties, dict):
            for key in value.keys():
                if key not in properties:
                    errors.append(
                        {
                            "path": ptr_join(path, key),
                            "message": "additional property not allowed",
                        }
                    )

    if isinstance(value, list):
        min_items = schema.get("minItems")
        if isinstance(min_items, int) and len(value) < min_items:
            errors.append(
                {
                    "path": path or "/",
                    "message": f"minItems mismatch: expected >= {min_items}, got {len(value)}",
                }
            )

        item_schema = schema.get("items")
        if isinstance(item_schema, dict):
            for idx, item in enumerate(value):
                validate(item, item_schema, ptr_join(path, str(idx)), errors)


checks = []
fatal_setup_errors = []
covered_user_journeys = set()
required_user_journeys = {"UJ1", "UJ2", "UJ3", "UJ14"}

if not index_file.exists():
    fatal_setup_errors.append(f"missing index file: {index_file}")
else:
    try:
        index_payload = load_json(index_file)
    except Exception as exc:
        fatal_setup_errors.append(f"invalid index json: {exc}")
        index_payload = {}

    for contract in index_payload.get("contracts", []) if isinstance(index_payload, dict) else []:
        contract_id = str(contract.get("id") or "").strip()
        description = str(contract.get("description") or "").strip()
        schema_rel = str(contract.get("schema") or "").strip()
        sample_rels = contract.get("samples") or []
        for journey in contract.get("user_journey", []) or []:
            covered_user_journeys.add(str(journey))
        if not contract_id:
            continue

        schema_path = (contracts_dir / schema_rel).resolve()
        if not schema_rel or not schema_path.exists():
            checks.append(
                {
                    "id": contract_id,
                    "status": "failed",
                    "category": "contract_mismatch",
                    "message": f"schema file missing: {schema_rel}",
                    "next_action": "Add or fix schema path in docs/current/contracts/index.json",
                    "evidence": str(schema_path),
                    "error_count": 1,
                    "errors": [{"path": "/", "message": "schema file missing"}],
                    "description": description,
                    "schema": schema_rel,
                    "sample": None,
                }
            )
            continue

        try:
            schema_data = load_json(schema_path)
        except Exception as exc:
            checks.append(
                {
                    "id": contract_id,
                    "status": "failed",
                    "category": "contract_mismatch",
                    "message": f"invalid schema json: {exc}",
                    "next_action": "Fix schema JSON syntax",
                    "evidence": str(schema_path),
                    "error_count": 1,
                    "errors": [{"path": "/", "message": f"invalid schema json: {exc}"}],
                    "description": description,
                    "schema": schema_rel,
                    "sample": None,
                }
            )
            continue

        if not isinstance(sample_rels, list) or not sample_rels:
            checks.append(
                {
                    "id": contract_id,
                    "status": "failed",
                    "category": "contract_mismatch",
                    "message": "no samples configured for contract",
                    "next_action": "Add at least one sample in index.json",
                    "evidence": str(index_file),
                    "error_count": 1,
                    "errors": [{"path": "/", "message": "samples list is empty"}],
                    "description": description,
                    "schema": schema_rel,
                    "sample": None,
                }
            )
            continue

        for sample_rel in sample_rels:
            sample_rel = str(sample_rel or "").strip()
            sample_path = (contracts_dir / sample_rel).resolve()
            if not sample_rel or not sample_path.exists():
                checks.append(
                    {
                        "id": contract_id,
                        "status": "failed",
                        "category": "contract_mismatch",
                        "message": f"sample file missing: {sample_rel}",
                        "next_action": "Add sample file or fix sample path in index.json",
                        "evidence": str(sample_path),
                        "error_count": 1,
                        "errors": [{"path": "/", "message": "sample file missing"}],
                        "description": description,
                        "schema": schema_rel,
                        "sample": sample_rel,
                    }
                )
                continue

            try:
                sample_data = load_json(sample_path)
            except Exception as exc:
                checks.append(
                    {
                        "id": contract_id,
                        "status": "failed",
                        "category": "contract_mismatch",
                        "message": f"invalid sample json: {exc}",
                        "next_action": "Fix sample JSON syntax",
                        "evidence": str(sample_path),
                        "error_count": 1,
                        "errors": [{"path": "/", "message": f"invalid sample json: {exc}"}],
                        "description": description,
                        "schema": schema_rel,
                        "sample": sample_rel,
                    }
                )
                continue

            validation_errors = []
            validate(sample_data, schema_data, "", validation_errors)
            if validation_errors:
                msg = validation_errors[0]
                checks.append(
                    {
                        "id": contract_id,
                        "status": "failed",
                        "category": "contract_mismatch",
                        "message": f"schema mismatch at {msg.get('path')}: {msg.get('message')}",
                        "next_action": "Update contract schema/sample or fix API response fields",
                        "evidence": str(sample_path),
                        "error_count": len(validation_errors),
                        "errors": validation_errors[:20],
                        "description": description,
                        "schema": schema_rel,
                        "sample": sample_rel,
                    }
                )
            else:
                checks.append(
                    {
                        "id": contract_id,
                        "status": "passed",
                        "category": None,
                        "message": "schema verified",
                        "next_action": "none",
                        "evidence": str(sample_path),
                        "error_count": 0,
                        "errors": [],
                        "description": description,
                        "schema": schema_rel,
                        "sample": sample_rel,
                    }
                )

if fatal_setup_errors:
    for err in fatal_setup_errors:
        checks.append(
            {
                "id": "contract-schema-gate-setup",
                "status": "failed",
                "category": "contract_mismatch",
                "message": err,
                "next_action": "Fix contracts index/bootstrap files",
                "evidence": str(index_file),
                "error_count": 1,
                "errors": [{"path": "/", "message": err}],
                "description": "contracts setup",
                "schema": None,
                "sample": None,
            }
        )

missing_user_journeys = sorted(required_user_journeys - covered_user_journeys)
if missing_user_journeys:
    checks.append(
        {
            "id": "web-control-plane-contract-coverage",
            "status": "failed",
            "category": "contract_mismatch",
            "message": f"missing required user journey coverage: {missing_user_journeys}",
            "next_action": "Add contract entries with user_journey metadata for auth/domain/entitlement/template/ticket",
            "evidence": str(index_file),
            "error_count": len(missing_user_journeys),
            "errors": [{"path": "/contracts", "message": f"missing user journey: {item}"} for item in missing_user_journeys],
            "description": "web control plane journey coverage",
            "schema": None,
            "sample": None,
        }
    )

total = len(checks)
failed = len([c for c in checks if c["status"] == "failed"])
passed = len([c for c in checks if c["status"] == "passed"])
warning = len([c for c in checks if c["status"] == "warning"])

status = "passed" if failed == 0 else "failed"
summary = {
    "total": total,
    "passed": passed,
    "failed": failed,
    "warning": warning,
    "blocking": failed,
    "info": passed,
}

payload = {
    "status": status,
    "test_id": test_id,
    "requirement_id": requirement_id,
    "user_journey_id": sorted(covered_user_journeys & required_user_journeys),
    "scenario_id": scenario_id,
    "run_id": run_id,
    "generated_at": datetime.now().isoformat(timespec="seconds"),
    "contracts_dir": str(contracts_dir),
    "index_file": str(index_file),
    "summary": summary,
    "checks": checks,
}

body = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
report_json.write_text(body, encoding="utf-8")
latest_json.write_text(body, encoding="utf-8")

print(f"[contract-schema] status={status}")
print(f"[contract-schema] total={total}")
print(f"[contract-schema] passed={passed}")
print(f"[contract-schema] failed={failed}")
print(f"[contract-schema] report={report_json}")
print(f"[contract-schema] latest={latest_json}")

if failed > 0:
    first = next(c for c in checks if c["status"] == "failed")
    first_error = first["errors"][0] if first["errors"] else {"path": "/", "message": first["message"]}
    print(
        f"[contract-schema] first_mismatch id={first['id']} path={first_error.get('path')} message={first_error.get('message')}"
    )
    raise SystemExit(1)
PY
