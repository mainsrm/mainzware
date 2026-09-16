-- Decouples scraping from the HTTP request: POST marks a source 'queued', a
-- detached worker flips it 'running' then 'idle'/'error', and the frontend
-- polls this state. Additive only (no changes to existing columns/rows).
ALTER TABLE scrape_sources ADD COLUMN IF NOT EXISTS scrape_state TEXT NOT NULL DEFAULT 'idle';
ALTER TABLE scrape_sources ADD COLUMN IF NOT EXISTS scrape_queued_at TIMESTAMPTZ;

ALTER TABLE scrape_sources DROP CONSTRAINT IF EXISTS scrape_sources_scrape_state_check;
ALTER TABLE scrape_sources ADD CONSTRAINT scrape_sources_scrape_state_check
    CHECK (scrape_state IN ('idle', 'queued', 'running', 'error'));
