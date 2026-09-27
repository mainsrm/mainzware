-- Keep the lifecycle contract complete when the control schema was created by
-- an earlier development build of migration 001.

ALTER TABLE lw_control.row_status_history
    ADD COLUMN IF NOT EXISTS activated_on timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS activated_by uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000001',
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by uuid;

ALTER TABLE lw_control.audit_events
    ADD COLUMN IF NOT EXISTS activated_on timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS activated_by uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000001',
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by uuid;

ALTER TABLE lw_control.event_outbox
    ADD COLUMN IF NOT EXISTS activated_on timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS activated_by uuid NOT NULL DEFAULT '00000000-0000-0000-0000-000000000001',
    ADD COLUMN IF NOT EXISTS inactivated_on timestamptz,
    ADD COLUMN IF NOT EXISTS inactivated_by uuid;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'lw_control_row_status_history_lifecycle_check'
           AND conrelid = 'lw_control.row_status_history'::regclass
    ) THEN
        ALTER TABLE lw_control.row_status_history
            ADD CONSTRAINT lw_control_row_status_history_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'lw_control_audit_events_lifecycle_check'
           AND conrelid = 'lw_control.audit_events'::regclass
    ) THEN
        ALTER TABLE lw_control.audit_events
            ADD CONSTRAINT lw_control_audit_events_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'lw_control_event_outbox_lifecycle_check'
           AND conrelid = 'lw_control.event_outbox'::regclass
    ) THEN
        ALTER TABLE lw_control.event_outbox
            ADD CONSTRAINT lw_control_event_outbox_lifecycle_check
            CHECK ((inactivated_on IS NULL) = (inactivated_by IS NULL));
    END IF;
END
$$;
