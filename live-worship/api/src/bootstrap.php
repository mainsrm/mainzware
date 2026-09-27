<?php
declare(strict_types=1);

foreach (['ApiError', 'Database', 'TenantNames', 'TenantConflict', 'TenantContextError', 'TenantInvitationError', 'SupportSessionError', 'ActorIdentity', 'TenantMembershipDirectory', 'SupportSessions', 'LoginTracking', 'PlatformMigrations', 'CatalogReview', 'Entitlements', 'TenantContext', 'TenantInvitations', 'TenantProvisioner', 'Access', 'Files', 'Transpose', 'OnsongParser', 'AdminApi', 'TenantRuntime', 'Api'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
