<?php
declare(strict_types=1);

namespace FloCMS\Api\Database;

use RuntimeException;

/**
 * Creates the API's tables from database/schema/<driver>.sql.
 * The statements use IF NOT EXISTS, so installing again is harmless.
 */
final class SchemaInstaller
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function schemaFile(string $driver): string
    {
        $file = dirname(__DIR__, 2) . '/database/schema/' . $driver . '.sql';
        if (!is_file($file)) {
            throw new RuntimeException('No API schema for database driver "' . $driver . '" (supported: mysql, sqlite).');
        }

        return $file;
    }

    /** @return int number of statements run */
    public function install(): int
    {
        $sql = (string) file_get_contents(self::schemaFile($this->connection->driver()));
        $count = 0;
        foreach (self::statements($sql) as $statement) {
            $this->connection->pdo()->exec($statement);
            $count++;
        }

        return $count;
    }

    /**
     * Split a schema file into statements. The files hold only DDL without
     * semicolons inside strings, so splitting on ";" at line ends is enough.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = [];
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
