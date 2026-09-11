-- Persist the scraped parcel number so the hub's /properties page can offer a
-- "Lookup Parcel" action (copy parcel -> open county GIS) like the standalone report.
ALTER TABLE sale_properties ADD COLUMN IF NOT EXISTS parcel TEXT;