-- Live Worship can be guided manually by any leader or automatically by one
-- leader device running the voice guide. The controller lease prevents two
-- recognition devices from publishing competing section changes.
ALTER TABLE live_worship.live_state
    ADD COLUMN IF NOT EXISTS control_mode text NOT NULL DEFAULT 'manual'
    CHECK (control_mode IN ('manual', 'automatic'));

ALTER TABLE live_worship.live_state
    ADD COLUMN IF NOT EXISTS controller_member_id bigint
    REFERENCES live_worship.members(id) ON DELETE SET NULL;

ALTER TABLE live_worship.live_state
    ADD COLUMN IF NOT EXISTS controller_token text;

CREATE INDEX IF NOT EXISTS live_worship_live_controller_idx
    ON live_worship.live_state (controller_member_id)
    WHERE controller_member_id IS NOT NULL;
