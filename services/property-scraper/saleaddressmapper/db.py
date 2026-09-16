"""Optional Postgres persistence for scraped properties (writes into the
`sale_properties` table used by the Mains World hub's /properties page).

Connection is configured via the same MAINZWORLD_DB_* environment variables
used by the PHP API, so both sides point at the same database without
duplicating config.
"""
from __future__ import annotations
import os
from typing import Iterable

from .models import Property


def _connection_params() -> dict:
    return {
        "host": os.environ.get("MAINZWORLD_DB_HOST", "127.0.0.1"),
        "port": os.environ.get("MAINZWORLD_DB_PORT", "5432"),
        "dbname": os.environ.get("MAINZWORLD_DB_NAME", "mainzworld"),
        "user": os.environ.get("MAINZWORLD_DB_USER", "mainzworld_app"),
        "password": os.environ.get("MAINZWORLD_DB_PASSWORD") or None,
    }


def _split_sale_group(sale_group: str) -> tuple[str | None, str | None]:
    # sale_group looks like "Fayette, Indiana — In-person Tax Sale — 09/17/2026".
    head = sale_group.split("—", 1)[0].strip()
    parts = [p.strip() for p in head.split(",", 1)]
    if len(parts) == 2:
        return parts[0] or None, parts[1] or None
    return None, None


def save_properties(properties: Iterable[Property], source_url: str) -> int:
    """Synchronize one source without deleting historical/listed properties."""
    import psycopg2
    from psycopg2.extras import execute_values

    rows = []
    for p in properties:
        county, state = _split_sale_group(p.sale_group)
        rows.append((source_url, county, state, p.address, p.status, p.sale_group, p.maps_url, p.parcel))

    conn = psycopg2.connect(**_connection_params())
    try:
        with conn, conn.cursor() as cur:
            cur.execute(
                "UPDATE sale_properties SET is_active = FALSE WHERE source_url = %s",
                (source_url,),
            )
            find_existing = cur.connection.cursor()
            try:
                for source, county, state, address, status, sale_group, map_url, parcel in rows:
                    find_existing.execute(
                        """
                        SELECT id FROM sale_properties
                        WHERE source_url = %s AND address = %s
                          AND COALESCE(parcel, '') = COALESCE(%s, '')
                        LIMIT 1
                        """,
                        (source, address, parcel),
                    )
                    existing_id = find_existing.fetchone()
                    if existing_id:
                        cur.execute(
                            """
                            UPDATE sale_properties
                            SET county = %s, state = %s, sale_status = %s, sale_group = %s,
                                map_url = %s, parcel = %s, scraped_at = now(),
                                last_seen_at = now(), is_active = TRUE
                            WHERE id = %s
                            """,
                            (county, state, status, sale_group, map_url, parcel, existing_id[0]),
                        )
                    else:
                        cur.execute(
                            """
                            INSERT INTO sale_properties
                                (source_url, county, state, address, sale_status, sale_group, map_url, parcel,
                                 scraped_at, last_seen_at, is_active)
                            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, now(), now(), TRUE)
                            """,
                            (source, county, state, address, status, sale_group, map_url, parcel),
                        )
            finally:
                find_existing.close()
        return len(rows)
    finally:
        conn.close()
