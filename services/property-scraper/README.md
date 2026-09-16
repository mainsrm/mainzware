# Sale Address Mapper

Scrapes tax / sheriff sale property listing sites (starting with
[sriservices.com](https://sriservices.com)) and generates a single HTML report
with a clickable **Google Maps** link for every property address, so you can
quickly eyeball the area instead of manually looking up each address.

## Setup

```bash
cd services/property-scraper
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
playwright install chromium
```

## Usage

```bash
python -m saleaddressmapper.cli "https://sriservices.com/properties?saleId=1356&state=IN&county=Fayette&saleType=Tax%20Sale&timeFrame=All%20Future%20Sale%20Dates" -o output/fayette.html
```

Then open `output/fayette.html` in your browser. Each row has a **View on
Map** button that opens the address in Google Maps in a new tab. Use the
search box and dropdown filters at the top to narrow down by status or sale
group.

Options:
- `-o/--output` — where to write the HTML report (default `output/report.html`)
- `--json` — also dump the raw scraped data to a JSON file
- `--headed` — show the browser while scraping (useful for debugging if a site changes its layout)
- `--max-pages` — safety cap on pagination per sale group (default 200)

## Adding support for another site

The sriservices.com URL is just the first source. Each pop-up county site
(Indiana, Ohio, etc.) may use its own listing platform, so scraping is
implemented per-site as a small "adapter":

1. Add a new module under `saleaddressmapper/sites/`, e.g. `mysite.py`.
2. Implement a class extending `SiteAdapter` (see `sites/base.py`) with a
   `fetch(url, ...) -> list[Property]` method and a `host_markers` tuple
   (substrings of the site's hostname).
3. Register an instance of it in `_ADAPTERS` in `sites/__init__.py`.

The CLI automatically picks the right adapter based on the hostname of the
URL you pass in.

## Notes

- sriservices.com renders its property list client-side (a single-page app),
  so scraping uses Playwright (a real headless browser) rather than a plain
  HTTP request, and pages through results by clicking "Next".
- Google Maps links use the public
  [Maps URL API](https://developers.google.com/maps/documentation/urls/get-started)
  (`https://www.google.com/maps/search/?api=1&query=<address>`), which needs
  no API key.
