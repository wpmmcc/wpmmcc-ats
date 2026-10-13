#!/usr/bin/env python3
"""Install catalog plugins into the lab and run isolate coverage tests."""

from __future__ import annotations

import json
import os
import re
import shlex
import subprocess
import sys
import zipfile
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from urllib.request import urlopen, Request
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "catalog.json"
RECORDS = ROOT / "records"
RESULTS = ROOT / "results"
PROGRESS = ROOT / "PROGRESS.md"
ZIPS = Path("/tmp/wptsall-plugin-zips")
UNPACK = Path("/tmp/wptsall-plugin-unpack")
CONTAINER = "wptsall-wp-lab-wordpress-blog-1"
DB_CONTAINER = "wptsall-wp-lab-db-1"
DB_USER = "wplab"
DB_PASS = "wplab_pass_2026"
DB_NAME = "wp_blog"
# Blog 2 of the lab multisite (the /en/ native subsite used by run-one.php).
SUBSITE_URL = "http://192.168.1.12:9081/en/"
# Main blog root of the lab, used for web-context warm-up requests.
MAIN_URL = "http://192.168.1.12:9081/"
KEEP_FILES = [
    "wpmmcc-ats/wpmmcc-ats.php",
    "plugin-check/plugin.php",
]
# Plugins that must never be activated (boot-fatal with no known recovery).
# Emptied 2026-09-25: wp-easycart was quarantined under the old SQL-activation
# regime, where skipping activation hooks left ec_option_wpoptions_version
# unset on a fresh site and wpeasycart_update_check() then called
# update_language_data() at plugins_loaded with an uninitialized
# wp_easycart_language::$languages -> in_array() TypeError on every boot.
# Real activation (isolate_cli's `wp plugin activate` on both blogs) runs
# ec_activate, which writes the version option per site; verified live:
# activation clean on both blogs and both homepages 200.
SKIP_ACTIVATE: set[str] = set()

# Plugins that cannot activate without a companion plugin loaded first.
COMPANIONS = {
    "dokan-lite": ["woocommerce/woocommerce.php"],
}


def sh(cmd, timeout=180):
    return subprocess.run(
        cmd,
        shell=True,
        text=True,
        capture_output=True,
        timeout=timeout,
    )


def download_one(slug: str) -> dict:
    ZIPS.mkdir(parents=True, exist_ok=True)
    dest = ZIPS / f"{slug}.zip"
    if dest.exists() and dest.stat().st_size > 1000:
        return {"slug": slug, "ok": True, "path": str(dest), "cached": True}
    url = f"https://downloads.wordpress.org/plugin/{slug}.latest-stable.zip"
    try:
        req = Request(url, headers={"User-Agent": "wptsall-coverage/1.0"})
        with urlopen(req, timeout=60) as resp:
            dest.write_bytes(resp.read())
        return {"slug": slug, "ok": dest.stat().st_size > 1000, "path": str(dest), "cached": False}
    except (HTTPError, URLError, TimeoutError, OSError) as exc:
        return {"slug": slug, "ok": False, "error": str(exc)}


def install_zip(slug: str, zip_path: str) -> dict:
    UNPACK.mkdir(parents=True, exist_ok=True)
    target = UNPACK / slug
    if target.exists():
        # already unpacked
        pass
    else:
        try:
            with zipfile.ZipFile(zip_path) as zf:
                zf.extractall(UNPACK)
        except zipfile.BadZipFile as exc:
            return {"slug": slug, "ok": False, "error": f"bad zip: {exc}"}
    # folder name inside zip may differ
    dirs = [p for p in UNPACK.iterdir() if p.is_dir()]
    # prefer exact slug
    folder = UNPACK / slug
    if not folder.is_dir():
        # pick newest dir
        folder = max(dirs, key=lambda p: p.stat().st_mtime) if dirs else None
    if not folder:
        return {"slug": slug, "ok": False, "error": "no unpacked folder"}
    r = sh(
        f"docker cp {folder} {CONTAINER}:/var/www/html/wp-content/plugins/{folder.name}",
        timeout=120,
    )
    return {"slug": slug, "ok": r.returncode == 0, "folder": folder.name, "err": r.stderr[-300:]}


