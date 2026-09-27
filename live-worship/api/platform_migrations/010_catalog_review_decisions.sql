ALTER TABLE lw_control.catalog_review_queue
    ADD COLUMN IF NOT EXISTS master_song_id uuid,
    ADD COLUMN IF NOT EXISTS review_note text;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'lw_control_catalog_review_master_song_fk'
           AND conrelid = 'lw_control.catalog_review_queue'::regclass
    ) THEN
        ALTER TABLE lw_control.catalog_review_queue
            ADD CONSTRAINT lw_control_catalog_review_master_song_fk
            FOREIGN KEY (master_song_id) REFERENCES lw_master.songs(id);
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS lw_control_catalog_review_pending_idx
    ON lw_control.catalog_review_queue (review_state, submitted_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
