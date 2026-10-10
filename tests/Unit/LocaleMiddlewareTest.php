<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\LocaleMiddleware;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleMiddlewareTest extends TestCase
{
    use MakesRequests;

    /** @return iterable<string, array{0: string, 1: array<string, string>, 2: string}> */
    public static function negotiations(): iterable
    {
        yield 'default' => ['/v1/x', [], 'en'];
        yield 'query wins' => ['/v1/x?lang=ku', ['Accept-Language' => 'ar'], 'ku'];
        yield 'query region' => ['/v1/x?lang=ar_IQ', [], 'ar'];
        yield 'unknown query falls through' => ['/v1/x?lang=fr', ['Accept-Language' => 'ar'], 'ar'];
        yield 'header region fallback' => ['/v1/x', ['Accept-Language' => 'ar-IQ,ar;q=0.9,en;q=0.8'], 'ar'];
        yield 'q values' => ['/v1/x', ['Accept-Language' => 'en;q=0.5, ku;q=0.9'], 'ku'];
        yield 'q zero excluded' => ['/v1/x', ['Accept-Language' => 'ku;q=0, fr'], 'en'];
        yield 'garbage header' => ['/v1/x', ['Accept-Language' => '"><script>'], 'en'];
        yield 'wildcard' => ['/v1/x', ['Accept-Language' => '*'], 'en'];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('negotiations')]
    public function testNegotiation(string $uri, array $headers, string $expected): void
    {
        $loaded = [];
        $router = new Router();
        $router->get('/v1/x', static fn (Request $request): array => ['locale' => $request->attribute('locale')]);
        $kernel = (new Kernel($router))->middleware([
            new LocaleMiddleware(['en', 'ar', 'KU'], 'en', static function (string $locale) use (&$loaded): void {
                $loaded[] = $locale;
            }),
        ]);

        $response = $kernel->handle($this->request('GET', $uri, $headers));

        self::assertSame($expected, $this->payload($response)['data']['locale']);
        self::assertSame($expected, $response->headers()['Content-Language']);
        self::assertSame('Accept-Language', $response->headers()['Vary']);
        self::assertSame([$expected], $loaded);
    }

    public function testDefaultMustBeConfiguredOrFirstIsUsed(): void
    {
        $middleware = new LocaleMiddleware(['ar', 'en'], 'fr', static fn () => null);

        self::assertSame('ar', $middleware->resolve($this->request('GET', '/')));
    }

    public function testLanguagesAreRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocaleMiddleware([''], 'en');
    }
}
