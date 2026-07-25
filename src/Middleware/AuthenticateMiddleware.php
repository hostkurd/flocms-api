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
     * @param list<string> $roles
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly AuthenticatorInterface $authenticator,
        private readonly string $guard = 'default',
        private readonly array $roles = [],
        private readonly array $scopes = []
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

        return $handler->handle(
            $request
                ->withAttribute('identity', $identity)
                ->withAttribute('auth_guard', $this->guard)
        );
    }
}
