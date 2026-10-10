<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\DecoratedException;
use FloCMS\Api\Support\ErrorRenderer;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;
use Throwable;

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
        // Answer preflights here: they carry no credentials, so they should not
        // reach rate limiting, authentication or the route handler.
        if ($this->isPreflight($request)) {
            return $this->apply(Response::noContent(), $this->corsHeaders($request));
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $error) {
            $headers = $this->corsHeaders($request);
            if ($headers === []) {
                throw $error;
            }

            // Client errors (404, 422, 429, ...) are rendered here so the
            // browser can read them. Anything else goes to the outer handler,
            // which logs it and adds these headers.
            if (!ErrorRenderer::isClientError($error)) {
                throw new DecoratedException($error, $headers);
            }

            $response = (new ErrorRenderer())->render(
                $error,
                (string) $request->attribute('request_id', '')
            );
        }

        return $this->apply($response, $this->corsHeaders($request));
    }

    private function isPreflight(Request $request): bool
    {
        return $request->method() === 'OPTIONS'
            && trim((string) $request->header('Origin', '')) !== ''
            && trim((string) $request->header('Access-Control-Request-Method', '')) !== '';
    }

    /** @return array<string, string> Headers for this request; empty without an Origin. */
    private function corsHeaders(Request $request): array
    {
        $origin = rtrim(trim((string) $request->header('Origin', '')), '/');
        if ($origin === '') {
            return [];
        }

        $allowOrigin = in_array('*', $this->allowedOrigins, true)
            ? '*'
            : (in_array($origin, $this->allowedOrigins, true) ? $origin : null);

        if ($allowOrigin === null) {
            return ['Vary' => 'Origin'];
        }

        $headers = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age' => (string) max(0, $this->maxAge),
            'Vary' => 'Origin',
        ];
        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        if ($this->exposedHeaders !== []) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $this->exposedHeaders);
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function apply(Response $response, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            $name === 'Vary'
                ? ErrorRenderer::appendVary($response, $value)
                : $response->header($name, $value);
        }

        return $response;
    }
}
