<?php
declare(strict_types=1);

namespace FloCMS\Api\Security;

final class Identity
{
    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int|string $id,
        public readonly array $roles = [],
        public readonly array $scopes = [],
        public readonly array $data = []
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
}
