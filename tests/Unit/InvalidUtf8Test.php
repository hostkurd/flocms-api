<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\ResponseFactory;
use FloCMS\Api\Router;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class InvalidUtf8Test extends TestCase
{
    use MakesRequests;

    public function testInvalidUtf8IsSubstitutedAndLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $router = new Router();
        $router->get('/v1/properties', static fn (): array => [['title' => "Sea villa \xB1"]]);
        $kernel = new Kernel($router, logger: $logger);

        $response = $kernel->handle($this->request('GET', '/v1/properties'));

        self::assertSame(200, $response->status());
        self::assertSame("Sea villa \u{FFFD}", $this->payload($response)['data'][0]['title']);
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame($response->headers()['X-Request-ID'], $logger->records[0][2]['request_id']);
    }

    public function testValidResponsesAreNotLogged(): void
    {
        $logger = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, $message, array $context = []): void
            {
                $this->count++;
            }
        };
        $router = new Router();
        $router->get('/v1/ok', static fn (): array => ['title' => 'کوردستان / Kurdistan']);

        $response = (new Kernel($router, logger: $logger))->handle($this->request('GET', '/v1/ok'));

        self::assertSame('{"success":true,"data":{"title":"کوردستان / Kurdistan"}}', $response->body());
        self::assertSame(0, $logger->count);
    }

    public function testErrorEnvelopeAlsoSubstitutes(): void
    {
        ResponseFactory::takeInvalidUtf8Count();
        $response = ResponseFactory::error("Bad \xFF", 400);

        self::assertSame("Bad \u{FFFD}", $this->payload($response)['message']);
        self::assertSame(1, ResponseFactory::takeInvalidUtf8Count());
        self::assertSame(0, ResponseFactory::takeInvalidUtf8Count());
    }

    public function testOtherEncodingErrorsStillThrow(): void
    {
        $this->expectException(\RuntimeException::class);

        ResponseFactory::success(['value' => NAN]);
    }
}
