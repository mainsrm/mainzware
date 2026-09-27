ALTER TABLE lw_control.provisioning_jobs
    ADD COLUMN IF NOT EXISTS updated_on timestamptz;

UPDATE lw_control.provisioning_jobs
   SET updated_on = COALESCE(updated_on, activated_on, now())
 WHERE updated_on IS NULL;

ALTER TABLE lw_control.provisioning_jobs
    ALTER COLUMN updated_on SET DEFAULT now(),
    ALTER COLUMN updated_on SET NOT NULL;

CREATE INDEX IF NOT EXISTS lw_control_provisioning_jobs_claim_idx
    ON lw_control.provisioning_jobs (job_state, updated_on)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;
