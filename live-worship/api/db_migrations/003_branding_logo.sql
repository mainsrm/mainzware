ALTER TABLE live_worship.settings
    ADD COLUMN IF NOT EXISTS logo_path text,
    ADD COLUMN IF NOT EXISTS logo_mime_type text,
    ADD COLUMN IF NOT EXISTS logo_file_size_bytes bigint;

ALTER TABLE live_worship.settings
    ADD CONSTRAINT live_worship_logo_size_nonnegative
        CHECK (logo_file_size_bytes IS NULL OR logo_file_size_bytes >= 0);
