ALTER TABLE property_list_items
    ADD COLUMN IF NOT EXISTS address TEXT,
    ADD COLUMN IF NOT EXISTS county TEXT,
    ADD COLUMN IF NOT EXISTS state TEXT,
    ADD COLUMN IF NOT EXISTS sale_status TEXT,
    ADD COLUMN IF NOT EXISTS sale_group TEXT,
    ADD COLUMN IF NOT EXISTS parcel TEXT,
    ADD COLUMN IF NOT EXISTS map_url TEXT,
    ADD COLUMN IF NOT EXISTS source_url TEXT,
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ;

UPDATE property_list_items pli
SET address = p.address,
    county = p.county,
    state = p.state,
    sale_status = p.sale_status,
    sale_group = p.sale_group,
    parcel = p.parcel,
    map_url = p.map_url,
    source_url = p.source_url
FROM sale_properties p
WHERE p.id = pli.property_id AND pli.address IS NULL;
