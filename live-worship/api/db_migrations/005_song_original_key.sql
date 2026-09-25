ALTER TABLE live_worship.songs ADD COLUMN IF NOT EXISTS original_key text;

-- Earlier versions only stored the current key; preserve that known value.
UPDATE live_worship.songs SET original_key = default_key WHERE original_key IS NULL;
