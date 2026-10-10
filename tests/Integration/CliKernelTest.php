<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Console\Application;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use PHPUnit\Framework\TestCase;

/**
 * 1.2: the api:* commands are flocms-cli commands, found through
 * composer.json "extra.flocms" like any package's.
 */
final class CliKernelTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'flo-api-kernel-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0777, true);
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/api', 0777, true);
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'test/site', 'require' => ['hostkurd/flocms-core' => '2.2.0']]));
        file_put_contents($this->root . '/.env', "APP_ENV=local\nDB_TYPE=sqlite\nDB_NAME=storage/database.sqlite\n");
        file_put_contents($this->root . '/api/routes.php', <<<'PHP'
            <?php
            use FloCMS\Api\Router;
            return static function (Router $router): void {
                $router->version('v1', static function (Router $router): void {
                    $router->get('/health', static fn (): array => ['status' => 'ok'])->name('health');
                    $router->post('/contact', [\FloCMS\Api\Http\HealthController::class, 'store'])->name('contact.store')->middleware('throttle:forms');
                });
            };
            PHP);
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'hostkurd/flocms-api',
            'install-path' => '../hostkurd/flocms-api',
            'extra' => $composer['extra'],
        ]]]));
    }

    protected function tearDown(): void
    {
        \FloCMS\Core\App::$db = null;
        gc_collect_cycles();
        self::delete($this->root);
    }

    public function testComposerRegistersEveryCommand(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        self::assertSame(Application::COMMANDS, $composer['extra']['flocms']['commands']);
        self::assertSame('^2.0', $composer['require']['hostkurd/flocms-cli']);
    }

    public function testApiCommandsRunThroughFlo(): void
    {
        $tester = ApplicationTester::forProject($this->root);

        self::assertSame(0, $tester->run(['list', 'api']));
        foreach (['api:install-schema', 'api:key:create', 'api:token:revoke', 'api:gc', 'api:routes', 'api:docs'] as $name) {
            self::assertStringContainsString($name, $tester->getDisplay());
        }

        self::assertSame(0, $tester->run(['api:install-schema']), $tester->getErrorOutput());
        self::assertStringContainsString('driver sqlite', $tester->getDisplay());
        self::assertSame(0, $tester->run(['api:key:create', 'Partner feed', '--scopes=a,b']));
        self::assertMatchesRegularExpression('/^flk_\d+_[0-9a-f]{40}$/m', $tester->getDisplay());
        self::assertSame(0, $tester->run(['api:key:list']));
        self::assertStringContainsString('Partner feed', $tester->getDisplay());

        // Through flo, invalid usage is exit code 2 with the usage line
        self::assertSame(2, $tester->run(['api:key:create']));
        self::assertStringContainsString('Usage: api:key:create [options] [--] <name>', $tester->getErrorOutput());
        self::assertSame(2, $tester->run(['api:token:create', 'abc', 'x']));

        // "Did you mean" for the old wrong name
        self::assertSame(1, $tester->run(['api:create:schema']));
        self::assertStringContainsString('api:install-schema', $tester->getErrorOutput());

        self::assertSame(0, $tester->run(['api:list']));
        self::assertStringContainsString('api:key:list', $tester->getDisplay());
    }

    public function testApiRoutesAppearInRouteList(): void
    {
        $tester = ApplicationTester::forProject($this->root);

        self::assertSame(0, $tester->run(['route:list', '--source=api', '--format=json']), $tester->getErrorOutput());
        $routes = json_decode($tester->getDisplay(), true);

        self::assertSame(['/api/v1/health', '/api/v1/contact'], array_column($routes, 'uri'));
        self::assertSame('v1.contact.store', $routes[1]['name']);
        self::assertSame('POST', $routes[1]['method']);
        self::assertSame('HealthController@store', $routes[1]['action']);
        self::assertSame(['throttle:forms'], $routes[1]['middleware']);
    }

    private static function delete(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $item) {
            self::delete($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
