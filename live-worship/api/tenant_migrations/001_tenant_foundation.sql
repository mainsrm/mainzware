CREATE SCHEMA IF NOT EXISTS {{TENANT_SCHEMA}};

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.settings (
    id boolean PRIMARY KEY DEFAULT true CHECK (id),
    display_name text NOT NULL,
    logo_path text,
    updated_on timestamptz NOT NULL DEFAULT now(),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.members (
    id uuid PRIMARY KEY,
    actor_id uuid NOT NULL,
    role text NOT NULL CHECK (role IN ('owner', 'leader', 'choir', 'musician')),
    view_mode text NOT NULL DEFAULT 'choir' CHECK (view_mode IN ('choir', 'musician')),
    theme text NOT NULL DEFAULT 'light' CHECK (theme IN ('light', 'dark')),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS active_member_actor_idx
    ON {{TENANT_SCHEMA}}.members (actor_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.library_songs (
    id uuid PRIMARY KEY,
    master_song_id uuid REFERENCES lw_master.songs(id),
    master_version_id uuid REFERENCES lw_master.song_versions(id),
    title text NOT NULL,
    writer text,
    original_key text,
    default_key text,
    lyrics text NOT NULL DEFAULT '',
    sections jsonb NOT NULL DEFAULT '[]'::jsonb,
    ocr_text text NOT NULL DEFAULT '',
    origin text NOT NULL CHECK (origin IN ('master_import', 'tenant_created', 'tenant_imported_external')),
    source_content_hash text,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    updated_on timestamptz NOT NULL DEFAULT now(),
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE INDEX IF NOT EXISTS active_library_song_title_idx
    ON {{TENANT_SCHEMA}}.library_songs (lower(title), lower(coalesce(writer, '')))
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.song_revisions (
    id uuid PRIMARY KEY,
    song_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.library_songs(id),
    source_key text,
    lyrics text NOT NULL DEFAULT '',
    sections jsonb NOT NULL DEFAULT '[]'::jsonb,
    content_hash text,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.setlists (
    id uuid PRIMARY KEY,
    name text NOT NULL,
    service_at timestamptz NOT NULL,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE INDEX IF NOT EXISTS active_setlists_service_idx
    ON {{TENANT_SCHEMA}}.setlists (service_at)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.setlist_songs (
    id uuid PRIMARY KEY,
    setlist_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.setlists(id),
    song_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.library_songs(id),
    position integer NOT NULL CHECK (position > 0),
    key_override text,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS active_setlist_song_position_idx
    ON {{TENANT_SCHEMA}}.setlist_songs (setlist_id, position)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.live_state (
    id uuid PRIMARY KEY,
    setlist_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.setlists(id),
    song_id uuid REFERENCES {{TENANT_SCHEMA}}.library_songs(id),
    section_id text,
    control_mode text NOT NULL DEFAULT 'manual' CHECK (control_mode IN ('manual', 'automatic')),
    controller_member_id uuid REFERENCES {{TENANT_SCHEMA}}.members(id),
    controller_token text,
    is_live boolean NOT NULL DEFAULT false,
    revision bigint NOT NULL DEFAULT 1,
    updated_on timestamptz NOT NULL DEFAULT now(),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS active_live_state_setlist_idx
    ON {{TENANT_SCHEMA}}.live_state (setlist_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS one_live_setlist_idx
    ON {{TENANT_SCHEMA}}.live_state (is_live)
    WHERE is_live = true AND inactivated_on IS NULL AND inactivated_by IS NULL;

-- Future feature structures are provisioned up front and entitlement-gated.
CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.conversations (
    id uuid PRIMARY KEY,
    name text,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.conversation_members (
    id uuid PRIMARY KEY,
    conversation_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.conversations(id),
    member_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.members(id),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS active_conversation_member_idx
    ON {{TENANT_SCHEMA}}.conversation_members (conversation_id, member_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.messages (
    id uuid PRIMARY KEY,
    conversation_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.conversations(id),
    sender_member_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.members(id),
    body text NOT NULL,
    sent_on timestamptz NOT NULL DEFAULT now(),
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.team_schedules (
    id uuid PRIMARY KEY,
    name text NOT NULL,
    starts_on timestamptz NOT NULL,
    ends_on timestamptz,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.rotation_rules (
    id uuid PRIMARY KEY,
    name text NOT NULL,
    rule jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_by uuid NOT NULL,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.rotation_assignments (
    id uuid PRIMARY KEY,
    rotation_rule_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.rotation_rules(id),
    member_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.members(id),
    starts_on timestamptz NOT NULL,
    ends_on timestamptz,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.device_registrations (
    id uuid PRIMARY KEY,
    member_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.members(id),
    platform text NOT NULL CHECK (platform IN ('web', 'ios', 'android')),
    push_token text,
    last_seen_on timestamptz,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.notification_preferences (
    id uuid PRIMARY KEY,
    member_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.members(id),
    preferences jsonb NOT NULL DEFAULT '{}'::jsonb,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);

CREATE UNIQUE INDEX IF NOT EXISTS active_notification_preferences_member_idx
    ON {{TENANT_SCHEMA}}.notification_preferences (member_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE TABLE IF NOT EXISTS {{TENANT_SCHEMA}}.notifications (
    id uuid PRIMARY KEY,
    member_id uuid NOT NULL REFERENCES {{TENANT_SCHEMA}}.members(id),
    notification_type text NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    read_on timestamptz,
    activated_on timestamptz NOT NULL,
    activated_by uuid NOT NULL,
    inactivated_on timestamptz,
    inactivated_by uuid,
    CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL)),
    CHECK (inactivated_on IS NULL OR inactivated_on >= activated_on)
);
