from __future__ import annotations
from abc import ABC, abstractmethod
from typing import List
from ..models import Property


class SiteAdapter(ABC):
    """Interface for a scraper that knows how to pull property listings from
    one particular sale-listing website. Add a new module + adapter here to
    support another county/site."""

    #: substring(s) of the hostname this adapter handles, e.g. "sriservices.com"
    host_markers: tuple[str, ...] = ()

    @abstractmethod
    def fetch(self, url: str, *, headless: bool = True, max_pages: int = 200) -> List[Property]:
        """Fetch and return all properties listed at `url`."""
        raise NotImplementedError
