-- Database-backed revisions let browser clients poll for new leader controls.
ALTER TABLE live_worship.live_state
    ADD COLUMN IF NOT EXISTS revision bigint NOT NULL DEFAULT 1;
ALTER TABLE live_worship.live_state
    ADD COLUMN IF NOT EXISTS is_live boolean NOT NULL DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS live_worship_members_role_idx
    ON live_worship.members (role) WHERE active;
CREATE UNIQUE INDEX IF NOT EXISTS live_worship_single_live_service_idx
    ON live_worship.live_state (is_live) WHERE is_live = TRUE;

-- The portal's runtime role is granted access only to this product schema.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mainzworld_runtime') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA live_worship TO mainzworld_runtime';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA live_worship TO mainzworld_runtime';
        EXECUTE 'GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA live_worship TO mainzworld_runtime';
        EXECUTE 'ALTER DEFAULT PRIVILEGES FOR ROLE mainzworld_app IN SCHEMA live_worship GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO mainzworld_runtime';
        EXECUTE 'ALTER DEFAULT PRIVILEGES FOR ROLE mainzworld_app IN SCHEMA live_worship GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO mainzworld_runtime';
    END IF;
END
$$;
