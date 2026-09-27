CREATE TABLE IF NOT EXISTS lw_control.tenant_memberships (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    actor_id uuid NOT NULL,
    member_id uuid NOT NULL,
    role text NOT NULL CHECK (role IN ('owner', 'leader', 'choir', 'musician')),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_tenant_membership_idx
    ON lw_control.tenant_memberships (tenant_id, actor_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS lw_control_actor_memberships_idx
    ON lw_control.tenant_memberships (actor_id, activated_on DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
