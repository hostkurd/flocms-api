<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use Closure;
use FloCMS\Core\Config;

/**
 * Loads the user behind a credential with the same loader core Auth uses
 * (Config 'auth.user_loader'), so suspended or deleted users lose API access
 * immediately and their role drives permission checks.
 *
 * @internal
 */
final class UserResolver
{
    private ?Closure $loader;

    /** @param null|callable(int): mixed $loader defaults to Config 'auth.user_loader' */
    public function __construct(?callable $loader = null)
    {
        $loader ??= Config::get('auth.user_loader');
        $this->loader = is_callable($loader) ? Closure::fromCallable($loader) : null;
    }

    public function enabled(): bool
    {
        return $this->loader !== null;
    }

    /**
     * Roles for the user, or null when the user is missing or not active.
     * Without a loader the user is trusted and has no roles.
     *
     * @return list<string>|null
     */
    public function roles(int $userId): ?array
    {
        if ($this->loader === null) {
            return [];
        }

        $user = ($this->loader)($userId);
        $user = is_object($user) ? (array) $user : $user;
        if (!is_array($user)) {
            return null;
        }

        if (array_key_exists('status', $user)) {
            $active = Config::get('auth.active_status', 1);
            if ((string) $user['status'] !== (string) $active) {
                return null;
            }
        }

        $role = $user['role'] ?? null;

        return $role === null || $role === '' ? [] : [(string) $role];
    }
}
