<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedOrigins;

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        array $allowedOrigins,
        private readonly bool $allowCredentials = false,
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders = [
            'Accept',
            'Authorization',
            'Content-Type',
            'X-Requested-With',
            'X-CSRF-TOKEN',
            'X-Guest-Token',
            'X-Request-ID',
            'X-FloCMS-Authorization',
        ],
        private readonly array $exposedHeaders = ['X-Request-ID', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],
        private readonly int $maxAge = 86400
    ) {
        $normalized = [];
        foreach ($allowedOrigins as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '*') {
                if ($allowCredentials) {
                    throw new InvalidArgumentException(
                        'CORS wildcard origins cannot be combined with credentials.'
                    );
                }
                $normalized[] = '*';
                continue;
            }
            if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Invalid CORS origin: ' . $origin);
            }
            $normalized[] = $origin;
        }

        $this->allowedOrigins = array_values(array_unique($normalized));
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);
        $origin = rtrim(trim((string) $request->header('Origin', '')), '/');
        if ($origin === '') {
            return $response;
        }

        $allowOrigin = in_array('*', $this->allowedOrigins, true)
            ? '*'
            : (in_array($origin, $this->allowedOrigins, true) ? $origin : null);

        if ($allowOrigin === null) {
            return $this->varyOrigin($response);
        }

        $response
            ->header('Access-Control-Allow-Origin', $allowOrigin)
            ->header('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->header('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->header('Access-Control-Max-Age', (string) max(0, $this->maxAge));
        $this->varyOrigin($response);

        if ($this->allowCredentials) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
        if ($this->exposedHeaders !== []) {
            $response->header('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }

    private function varyOrigin(Response $response): Response
    {
        foreach ($response->headers() as $name => $value) {
            if (strcasecmp($name, 'Vary') !== 0) {
                continue;
            }

            $values = array_map('trim', explode(',', $value));
            if (in_array('Origin', $values, true) || in_array('*', $values, true)) {
                return $response;
            }
        }

        return $response->appendHeader('Vary', 'Origin');
    }
}
