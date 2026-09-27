CREATE TABLE IF NOT EXISTS lw_control.actors (
    id uuid PRIMARY KEY,
    identity_provider text NOT NULL,
    external_subject text NOT NULL,
    display_name text,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK (length(trim(identity_provider)) > 0),
    CHECK (length(trim(external_subject)) > 0),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_actor_identity_idx
    ON lw_control.actors (identity_provider, external_subject)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

INSERT INTO lw_control.actors
    (id, identity_provider, external_subject, display_name, activated_on, activated_by)
VALUES
    ('00000000-0000-0000-0000-000000000001', 'system', 'system', 'MainzWare system', now(), '00000000-0000-0000-0000-000000000001')
ON CONFLICT (id) DO NOTHING;
