ALTER TABLE lw_control.tenants
    ADD COLUMN IF NOT EXISTS retention_until timestamptz,
    ADD COLUMN IF NOT EXISTS deprovision_reason text;

ALTER TABLE lw_control.tenants
    DROP CONSTRAINT IF EXISTS tenants_provisioning_state_check;

ALTER TABLE lw_control.tenants
    ADD CONSTRAINT tenants_provisioning_state_check
    CHECK (provisioning_state IN ('provisioning', 'active', 'suspended', 'failed', 'deprovisioning', 'deprovisioned'));

ALTER TABLE lw_control.provisioning_jobs
    ADD COLUMN IF NOT EXISTS operation text;

UPDATE lw_control.provisioning_jobs
   SET operation = 'provision'
 WHERE operation IS NULL;

ALTER TABLE lw_control.provisioning_jobs
    ALTER COLUMN operation SET DEFAULT 'provision',
    ALTER COLUMN operation SET NOT NULL;

ALTER TABLE lw_control.provisioning_jobs
    ADD CONSTRAINT lw_control_provisioning_jobs_operation_check
    CHECK (operation IN ('provision', 'deprovision'));

CREATE INDEX IF NOT EXISTS lw_control_deprovision_jobs_claim_idx
    ON lw_control.provisioning_jobs (operation, job_state, updated_on)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
