-- Lets the parcel number link to SRI's own property-details modal. Both
-- columns come from SRI's card-detail API (captured during scraping) and are
-- required together to open that modal; neither works alone.
ALTER TABLE sale_properties ADD COLUMN IF NOT EXISTS sri_id TEXT;
ALTER TABLE sale_properties ADD COLUMN IF NOT EXISTS sri_property_id TEXT;
