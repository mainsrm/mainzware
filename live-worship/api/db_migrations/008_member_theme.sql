ALTER TABLE live_worship.members
    ADD COLUMN theme text NOT NULL DEFAULT 'light'
    CHECK (theme IN ('light', 'dark'));
