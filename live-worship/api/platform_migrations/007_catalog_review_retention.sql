ALTER TABLE lw_control.catalog_review_queue
    ADD COLUMN IF NOT EXISTS submitted_on timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS submitted_by uuid,
    ADD COLUMN IF NOT EXISTS source_state text NOT NULL DEFAULT 'tenant_active',
    ADD COLUMN IF NOT EXISTS source_deleted_on timestamptz;

ALTER TABLE lw_control.catalog_review_queue
    ADD CONSTRAINT lw_control_catalog_review_source_state_check
    CHECK (source_state IN ('tenant_active', 'tenant_deprovisioning', 'tenant_deprovisioned'));

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_catalog_review_tenant_song_idx
    ON lw_control.catalog_review_queue (tenant_id, tenant_song_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
