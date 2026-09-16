from dataclasses import dataclass, asdict


@dataclass
class Property:
    """A single tax/sheriff sale listing scraped from a sale site."""

    sale_group: str  # e.g. "Fayette, Indiana — In-person Tax Sale — 09/17/2026"
    address: str
    sale_id: str = ""
    parcel: str = ""
    sale_date: str = ""
    sale_time: str = ""
    status: str = ""
    sale_type: str = ""
    method: str = ""
    source_url: str = ""
    maps_url: str = ""

    def to_dict(self) -> dict:
        return asdict(self)
