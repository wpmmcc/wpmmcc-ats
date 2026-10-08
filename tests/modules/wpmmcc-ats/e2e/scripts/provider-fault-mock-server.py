#!/usr/bin/env python3
"""ISS T5 provider fault mock server.

Serves YAML profiles from fixtures/provider-fault-profiles.yaml:
  GET  /health
  GET  /fault/<profile>          — peek next step without consuming (debug)
  POST /fault/<profile>/translate — consume one sequence step (429/503/timeout/200)
  GET  /fault/<profile>/stats     — hits, successes, last status
  POST /reset                     — clear counters / sequence cursors

Also proxies successful translate payloads in the same shape as mock-translate-api
so a Client can point at this port for fault drills.

Default bind: 127.0.0.1:9091
"""
from __future__ import annotations

import json
import os
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Dict
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "fixtures" / "provider-fault-profiles.yaml"
HOST = os.environ.get("E2E_PROVIDER_FAULT_HOST", "127.0.0.1")
PORT = int(os.environ.get("E2E_PROVIDER_FAULT_PORT", "9091"))


def load_profiles() -> Dict[str, Any]:
    text = FIXTURE.read_text(encoding="utf-8")
    try:
        import yaml  # type: ignore

        data = yaml.safe_load(text) or {}
        return dict(data.get("profiles") or {})
    except Exception:
        # Minimal fallback parser for our fixture shape.
        profiles: Dict[str, Any] = {}
        current = None
        for line in text.splitlines():
            if line.startswith("  ") and not line.startswith("    ") and line.strip().endswith(":"):
                current = line.strip().rstrip(":")
                profiles[current] = {
                    "sequence": [],
                    "expect": {"max_writebacks": 1},
                }
        # Hardcode sequences if yaml missing — keep tests runnable.
        profiles.setdefault(
            "rate_limit_then_ok",
            {
                "sequence": [
                    {"status": 429, "body": '{"error":"rate_limited"}', "delay_ms": 0},
                    {"status": 429, "body": '{"error":"rate_limited"}', "delay_ms": 50},
                    {"status": 200, "body": '{"ok":true,"translated_text":"OK"}', "delay_ms": 0},
                ],
                "expect": {"max_writebacks": 1, "retries_min": 2},
            },
        )
        profiles.setdefault(
            "server_error_recovery",
            {
                "sequence": [
                    {"status": 503, "body": '{"error":"unavailable"}', "delay_ms": 0},
                    {"status": 200, "body": '{"ok":true,"translated_text":"OK"}', "delay_ms": 0},
                ],
                "expect": {"max_writebacks": 1},
            },
        )
        profiles.setdefault(
            "duplicate_callback",
            {
                "sequence": [
                    {"status": 200, "body": '{"ok":true,"duplicate":false}', "delay_ms": 0},
                    {"status": 200, "body": '{"ok":true,"duplicate":true}', "delay_ms": 0},
                ],
                "expect": {"max_writebacks": 1, "idempotent": True},
            },
        )
        return profiles


PROFILES = load_profiles()
LOCK = threading.Lock()
STATE: Dict[str, Any] = {
    "cursors": {},  # profile -> next index
    "stats": {},  # profile -> {hits, successes, statuses:[]}
    "writebacks": {},  # client_task_id -> count
}


def stats_for(profile: str) -> Dict[str, Any]:
    with LOCK:
        return dict(STATE["stats"].get(profile) or {"hits": 0, "successes": 0, "statuses": []})


def next_step(profile: str) -> Dict[str, Any]:
    prof = PROFILES.get(profile) or {}
    seq = list(prof.get("sequence") or [])
    with LOCK:
        idx = int(STATE["cursors"].get(profile, 0))
        if not seq:
            step = {"status": 200, "body": '{"ok":true}', "delay_ms": 0}
        elif idx >= len(seq):
            # After sequence exhausted, keep returning last successful-like step.
            step = dict(seq[-1])
            if int(step.get("status") or 0) >= 400 or step.get("timeout"):
                step = {"status": 200, "body": '{"ok":true,"translated_text":"OK"}', "delay_ms": 0}
        else:
            step = dict(seq[idx])
            STATE["cursors"][profile] = idx + 1
        st = STATE["stats"].setdefault(profile, {"hits": 0, "successes": 0, "statuses": []})
        st["hits"] += 1
        status = int(step.get("status") or 0)
        st["statuses"].append(status)
        if status == 200 and not step.get("timeout"):
            st["successes"] += 1
        return step


