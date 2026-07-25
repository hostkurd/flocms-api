<?php
declare(strict_types=1);

namespace FloCMS\Api\Security;

use FloCMS\Core\Http\Request;
use InvalidArgumentException;

final class ClientIpResolver
{
    /** @var list<string> */
    private array $trustedProxies;

    /** @param list<string> $trustedProxies IP addresses or CIDR ranges */
    public function __construct(array $trustedProxies = [])
    {
        $normalized = [];
        foreach ($trustedProxies as $proxy) {
            $proxy = trim($proxy);
            if (!$this->validProxy($proxy)) {
                throw new InvalidArgumentException('Invalid trusted proxy address or CIDR: ' . $proxy);
            }
            $normalized[] = $proxy;
        }

        $this->trustedProxies = array_values(array_unique($normalized));
    }

    public function resolve(Request $request): string
    {
        $remote = trim((string) $request->server('REMOTE_ADDR', ''));
        if (!$this->validIp($remote)) {
            return 'unknown';
        }

        if (!$this->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->header('X-Forwarded-For', ''))
        ), fn (string $candidate): bool => $this->validIp($candidate)));

        for ($index = count($forwarded) - 1; $index >= 0; $index--) {
            $candidate = $forwarded[$index];
            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        $realIp = trim((string) $request->header('X-Real-IP', ''));
        if ($this->validIp($realIp)) {
            return $realIp;
        }

        $cloudflare = trim((string) $request->header('CF-Connecting-IP', ''));
        if ($this->validIp($cloudflare)) {
            return $cloudflare;
        }

        if ($forwarded !== []) {
            return $forwarded[0];
        }

        return $remote;
    }

    private function isTrusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $proxy) {
            if ($this->matchesCidr($ip, trim($proxy))) {
                return true;
            }
        }

        return false;
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $bits = (int) $prefix;
        $maxBits = strlen($ipBinary) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }

    private function validIp(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function validProxy(string $proxy): bool
    {
        if (!str_contains($proxy, '/')) {
            return $this->validIp($proxy);
        }

        [$network, $prefix] = explode('/', $proxy, 2);
        if (!$this->validIp($network) || !ctype_digit($prefix)) {
            return false;
        }

        $binary = inet_pton($network);
        if ($binary === false) {
            return false;
        }

        $bits = (int) $prefix;

        return $bits >= 0 && $bits <= strlen($binary) * 8;
    }
}
