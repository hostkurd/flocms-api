<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use InvalidArgumentException;
use JsonException;

/**
 * Minimal JSON Web Tokens, HS256 only. The algorithm is fixed: tokens whose
 * header names any other algorithm (including "none") are rejected.
 */
final class Jwt
{
    private const MIN_SECRET_BYTES = 32;

    /** @param array<string, mixed> $claims */
    public static function encode(array $claims, string $secret): string
    {
        self::assertSecret($secret);
        $segments = [
            self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = self::base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    /**
     * The claims of a token with a valid signature and valid exp/nbf/iat,
     * or null.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token, string $secret, int $leeway = 30, ?int $now = null): ?array
    {
        self::assertSecret($secret);
        $now ??= time();

        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 8192) {
            return null;
        }
        [$header64, $payload64, $signature64] = $parts;

        $signature = self::base64UrlDecode($signature64);
        $expected = hash_hmac('sha256', $header64 . '.' . $payload64, $secret, true);
        if ($signature === null || !hash_equals($expected, $signature)) {
            return null;
        }

        $header = self::json(self::base64UrlDecode($header64));
        if ($header === null || ($header['alg'] ?? null) !== 'HS256' || isset($header['crit'])) {
            return null;
        }

        $claims = self::json(self::base64UrlDecode($payload64));
        if ($claims === null) {
            return null;
        }

        foreach (['exp', 'nbf', 'iat'] as $claim) {
            if (isset($claims[$claim]) && !is_int($claims[$claim]) && !is_float($claims[$claim])) {
                return null;
            }
        }
        if (isset($claims['exp']) && $now - $leeway >= $claims['exp']) {
            return null;
        }
        if (isset($claims['nbf']) && $now + $leeway < $claims['nbf']) {
            return null;
        }
        if (isset($claims['iat']) && $now + $leeway < $claims['iat']) {
            return null;
        }

        return $claims;
    }

    private static function assertSecret(string $secret): void
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException('The JWT secret must be at least 32 bytes.');
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        if ($data === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /** @return array<string, mixed>|null */
    private static function json(?string $data): ?array
    {
        if ($data === null) {
            return null;
        }
        try {
            $decoded = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
