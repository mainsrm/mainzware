CREATE TABLE IF NOT EXISTS gis_sources (
    id SERIAL PRIMARY KEY,
    county VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    url TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (county, state)
);

INSERT INTO gis_sources (county,state,url) VALUES
 ('Fayette','IN','https://fayettein.wthgis.com/'),
 ('Franklin','IN','https://franklinin.wthgis.com/')
ON CONFLICT (county,state) DO UPDATE SET url = EXCLUDED.url;
