from __future__ import annotations
from pathlib import Path

DEFAULT_PORT = 8123

_LAUNCHER_TEMPLATE = """#!/bin/bash
# Double-click this file to open the sale report in your browser.
# It starts a small local web server in the background (if one isn't already
# running) so the "Lookup Parcel" copy button works, then opens the report.
cd "$(dirname "$0")"
PORT={port}
FILE="{html_name}"

if ! curl -s -o /dev/null "http://localhost:$PORT/$FILE"; then
  nohup python3 -m http.server "$PORT" >/dev/null 2>&1 &
  disown
  sleep 1
fi

open "http://localhost:$PORT/$FILE"
"""


def write_launcher(html_path: str | Path, port: int = DEFAULT_PORT) -> Path:
    """Write a double-clickable macOS launcher (.command file) next to html_path.

    Double-clicking it starts a local server (if not already running) and opens
    the report in the default browser - no terminal/commands needed by the user.
    """
    html_path = Path(html_path)
    launcher_path = html_path.with_name("Open Report.command")
    script = _LAUNCHER_TEMPLATE.format(port=port, html_name=html_path.name)
    launcher_path.write_text(script, encoding="utf-8")
    launcher_path.chmod(0o755)
    return launcher_path
