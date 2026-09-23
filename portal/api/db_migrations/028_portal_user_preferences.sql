-- Per-user portal settings belong to the portal product schema. The user id
-- references shared auth accounts and cascades when an account is removed.
CREATE TABLE IF NOT EXISTS portal.user_preferences (
    user_id     INTEGER PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    color_mode  TEXT NOT NULL DEFAULT 'light' CHECK (color_mode IN ('light', 'dark')),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
