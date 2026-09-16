-- Saved-list snapshots didn't carry the SRI detail-link fields added in 023,
-- so the parcel link degraded to plain text there. Backfill from live rows;
-- archived-only items keep NULL (matches how the other snapshot columns work).
ALTER TABLE property_list_items ADD COLUMN IF NOT EXISTS sri_id TEXT;
ALTER TABLE property_list_items ADD COLUMN IF NOT EXISTS sri_property_id TEXT;

UPDATE property_list_items pli
SET sri_id = p.sri_id,
    sri_property_id = p.sri_property_id
FROM sale_properties p
WHERE p.id = pli.property_id AND pli.sri_id IS NULL;
