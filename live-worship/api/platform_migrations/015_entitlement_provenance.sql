-- Make tenant entitlements traceable to the subscription and plan that
-- supplied them. Non-plan grants, trials, and overrides remain independent
-- of a subscription and use NULL provenance columns.
ALTER TABLE lw_control.tenant_entitlements
    ADD COLUMN IF NOT EXISTS source_plan_id uuid,
    ADD COLUMN IF NOT EXISTS source_subscription_id uuid;

ALTER TABLE lw_control.tenant_entitlements
    ADD CONSTRAINT tenant_entitlements_source_plan_fk
        FOREIGN KEY (source_plan_id) REFERENCES lw_control.plans(id),
    ADD CONSTRAINT tenant_entitlements_source_subscription_fk
        FOREIGN KEY (source_subscription_id) REFERENCES lw_control.tenant_subscriptions(id);

CREATE INDEX IF NOT EXISTS lw_control_entitlement_subscription_idx
    ON lw_control.tenant_entitlements (tenant_id, source_subscription_id)
    WHERE inactivated_on IS NULL AND inactivated_by IS NULL;

-- Backfill plan entitlements that predate provenance. A plan entitlement is
-- valid only for the tenant's current active subscription and matching plan
-- feature, so rows from an old subscription cannot accidentally authorize a
-- tenant after a billing transition.
UPDATE lw_control.tenant_entitlements te
   SET source_plan_id = ts.plan_id,
       source_subscription_id = ts.id
  FROM lw_control.tenant_subscriptions ts
     , lw_control.plan_features pf
 WHERE te.tenant_id = ts.tenant_id
   AND pf.plan_id = ts.plan_id
   AND pf.feature_id = te.feature_id
   AND pf.inactivated_on IS NULL AND pf.inactivated_by IS NULL
   AND te.source = 'plan'
   AND te.inactivated_on IS NULL AND te.inactivated_by IS NULL
   AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
   AND (te.source_plan_id IS NULL OR te.source_subscription_id IS NULL);

-- Existing tenants need the same baseline rows that new provisioning creates.
-- md5 gives this migration a deterministic UUID without requiring a database
-- extension; the tenant/subscription/feature tuple is unique by design.
INSERT INTO lw_control.tenant_entitlements
    (id, tenant_id, feature_id, source, source_plan_id, source_subscription_id,
     activated_on, activated_by)
SELECT md5('lw-entitlement:' || ts.id::text || ':' || pf.feature_id::text)::uuid,
       ts.tenant_id, pf.feature_id, 'plan', ts.plan_id, ts.id,
       now(), ts.activated_by
  FROM lw_control.tenant_subscriptions ts
  JOIN lw_control.tenants t ON t.id = ts.tenant_id
  JOIN lw_control.plan_features pf ON pf.plan_id = ts.plan_id
                                  AND pf.inactivated_on IS NULL
                                  AND pf.inactivated_by IS NULL
  JOIN lw_control.plans p ON p.id = ts.plan_id
                          AND p.inactivated_on IS NULL
                          AND p.inactivated_by IS NULL
 WHERE t.provisioning_state = 'active'
   AND t.inactivated_on IS NULL AND t.inactivated_by IS NULL
   AND ts.subscription_state IN ('free', 'trialing', 'active')
   AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
   AND (ts.expires_on IS NULL OR ts.expires_on > now())
   AND NOT EXISTS (
       SELECT 1
         FROM lw_control.tenant_entitlements existing
        WHERE existing.tenant_id = ts.tenant_id
          AND existing.feature_id = pf.feature_id
          AND existing.inactivated_on IS NULL
          AND existing.inactivated_by IS NULL
   )
ON CONFLICT DO NOTHING;
