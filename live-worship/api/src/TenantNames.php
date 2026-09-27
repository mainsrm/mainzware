<?php
declare(strict_types=1);

namespace LiveWorship;

final class TenantNames
{
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    public static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1
            && strlen($slug) <= 80
            && !in_array($slug, ['api', 'admin', 'catalog', 'login', 'settings'], true);
    }

    public static function slugFromDisplayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 120) {
            throw new \InvalidArgumentException('Team name must be between 1 and 120 characters.');
        }

        $normalized = class_exists('Normalizer')
            ? \Normalizer::normalize($displayName, \Normalizer::FORM_D)
            : $displayName;
        $normalized = is_string($normalized) && $normalized !== '' ? $normalized : $displayName;
        $normalized = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
        $normalized = strtr($normalized, [
            'Æ' => 'AE', 'æ' => 'ae', 'Ø' => 'O', 'ø' => 'o',
            'Ł' => 'L', 'ł' => 'l', 'Đ' => 'D', 'đ' => 'd',
            'Þ' => 'Th', 'þ' => 'th', 'ß' => 'ss',
        ]);
        $ascii = function_exists('iconv')
            ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized)
            : $normalized;
        $ascii = strtolower(is_string($ascii) && $ascii !== '' ? $ascii : $displayName);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $ascii) ?? '';
        $slug = trim($slug, '-');
        if (strlen($slug) > 80) $slug = rtrim(substr($slug, 0, 80), '-');
        if (!self::validSlug($slug)) {
            throw new \InvalidArgumentException('That team name cannot produce an available Live Worship URL. Choose a more specific name.');
        }
        return $slug;
    }

    public static function schemaName(string $tenantId): string
    {
        if (!self::validUuid($tenantId)) throw new \InvalidArgumentException('Tenant ID must be a UUID.');
        return 'lw_t_' . str_replace('-', '', strtolower($tenantId));
    }

    public static function storagePrefix(string $tenantId): string
    {
        if (!self::validUuid($tenantId)) throw new \InvalidArgumentException('Tenant ID must be a UUID.');
        return 'instances/' . strtolower($tenantId);
    }
}
