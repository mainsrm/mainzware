-- The original foundation seed used one fixed plan_features ID for the free
-- plan's multi-row insert, so only its first feature could be stored. Backfill
-- every intended mapping with a deterministic UUID derived from both codes.
INSERT INTO lw_control.plan_features
    (id, plan_id, feature_id, activated_on, activated_by)
SELECT md5('live-worship-plan-feature:' || p.code || ':' || f.code)::uuid,
       p.id, f.id, now(), '00000000-0000-0000-0000-000000000001'::uuid
  FROM lw_control.plans p
  JOIN lw_control.features f ON (
       p.code = 'live-worship' AND f.code IN ('song-library', 'master-catalog', 'setlists', 'live-control')
    OR p.code = 'live-worship-pro' AND f.code IN ('song-library', 'master-catalog', 'setlists', 'live-control', 'advanced-import')
    OR p.code = 'live-worship-360'
  )
 WHERE p.inactivated_on IS NULL AND p.inactivated_by IS NULL
   AND f.inactivated_on IS NULL AND f.inactivated_by IS NULL
ON CONFLICT DO NOTHING;
