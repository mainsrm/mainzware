ALTER TABLE {{TENANT_SCHEMA}}.settings
    ADD COLUMN IF NOT EXISTS logo_mime_type text,
    ADD COLUMN IF NOT EXISTS logo_file_size_bytes bigint,
    ADD COLUMN IF NOT EXISTS updated_at timestamptz;

UPDATE {{TENANT_SCHEMA}}.settings
   SET updated_at = COALESCE(updated_at, updated_on)
 WHERE updated_at IS NULL;

ALTER TABLE {{TENANT_SCHEMA}}.settings
    ALTER COLUMN updated_at SET DEFAULT now();
