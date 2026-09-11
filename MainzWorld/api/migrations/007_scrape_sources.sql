CREATE TABLE IF NOT EXISTS scrape_sources (
    id SERIAL PRIMARY KEY,
    vendor VARCHAR(50) NOT NULL DEFAULT 'SRI',
    label TEXT NOT NULL,
    url TEXT NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_scraped_at TIMESTAMPTZ,
    last_scrape_count INTEGER,
    last_scrape_error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE sale_properties ADD COLUMN IF NOT EXISTS source_id INTEGER REFERENCES scrape_sources(id);
