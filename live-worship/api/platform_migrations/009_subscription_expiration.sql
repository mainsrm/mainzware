ALTER TABLE lw_control.tenant_subscriptions
    ADD COLUMN IF NOT EXISTS expires_on timestamptz;
