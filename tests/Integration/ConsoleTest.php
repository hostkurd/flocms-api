<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Auth\ApiKeyRepository;
use FloCMS\Api\Auth\TokenRepository;
use FloCMS\Api\Console\Application;
use FloCMS\Api\Database\Connection;
use FloCMS\Api\Tests\Support\Databases;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->out = fopen('php://memory', 'w+');
        $this->err = fopen('php://memory', 'w+');
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testKeyCommands(string $driver): void
    {
        $db = Databases::connect($driver);
        $app = $this->app($db);

        self::assertSame(0, $app->run(['api:install-schema']));
        self::assertSame(0, $app->run(['api:key:create', 'Partner feed', '--scopes=properties.read,pages.read', '--expires=2099-12-31']));
        $output = $this->stdout();
        self::assertSame(1, preg_match('/^(flk_(\d+)_[0-9a-f]{40})$/m', $output, $matches));
        $key = (new ApiKeyRepository($db))->verify($matches[1]);
        self::assertSame(['properties.read', 'pages.read'], $key['scopes']);
        self::assertSame('2099-12-31 00:00:00', $key['expires_at']);

        self::assertSame(0, $app->run(['api:key:list']));
        $list = $this->stdout();
        self::assertStringContainsString('Partner feed', $list);
        self::assertStringNotContainsString(substr($matches[1], -40), $list);

        self::assertSame(0, $app->run(['api:key:revoke', $matches[2]]));
        self::assertNull((new ApiKeyRepository($db))->verify($matches[1]));
        self::assertSame(1, $app->run(['api:key:revoke', $matches[2]]));
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testTokenCommands(string $driver): void
    {
        $db = Databases::connect($driver);
        $app = $this->app($db);

        self::assertSame(0, $app->run(['api:token:create', '12', 'Phone']));
        self::assertSame(1, preg_match('/^(flt_\d+_[0-9a-f]{40})$/m', $this->stdout(), $matches));
        self::assertSame(12, (new TokenRepository($db))->verify($matches[1])['user_id']);

        self::assertSame(0, $app->run(['api:token:list', '--user=12']));
        self::assertStringContainsString('Phone', $this->stdout());

        self::assertSame(0, $app->run(['api:token:revoke', '--user=12']));
        self::assertStringContainsString('1 token(s) of user #12 revoked', $this->stdout());
    }

    public function testUsageErrors(): void
    {
        $app = $this->app(new Connection(new \PDO('sqlite::memory:')));

        self::assertSame(1, $app->run(['api:key:create']));
        self::assertStringContainsString('Usage: api:key:create', $this->errors());
        self::assertSame(1, $app->run(['api:token:create', 'abc', 'x']));
        self::assertSame(1, $app->run(['api:nope']));
        self::assertSame(0, $app->run(['list']));
        self::assertStringContainsString('api:key:create', $this->stdout());
    }

    public function testMissingEnvIsReported(): void
    {
        $app = new Application(sys_get_temp_dir() . '/flo-api-no-project', null, $this->out, $this->err);

        self::assertSame(1, $app->run(['api:key:list']));
        self::assertStringContainsString('No .env file', $this->errors());
    }

    public function testGcCommand(): void
    {
        $root = sys_get_temp_dir() . '/flo-api-gc-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/cache/api-rate-limit', 0777, true);
        $stale = $root . '/storage/cache/api-rate-limit/' . str_repeat('a', 64) . '.json';
        file_put_contents($stale, '{}');
        touch($stale, time() - 100_000);
        $app = new Application($root, null, $this->out, $this->err);

        self::assertSame(0, $app->run(['api:gc']));

        self::assertStringContainsString('1 stale file(s) removed', $this->stdout());
        self::assertFileDoesNotExist($stale);
        rmdir($root . '/storage/cache/api-rate-limit');
        rmdir($root . '/storage/cache');
        rmdir($root . '/storage');
        rmdir($root);
    }

    private function app(Connection $connection): Application
    {
        return new Application(sys_get_temp_dir(), $connection, $this->out, $this->err);
    }

    private function stdout(): string
    {
        return $this->drain($this->out);
    }

    private function errors(): string
    {
        return $this->drain($this->err);
    }

    /** @param resource $stream */
    private function drain($stream): string
    {
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        ftruncate($stream, 0);
        rewind($stream);

        return $contents;
    }
}
