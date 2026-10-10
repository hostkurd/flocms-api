<?php
declare(strict_types=1);

namespace FloCMS\Api\Security;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Core\Config;

final class Identity
{
    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     * @param array<string, mixed> $data
     * @param list<string>|null $permissions explicit permissions; null to derive
     *        them from the roles and the core 'permissions' map
     */
    public function __construct(
        public readonly int|string $id,
        public readonly array $roles = [],
        public readonly array $scopes = [],
        public readonly array $data = [],
        public readonly ?array $permissions = null
    ) {
    }

    public function hasAnyRole(array $roles): bool
    {
        return $roles === [] || array_intersect($roles, $this->roles) !== [];
    }

    public function hasScopes(array $scopes): bool
    {
        return $scopes === [] || array_diff($scopes, $this->scopes) === [];
    }

    /**
     * Whether this identity holds $permission, using the same role map and
     * wildcard rules as core Auth::can():
     *
     *     Config::set('permissions', [1 => ['content.*'], 3 => ['*']]);
     *
     * Entries may be exact ('users.manage'), a prefix wildcard ('users.*') or
     * '*'. Without a configured map, only explicit permissions count.
     */
    public function can(string $permission): bool
    {
        foreach ($this->grantedPermissions() as $granted) {
            if (self::matches($granted, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @throws ApiException 403 when the permission is missing */
    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new ApiException('You do not have permission to access this resource.', 403);
        }
    }

    /** @return list<string> */
    private function grantedPermissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $map = Config::get('permissions');
        if (!is_array($map)) {
            return [];
        }

        $granted = [];
        foreach ($this->roles as $role) {
            $key = is_numeric($role) ? (int) $role : $role;
            if (isset($map[$key]) && is_array($map[$key])) {
                array_push($granted, ...array_map('strval', $map[$key]));
            }
        }

        return $granted;
    }

    private static function matches(string $granted, string $permission): bool
    {
        if ($granted === '*' || $granted === $permission) {
            return true;
        }

        return str_ends_with($granted, '.*')
            && str_starts_with($permission, substr($granted, 0, -1));
    }
}
