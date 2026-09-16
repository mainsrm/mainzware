-- Dedupe case-variant rows (e.g. "Franklin" vs "franklin") before enforcing
-- case-insensitive uniqueness, keeping the newest row per canonical key.
WITH ranked AS (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY lower(county), lower(state)
               ORDER BY id DESC
           ) AS rn
    FROM gis_sources
)
DELETE FROM gis_sources WHERE id IN (SELECT id FROM ranked WHERE rn > 1);

-- Canonicalize display case: county Title Case, state USPS code uppercase.
UPDATE gis_sources SET county = initcap(county), state = upper(state);

ALTER TABLE gis_sources DROP CONSTRAINT IF EXISTS gis_sources_county_state_key;
CREATE UNIQUE INDEX IF NOT EXISTS gis_sources_county_state_ci_key
    ON gis_sources (lower(county), lower(state));
