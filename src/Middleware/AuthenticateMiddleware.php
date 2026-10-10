<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class AuthenticateMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $roles any of these roles (empty: no role check)
     * @param list<string> $scopes all of these scopes
     * @param list<string> $permissions all of these permissions (Identity::can())
     */
    public function __construct(
        private readonly AuthenticatorInterface $authenticator,
        private readonly string $guard = 'default',
        private readonly array $roles = [],
        private readonly array $scopes = [],
        private readonly array $permissions = []
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $identity = $this->authenticator->authenticate($request, $this->guard);
        if ($identity === null) {
            throw new ApiException('Authentication token is required or invalid.', 401);
        }
        if (!$identity->hasAnyRole($this->roles) || !$identity->hasScopes($this->scopes)) {
            throw new ApiException('You do not have permission to access this resource.', 403);
        }
        foreach ($this->permissions as $permission) {
            $identity->authorize($permission);
        }

        return $handler->handle(
            $request
                ->withAttribute('identity', $identity)
                ->withAttribute('auth_guard', $this->guard)
        );
    }
}
