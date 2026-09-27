<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/TenantNames.php';

use LiveWorship\TenantNames;

function check(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
}

$tenantId = '123e4567-e89b-42d3-a456-426614174000';
check(TenantNames::validUuid($tenantId), true, 'valid tenant UUID');
check(TenantNames::validSlug('grace-community'), true, 'valid team slug');
check(TenantNames::validSlug('Grace Community'), false, 'uppercase/spaced slug rejected');
check(TenantNames::validSlug('admin'), false, 'reserved slug rejected');
check(TenantNames::slugFromDisplayName('Grace Community Church'), 'grace-community-church', 'display name slug translation');
check(TenantNames::slugFromDisplayName('München & Grace'), 'munchen-grace', 'unicode/punctuation slug translation');
check(TenantNames::schemaName($tenantId), 'lw_t_123e4567e89b42d3a456426614174000', 'UUID-derived schema name');
check(TenantNames::storagePrefix($tenantId), 'instances/123e4567-e89b-42d3-a456-426614174000', 'UUID-derived storage prefix');
echo "Tenant naming checks passed.\n";
