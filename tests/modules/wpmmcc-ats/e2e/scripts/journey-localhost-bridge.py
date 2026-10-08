#!/usr/bin/env python3
"""IPv6-loopback to IPv4-loopback TCP bridge for the journey lane (批 O6).

`*.localhost` names resolve to ::1 (systemd-resolved) and PASS the WP
plugin's site-verification domain validation (no IPs, no bare `localhost`
— `blog.localhost` is a dotted, RFC-valid name). But the lab WP docker
slots publish their ports on 127.0.0.1 only, so [::1]:<port> is refused.

This bridge listens on [::1]:<port> and forwards to 127.0.0.1:<port> so
the lane's blog.localhost siteurl works for every party (browser, client
process, server's verification fetch) without /etc/hosts or root.

Usage: BRIDGE_PORT=9180 python3 journey-localhost-bridge.py
"""
from __future__ import annotations

import os
import socket
import threading


def _pipe(a: socket.socket, b: socket.socket) -> None:
    try:
        while True:
            data = a.recv(65536)
            if not data:
                break
            b.sendall(data)
    except OSError:
        pass
    finally:
        for s in (a, b):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def _handle(client: socket.socket, target: tuple[str, int]) -> None:
    try:
        upstream = socket.create_connection(target)
    except OSError:
        client.close()
        return
    t1 = threading.Thread(target=_pipe, args=(client, upstream), daemon=True)
    t2 = threading.Thread(target=_pipe, args=(upstream, client), daemon=True)
    t1.start()
    t2.start()
    t1.join()
    t2.join()


def main() -> None:
    port = int(os.environ.get("BRIDGE_PORT", "9180"))
    target_host = os.environ.get("BRIDGE_TARGET_HOST", "127.0.0.1")
    target_port = int(os.environ.get("BRIDGE_TARGET_PORT", str(port)))
    srv = socket.socket(socket.AF_INET6, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("::1", port))
    srv.listen(128)
    print(f"[bridge] [::1]:{port} -> {target_host}:{target_port}", flush=True)
    while True:
        client, _ = srv.accept()
        threading.Thread(
            target=_handle, args=(client, (target_host, target_port)), daemon=True
        ).start()


if __name__ == "__main__":
    main()
