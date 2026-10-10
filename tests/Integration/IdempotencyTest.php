<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Idempotency\DatabaseIdempotencyStore;
use FloCMS\Api\Idempotency\FileIdempotencyStore;
use FloCMS\Api\Idempotency\IdempotencyStore;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\IdempotencyMiddleware;
use FloCMS\Api\Router;
use FloCMS\Api\Tests\Support\Databases;
use FloCMS\Api\Tests\Unit\MakesRequests;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    use MakesRequests;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flo-api-idem-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function stores(): iterable
    {
        yield 'file' => ['file'];
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
    }

    #[DataProvider('stores')]
    public function testRepeatedPostIsReplayed(string $store): void
    {
        $created = 0;
        $kernel = $this->kernel($this->store($store), $created);
        $post = fn (string $key, string $body = '{"name":"Sara"}', string $ip = '127.0.0.1'): Response => $kernel->handle(
            $this->request('POST', '/v1/inquiries', ['Content-Type' => 'application/json', 'Idempotency-Key' => $key], $body, $ip)
        );

        $first = $post('key-1');
        $second = $post('key-1');

        self::assertSame(201, $first->status());
        self::assertSame(201, $second->status());
        self::assertSame($first->body(), $second->body());
        self::assertSame('/v1/inquiries/1', $second->headers()['Location']);
        self::assertSame('true', $second->headers()['Idempotent-Replayed']);
        self::assertSame(1, $created);

        self::assertSame(422, $post('key-1', '{"name":"Other"}')->status(), 'same key, different body');
        self::assertSame(201, $post('key-2')->status());
        self::assertSame(201, $post('key-1', ip: '10.0.0.2')->status(), 'keys are scoped per client');
        self::assertSame(3, $created);

        // Without a key the middleware does nothing.
        $kernel->handle($this->request('POST', '/v1/inquiries', ['Content-Type' => 'application/json'], '{}'));
        self::assertSame(4, $created);
    }

    #[DataProvider('stores')]
    public function testFailedRequestsCanBeRetried(string $store): void
    {
        $created = 0;
        $kernel = $this->kernel($this->store($store), $created);
        $request = $this->request('POST', '/v1/flaky', ['Idempotency-Key' => 'retry-me']);

        self::assertSame(503, $kernel->handle($request)->status());
        self::assertSame(500, $kernel->handle($request)->status());
        self::assertSame(201, $kernel->handle($request)->status());
        self::assertSame(201, $kernel->handle($request)->status());
        self::assertSame('true', $kernel->handle($request)->headers()['Idempotent-Replayed'] ?? null);
    }

    #[DataProvider('stores')]
    public function testInFlightRequestIsA409(string $store): void
    {
        $backend = $this->store($store);
        $created = 0;
        $kernel = $this->kernel($backend, $created);
        $key = hash('sha256', implode("\n", ['ip:127.0.0.1', 'POST', '/v1/inquiries', 'busy']));
        $fingerprintOf = function (Request $request): string {
            return hash('sha256', $request->contentType() . "\n" . $request->rawBody() . "\n"
                . http_build_query($request->query()) . "\n" . json_encode($request->all(), JSON_PARTIAL_OUTPUT_ON_ERROR));
        };
        $request = $this->request('POST', '/v1/inquiries', ['Content-Type' => 'application/json', 'Idempotency-Key' => 'busy'], '{}');
        self::assertNull($backend->reserve($key, $fingerprintOf($request), 60));

        $response = $kernel->handle($request);

        self::assertSame(409, $response->status());
        self::assertSame('1', $response->headers()['Retry-After']);
        self::assertSame(0, $created);
    }

    #[DataProvider('stores')]
    public function testExpiredRecordsAreReclaimedAndPurged(string $store): void
    {
        $backend = $this->store($store);
        $key = str_repeat('a', 64);

        self::assertNull($backend->reserve($key, 'f1', 1));
        $backend->complete($key, ['status' => 201, 'headers' => [], 'body' => '{}'], 1);
        self::assertSame('done', $backend->reserve($key, 'f1', 1)['status']);

        sleep(2);
        self::assertNull($backend->reserve($key, 'f2', 60), 'expired keys can be claimed again');

        $backend->reserve(str_repeat('b', 64), 'f', 1);
        sleep(2);
        self::assertSame(1, $backend->purge());
    }

    public function testRequiredKeyAndInvalidKey(): void
    {
        $router = new Router();
        $router->post('/v1/x', static fn (): array => [])
            ->middleware(new IdempotencyMiddleware(new FileIdempotencyStore($this->directory), required: true));
        $kernel = new Kernel($router);

        self::assertSame(400, $kernel->handle($this->request('POST', '/v1/x'))->status());
        self::assertSame(400, $kernel->handle($this->request('POST', '/v1/x', ['Idempotency-Key' => "bad key\x01"]))->status());
        self::assertSame(200, $kernel->handle($this->request('POST', '/v1/x', ['Idempotency-Key' => 'ok-key']))->status());
    }

    public function testConcurrentReservationsHaveOneWinner(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        $store = new FileIdempotencyStore($this->directory);
        $key = str_repeat('c', 64);
        $results = $this->directory . '-results';
        @mkdir($results);
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $won = $store->reserve($key, 'f', 60) === null;
                file_put_contents($results . '/' . getmypid(), $won ? '1' : '0');
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $outcomes = array_map('file_get_contents', glob($results . '/*') ?: []);
        array_map('unlink', glob($results . '/*') ?: []);
        rmdir($results);

        self::assertCount(8, $outcomes);
        self::assertSame(1, array_sum(array_map('intval', $outcomes)));
    }

    private function store(string $type): IdempotencyStore
    {
        return $type === 'file'
            ? new FileIdempotencyStore($this->directory)
            : new DatabaseIdempotencyStore(Databases::connect($type));
    }

    private function kernel(IdempotencyStore $store, int &$created): Kernel
    {
        $attempts = 0;
        $router = new Router();
        $idempotency = new IdempotencyMiddleware($store);
        $router->post('/v1/inquiries', static function () use (&$created): Response {
            $created++;

            return Response::json(['id' => $created], 201)->header('Location', '/v1/inquiries/' . $created);
        })->middleware($idempotency);
        $router->post('/v1/flaky', static function () use (&$attempts): Response {
            $attempts++;
            if ($attempts === 1) {
                return Response::json(['error' => 'busy'], 503);
            }
            if ($attempts === 2) {
                throw new \RuntimeException('database went away');
            }

            return Response::json(['ok' => true], 201);
        })->middleware($idempotency);

        return (new Kernel($router))->middleware([new ExceptionMiddleware()]);
    }
}
