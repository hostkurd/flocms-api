<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Tests\Support\Databases;
use FloCMS\Api\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class DatabaseRulesTest extends TestCase
{
    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testExistsAndUnique(string $driver): void
    {
        $db = Databases::connect($driver);
        $db->pdo()->exec('DROP TABLE IF EXISTS flo_test_agents');
        $db->pdo()->exec('CREATE TABLE flo_test_agents (id INTEGER PRIMARY KEY, email VARCHAR(100) NOT NULL)');
        $db->execute('INSERT INTO flo_test_agents (id, email) VALUES (1, ?), (2, ?)', ['a@example.com', 'b@example.com']);
        $validator = new Validator($db);

        try {
            self::assertSame(['agent' => 1], $validator->validate(['agent' => '1'], ['agent' => 'required|integer|exists:flo_test_agents,id']));
            self::assertFalse($this->passes($validator, ['agent' => '3'], ['agent' => 'required|integer|exists:flo_test_agents,id']));
            self::assertFalse($this->passes($validator, ['agent' => ['1']], ['agent' => 'required|exists:flo_test_agents,id']));

            // A value that looks like SQL is just a value.
            self::assertFalse($this->passes($validator, ['email' => "x' OR '1'='1"], ['email' => 'required|exists:flo_test_agents,email']));

            self::assertTrue($this->passes($validator, ['email' => 'new@example.com'], ['email' => 'required|email|unique:flo_test_agents,email']));
            self::assertFalse($this->passes($validator, ['email' => 'a@example.com'], ['email' => 'required|unique:flo_test_agents,email']));
            // Updating agent 1 may keep its own email, but not take agent 2's.
            self::assertTrue($this->passes($validator, ['email' => 'a@example.com'], ['email' => 'required|unique:flo_test_agents,email,1']));
            self::assertFalse($this->passes($validator, ['email' => 'b@example.com'], ['email' => 'required|unique:flo_test_agents,email,1,id']));
        } finally {
            $db->pdo()->exec('DROP TABLE flo_test_agents');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     */
    private function passes(Validator $validator, array $data, array $rules): bool
    {
        try {
            $validator->validate($data, $rules);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
