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

/**
 * CORS for the API.
 *
 * Origins are exact (`https://www.example.com`), `*` (without credentials), or
 * a pattern with one leading wildcard label (`https://*.example.com`), which
 * matches exactly one subdomain label: `https://app.example.com`, but not
 * `https://example.com`, `https://a.b.example.com` or `http://app.example.com`.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedOrigins = [];

    /** @var list<string> Anchored regexes built from origin patterns. */
    private array $originPatterns = [];

    /** @var list<string> */
    private array $allowedMethods;

    /** @var array<string, string> lower-case name => configured spelling */
    private array $allowedHeaders = [];

    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        array $allowedOrigins,
        private readonly bool $allowCredentials = false,
        array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = [
            'Accept',
            'Authorization',
            'Content-Type',
            'X-Requested-With',
            'X-CSRF-TOKEN',
            'X-Guest-Token',
            'X-Request-ID',
            'X-FloCMS-Authorization',
        ],
        private readonly array $exposedHeaders = [
            'X-Request-ID',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
            'Retry-After',
        ],
        private readonly int $maxAge = 86400
    ) {
        foreach ($allowedOrigins as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '*') {
                if ($allowCredentials) {
                    throw new InvalidArgumentException(
                        'CORS wildcard origins cannot be combined with credentials.'
                    );
                }
                $this->allowedOrigins[] = '*';
                continue;
            }
            if (str_contains($origin, '*')) {
                $this->originPatterns[] = self::compilePattern($origin);
                continue;
            }
            if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Invalid CORS origin: ' . $origin);
            }
            $this->allowedOrigins[] = $origin;
        }
        $this->allowedOrigins = array_values(array_unique($this->allowedOrigins));

        $this->allowedMethods = array_values(array_unique(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            $allowedMethods
        )));
        foreach ($allowedHeaders as $header) {
            $this->allowedHeaders[strtolower(trim($header))] = trim($header);
        }
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Answer preflights here: they carry no credentials, so they should not
        // reach rate limiting, authentication or the route handler.
        if ($this->isPreflight($request)) {
            return $this->apply(Response::noContent(), $this->preflightHeaders($request));
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $error) {
            $headers = $this->responseHeaders($request);
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

        return $this->apply($response, $this->responseHeaders($request));
    }

    private function isPreflight(Request $request): bool
    {
        return $request->method() === 'OPTIONS'
            && trim((string) $request->header('Origin', '')) !== ''
            && trim((string) $request->header('Access-Control-Request-Method', '')) !== '';
    }

    /**
     * Headers for a preflight. When the origin, method or any requested header
     * is not allowed, only Vary is sent and the browser blocks the request.
     *
     * @return array<string, string>
     */
    private function preflightHeaders(Request $request): array
    {
        $vary = ['Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers'];
        $allowOrigin = $this->allowOrigin($request);
        if ($allowOrigin === null) {
            return $vary;
        }

        $method = strtoupper(trim((string) $request->header('Access-Control-Request-Method', '')));
        if (!in_array($method, $this->allowedMethods, true)) {
            return $vary;
        }

        $requested = array_filter(array_map(
            'trim',
            explode(',', (string) $request->header('Access-Control-Request-Headers', ''))
        ), static fn (string $header): bool => $header !== '');
        $headers = [];
        foreach ($requested as $header) {
            $configured = $this->allowedHeaders[strtolower($header)] ?? null;
            if ($configured === null) {
                return $vary;
            }
            $headers[] = $configured;
        }

        $result = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Max-Age' => (string) max(0, $this->maxAge),
        ] + $vary;
        if ($headers !== []) {
            $result['Access-Control-Allow-Headers'] = implode(', ', array_values(array_unique($headers)));
        }
        if ($this->allowCredentials) {
            $result['Access-Control-Allow-Credentials'] = 'true';
        }

        return $result;
    }

    /** @return array<string, string> Headers for an actual response; empty without an Origin. */
    private function responseHeaders(Request $request): array
    {
        if (trim((string) $request->header('Origin', '')) === '') {
            return [];
        }

        $allowOrigin = $this->allowOrigin($request);
        if ($allowOrigin === null) {
            return ['Vary' => 'Origin'];
        }

        $headers = [
            'Access-Control-Allow-Origin' => $allowOrigin,
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

    private function allowOrigin(Request $request): ?string
    {
        $origin = rtrim(trim((string) $request->header('Origin', '')), '/');
        if ($origin === '') {
            return null;
        }
        if (in_array('*', $this->allowedOrigins, true)) {
            return '*';
        }
        if (in_array($origin, $this->allowedOrigins, true)) {
            return $origin;
        }
        foreach ($this->originPatterns as $pattern) {
            if (preg_match($pattern, $origin) === 1) {
                return $origin;
            }
        }

        return null;
    }

    /** `https://*.example.com[:port]` => an anchored regex matching one DNS label. */
    private static function compilePattern(string $pattern): string
    {
        if (preg_match('~^(https?)://\*\.((?:[A-Za-z0-9-]+\.)*[A-Za-z0-9-]+\.[A-Za-z]{2,}|localhost)(:\d{1,5})?$~', $pattern, $parts) !== 1) {
            throw new InvalidArgumentException(
                'Invalid CORS origin pattern (expected scheme://*.domain): ' . $pattern
            );
        }

        return '~^' . preg_quote($parts[1] . '://', '~')
            . '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?'
            . preg_quote('.' . $parts[2] . ($parts[3] ?? ''), '~')
            . '$~iD';
    }

    /** @param array<string, string> $headers */
    private function apply(Response $response, array $headers): Response
    {
        foreach ($headers as $name => $value) {
            if ($name !== 'Vary') {
                $response->header($name, $value);
                continue;
            }
            foreach (explode(', ', $value) as $vary) {
                ErrorRenderer::appendVary($response, $vary);
            }
        }

        return $response;
    }
}
