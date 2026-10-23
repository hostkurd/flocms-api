<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Console\Commands\InstallCommand;
use FloCMS\CLI\Console\Testing\ApplicationTester;
use PHPUnit\Framework\TestCase;

/**
 * 1.3: `php flo api:install` adds the API to a site (the skeleton no longer
 * ships it). It never changes a file that exists.
 */
final class InstallCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'flo-api-install-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0777, true);
        mkdir($this->root . '/public', 0777, true);
        mkdir($this->root . '/vendor/composer', 0777, true);
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/site',
            'autoload' => ['psr-4' => ['App\\Api\\' => 'api/']],
        ]));
        file_put_contents($this->root . '/.env', "APP_ENV=local\nDB_TYPE=sqlite\nDB_NAME=storage/database.sqlite\n");
        file_put_contents($this->root . '/public/.htaccess', "RewriteEngine on\nRewriteRule ^api/v1(/.*)?$ api.php [L,QSA]\nRewriteRule ^(.*)$ index.php [PT,L]\n");
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'hostkurd/flocms-api',
            'install-path' => '../hostkurd/flocms-api',
            'extra' => $composer['extra'],
        ]]]));
    }

    protected function tearDown(): void
    {
        self::delete($this->root);
    }

    public function testItWritesTheApiFiles(): void
    {
        $tester = ApplicationTester::forProject($this->root);

        self::assertSame(0, $tester->run(['api:install']), $tester->getErrorOutput());
        $display = $tester->getDisplay();
        foreach (array_keys(InstallCommand::FILES) as $file) {
            self::assertFileExists($this->root . '/' . $file);
            self::assertStringContainsString('Created ' . $file, $display);
            self::assertSame(InstallCommand::stub(InstallCommand::FILES[$file]), file_get_contents($this->root . '/' . $file));
        }
        self::assertStringContainsString('php flo migrate', $display);
        self::assertStringNotContainsString('composer.json does not load', $display);
        self::assertStringNotContainsString('.htaccess has no rule', $display);

        // The routes load, with the example controllers.
        self::assertSame(0, $tester->run(['api:routes']), $tester->getErrorOutput());
        $routes = $tester->getDisplay();
        foreach (['/api/v1/health', '/api/v1/contact', '/api/v1/users'] as $path) {
            self::assertStringContainsString($path, $routes);
        }
    }

    public function testItNeverChangesAnExistingFile(): void
    {
        mkdir($this->root . '/api', 0777, true);
        file_put_contents($this->root . '/api/routes.php', "<?php // mine\n");
        $tester = ApplicationTester::forProject($this->root);

        self::assertSame(0, $tester->run(['api:install']), $tester->getErrorOutput());
        self::assertSame("<?php // mine\n", file_get_contents($this->root . '/api/routes.php'));
        self::assertStringContainsString('api/routes.php already exists: left as it is.', $tester->getDisplay());
        self::assertFileExists($this->root . '/public/api.php');

        // A second run adds nothing.
        file_put_contents($this->root . '/public/api.php', "<?php // edited\n");
        self::assertSame(0, $tester->run(['api:install']));
        self::assertStringContainsString('Nothing to add', $tester->getDisplay());
        self::assertSame("<?php // edited\n", file_get_contents($this->root . '/public/api.php'));
    }

    public function testMinimalWritesOnlyTheFrontControllerAndAHealthRoute(): void
    {
        $tester = ApplicationTester::forProject($this->root);

        self::assertSame(0, $tester->run(['api:install', '--minimal']), $tester->getErrorOutput());
        self::assertFileExists($this->root . '/public/api.php');
        self::assertFileExists($this->root . '/api/routes.php');
        self::assertDirectoryDoesNotExist($this->root . '/api/Controllers');

        self::assertSame(0, $tester->run(['api:routes']), $tester->getErrorOutput());
        self::assertStringContainsString('/api/v1/health', $tester->getDisplay());
        self::assertStringNotContainsString('/api/v1/contact', $tester->getDisplay());
    }

    public function testItSaysWhatIsMissingInComposerJsonAndHtaccess(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'test/site']));
        file_put_contents($this->root . '/public/.htaccess', "RewriteEngine on\nRewriteRule ^(.*)$ index.php [PT,L]\n");
        $tester = ApplicationTester::forProject($this->root);

        self::assertSame(0, $tester->run(['api:install']), $tester->getErrorOutput());
        $display = $tester->getDisplay() . $tester->getErrorOutput();
        self::assertStringContainsString('composer.json does not load App\\Api\\ from api/', $display);
        self::assertStringContainsString('"App\\\\Api\\\\": "api/"', $display);
        self::assertStringContainsString('public/.htaccess has no rule for /api/v1', $display);
        self::assertStringContainsString('RewriteRule ^api/v1(/.*)?$ api.php [L,QSA]', $display);
    }

    public function testApiRoutesWithoutTheApiSaysHowToAddIt(): void
    {
        $tester = ApplicationTester::forProject($this->root);

        self::assertNotSame(0, $tester->run(['api:routes']));
        self::assertStringContainsString('Run `php flo api:install`', $tester->getDisplay() . $tester->getErrorOutput());
    }

    public function testEveryStubIsValidPhpAndUsesClassesThatExist(): void
    {
        foreach (InstallCommand::FILES as $stub) {
            $file = dirname(__DIR__, 2) . '/stubs/' . $stub;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            self::assertSame(0, $code, $stub . ': ' . implode("\n", $output));

            preg_match_all('/^use (FloCMS\\\\[A-Za-z\\\\]+);/m', InstallCommand::stub($stub), $uses);
            foreach ($uses[1] as $class) {
                self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), $stub . ' uses a missing class: ' . $class);
                self::assertStringNotContainsString('FloCMS\\Api\\RateLimit\\FileRateLimiter', $class, $stub . ': use the core class');
            }
        }
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
