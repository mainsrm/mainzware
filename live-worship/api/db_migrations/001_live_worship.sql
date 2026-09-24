-- Independent data boundary for Live Worship. All product-owned objects live in
-- this schema; no portal or budget tables are reused for application data.
CREATE SCHEMA IF NOT EXISTS live_worship;

CREATE TABLE IF NOT EXISTS live_worship.settings (
    id boolean PRIMARY KEY DEFAULT true CHECK (id),
    display_name text NOT NULL DEFAULT 'PTC Worship',
    updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO live_worship.settings (id, display_name) VALUES (true, 'PTC Worship') ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS live_worship.members (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    identity_id bigint NOT NULL UNIQUE,
    role text NOT NULL CHECK (role IN ('leader', 'choir', 'musician')),
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS live_worship.songs (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title text NOT NULL,
    writer text,
    default_key text,
    lyrics text NOT NULL DEFAULT '',
    sections jsonb NOT NULL DEFAULT '[]'::jsonb,
    ocr_text text NOT NULL DEFAULT '',
    created_by bigint NOT NULL REFERENCES live_worship.members(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS live_worship.song_pages (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    song_id bigint NOT NULL REFERENCES live_worship.songs(id) ON DELETE CASCADE,
    page_number integer NOT NULL CHECK (page_number > 0),
    image_path text NOT NULL,
    mime_type text NOT NULL,
    file_size_bytes bigint NOT NULL CHECK (file_size_bytes >= 0),
    UNIQUE (song_id, page_number)
);

CREATE TABLE IF NOT EXISTS live_worship.setlists (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL,
    service_at timestamptz NOT NULL,
    status text NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by bigint NOT NULL REFERENCES live_worship.members(id),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS live_worship.setlist_songs (
    setlist_id bigint NOT NULL REFERENCES live_worship.setlists(id) ON DELETE CASCADE,
    song_id bigint NOT NULL REFERENCES live_worship.songs(id),
    position integer NOT NULL CHECK (position > 0),
    PRIMARY KEY (setlist_id, song_id),
    UNIQUE (setlist_id, position)
);

CREATE TABLE IF NOT EXISTS live_worship.live_state (
    setlist_id bigint PRIMARY KEY REFERENCES live_worship.setlists(id) ON DELETE CASCADE,
    song_id bigint REFERENCES live_worship.songs(id) ON DELETE SET NULL,
    section_id text,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS live_worship_setlists_service_idx
    ON live_worship.setlists (service_at DESC) WHERE status = 'active';
