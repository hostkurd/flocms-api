<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Console\Application;
use FloCMS\Api\Kernel;
use FloCMS\Api\Middleware\Deprecated;
use FloCMS\Api\OpenApi\OpenApiController;
use FloCMS\Api\OpenApi\OpenApiGenerator;
use FloCMS\Api\OpenApi\RequestBody;
use FloCMS\Api\OpenApi\ResponseSchema;
use FloCMS\Api\OpenApi\Summary;
use FloCMS\Api\OpenApi\Tag;
use FloCMS\Api\Router;
use PHPUnit\Framework\TestCase;

final class OpenApiTest extends TestCase
{
    use MakesRequests;

    public function testDocumentFromRoutesAndAttributes(): void
    {
        $doc = json_decode((new OpenApiGenerator($this->router(), 'Homes API', '1.1.0', 'https://example.com/api'))->toJson(), true);

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame(['title' => 'Homes API', 'version' => '1.1.0'], $doc['info']);
        self::assertSame([['url' => 'https://example.com/api']], $doc['servers']);
        self::assertSame(['/api/v1/health', '/api/v1/old', '/api/v1/properties', '/api/v1/properties/{id}', '/api/v1/properties/{slug}/{code}'], array_keys($doc['paths']));

        $index = $doc['paths']['/api/v1/properties']['get'];
        self::assertSame('v1.properties.index', $index['operationId']);
        self::assertSame('List properties', $index['summary']);
        self::assertSame(['Properties'], $index['tags']);
        self::assertSame('integer', $index['responses']['200']['content']['application/json']['schema']['properties']['data']['items']['properties']['id']['type']);

        $store = $doc['paths']['/api/v1/properties']['post'];
        self::assertSame(['email'], $store['requestBody']['content']['application/json']['schema']['required']);
        self::assertArrayHasKey('201', $store['responses']);
        self::assertSame(['Properties'], $store['tags'], 'class-level tag');

        $show = $doc['paths']['/api/v1/properties/{id}']['get'];
        self::assertSame([['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], $show['parameters']);
        self::assertSame('Property not found', $show['responses']['404']['description']);
        self::assertArrayNotHasKey('head', $doc['paths']['/api/v1/properties/{id}']);

        $closure = $doc['paths']['/api/v1/properties/{slug}/{code}']['get'];
        self::assertSame(['type' => 'string'], $closure['parameters'][0]['schema']);
        self::assertSame(['type' => 'string', 'pattern' => '^(?:[A-Z]{2})$'], $closure['parameters'][1]['schema']);
        self::assertSame(['properties'], $closure['tags'], 'default tag skips api and version segments');
        self::assertSame('get_api_v1_properties_slug_code', $closure['operationId']);

        self::assertTrue($doc['paths']['/api/v1/old']['get']['deprecated']);
        self::assertSame('#/components/schemas/Error', $doc['paths']['/api/v1/health']['get']['responses']['default']['content']['application/json']['schema']['$ref']);
    }

    public function testControllerServesRawDocument(): void
    {
        $router = $this->router();
        $router->get('/api/v1/openapi.json', new OpenApiController(new OpenApiGenerator($router)));

        $response = (new Kernel($router))->handle($this->request('GET', '/api/v1/openapi.json'));
        $doc = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame('3.1.0', $doc['openapi']);
        self::assertArrayHasKey('/api/v1/openapi.json', $doc['paths']);
    }

    public function testRoutesAndDocsCommands(): void
    {
        $root = sys_get_temp_dir() . '/flo-api-docs-' . bin2hex(random_bytes(4));
        mkdir($root . '/api', 0777, true);
        file_put_contents($root . '/api/routes.php', <<<'PHP'
            <?php
            use FloCMS\Api\Router;
            return static function (Router $router): void {
                $router->version('v1', static function (Router $router): void {
                    $router->get('/health', static fn (): array => ['status' => 'ok'])->name('health');
                    $router->post('/inquiries', static fn (): array => [])->name('inquiries.store')->middleware('throttle:forms');
                });
            };
            PHP);
        $out = fopen('php://memory', 'w+');
        $app = new Application($root, null, $out, $out);

        self::assertSame(0, $app->run(['api:routes']));
        self::assertSame(0, $app->run(['api:docs', '--out=openapi.json', '--title=Test']));
        rewind($out);
        $output = (string) stream_get_contents($out);
        $doc = json_decode((string) file_get_contents($root . '/openapi.json'), true);
        unlink($root . '/openapi.json');
        unlink($root . '/api/routes.php');
        rmdir($root . '/api');
        rmdir($root);

        self::assertMatchesRegularExpression('~POST\s+/api/v1/inquiries\s+v1\.inquiries\.store\s+throttle:forms~', $output);
        self::assertStringContainsString('OpenAPI document written to openapi.json', $output);
        self::assertSame('Test', $doc['info']['title']);
        self::assertSame(['/api/v1/health', '/api/v1/inquiries'], array_keys($doc['paths']));
    }

    private function router(): Router
    {
        $router = new Router();
        $router->group('/api', static function (Router $router): void {
            $router->version('v1', static function (Router $router): void {
                $router->get('/health', static fn (): array => ['status' => 'ok'])->name('health');
                $router->get('/properties', [DocumentedController::class, 'index'])->name('properties.index');
                $router->post('/properties', [DocumentedController::class, 'store'])->name('properties.store');
                $router->get('/properties/{id}', [DocumentedController::class, 'show'])->name('properties.show');
                $router->get('/properties/{slug}/{code:[A-Z]{2}}', static fn (string $slug, string $code): array => []);
                $router->get('/old', static fn (): array => [])->middleware(new Deprecated());
            });
        });

        return $router;
    }
}

#[Tag('Properties')]
final class DocumentedController
{
    #[Summary('List properties', 'Paginated list of published properties.')]
    #[ResponseSchema(200, ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]])]
    public function index(): array
    {
        return [];
    }

    #[RequestBody(['type' => 'object', 'required' => ['email'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email']]])]
    public function store(): array
    {
        return [];
    }

    #[ResponseSchema(404, description: 'Property not found')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
