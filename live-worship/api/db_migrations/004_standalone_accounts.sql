CREATE TABLE IF NOT EXISTS live_worship.standalone_accounts (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username text NOT NULL,
    password_hash text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS live_worship_standalone_username_idx
    ON live_worship.standalone_accounts (lower(username));

ALTER TABLE live_worship.members
    ALTER COLUMN identity_id DROP NOT NULL;

ALTER TABLE live_worship.members
    ADD COLUMN IF NOT EXISTS standalone_account_id bigint REFERENCES live_worship.standalone_accounts(id);

CREATE UNIQUE INDEX IF NOT EXISTS live_worship_members_standalone_account_idx
    ON live_worship.members (standalone_account_id) WHERE standalone_account_id IS NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'live_worship_members_identity_source_check'
           AND conrelid = 'live_worship.members'::regclass
    ) THEN
        ALTER TABLE live_worship.members
            ADD CONSTRAINT live_worship_members_identity_source_check
            CHECK ((identity_id IS NULL) <> (standalone_account_id IS NULL));
    END IF;
END
$$;
