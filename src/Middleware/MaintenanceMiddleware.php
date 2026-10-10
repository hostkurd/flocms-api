<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use Closure;
use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Security\ClientIpResolver;
use FloCMS\Api\Security\IpMatcher;
use FloCMS\Core\Config;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;

/**
 * Answers 503 with Retry-After while the site is in maintenance mode, the
 * same switch the FloCMS front end uses (the 'offline_mode' setting), except
 * for allowed IPs or requests $bypass accepts.
 *
 *     new MaintenanceMiddleware(allowedIps: ['203.0.113.7', '10.0.0.0/8'], clientIp: $clientIp)
 *
 * List it inside ExceptionMiddleware and CorsMiddleware so the 503 is JSON
 * and readable cross-origin.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    private readonly Closure $isDown;

    private readonly ?Closure $bypass;

    private readonly ClientIpResolver $clientIp;

    /**
     * @param null|callable(): bool $isDown defaults to Config::getSetting('offline_mode') === '1'
     * @param list<string> $allowedIps addresses or CIDR ranges that keep access
     * @param null|callable(Request): bool $bypass e.g. let health checks through
     */
    public function __construct(
        ?callable $isDown = null,
        private readonly array $allowedIps = [],
        private readonly int $retryAfter = 600,
        ?ClientIpResolver $clientIp = null,
        ?callable $bypass = null,
        private readonly string $message = 'The site is under maintenance. Please try again later.'
    ) {
        foreach ($allowedIps as $range) {
            if (!IpMatcher::isValidRange($range)) {
                throw new InvalidArgumentException('Invalid IP address or CIDR: ' . $range);
            }
        }
        $this->isDown = $isDown !== null
            ? Closure::fromCallable($isDown)
            : static fn (): bool => (string) Config::getSetting('offline_mode', '0') === '1';
        $this->bypass = $bypass !== null ? Closure::fromCallable($bypass) : null;
        $this->clientIp = $clientIp ?? new ClientIpResolver();
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!($this->isDown)()) {
            return $handler->handle($request);
        }
        if ($this->allowedIps !== [] && IpMatcher::matchesAny($this->clientIp->resolve($request), $this->allowedIps)) {
            return $handler->handle($request);
        }
        if ($this->bypass !== null && ($this->bypass)($request)) {
            return $handler->handle($request);
        }

        throw new ApiException($this->message, 503, [], ['Retry-After' => (string) max(0, $this->retryAfter)]);
    }
}
