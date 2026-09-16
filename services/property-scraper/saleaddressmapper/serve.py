from __future__ import annotations
import argparse
import functools
import webbrowser
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from pathlib import Path


def serve_report(html_path: Path, port: int = 8123) -> None:
    """Serve html_path's directory over http://localhost so clipboard access works, until Ctrl+C."""
    html_path = Path(html_path).resolve()
    directory = str(html_path.parent)
    handler = functools.partial(SimpleHTTPRequestHandler, directory=directory)
    url = f"http://localhost:{port}/{html_path.name}"
    print(f"Serving {directory} at {url} (Ctrl+C to stop)")
    webbrowser.open(url)
    with ThreadingHTTPServer(("localhost", port), handler) as httpd:
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nStopped.")


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        description="Serve an already-generated report over http://localhost so browser features "
        "that require a secure context (like clipboard copy) work. Use this instead of opening the "
        "HTML file directly as file://."
    )
    parser.add_argument("html_path", help="Path to the generated report HTML file (e.g. output/fayette.html)")
    parser.add_argument("--port", type=int, default=8123, help="Port to serve on (default 8123)")
    args = parser.parse_args(argv)

    html_path = Path(args.html_path).resolve()
    if not html_path.is_file():
        parser.error(f"No such file: {html_path}")

    serve_report(html_path, args.port)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
