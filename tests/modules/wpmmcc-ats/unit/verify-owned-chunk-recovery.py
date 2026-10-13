#!/usr/bin/env python3
"""Private WP chunk owner, durable-result and refusal checks on owned sites only."""
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location("owned_chunk_sites", ROOT / "tests/infra/tools/owned-wp-sites.py")
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
    label = subprocess.check_output([
        "docker", "inspect", site["name"], "--format",
        '{{index .Config.Labels "com.wptsall.owned.run"}}',
    ], text=True).strip()
    if label != context["owner"]:
        raise ValueError("owned container identity mismatch")
    source = Path(__file__).resolve().parent / "tasks"
    for name in ["owned-chunk-recovery-faults.php", "owned-chunk-recovery-probe.php"]:
        subprocess.run(["docker", "cp", str(source / name), site["name"] + ":/tmp/" + name], check=True)
    result = json.loads(owned.wp(site["name"], """
require '/tmp/owned-chunk-recovery-faults.php';
require '/tmp/owned-chunk-recovery-probe.php';
"""))
    if len(result["rows"]) != 25:
        raise RuntimeError("nonempty, exact owned probe verdict required")
    reopened = json.loads(owned.wp(site["name"], """
$saved=get_option('owned_chunk_recovery_resume_case');
$request=new \\WP_REST_Request('POST','/client/media-upload/complete');
$request->set_header('X-WPTSALL-Device-Id','owned-device-a');
$request->set_header('Content-Type','application/json');
$request->set_body(wp_json_encode(array('upload_id'=>$saved['upload_id'])));
$controller=new \\WPTSALL\\Tasks\\API\\Client_Data_REST_Controller();
$response=$controller->media_upload_complete($request);
echo wp_json_encode(array(
 'same_response'=>200===$response->get_status() && $saved['response']===$response->get_data(),
 'chunk_retained'=>is_file(trailingslashit(wp_upload_dir()['basedir']).'wptsall-chunked/'.$saved['upload_id'].'/chunk-0.bin')
));
"""))
    for name, passed in reopened.items():
        result["rows"].append({"name": "fresh WP process " + name, "passed": bool(passed)})
    result["passed"] = sum(row["passed"] for row in result["rows"])
    result["failed"] = len(result["rows"]) - result["passed"]
    result["scope"] = "real controller/filesystem; fault subclass importer, plus separate genuine attachment unit lifecycle"
    with args.report.open("x") as output:
        json.dump(result, output, indent=2)
        output.write("\n")
    for row in result["rows"]:
        print(("PASS " if row["passed"] else "FAIL ") + row["name"])
    return int(result["failed"] != 0)


if __name__ == "__main__":
    raise SystemExit(main())
