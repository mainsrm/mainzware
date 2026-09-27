-- Replace boolean/status lifecycle decisions with the required four-field rule.
-- This migration is part of the coordinated development cutover. The legacy
-- active/status columns are converted and then removed; application queries
-- use inactivated_on and inactivated_by together.

ALTER TABLE live_worship.settings
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.members
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.songs
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.song_pages
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.setlists
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.setlist_songs
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.live_state
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

ALTER TABLE live_worship.standalone_accounts
    ADD COLUMN IF NOT EXISTS activated_on timestamptz,
    ADD COLUMN IF NOT EXISTS activated_by bigint,
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by bigint;

UPDATE live_worship.settings
   SET activated_on = COALESCE(activated_on, now()),
       activated_by = COALESCE(activated_by, 0)
 WHERE activated_on IS NULL OR activated_by IS NULL;

UPDATE live_worship.members
   SET activated_on = COALESCE(activated_on, created_at),
       activated_by = COALESCE(activated_by, 0),
       inactivated_on = CASE WHEN active = FALSE THEN COALESCE(inactivated_on, now()) ELSE NULL END,
       inactivated_by = CASE WHEN active = FALSE THEN COALESCE(inactivated_by, 0) ELSE NULL END;

UPDATE live_worship.songs
   SET activated_on = COALESCE(activated_on, created_at),
       activated_by = COALESCE(activated_by, created_by, 0);

UPDATE live_worship.song_pages
   SET activated_on = COALESCE(activated_on, now()),
       activated_by = COALESCE(activated_by, 0);

UPDATE live_worship.setlists
   SET activated_on = COALESCE(activated_on, created_at),
       activated_by = COALESCE(activated_by, created_by, 0),
       inactivated_on = CASE WHEN status = 'archived' THEN COALESCE(inactivated_on, now()) ELSE NULL END,
       inactivated_by = CASE WHEN status = 'archived' THEN COALESCE(inactivated_by, 0) ELSE NULL END;

UPDATE live_worship.standalone_accounts
   SET activated_on = COALESCE(activated_on, created_at),
       activated_by = COALESCE(activated_by, 0);

UPDATE live_worship.setlist_songs
   SET activated_on = COALESCE(activated_on, now()),
       activated_by = COALESCE(activated_by, 0);

UPDATE live_worship.live_state
   SET activated_on = COALESCE(activated_on, updated_at, now()),
       activated_by = COALESCE(activated_by, 0);

ALTER TABLE live_worship.settings
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.members
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.songs
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.song_pages
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.setlists
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.setlist_songs
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.live_state
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.standalone_accounts
    ALTER COLUMN activated_on SET NOT NULL,
    ALTER COLUMN activated_by SET NOT NULL,
    ALTER COLUMN activated_on SET DEFAULT now(),
    ALTER COLUMN activated_by SET DEFAULT 0;

ALTER TABLE live_worship.song_pages
    DROP CONSTRAINT IF EXISTS song_pages_song_id_page_number_key;

ALTER TABLE live_worship.setlist_songs
    DROP CONSTRAINT IF EXISTS setlist_songs_setlist_id_position_key;

DROP INDEX IF EXISTS live_worship_members_role_idx;
DROP INDEX IF EXISTS live_worship_setlists_service_idx;

ALTER TABLE live_worship.members DROP COLUMN IF EXISTS active;
ALTER TABLE live_worship.setlists DROP COLUMN IF EXISTS status;

CREATE UNIQUE INDEX IF NOT EXISTS live_worship_active_song_page_number_idx
    ON live_worship.song_pages (song_id, page_number)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS live_worship_active_setlist_song_position_idx
    ON live_worship.setlist_songs (setlist_id, position)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS live_worship_active_members_role_idx
    ON live_worship.members (role)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

CREATE INDEX IF NOT EXISTS live_worship_active_setlists_service_idx
    ON live_worship.setlists (service_at DESC)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_settings_row_lifecycle_check'
           AND conrelid = 'live_worship.settings'::regclass
    ) THEN
        ALTER TABLE live_worship.settings ADD CONSTRAINT live_worship_settings_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_members_row_lifecycle_check'
           AND conrelid = 'live_worship.members'::regclass
    ) THEN
        ALTER TABLE live_worship.members ADD CONSTRAINT live_worship_members_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_songs_row_lifecycle_check'
           AND conrelid = 'live_worship.songs'::regclass
    ) THEN
        ALTER TABLE live_worship.songs ADD CONSTRAINT live_worship_songs_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_song_pages_row_lifecycle_check'
           AND conrelid = 'live_worship.song_pages'::regclass
    ) THEN
        ALTER TABLE live_worship.song_pages ADD CONSTRAINT live_worship_song_pages_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_setlists_row_lifecycle_check'
           AND conrelid = 'live_worship.setlists'::regclass
    ) THEN
        ALTER TABLE live_worship.setlists ADD CONSTRAINT live_worship_setlists_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_setlist_songs_row_lifecycle_check'
           AND conrelid = 'live_worship.setlist_songs'::regclass
    ) THEN
        ALTER TABLE live_worship.setlist_songs ADD CONSTRAINT live_worship_setlist_songs_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_live_state_row_lifecycle_check'
           AND conrelid = 'live_worship.live_state'::regclass
    ) THEN
        ALTER TABLE live_worship.live_state ADD CONSTRAINT live_worship_live_state_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_standalone_accounts_row_lifecycle_check'
           AND conrelid = 'live_worship.standalone_accounts'::regclass
    ) THEN
        ALTER TABLE live_worship.standalone_accounts ADD CONSTRAINT live_worship_standalone_accounts_row_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
END
$$;
