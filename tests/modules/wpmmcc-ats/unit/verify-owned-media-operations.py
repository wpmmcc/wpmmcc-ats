#!/usr/bin/env python3
"""Run real media-operation and reconciliation probes on verified owned WP."""
import argparse
import importlib.util
import hashlib
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location("owned_media_sites", ROOT / "tests/infra/tools/owned-wp-sites.py")
owned = importlib.util.module_from_spec(spec)
spec.loader.exec_module(owned)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    context = owned.load(args.context)
    if context["plugin"] != "wpmmcc-ats":
        raise ValueError("requires owned ATS sites")
    site = context["sites"][0]
    label = subprocess.check_output(["docker", "inspect", site["name"], "--format",
                                    '{{index .Config.Labels "com.wptsall.owned.run"}}'], text=True).strip()
    if label != context["owner"]:
        raise ValueError("owned container identity mismatch")
    sources = {
        ROOT / "wpmmcc-ats/source/includes/tasks/api/class-client-data-rest-controller.php":
            "/var/www/html/wp-content/plugins/wpmmcc-ats/includes/tasks/api/class-client-data-rest-controller.php",
        ROOT / "wpmmcc-ats/source/includes/core/class-transport-middleware.php":
            "/var/www/html/wp-content/plugins/wpmmcc-ats/includes/core/class-transport-middleware.php",
        Path(__file__).resolve().parent / "tasks/owned-chunk-recovery-faults.php": "/tmp/owned-chunk-recovery-faults.php",
        Path(__file__).resolve().parent / "tasks/owned-media-operation-probe.php": "/tmp/owned-media-operation-probe.php",
    }
    for source, destination in sources.items():
        subprocess.run(["docker", "cp", str(source), site["name"] + ":" + destination], check=True)
        copied=subprocess.check_output(["docker","exec",site["name"],"sha256sum",destination],text=True).split()[0]
        if copied!=hashlib.sha256(source.read_bytes()).hexdigest():
            raise RuntimeError("owned WP source copy does not match")
        if destination.startswith("/var/www/html/wp-content/plugins/"):
            subprocess.run(["docker","exec",site["name"],"chmod","644",destination],check=True)
    result = json.loads(owned.wp(site["name"], """
require '/tmp/owned-chunk-recovery-faults.php';
require '/tmp/owned-media-operation-probe.php';
"""))
    if len(result["rows"]) != 26:
        raise RuntimeError("exact nonempty media-operation verdict required")
    result["passed"] = sum(row["passed"] for row in result["rows"])
    result["failed"] = len(result["rows"]) - result["passed"]
    with args.report.open("x") as output:
        json.dump(result, output, indent=2)
        output.write("\n")
    for row in result["rows"]:
        print(("PASS " if row["passed"] else "FAIL ") + row["name"])
    return int(result["failed"] != 0)


if __name__ == "__main__":
    raise SystemExit(main())
