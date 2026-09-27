CREATE TABLE IF NOT EXISTS lw_control.login_activity (
    id uuid PRIMARY KEY,
    actor_id uuid,
    tenant_id uuid,
    event_type text NOT NULL CHECK (event_type IN ('login', 'logout', 'tenant_context')),
    outcome text NOT NULL CHECK (outcome IN ('success', 'failure')),
    auth_mode text NOT NULL CHECK (auth_mode IN ('mainzworld', 'standalone', 'mobile')),
    client_kind text NOT NULL DEFAULT 'web' CHECK (client_kind IN ('web', 'mobile', 'unknown')),
    username_hint text,
    failure_code text,
    occurred_on timestamptz NOT NULL DEFAULT now(),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK (length(coalesce(username_hint, '')) <= 120),
    CHECK (length(coalesce(failure_code, '')) <= 80),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL))
);

CREATE INDEX IF NOT EXISTS lw_control_login_activity_occurred_idx
    ON lw_control.login_activity (occurred_on DESC, id DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS lw_control_login_activity_actor_idx
    ON lw_control.login_activity (actor_id, occurred_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS lw_control_login_activity_tenant_idx
    ON lw_control.login_activity (tenant_id, occurred_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
