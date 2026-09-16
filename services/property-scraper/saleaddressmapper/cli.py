from __future__ import annotations
import argparse
import json
import sys
from pathlib import Path

from .sites import get_adapter_for_url
from .report import render_report
from .serve import serve_report


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        description="Scrape a tax/sheriff sale listing page and generate an HTML "
        "report with clickable Google Maps links for each property address."
    )
    parser.add_argument("url", help="URL of the sale listing page (e.g. a sriservices.com properties search)")
    parser.add_argument("-o", "--output", default="output/report.html", help="HTML report output path")
    parser.add_argument("--json", dest="json_path", help="Optional path to also dump raw scraped data as JSON")
    parser.add_argument("--headed", action="store_true", help="Show the browser window while scraping (debug)")
    parser.add_argument("--max-pages", type=int, default=200, help="Safety cap on pages per sale group")
    parser.add_argument(
        "--serve",
        nargs="?",
        type=int,
        const=8123,
        default=None,
        metavar="PORT",
        help="After generating the report, serve it over http://localhost:PORT (default 8123) instead of "
        "leaving it as a file:// path. Required for the 'Lookup Parcel' clipboard-copy button to work, "
        "since browsers block clipboard access on file:// pages. Runs until Ctrl+C.",
    )
    parser.add_argument(
        "--db",
        action="store_true",
        help="Also write scraped properties into the Mains World Postgres database "
        "(sale_properties table), using the MAINZWORLD_DB_* environment variables.",
    )
    args = parser.parse_args(argv)

    adapter = get_adapter_for_url(args.url)
    properties = adapter.fetch(args.url, headless=not args.headed, max_pages=args.max_pages)

    if not properties:
        print("No properties found. The page layout may have changed or the URL returned no results.", file=sys.stderr)

    if args.json_path:
        Path(args.json_path).parent.mkdir(parents=True, exist_ok=True)
        Path(args.json_path).write_text(
            json.dumps([p.to_dict() for p in properties], indent=2), encoding="utf-8"
        )

    out_path = render_report(properties, args.output)
    print(f"Found {len(properties)} properties.")
    print(f"Report written to {out_path.resolve()}")

    if args.db:
        from .db import save_properties

        written = save_properties(properties, args.url)
        print(f"Wrote {written} properties to the mainzworld database.")

    if args.serve is not None:
        serve_report(out_path, args.serve)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())


if __name__ == "__main__":
    raise SystemExit(main())
