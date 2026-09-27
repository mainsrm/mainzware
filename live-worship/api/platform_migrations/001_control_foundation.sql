CREATE SCHEMA IF NOT EXISTS lw_control;

CREATE TABLE IF NOT EXISTS lw_control.tenant_schema_migrations (
    schema_name text NOT NULL,
    filename text NOT NULL,
    applied_on timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (schema_name, filename)
);

CREATE TABLE IF NOT EXISTS lw_control.tenants (
    id uuid PRIMARY KEY,
    slug text NOT NULL,
    display_name text NOT NULL,
    schema_name text NOT NULL UNIQUE,
    storage_prefix text NOT NULL UNIQUE,
    provisioning_state text NOT NULL DEFAULT 'provisioning'
        CHECK (provisioning_state IN ('provisioning', 'active', 'suspended', 'failed')),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    updated_on timestamptz NOT NULL DEFAULT now(),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on),
    CHECK (slug ~ '^[a-z0-9]+(?:-[a-z0-9]+)*$'),
    CHECK (schema_name ~ '^lw_t_[0-9a-f]+$')
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_tenant_slug_idx
    ON lw_control.tenants (lower(slug))
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS lw_control.row_status_history (
    id uuid PRIMARY KEY,
    table_name text NOT NULL,
    row_id text NOT NULL,
    action text NOT NULL CHECK (action IN ('activated', 'inactivated', 'reactivated')),
    performed_by uuid NOT NULL,
    performed_on timestamptz NOT NULL DEFAULT now(),
    reason text,
    activated_on timestamptz NOT NULL DEFAULT now(),
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL))
);

CREATE TABLE IF NOT EXISTS lw_control.plans (
    id uuid PRIMARY KEY,
    code text NOT NULL UNIQUE,
    display_name text NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS lw_control.features (
    id uuid PRIMARY KEY,
    code text NOT NULL UNIQUE,
    display_name text NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS lw_control.plan_features (
    id uuid PRIMARY KEY,
    plan_id uuid NOT NULL REFERENCES lw_control.plans(id),
    feature_id uuid NOT NULL REFERENCES lw_control.features(id),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_plan_feature_idx
    ON lw_control.plan_features (plan_id, feature_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS lw_control.tenant_subscriptions (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    plan_id uuid NOT NULL REFERENCES lw_control.plans(id),
    provider text,
    provider_subscription_id text,
    subscription_state text NOT NULL DEFAULT 'free'
        CHECK (subscription_state IN ('free', 'trialing', 'active', 'past_due', 'canceled', 'expired')),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_subscription_idx
    ON lw_control.tenant_subscriptions (tenant_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS lw_control.tenant_entitlements (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    feature_id uuid NOT NULL REFERENCES lw_control.features(id),
    source text NOT NULL CHECK (source IN ('plan', 'grant', 'trial', 'override')),
    expires_on timestamptz,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS lw_control_active_entitlement_idx
    ON lw_control.tenant_entitlements (tenant_id, feature_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS lw_control.provisioning_jobs (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    job_state text NOT NULL DEFAULT 'queued'
        CHECK (job_state IN ('queued', 'running', 'complete', 'failed')),
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    last_error text,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS lw_control.catalog_review_queue (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES lw_control.tenants(id),
    tenant_song_id uuid NOT NULL,
    title text NOT NULL,
    writer text,
    content_hash text,
    content_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    review_state text NOT NULL DEFAULT 'pending'
        CHECK (review_state IN ('pending', 'approved', 'rejected', 'duplicate', 'ignored')),
    reviewed_by uuid,
    reviewed_on timestamptz,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS lw_control.audit_events (
    id uuid PRIMARY KEY,
    tenant_id uuid,
    actor_id uuid,
    event_type text NOT NULL,
    occurred_on timestamptz NOT NULL DEFAULT now(),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    activated_on timestamptz NOT NULL DEFAULT now(),
    activated_by uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000001',
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL))
);

CREATE TABLE IF NOT EXISTS lw_control.event_outbox (
    id uuid PRIMARY KEY,
    tenant_id uuid,
    event_type text NOT NULL,
    aggregate_type text NOT NULL,
    aggregate_id uuid,
    occurred_on timestamptz NOT NULL DEFAULT now(),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    published_on timestamptz,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    last_error text,
    activated_on timestamptz NOT NULL DEFAULT now(),
    activated_by uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000001',
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL))
);

-- The zero-one UUID is the non-user system actor used by seed data and migrations.
INSERT INTO lw_control.plans (id, code, display_name, activated_on, activated_by)
VALUES
    ('00000000-0000-0000-0000-000000000101', 'live-worship', 'Live Worship', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000102', 'live-worship-pro', 'Live Worship Pro', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000103', 'live-worship-360', 'Live Worship 360', now(), '00000000-0000-0000-0000-000000000001')
ON CONFLICT (code) DO NOTHING;

INSERT INTO lw_control.features (id, code, display_name, activated_on, activated_by)
VALUES
    ('00000000-0000-0000-0000-000000000201', 'song-library', 'Song library', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000202', 'master-catalog', 'Master catalog', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000203', 'setlists', 'Setlists', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000204', 'live-control', 'Live control', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000205', 'advanced-import', 'Advanced song import', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000206', 'messaging', 'Team messaging', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000207', 'team-rotations', 'Team rotations', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000208', 'scheduling', 'Team scheduling', now(), '00000000-0000-0000-0000-000000000001'),
    ('00000000-0000-0000-0000-000000000209', 'push-notifications', 'Push notifications', now(), '00000000-0000-0000-0000-000000000001')
ON CONFLICT (code) DO NOTHING;

INSERT INTO lw_control.plan_features (id, plan_id, feature_id, activated_on, activated_by)
SELECT '00000000-0000-0000-0000-000000000301'::uuid, p.id, f.id, now(), '00000000-0000-0000-0000-000000000001'::uuid
FROM lw_control.plans p, lw_control.features f
WHERE p.code = 'live-worship'
  AND f.code IN ('song-library', 'master-catalog', 'setlists', 'live-control')
ON CONFLICT DO NOTHING;

INSERT INTO lw_control.plan_features (id, plan_id, feature_id, activated_on, activated_by)
SELECT ('00000000-0000-0000-0000-0000000004' || lpad(row_number() OVER (ORDER BY f.code)::text, 2, '0'))::uuid,
       p.id, f.id, now(), '00000000-0000-0000-0000-000000000001'::uuid
FROM lw_control.plans p, lw_control.features f
WHERE p.code = 'live-worship-pro'
  AND f.code IN ('song-library', 'master-catalog', 'setlists', 'live-control', 'advanced-import')
ON CONFLICT DO NOTHING;

INSERT INTO lw_control.plan_features (id, plan_id, feature_id, activated_on, activated_by)
SELECT ('00000000-0000-0000-0000-0000000005' || lpad(row_number() OVER (ORDER BY f.code)::text, 2, '0'))::uuid,
       p.id, f.id, now(), '00000000-0000-0000-0000-000000000001'::uuid
FROM lw_control.plans p, lw_control.features f
WHERE p.code = 'live-worship-360'
ON CONFLICT DO NOTHING;
