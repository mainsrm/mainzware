CREATE TABLE IF NOT EXISTS lw_control.tenant_invitations (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    role text NOT NULL CHECK (role IN ('leader', 'choir', 'musician')),
    token_hash text NOT NULL UNIQUE,
    created_by uuid NOT NULL,
    expires_on timestamptz NOT NULL,
    accepted_by uuid,
    accepted_on timestamptz,
    revoked_by uuid,
    revoked_on timestamptz,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((accepted_on IS NULL) = (accepted_by IS NULL)),
    CHECK ((revoked_on IS NULL) = (revoked_by IS NULL)),
    CHECK (NOT (accepted_on IS NOT NULL AND revoked_on IS NOT NULL)),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE INDEX IF NOT EXISTS lw_control_tenant_invitations_active_idx
    ON lw_control.tenant_invitations (tenant_id, expires_on)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS lw_control_tenant_invitations_accepted_idx
    ON lw_control.tenant_invitations (accepted_by, accepted_on)
    WHERE accepted_by IS NOT NULL;
