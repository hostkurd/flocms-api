<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Idempotency\IdempotencyStore;
use FloCMS\Api\Security\ClientIpResolver;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;
use Throwable;

/**
 * Makes POST requests safe to retry: a client sends `Idempotency-Key: <uuid>`
 * and a repeated request (double click, network retry) gets the stored
 * response instead of creating a second record.
 *
 *     $router->post('/v1/inquiries', $handler)
 *         ->middleware(new IdempotencyMiddleware(new FileIdempotencyStore(ROOT . '/storage/cache/api-idempotency')));
 *
 * - Keys are scoped to the identity (or client IP), method and path.
 * - Reusing a key with a different body is a 422; a repeat while the first
 *   request is still running is a 409 with Retry-After.
 * - Responses below 500 are stored for $ttlSeconds (24 hours) and replayed
 *   with `Idempotent-Replayed: true`. Failed requests (exceptions, 5xx) are
 *   not stored, so they can be retried with the same key.
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    private const REPLAYED_HEADERS = ['content-type', 'location', 'cache-control', 'content-language'];

    private readonly ClientIpResolver $clientIp;

    /** @param list<string> $methods */
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly int $ttlSeconds = 86400,
        private readonly bool $required = false,
        ?ClientIpResolver $clientIp = null,
        private readonly array $methods = ['POST']
    ) {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('The idempotency TTL must be positive.');
        }
        $this->clientIp = $clientIp ?? new ClientIpResolver();
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$request->isMethod(...$this->methods)) {
            return $handler->handle($request);
        }

        $key = $request->header('Idempotency-Key');
        if ($key === null || trim($key) === '') {
            if ($this->required) {
                throw new ApiException('The Idempotency-Key header is required.', 400);
            }

            return $handler->handle($request);
        }
        if (!preg_match('/^[\x21-\x7E]{1,255}$/', trim($key))) {
            throw new ApiException('Invalid Idempotency-Key header.', 400);
        }

        $identity = $request->attribute('identity');
        $scope = $identity instanceof Identity ? 'id:' . $identity->id : 'ip:' . $this->clientIp->resolve($request);
        $storeKey = hash('sha256', implode("\n", [$scope, $request->method(), $request->path(), trim($key)]));
        $fingerprint = hash('sha256', $request->contentType() . "\n" . $request->rawBody() . "\n"
            . http_build_query($request->query()) . "\n" . json_encode($request->all(), JSON_PARTIAL_OUTPUT_ON_ERROR));

        $existing = $this->store->reserve($storeKey, $fingerprint, $this->ttlSeconds);
        if ($existing !== null) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $response = $handler->handle($request);
        } catch (Throwable $error) {
            $this->store->release($storeKey);
            throw $error;
        }

        if ($response->status() >= 500) {
            $this->store->release($storeKey);

            return $response;
        }

        $headers = [];
        foreach ($response->headers() as $name => $value) {
            if (in_array(strtolower($name), self::REPLAYED_HEADERS, true)) {
                $headers[$name] = $value;
            }
        }
        $this->store->complete($storeKey, [
            'status' => $response->status(),
            'headers' => $headers,
            'body' => $response->body(),
        ], $this->ttlSeconds);

        return $response;
    }

    /** @param array{status: string, fingerprint: string, response: ?array<string, mixed>} $existing */
    private function replay(array $existing, string $fingerprint): Response
    {
        if (!hash_equals($existing['fingerprint'], $fingerprint)) {
            throw new ApiException('This Idempotency-Key was already used with a different request.', 422);
        }

        $stored = $existing['response'];
        if ($existing['status'] !== 'done' || !is_array($stored)) {
            throw new ApiException(
                'A request with this Idempotency-Key is still being processed.',
                409,
                [],
                ['Retry-After' => '1']
            );
        }

        $response = Response::make((string) ($stored['body'] ?? ''), (int) ($stored['status'] ?? 200));
        foreach ((array) ($stored['headers'] ?? []) as $name => $value) {
            $response->header((string) $name, (string) $value);
        }

        return $response->header('Idempotent-Replayed', 'true');
    }
}
