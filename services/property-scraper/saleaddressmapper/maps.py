from urllib.parse import quote_plus


def google_maps_search_url(address: str) -> str:
    """Build a clickable Google Maps search link for a free-form address.

    Uses the documented Maps URL API (no API key required):
    https://developers.google.com/maps/documentation/urls/get-started#search-action
    """
    return f"https://www.google.com/maps/search/?api=1&query={quote_plus(address)}"