def write_record(data: dict) -> None:
    RECORDS.mkdir(parents=True, exist_ok=True)
    RESULTS.mkdir(parents=True, exist_ok=True)
    slug = data.get("slug") or "unknown"
    (RESULTS / f"{slug}.json").write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    steps = data.get("steps") or []
    lines = [
        f"# {data.get('name') or slug}",
        "",
        f"- slug: `{slug}`",
        f"- category: `{data.get('category') or ''}`",
        f"- status: **{data.get('status') or 'unknown'}**",
        f"- pass: {data.get('pass')}",
        f"- post_type: `{data.get('post_type') or ''}`",
        f"- finished: {data.get('finished') or data.get('started') or ''}",
        "",
        "## URLs",
        "",
    ]
    for k, v in (data.get("urls") or {}).items():
        lines.append(f"- {k}: {v}")
    lines += ["", "## Checks", ""]
    for s in steps:
        mark = "PASS" if s.get("ok") else "FAIL"
        lines.append(f"- [{mark}] `{s.get('name')}`")
    if data.get("notes"):
        lines += ["", "## Notes", ""]
        for n in data["notes"]:
            lines.append(f"- {n}")
    if data.get("ids"):
        lines += ["", "## IDs", "", "```json", json.dumps(data["ids"], ensure_ascii=False), "```"]
    (RECORDS / f"{slug}.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def refresh_progress(catalog: dict) -> None:
    results = {}
    for p in RESULTS.glob("*.json"):
        try:
            results[p.stem] = json.loads(p.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            continue
    total = len(catalog.get("plugins") or [])
    done = len(results)
    passed = sum(1 for r in results.values() if r.get("pass"))
    failed = sum(1 for r in results.values() if r.get("status") == "failed")
    missing = sum(1 for r in results.values() if r.get("status") == "not_installed")
    lines = [
        "# Plugin coverage progress",
        "",
        f"- updated: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}",
        f"- catalog: {total}",
        f"- recorded: {done}",
        f"- passed: {passed}",
        f"- failed: {failed}",
        f"- not_installed: {missing}",
        "",
        "| slug | category | status | post_type | virtual URL |",
        "|---|---|---|---|---|",
    ]
    for plug in catalog.get("plugins") or []:
        slug = plug["slug"]
        r = results.get(slug)
        if not r:
            lines.append(f"| {slug} | {plug.get('category','')} | pending |  |  |")
            continue
        url = (r.get("urls") or {}).get("virtual", "")
        lines.append(
            f"| [{slug}](records/{slug}.md) | {r.get('category') or plug.get('category','')} | {r.get('status')} | {r.get('post_type') or ''} | {url} |"
        )
    PROGRESS.write_text("\n".join(lines) + "\n", encoding="utf-8")


def php_serialize_plugins(files: list[str]) -> str:
    php = "echo serialize(" + json.dumps(files) + ");"
    r = sh("docker exec " + CONTAINER + " php -r " + shlex.quote(php))
    return (r.stdout or "").strip()


def sql(query: str) -> subprocess.CompletedProcess:
    return sh(
        f"docker exec {DB_CONTAINER} mysql -u{DB_USER} -p{DB_PASS} {DB_NAME} -N -e "
        + json.dumps(query),
        timeout=30,
    )


def plugin_bootstrap_file(slug: str) -> str:
    """Resolve installed plugin bootstrap file without loading WordPress.

    Only a file carrying a "Plugin Name:" header can be activated, so scan
    the plugin's top-level PHP files for headers and pick from those.
    Directory-order heuristics alone misresolve real plugins:
    my-calendar.php sits ~25th alphabetically (a head-20 listing missed it),
    newsletter's bootstrap is plugin.php while admin.php sorts first, and
    podlove/reviews-feed use podlove.php / sb-reviews.php — none of which
    the old name-preference list found, so `wp plugin activate` got a
    header-less module file or a nonexistent path and failed.
    """
    if slug in ("", "wordpress-blog"):
        return ""
    # grep -l lists the top-level files carrying a plugin header without
    # any shell variables — the harness runs commands through a host shell,
    # so a "$f"-style loop would be expanded by the HOST and arrive empty.
    scan = sh(
        f"docker exec {CONTAINER} sh -c "
        + json.dumps(
            "cd /var/www/html/wp-content/plugins/"
            + slug
            + " && grep -l 'Plugin Name:' *.php 2>/dev/null"
        )
    )
    headered = [ln.strip() for ln in (scan.stdout or "").splitlines() if ln.strip()]
    # Preference order among headered files: exact slug name, de-hyphenated
    # slug, then conventional bootstrap names, then whatever is left.
    candidates = [
        f"{slug}.php",
        slug.replace("-", "") + ".php",
        "plugin.php",
        "index.php",
        "main.php",
        "bootstrap.php",
        "init.php",
    ]
    for name in candidates:
        if name in headered:
            return f"{slug}/{name}"
    if headered:
        return f"{slug}/{headered[0]}"
    # Legacy fallback for plugins whose only headered file lives deeper or
    # whose listing conventions differ: first non-index/uninstall file.
    r = sh(
        f"docker exec {CONTAINER} sh -c "
        + json.dumps(
            f"ls /var/www/html/wp-content/plugins/{slug}/*.php 2>/dev/null | head -200"
        )
    )
    files = [ln.strip() for ln in (r.stdout or "").splitlines() if ln.strip()]
    for p in files:
        name = Path(p).name
        if name in ("index.php", "uninstall.php"):
            continue
        return f"{slug}/{name}"
    return f"{slug}/{slug}.php"


def set_active_plugins(files: list[str]) -> None:
    """Write active_plugins on blog 1 and blog 2 via SQL — deactivate others, do not uninstall."""
    serialized = php_serialize_plugins(files)
    if not serialized:
        return
    escaped = serialized.replace("\\", "\\\\").replace("'", "\\'")
    sql(
        "UPDATE wp_options SET option_value='"
        + escaped
        + "' WHERE option_name='active_plugins';"
    )
    sql(
        "UPDATE wp_2_options SET option_value='"
        + escaped
        + "' WHERE option_name='active_plugins';"
    )


def real_activate(boot: str) -> bool:
    """Properly activate a plugin on blog 1 AND blog 2 with activation hooks.

    SQL-writing active_plugins (and silent activate_plugin() inside the
    runner) skip every activation hook. That broke four 2026-09-24 failure
    surfaces: FluentForm/Strong Testimonials never create their per-site
    tables on the subsite (wp_2_fluentform_forms / wp_2_strong_views) so
    subsite admin + singles fatal, Business Directory never initializes its
    permalink settings so its pretty URLs 404, The Events Calendar never
    creates its events page/settings so its calendar hijack lands on a 404.
    `wp plugin activate` runs the real hooks per blog. Activation of an
    already-active plugin is a no-op, which is why isolate_cli() resets to
    the keep-only baseline FIRST so the activation hooks always fire.

    Returns False when the activation hook fatals — some plugins' hooks
    dereference a plugin global that never exists in wp-cli's include scope
    (asgaros-forum's buildDatabase() is the documented case; tutor and
    my-calendar fatal the same way). Those need the SQL + web-warm-up
    recovery in isolate_cli().
    """
    ok = True
    for main_cmd in (
        f"docker exec {CONTAINER} wp plugin activate {boot} --allow-root",
        f"docker exec {CONTAINER} wp plugin activate {boot} --url={SUBSITE_URL} --allow-root",
    ):
        r = sh(main_cmd, timeout=180)
        if r.returncode != 0:
            tag = "subsite" if "--url" in main_cmd else "main"
            print(
                f"    activate-failed {boot} ({tag}): {(r.stderr or r.stdout or '').strip()[-200:]}",
                flush=True,
            )
            ok = False
    return ok


def sql_enable(files: list[str]) -> None:
    """Add plugins to active_plugins on both blogs WITHOUT running hooks.

    Recovery path for plugins whose activation hook fatals under wp-cli
    (see real_activate). `wp plugin activate` updates the option before the
    hook runs, so a fatalled activation usually leaves the plugin marked
    active already; this merge just makes that explicit and idempotent.
    """
    for table, site in (("wp_options", 1), ("wp_2_options", 2)):
        row = sql(f"SELECT option_value FROM {table} WHERE option_name='active_plugins';")
        try:
            current = php_unserialize((row.stdout or "").strip())
        except Exception:
            current = []
        if not isinstance(current, list):
            current = []
        merged = list(dict.fromkeys(list(current) + list(files)))
        serialized = php_serialize_plugins(merged)
        if not serialized:
            continue
        escaped = serialized.replace("\\", "\\\\").replace("'", "\\'")
        sql(
            f"UPDATE {table} SET option_value='"
            + escaped
            + "' WHERE option_name='active_plugins';"
        )


def php_unserialize(text: str):
    """Minimal PHP unserialize for the a:1:{...} active_plugins format."""
    import re

    m = re.match(r"^a:(\d+):\{(.*)\}$", text.strip(), re.S)
    if not m:
        return []
    items = []
    body = m.group(2)
    for part in re.finditer(r's:\d+:"([^"]*)";', body):
        items.append(part.group(1))
    return items


def warmup_web_context(hits: int = 2) -> None:
    """Hit both blogs through Apache so web-context installers can finish.

    wp-cli boots include plugin files in a scope where file-level globals
    (e.g. asgarosforum) never reach the global table, so the plugin's own
    wp_loaded installer fatals in every CLI process. In a real Apache
    request the include happens at true global scope and the installer
    completes (verified live: after two real web hits per blog,
    asgarosforum_db_version=64 lands on both blogs and CLI boots go clean).
    Host curl reaches the lab directly, so these are genuine web contexts.
    """
    for _ in range(hits):
        for url in (MAIN_URL, SUBSITE_URL):
            sh(
                f"curl -s -o /dev/null --max-time 25 {url}",
                timeout=40,
            )


def flush_both_blogs() -> None:
    """Flush rewrite rules on blog 1 and blog 2 in separate boot contexts.

    Each blog has its own permalink structure (main uses /blog/Y/M/D/slug,
    the subsite uses /Y/M/D/slug) and the rewrite rules must be generated
    per blog from a load booted AS that blog — flushing blog 2 from the
    runner's main-blog process stamps main-blog permastruct rules into
    wp_2_options and subsite date-based pretty permalinks 404 afterwards
    (the 2026-09-25 fluentform url_subsite regression). Runs after
    activation so the freshly activated plugin's CPT rules are included.
    """
    for cmd in (
        f"docker exec {CONTAINER} wp eval \"flush_rewrite_rules();\" --allow-root",
        f"docker exec {CONTAINER} wp eval \"flush_rewrite_rules();\" --url={SUBSITE_URL} --allow-root",
    ):
        sh(cmd, timeout=180)


# Lab-environment shims for upstream plugin defects that fatal the harness
# environment itself. These are documented upstream bugs, not product or
# harness bugs; the shim restores the semantics the plugin intended.
UPSTREAM_SHIMS: dict[str, list[str]] = {
    # tutor's Ecommerce AdminMenu counts unpaid orders at admin_menu with
    # `INNER JOIN {prefix}wp_users` — on multisite the users table is global
    # (wp_users), so `wp_2_wp_users` never exists and EVERY subsite admin
    # page 500s (QueryHelper.php:1156 exception). A view aliasing the
    # per-blog name to the real global users table is exactly the multisite
    # semantic tutor meant to query. Verified live 2026-09-25.
    "tutor": [
        "CREATE OR REPLACE VIEW wp_2_wp_users AS SELECT * FROM wp_users;"
    ],
}


def isolate_cli(slug: str) -> None:
    """Deactivate every content plugin, then enable only the one under test.

    The SQL reset drops to the KEEP-only baseline WITHOUT the slug under
    test on purpose: `wp plugin activate` is a NO-OP (zero hooks) for an
    already-active plugin, so pre-marking the slug active via SQL would
    silently skip every activation hook again — the exact defect this
    round fixes (missing per-site schema/settings/pages). Reset to
    keep-only, then real-activate the slug + companions so their hooks
    run on both blogs, then flush both blogs so the activated CPT rules
    are stored per blog.
    """
    set_active_plugins(list(KEEP_FILES))
    boots: list[str] = []
    if slug and slug not in ("wordpress-blog",) and slug not in SKIP_ACTIVATE:
        boot = plugin_bootstrap_file(slug)
        if boot:
            boots.append(boot)
        boots += COMPANIONS.get(slug, [])
    hook_broken: list[str] = []
    for boot in boots:
        if not real_activate(boot):
            hook_broken.append(boot)
    if hook_broken:
        # Activation hooks that fatal under wp-cli (asgaros-forum, tutor,
        # my-calendar on 2026-09-25) leave the install half-done: schema
        # created but the "installed" flag never written, so every later
        # CLI boot re-runs the installer and dies the same way. Recover the
        # way a real site would finish the setup: enable without hooks, then
        # let the plugin's own web-context installer complete on genuine
        # Apache requests (globals exist there), then flush normally.
        print(f"    hook-broken activation, recovering via SQL + web warm-up: {hook_broken}", flush=True)
        sql_enable(hook_broken)
        warmup_web_context()
    for stmt in UPSTREAM_SHIMS.get(slug, []):
        sql(stmt)
    if boots:
        flush_both_blogs()


def deactivate_all_content() -> None:
    set_active_plugins(list(KEEP_FILES))


def run_one(slug: str) -> dict:
    sh(f"docker exec {CONTAINER} mkdir -p /tmp/plugin-coverage")
    sh(f"docker cp {ROOT / 'catalog.json'} {CONTAINER}:/tmp/plugin-coverage/catalog.json")
    sh(f"docker cp {ROOT / 'harness' / 'run-one.php'} {CONTAINER}:/tmp/run-one.php")
    # run-one.php does `require_once __DIR__ . '/cov-relations.php'` — with the
    # runner copied to /tmp, __DIR__ is /tmp, so the support file must land next
    # to it or every slug fatals with "Failed opening required ...cov-relations.php".
    sh(f"docker cp {ROOT / 'harness' / 'cov-relations.php'} {CONTAINER}:/tmp/cov-relations.php")
    isolate_cli(slug)
    r = sh(
        f"docker exec {CONTAINER} wp eval-file /tmp/run-one.php {slug} --allow-root",
        timeout=180,
    )
    blob = (r.stdout or "") + (r.stderr or "")
    fatal = r.returncode != 0 or "There has been a critical error" in blob
    if fatal:
        # Keep the plugin installed; only deactivate so the next test can boot.
        deactivate_all_content()
    # Parse JSON from stdout only. Some plugins (e.g. Ultimate Member) echo an
    # FTP-credentials form and PHP warnings around the emitter output, and stderr
    # warnings are appended after stdout, so bare json.loads(text) sees "extra data".
    # Anchor on the emitter prefix (last occurrence) and raw_decode one object.
    text = r.stdout or ""
    start = text.rfind('{"slug"')
    if start < 0:
        start = text.find("{")
    data = None
    if start >= 0:
        try:
            obj, _ = json.JSONDecoder().raw_decode(text[start:].lstrip())
            if isinstance(obj, dict):
                data = obj
        except json.JSONDecodeError:
            pass
    if not data:
        data = {
            "slug": slug,
            "pass": False,
            "status": "plugin_fatal" if fatal else "runner_error",
            "notes": [
                "Plugin stays installed; it was deactivated after a boot/activate fatal."
                if fatal
                else "runner produced no JSON",
                text[-1500:],
            ],
            "steps": [],
        }
    elif fatal and not data.get("steps"):
        data["status"] = "plugin_fatal"
        data["notes"] = list(data.get("notes") or []) + [
            "Activated then fatals; left installed and deactivated."
        ]
    write_record(data)
    return data


def main(argv: list[str]) -> int:
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    flags = {a for a in argv[1:] if a.startswith("--")}
    slugs = [a for a in argv[1:] if not a.startswith("--")]
    if not slugs:
        slugs = [p["slug"] for p in catalog["plugins"]]
    retry = "--retry" in flags
    slugs = [s for s in slugs if s not in SKIP_ACTIVATE]
    RECORDS.mkdir(exist_ok=True)
    RESULTS.mkdir(exist_ok=True)
    sh(f"docker exec {CONTAINER} mkdir -p /tmp/plugin-coverage")

    tests_only = "--tests-only" in flags
    to_dl = [s for s in slugs if s != "wordpress-blog"]
    downloaded = {}
    if not tests_only:
        print(f"download {len(to_dl)} plugins...", flush=True)
        with ThreadPoolExecutor(max_workers=8) as ex:
            futs = {ex.submit(download_one, s): s for s in to_dl}
            for fut in as_completed(futs):
                info = fut.result()
                downloaded[info["slug"]] = info
                mark = "OK" if info.get("ok") else "FAIL"
                print(f"  {mark} download {info['slug']}", flush=True)

        print("install into lab...", flush=True)
    else:
        print("tests-only: skip download/install", flush=True)
    for slug, info in downloaded.items():
        if slug in SKIP_ACTIVATE:
            write_record(
                {
                    "slug": slug,
                    "name": slug,
                    "pass": False,
                    "status": "plugin_fatal",
                    "notes": ["Quarantined: fatals on plugins_loaded (ec_language.php). Not loaded during coverage."],
                    "steps": [],
                }
            )
            print(f"  SKIP install {slug} plugin_fatal", flush=True)
            continue
        if not info.get("ok"):
            write_record(
                {
                    "slug": slug,
                    "name": next((p["name"] for p in catalog["plugins"] if p["slug"] == slug), slug),
                    "category": next((p["category"] for p in catalog["plugins"] if p["slug"] == slug), ""),
                    "pass": False,
                    "status": "not_installed",
                    "notes": [info.get("error") or "download failed"],
                    "steps": [],
                }
            )
            continue
        inst = install_zip(slug, info["path"])
        if not inst.get("ok"):
            write_record(
                {
                    "slug": slug,
                    "name": slug,
                    "category": "",
                    "pass": False,
                    "status": "not_installed",
                    "notes": [inst.get("error") or inst.get("err") or "install failed"],
                    "steps": [],
                }
            )
            print(f"  FAIL install {slug}", flush=True)
            continue
        print(f"  OK install {slug}", flush=True)

    print("run tests...", flush=True)
    for slug in slugs:
        existing = RESULTS / f"{slug}.json"
        if existing.exists():
            prev = json.loads(existing.read_text(encoding="utf-8"))
            if prev.get("status") == "not_installed":
                print(f"  SKIP {slug} not_installed", flush=True)
                continue
            if prev.get("status") == "plugin_fatal" and not retry:
                print(f"  SKIP {slug} plugin_fatal (installed, left deactivated)", flush=True)
                continue
            if prev.get("status") == "passed" and not retry:
                print(f"  SKIP {slug} already passed", flush=True)
                continue
            if retry and prev.get("status") == "passed":
                print(f"  SKIP {slug} already passed", flush=True)
                continue
        print(f"  TEST {slug}", flush=True)
        data = run_one(slug)
        print(f"    -> {data.get('status')} pass={data.get('pass')}", flush=True)
        refresh_progress(catalog)

    refresh_progress(catalog)
    print("done", PROGRESS)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
