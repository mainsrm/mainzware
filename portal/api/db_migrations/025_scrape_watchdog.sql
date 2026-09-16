-- Tracks when a source entered 'running' so a fresh worker can tell a stuck
-- row (previous worker was killed mid-run, not just timed out) from one that
-- is genuinely in flight. See SaleScraper::reclaimStaleRunning().
ALTER TABLE scrape_sources ADD COLUMN IF NOT EXISTS scrape_started_at TIMESTAMPTZ;
