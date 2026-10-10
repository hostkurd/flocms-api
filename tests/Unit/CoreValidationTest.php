<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Router;
use FloCMS\Core\Validator;
use PHPUnit\Framework\TestCase;

final class CoreValidationTest extends TestCase
{
    use MakesRequests;

    public function testCoreValidatorFailureIsA422WithFieldErrors(): void
    {
        $router = new Router();
        $router->post('/v1/inquiries', static function (): array {
            Validator::validate(['name' => ''], ['name' => 'required|string']);

            return ['created' => true];
        });

        foreach ([null, [], [new ExceptionMiddleware()]] as $middleware) {
            $kernel = new Kernel($router);
            if ($middleware !== null) {
                $kernel->middleware($middleware);
            }

            $response = $kernel->handle($this->request('POST', '/v1/inquiries'));
            $payload = $this->payload($response);

            self::assertSame(422, $response->status());
            self::assertSame('Validation failed.', $payload['message']);
            self::assertSame(['name is Required.'], $payload['errors']['name']);
        }
    }

    public function testCoreValidationFailureKeepsCorsHeaders(): void
    {
        $router = new Router();
        $router->post('/v1/inquiries', static function (): array {
            Validator::validate([], ['email' => 'required']);

            return [];
        });
        $kernel = (new Kernel($router))->middleware([
            new ExceptionMiddleware(),
            new CorsMiddleware(['https://app.example.com']),
        ]);

        $response = $kernel->handle(
            $this->request('POST', '/v1/inquiries', ['Origin' => 'https://app.example.com'])
        );

        self::assertSame(422, $response->status());
        self::assertSame('https://app.example.com', $response->headers()['Access-Control-Allow-Origin'] ?? null);
    }
}
