#!/usr/bin/env python3
"""Local dev server mirroring nv5.haatetepe.no URL layout."""

from __future__ import annotations

import argparse
import mimetypes
import os
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlparse

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def resolve_path(url_path: str) -> str | None:
    path = unquote(urlparse(url_path).path)

    if path in ("", "/"):
        return None

    if path.startswith("/shared/"):
        return os.path.join(ROOT, "shared", path[len("/shared/") :])

    if path.startswith("/sis/"):
        rel = path[len("/sis/") :]
        if not rel:
            rel = "index.html"
        return os.path.join(ROOT, "sis", rel)

    if path.startswith("/reise/"):
        rel = path[len("/reise/") :]
        if rel.startswith("icons/"):
            return os.path.join(ROOT, "sis", rel)
        if not rel:
            rel = "index.html"
        return os.path.join(ROOT, "reise", rel)

    if path.startswith("/admin/"):
        rel = path[len("/admin/") :]
        if not rel:
            rel = "index.html"
        return os.path.join(ROOT, "admin", rel)

    return None


class Nv5DevHandler(SimpleHTTPRequestHandler):
    def log_message(self, fmt: str, *args) -> None:
        print(f"[dev] {self.address_string()} - {fmt % args}")

    def do_GET(self) -> None:
        path = urlparse(self.path).path
        if path in ("", "/"):
            self.send_response(302)
            self.send_header("Location", "/admin/")
            self.end_headers()
            return

        file_path = resolve_path(self.path)
        if file_path is None:
            self.send_error(404, "Not found")
            return

        if os.path.isdir(file_path):
            file_path = os.path.join(file_path, "index.html")

        if not os.path.isfile(file_path):
            self.send_error(404, "Not found")
            return

        ctype, _ = mimetypes.guess_type(file_path)
        if not ctype:
            ctype = "application/octet-stream"

        try:
            with open(file_path, "rb") as handle:
                data = handle.read()
        except OSError:
            self.send_error(500, "Read error")
            return

        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)


def main() -> None:
    parser = argparse.ArgumentParser(description="NV5 local dev server")
    parser.add_argument("port", nargs="?", type=int, default=8080)
    args = parser.parse_args()

    server = ThreadingHTTPServer(("127.0.0.1", args.port), Nv5DevHandler)
    print(f"NV5 dev server on http://127.0.0.1:{args.port}/")
    print("  /admin/  — drift")
    print("  /sis/    — sanntidstavle")
    print("  /reise/  — planlegger")
    print("  /shared/ — delt kode")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")


if __name__ == "__main__":
    main()
