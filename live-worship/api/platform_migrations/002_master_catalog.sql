CREATE SCHEMA IF NOT EXISTS lw_master;

CREATE TABLE IF NOT EXISTS lw_master.songs (
    id uuid PRIMARY KEY,
    title text NOT NULL,
    writer text,
    original_key text,
    current_version_id uuid,
    source_state text NOT NULL DEFAULT 'curated'
        CHECK (source_state IN ('curated', 'pending', 'retired')),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    updated_on timestamptz NOT NULL DEFAULT now(),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_master_active_song_title_writer_idx
    ON lw_master.songs (lower(title), coalesce(lower(writer), ''))
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS lw_master.song_versions (
    id uuid PRIMARY KEY,
    song_id uuid NOT NULL REFERENCES lw_master.songs(id),
    source_key text,
    lyrics text NOT NULL DEFAULT '',
    sections jsonb NOT NULL DEFAULT '[]'::jsonb,
    content_hash text,
    source_version text,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE INDEX IF NOT EXISTS lw_master_active_song_versions_idx
    ON lw_master.song_versions (song_id, activated_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'lw_master_current_version_fk'
           AND conrelid = 'lw_master.songs'::regclass
    ) THEN
        ALTER TABLE lw_master.songs
            ADD CONSTRAINT lw_master_current_version_fk
            FOREIGN KEY (current_version_id) REFERENCES lw_master.song_versions(id)
            NOT VALID;
    END IF;
END
$$;

CREATE TABLE IF NOT EXISTS lw_master.song_sources (
    id uuid PRIMARY KEY,
    song_id uuid NOT NULL REFERENCES lw_master.songs(id),
    source_url text,
    source_path text,
    source_sha256 text,
    source_type text NOT NULL DEFAULT 'onsong',
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);
