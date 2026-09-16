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
    # SRI's own internal record id and "id / altId" parcel string, captured
    # from its card-detail API so the parcel can link straight to SRI's
    # property-details modal (both are required by that URL; neither alone
    # resolves it). Empty when the API shape isn't matched.
    sri_id: str = ""
    sri_property_id: str = ""

    def to_dict(self) -> dict:
        return asdict(self)
