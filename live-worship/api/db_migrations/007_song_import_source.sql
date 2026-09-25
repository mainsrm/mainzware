ALTER TABLE live_worship.songs
    ADD COLUMN IF NOT EXISTS import_source text,
    ADD COLUMN IF NOT EXISTS import_path text,
    ADD COLUMN IF NOT EXISTS import_sha256 text;

CREATE UNIQUE INDEX IF NOT EXISTS live_worship_songs_import_source_path_idx
    ON live_worship.songs (import_source, import_path)
    WHERE import_source IS NOT NULL AND import_path IS NOT NULL;
