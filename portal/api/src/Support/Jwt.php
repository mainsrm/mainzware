<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use RuntimeException;

// Minimal dependency-free HS256 JWT for the mobile app's stateless bearer-token auth.
// Web clients keep using PHP session cookies; this is only for token-based clients.
final class Jwt
{
    public static function encode(array $claims, int $ttlSeconds): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $payload = $claims + ['iat' => $now, 'exp' => $now + $ttlSeconds];

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), self::secret(), true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    // Returns the decoded payload, or null if the token is missing, malformed, expired, or has a bad signature.
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $expectedSignature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", self::secret(), true);
        $actualSignature = self::base64UrlDecode($encodedSignature);
        if ($actualSignature === false || !hash_equals($expectedSignature, $actualSignature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if ($payloadJson === false) {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = getenv('MAINZWORLD_JWT_SECRET');
        if ($secret === false || $secret === '') {
            throw new RuntimeException('MAINZWORLD_JWT_SECRET must be set to issue or verify auth tokens.');
        }
        return $secret;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode($padded, true);
    }
}