def translated_text(payload: Dict[str, Any]) -> str:
    """Generic success fallback mirrors the common mock text/HTML contract."""
    text = str(payload.get("text", payload.get("q", "")))
    lang = str(payload.get("target_lang", payload.get("to", "en")))
    wrap = lambda value: f"【{lang}】{value}【/{lang}】"
    html = (str(payload.get("content_format", "")).strip().lower() in
            {"rich_html", "html", "text/html", "application/xhtml+xml"} or
            str(payload.get("input_type", "")).strip().lower() in {"rich_html", "html"} or
            (">" in text and re.search(r"<[a-zA-Z/!]", text)))
    if not html:
        return wrap(text)

    def segment(value: str) -> str:
        trimmed = value.strip()
        if not trimmed:
            return value
        start = value.index(trimmed)
        return value[:start] + wrap(trimmed) + value[start + len(trimmed):]

    output = []
    pending = ""
    in_tag = False
    quote = ""
    for char in text:
        if in_tag:
            output.append(char)
            if quote and char == quote:
                quote = ""
            elif not quote and char in {'"', "'"}:
                quote = char
            elif not quote and char == ">":
                in_tag = False
        elif char == "<":
            output.extend((segment(pending), char))
            pending = ""
            in_tag = True
        else:
            pending += char
    return "".join(output) + segment(pending)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: A003
        print(f"[fault-mock] {self.address_string()} {fmt % args}")

    def _send(self, code: int, body: bytes, headers: Dict[str, str] | None = None) -> None:
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802
        path = urlparse(self.path).path.rstrip("/") or "/"
        if path == "/health":
            body = json.dumps({"ok": True, "identity": "wpmmcc-provider-fault-mock", "profiles": list(PROFILES.keys())}).encode()
            self._send(200, body)
            return
        if path.startswith("/fault/") and path.endswith("/stats"):
            profile = path[len("/fault/") : -len("/stats")].strip("/")
            body = json.dumps(
                {
                    "profile": profile,
                    "expect": (PROFILES.get(profile) or {}).get("expect") or {},
                    "stats": stats_for(profile),
                    "writebacks": dict(STATE["writebacks"]),
                }
            ).encode()
            self._send(200, body)
            return
        if path.startswith("/fault/"):
            profile = path[len("/fault/") :].strip("/")
            if profile not in PROFILES:
                self._send(404, json.dumps({"error": "unknown_profile"}).encode())
                return
            # Peek: do not consume.
            seq = (PROFILES[profile].get("sequence") or [])
            idx = int(STATE["cursors"].get(profile, 0))
            peek = seq[idx] if idx < len(seq) else {"status": 200, "exhausted": True}
            self._send(200, json.dumps({"profile": profile, "next": peek, "idx": idx}).encode())
            return
        self._send(404, json.dumps({"error": "not_found", "path": path}).encode())

    def do_POST(self) -> None:  # noqa: N802
        path = urlparse(self.path).path.rstrip("/") or "/"
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        try:
            payload = json.loads(raw.decode("utf-8") or "{}")
        except Exception:
            payload = {}

        if path == "/reset":
            with LOCK:
                STATE["cursors"].clear()
                STATE["stats"].clear()
                STATE["writebacks"].clear()
            self._send(200, json.dumps({"ok": True}).encode())
            return

        if path == "/simulate/writeback":
            # Count logical writebacks keyed by client_task_id (Client→WP apply).
            task_id = str(payload.get("client_task_id") or "")
            if not task_id:
                self._send(400, json.dumps({"error": "missing_client_task_id"}).encode())
                return
            with LOCK:
                n = int(STATE["writebacks"].get(task_id, 0)) + 1
                STATE["writebacks"][task_id] = n
            self._send(
                200,
                json.dumps({"ok": True, "client_task_id": task_id, "writeback_count": n, "applied": n == 1}).encode(),
            )
            return

        if path.startswith("/fault/") and path.endswith("/translate"):
            profile = path[len("/fault/") : -len("/translate")].strip("/")
            if profile not in PROFILES:
                self._send(404, json.dumps({"error": "unknown_profile"}).encode())
                return
            step = next_step(profile)
            delay = int(step.get("delay_ms") or 0)
            if delay > 0:
                time.sleep(delay / 1000.0)
            if step.get("timeout"):
                # Hang past typical client timeout; still close eventually.
                time.sleep(float(os.environ.get("E2E_FAULT_TIMEOUT_SLEEP", "6")))
                self._send(504, json.dumps({"error": "timeout"}).encode())
                return
            status = int(step.get("status") or 200)
            body_txt = step.get("body")
            if not body_txt:
                body_txt = json.dumps(
                    {
                        "ok": True,
                        "translated_text": translated_text(payload),
                        "profile": profile,
                    }
                )
            headers = {}
            if status == 429:
                headers["Retry-After"] = "1"
            self._send(status, str(body_txt).encode(), headers)
            return

        self._send(404, json.dumps({"error": "not_found", "path": path}).encode())


def main() -> None:
    if not FIXTURE.exists():
        print(f"[fault-mock] WARNING: fixture missing at {FIXTURE}")
    httpd = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"[fault-mock] listening on http://{HOST}:{PORT} profiles={list(PROFILES.keys())}")
    httpd.serve_forever()


if __name__ == "__main__":
    main()
