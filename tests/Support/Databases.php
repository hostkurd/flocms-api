<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Support;

use FloCMS\Api\Database\Connection;
use FloCMS\Api\Database\SchemaInstaller;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Database connections for integration tests.
 *
 * SQLite (in memory) always runs. MySQL/MariaDB runs when FLO_TEST_MYSQL_HOST
 * is set (optionally FLO_TEST_MYSQL_PORT, _USER, _PASS, _NAME), the same
 * variables the FloCMS skeleton's tests use.
 */
final class Databases
{
    /** @return iterable<string, array{0: string}> data provider */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
    }

    /** A connection with the API schema installed and its tables emptied. */
    public static function connect(string $driver): Connection
    {
        $connection = new Connection(self::pdo($driver));
        (new SchemaInstaller($connection))->install();
        foreach (['api_keys', 'api_tokens', 'api_idempotency'] as $table) {
            if (self::tableExists($connection, $table)) {
                $connection->pdo()->exec('DELETE FROM ' . $table);
            }
        }

        return $connection;
    }

    private static function pdo(string $driver): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if ($driver === 'sqlite') {
            return new PDO('sqlite::memory:', null, null, $options);
        }

        $host = getenv('FLO_TEST_MYSQL_HOST');
        if ($host === false || $host === '') {
            TestCase::markTestSkipped('Set FLO_TEST_MYSQL_HOST to run the MySQL/MariaDB tests.');
        }

        return new PDO(
            'mysql:host=' . $host
            . ';port=' . (int) (getenv('FLO_TEST_MYSQL_PORT') ?: 3306)
            . ';dbname=' . (getenv('FLO_TEST_MYSQL_NAME') ?: 'flocms_api_test')
            . ';charset=utf8mb4',
            (string) (getenv('FLO_TEST_MYSQL_USER') ?: 'root'),
            (string) (getenv('FLO_TEST_MYSQL_PASS') ?: ''),
            $options
        );
    }

    private static function tableExists(Connection $connection, string $table): bool
    {
        $sql = $connection->driver() === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?"
            : 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';

        return $connection->first($sql, [$table]) !== null;
    }
}
