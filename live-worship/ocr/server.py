#!/usr/bin/env python3
"""Private, app-scoped PaddleOCR HTTP service for Live Worship."""

from __future__ import annotations

import base64
import json
import os
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


APP_DIR = Path(__file__).resolve().parent
CACHE_DIR = Path(os.environ.get("PADDLE_PDX_CACHE_HOME", APP_DIR / ".cache"))
CACHE_DIR.mkdir(parents=True, exist_ok=True)
os.environ.setdefault("PADDLE_PDX_CACHE_HOME", str(CACHE_DIR))

MAX_IMAGE_BYTES = 16 * 1024 * 1024
MAX_REQUEST_BYTES = 24 * 1024 * 1024
INFERENCE_LOCK = threading.Lock()

import paddle  # noqa: E402

# On macOS, Paddle's native crash handler can hang while handling a shutdown
# signal, leaving an orphan process holding the HTTP port after dev exits.
if sys.platform == "darwin":
    paddle.disable_signal_handler()

from paddleocr import PaddleOCR  # noqa: E402


ocr = PaddleOCR(
    lang="en",
    device="cpu",
    # PaddlePaddle 3.3.0's oneDNN/PIR CPU inference path raises
    # ConvertPirAttribute2RuntimeAttribute for OCR detection models.
    enable_mkldnn=False,
    use_doc_orientation_classify=True,
    use_doc_unwarping=True,
    use_textline_orientation=True,
)


def recognized_lines(image_bytes: bytes) -> list[dict[str, Any]]:
    with tempfile.NamedTemporaryFile(suffix=".jpg") as image_file:
        image_file.write(image_bytes)
        image_file.flush()
        with INFERENCE_LOCK:
            results = ocr.predict(image_file.name)

    lines: list[dict[str, Any]] = []
    for result in results:
        payload = result.json or {}
        data = payload.get("res", payload)
        texts = data.get("rec_texts", [])
        scores = data.get("rec_scores", [])
        boxes = data.get("rec_boxes", [])
        for index, text in enumerate(texts):
            text = str(text or "").strip()
            if not text:
                continue
            box = boxes[index] if index < len(boxes) else None
            left = float(box[0]) if box is not None and len(box) >= 4 else 0.0
            top = float(box[1]) if box is not None and len(box) >= 4 else float(index)
            right = float(box[2]) if box is not None and len(box) >= 4 else left
            bottom = float(box[3]) if box is not None and len(box) >= 4 else top
            score = float(scores[index]) if index < len(scores) else 0.0
            lines.append({
                "text": text,
                "confidence": score,
                "box": [left, top, right, bottom],
            })

    # OCR can return text regions in model order, which is not always reading
    # order. The photographed charts are single-column, so sort top-to-bottom.
    lines.sort(key=lambda line: ((line["box"][1] + line["box"][3]) / 2, line["box"][0]))
    return lines


class Handler(BaseHTTPRequestHandler):
    server_version = "LiveWorshipOCR/1.0"

    def do_GET(self) -> None:  # noqa: N802
        if self.path != "/health":
            self.send_error(404)
            return
        self._send_json(200, {"ok": True})

    def do_POST(self) -> None:  # noqa: N802
        if self.path != "/ocr":
            self.send_error(404)
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length < 1 or length > MAX_REQUEST_BYTES:
                self._send_json(413, {"error": "The image must be smaller than 16 MB."})
                return
            request = json.loads(self.rfile.read(length))
            image_bytes = base64.b64decode(request.get("image", ""), validate=True)
            if not image_bytes or len(image_bytes) > MAX_IMAGE_BYTES:
                self._send_json(413, {"error": "The image must be smaller than 16 MB."})
                return
            self._send_json(200, {"lines": recognized_lines(image_bytes)})
        except (ValueError, json.JSONDecodeError):
            self._send_json(400, {"error": "The image request was invalid."})
        except Exception:
            self._send_json(500, {"error": "PaddleOCR could not process this image."})

    def _send_json(self, status: int, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt: str, *args: Any) -> None:
        # Avoid logging request contents or uploaded song data.
        super().log_message(fmt, *args)


if __name__ == "__main__":
    host = os.environ.get("LIVE_WORSHIP_OCR_HOST", "127.0.0.1")
    port = int(os.environ.get("LIVE_WORSHIP_OCR_PORT", "8765"))
    ThreadingHTTPServer((host, port), Handler).serve_forever()
