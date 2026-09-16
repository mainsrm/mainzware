from __future__ import annotations
from typing import List

from ..models import Property
from .base import SiteAdapter
from .sriservices import SriServicesAdapter, hostname

_ADAPTERS: List[SiteAdapter] = [SriServicesAdapter()]


def get_adapter_for_url(url: str) -> SiteAdapter:
    host = hostname(url)
    for adapter in _ADAPTERS:
        if any(marker in host for marker in adapter.host_markers):
            return adapter
    raise ValueError(
        f"No scraper adapter registered for host '{host}'. "
        "Add a new module under saleaddressmapper/sites/ implementing SiteAdapter "
        "and register it in _ADAPTERS."
    )
