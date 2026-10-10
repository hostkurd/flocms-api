<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\JsonBodyMiddleware;
use FloCMS\Api\Middleware\SecurityHeadersMiddleware;
use FloCMS\Api\Router;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class CorsErrorTest extends TestCase
{
    use MakesRequests;

    private const ORIGIN = 'https://app.example.com';

    public function testNotFoundKeepsCorsHeaders(): void
    {
        $response = $this->kernel()->handle(
            $this->request('GET', '/v1/missing', ['Origin' => self::ORIGIN])
        );

        self::assertSame(404, $response->status());
        self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin'] ?? null);
        self::assertSame('Origin', $response->headers()['Vary'] ?? null);
    }

    public function testValidationErrorKeepsCorsHeadersAndBody(): void
    {
        $response = $this->kernel()->handle(
            $this->request('POST', '/v1/inquiries', ['Origin' => self::ORIGIN])
        );

        self::assertSame(422, $response->status());
        self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin'] ?? null);
        self::assertSame(['email' => ['Required.']], $this->payload($response)['errors']);
        self::assertNotEmpty($this->payload($response)['request_id']);
    }

    public function testUnexpectedErrorIsLoggedByOuterHandlerAndKeepsCorsHeaders(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        $response = $this->kernel(debug: true, logger: $logger)->handle(
            $this->request('GET', '/v1/boom', ['Origin' => self::ORIGIN])
        );

        self::assertSame(500, $response->status());
        self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin'] ?? null);
        self::assertSame('database down', $this->payload($response)['message']);
        self::assertSame(RuntimeException::class, $this->payload($response)['errors']['exception']);
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0][2]['exception']);
    }

    public function testUnexpectedErrorWithoutExceptionMiddlewareKeepsCorsHeaders(): void
    {
        $router = $this->router();
        $kernel = (new Kernel($router))->middleware([new CorsMiddleware([self::ORIGIN])]);

        $response = $kernel->handle($this->request('GET', '/v1/boom', ['Origin' => self::ORIGIN]));

        self::assertSame(500, $response->status());
        self::assertSame('Internal Server Error', $this->payload($response)['message']);
        self::assertSame(self::ORIGIN, $response->headers()['Access-Control-Allow-Origin'] ?? null);
    }

    public function testDisallowedOriginGetsNoAllowOrigin(): void
    {
        $response = $this->kernel()->handle(
            $this->request('GET', '/v1/missing', ['Origin' => 'https://evil.example'])
        );

        self::assertSame(404, $response->status());
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        self::assertSame('Origin', $response->headers()['Vary'] ?? null);
    }

    public function testSameOriginErrorIsUnchanged(): void
    {
        $response = $this->kernel()->handle($this->request('GET', '/v1/missing'));

        self::assertSame(404, $response->status());
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        self::assertArrayNotHasKey('Vary', $response->headers());
    }

    private function router(): Router
    {
        $router = new Router();
        $router->post('/v1/inquiries', static fn () => throw new ValidationException(['email' => ['Required.']]));
        $router->get('/v1/boom', static fn () => throw new RuntimeException('database down'));

        return $router;
    }

    /** The order used by the FloCMS 1.5 skeleton: errors thrown inside bubble through CORS. */
    private function kernel(bool $debug = false, ?AbstractLogger $logger = null): Kernel
    {
        return (new Kernel($this->router(), debug: $debug, logger: $logger))->middleware([
            new ExceptionMiddleware($debug, $logger),
            new SecurityHeadersMiddleware(),
            new CorsMiddleware([self::ORIGIN]),
            new JsonBodyMiddleware(),
        ]);
    }
}
