from __future__ import annotations
import re
from typing import List
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright, Page

from ..models import Property
from ..maps import google_maps_search_url
from .base import SiteAdapter

# Matches one property "card" as it appears in the line-by-line text content
# of the page (Playwright's inner_text puts each visual element on its own
# line), e.g.:
#   3059 W Cr 350s,
#   Connersville, Indiana 47331
#   Sale ID:
#   212600001
#   Parcel #:
#   21-08-09-300-004.000-001
#   001-00497-01
#   Sale Date:
#   09/17/2026
#   Sale Time:
#   10:00 AM
#   Show User's Local Time
#   Status:
#   DELINQUENT
#   Sale Type:
#   Tax Sale
#   Method:
#   In-person
#   Register
#   Favorite
#   Map/Details
_CARD_RE = re.compile(
    r"(?P<street>[^\n]+,)\s*"
    r"(?P<citystate>[^\n]+)\s*"
    r"Sale ID:\s*(?P<sale_id>\S+)\s*"
    r"Parcel #:\s*(?P<parcel>.+?)\s*"
    r"Sale Date:\s*(?P<sale_date>\S+)\s*"
    r"Sale Time:\s*(?P<sale_time>[\d:]+\s?[AP]M)\s*Show User's Local Time\s*"
    r"Status:\s*(?P<status>\w+)\s*"
    r"Sale Type:\s*(?P<sale_type>.+?)\s*"
    r"Method:\s*(?P<method>.+?)\s*"
    r"(?:Register\s*Favorite\s*Map/Details)",
    re.DOTALL,
)

_PAGE_COUNT_RE = re.compile(r"Page\s+(\d+)\s+of\s+(\d+)")


def _parse_cards(text: str, sale_group: str, source_url: str) -> List[Property]:
    results = []
    for m in _CARD_RE.finditer(text):
        address = f"{m.group('street').strip()} {m.group('citystate').strip()}"
        results.append(
            Property(
                sale_group=sale_group,
                address=address,
                sale_id=m.group("sale_id").strip(),
                parcel=re.sub(r"\s+", " ", m.group("parcel").strip()),
                sale_date=m.group("sale_date").strip(),
                sale_time=m.group("sale_time").strip(),
                status=m.group("status").strip(),
                sale_type=m.group("sale_type").strip(),
                method=m.group("method").strip(),
                source_url=source_url,
                maps_url=google_maps_search_url(address),
            )
        )
    return results


class SriServicesAdapter(SiteAdapter):
    host_markers = ("sriservices.com",)

    def fetch(self, url: str, *, headless: bool = True, max_pages: int = 200) -> List[Property]:
        properties: List[Property] = []
        seen_sale_ids: set[str] = set()

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=headless)
            page = browser.new_page()
            page.goto(url, wait_until="networkidle")
            page.wait_for_selector("text=/Showing \\d+ propert/i", timeout=30000)

            for heading in page.locator("h3").all():
                sale_group = heading.inner_text().strip()
                # Nearest ancestor that contains this heading's own pager/list.
                container = heading.locator(
                    "xpath=ancestor::*[.//button[normalize-space()='Next']][1]"
                )
                if container.count() == 0:
                    # No pagination for this section; use heading's parent.
                    container = heading.locator("xpath=..")

                for _ in range(max_pages):
                    text = container.inner_text()
                    for prop in _parse_cards(text, sale_group, url):
                        if prop.sale_id and prop.sale_id in seen_sale_ids:
                            continue
                        seen_sale_ids.add(prop.sale_id)
                        properties.append(prop)

                    next_btn = container.get_by_role("button", name="Next")
                    if next_btn.count() == 0:
                        break
                    if next_btn.first.is_disabled():
                        break

                    match = _PAGE_COUNT_RE.search(text)
                    if match and match.group(1) == match.group(2):
                        break

                    next_btn.first.click()
                    page.wait_for_timeout(600)

            browser.close()

        return properties


def hostname(url: str) -> str:
    return urlparse(url).hostname or ""
