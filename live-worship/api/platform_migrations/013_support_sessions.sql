CREATE TABLE IF NOT EXISTS lw_control.support_sessions (
    id uuid PRIMARY KEY,
    admin_actor_id uuid NOT NULL,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    mode text NOT NULL DEFAULT 'read_only' CHECK (mode = 'read_only'),
    reason text NOT NULL,
    started_on timestamptz NOT NULL,
    expires_on timestamptz NOT NULL,
    last_seen_on timestamptz NOT NULL,
    ended_on timestamptz,
    ended_by uuid,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK (length(trim(reason)) > 0),
    CHECK ((ended_on IS NULL) = (ended_by IS NULL)),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (expires_on > started_on),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE INDEX IF NOT EXISTS lw_control_support_sessions_admin_idx
    ON lw_control.support_sessions (admin_actor_id, expires_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS lw_control_support_sessions_tenant_idx
    ON lw_control.support_sessions (tenant_id, started_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
