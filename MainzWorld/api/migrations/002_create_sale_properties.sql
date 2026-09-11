CREATE TABLE IF NOT EXISTS sale_properties (
    id SERIAL PRIMARY KEY,
    source_url TEXT NOT NULL,
    county TEXT,
    state TEXT,
    address TEXT NOT NULL,
    sale_status TEXT,
    sale_group TEXT,
    map_url TEXT NOT NULL,
    scraped_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_sale_properties_scraped_at ON sale_properties (scraped_at DESC);
