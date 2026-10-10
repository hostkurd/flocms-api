<?php
declare(strict_types=1);

namespace FloCMS\Api\Database;

use FloCMS\Core\App;
use PDO;
use RuntimeException;

/**
 * The PDO connection used by the API's database-backed features (API keys,
 * tokens, idempotency, health checks).
 *
 * Pass a PDO explicitly, or let it use the FloCMS connection (App::db()) on
 * first use, so building the API never touches the database. Works with
 * MySQL/MariaDB and SQLite.
 */
final class Connection
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $database = App::db();
            if ($database === null) {
                throw new RuntimeException('No database is configured (set DB_NAME and DB_USERNAME).');
            }
            $this->pdo = $database->pdo();
        }

        return $this->pdo;
    }

    public function driver(): string
    {
        return strtolower((string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Run a prepared statement.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Current time as stored in DATETIME columns. */
    public static function now(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }
}
