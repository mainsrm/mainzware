-- Retain, but inactivate, plan rows that cannot be tied to the current
-- subscription. This prevents historical rows from occupying the active
-- tenant/feature uniqueness slot during the next plan transition.
UPDATE lw_control.tenant_entitlements te
   SET inactivated_on = now(),
       inactivated_by = '00000000-0000-0000-0000-000000000001'
 WHERE te.source = 'plan'
   AND te.inactivated_on IS NULL AND te.inactivated_by IS NULL
   AND NOT EXISTS (
       SELECT 1
         FROM lw_control.tenant_subscriptions ts
        WHERE ts.id = te.source_subscription_id
          AND ts.tenant_id = te.tenant_id
          AND ts.plan_id = te.source_plan_id
          AND ts.inactivated_on IS NULL AND ts.inactivated_by IS NULL
   );
