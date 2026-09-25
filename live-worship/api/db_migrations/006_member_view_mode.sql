ALTER TABLE live_worship.members
    ADD COLUMN view_mode text NOT NULL DEFAULT 'choir'
    CHECK (view_mode IN ('choir', 'musician'));

UPDATE live_worship.members SET view_mode = 'musician' WHERE role = 'musician';
