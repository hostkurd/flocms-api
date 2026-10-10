<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Query\QueryOptions;
use FloCMS\Api\Tests\Support\Databases;
use FloCMS\Api\Tests\Unit\MakesRequests;
use FloCMS\Core\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class QueryOptionsTest extends TestCase
{
    use MakesRequests;

    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        $this->pdo?->exec('DROP TABLE IF EXISTS flo_test_homes');
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testFiltersSortAndFieldsReachTheQuery(string $driver): void
    {
        $db = $this->seed($driver);
        $options = $this->options('/v1/homes?sort=-price,title&filter[status]=sale&filter[price][gte]=150&fields=id,title,cost');

        $rows = $options->applyTo($db->table('flo_test_homes'))->get();

        self::assertSame([['4', 'D', '400'], ['3', 'C', '300'], ['5', 'E', '300']], array_map(
            static fn (object $row): array => [(string) $row->id, $row->title, (string) $row->price],
            $rows
        ));
        self::assertSame(['id', 'title', 'price'], array_keys(get_object_vars($rows[0])));
        self::assertSame(['id' => $rows[0]->id, 'title' => 'D'], $options->only(['id' => $rows[0]->id, 'title' => 'D', 'secret' => 'x']));
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testInFilterAndDefaultSort(string $driver): void
    {
        $db = $this->seed($driver);
        $options = $this->options('/v1/homes?filter[status][in]=rent,sold');

        $rows = $options->applyTo($db->table('flo_test_homes'))->get();

        self::assertSame(['B', 'F'], array_column(array_map('get_object_vars', $rows), 'title'));
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testInjectionAttemptsNeverReachSql(string $driver): void
    {
        $db = $this->seed($driver);

        $rows = $this->options("/v1/homes?filter[status]=" . rawurlencode("sale' OR '1'='1"))->applyTo($db->table('flo_test_homes'))->get();
        self::assertSame([], $rows);

        foreach (['sort=' . rawurlencode('price;DROP TABLE flo_test_homes'), 'filter[' . rawurlencode('1=1 OR status') . ']=x', 'fields=' . rawurlencode('id,(SELECT 1)')] as $query) {
            try {
                $this->options('/v1/homes?' . $query);
                self::fail('Expected a 422 for ' . $query);
            } catch (ValidationException $error) {
                self::assertSame(422, $error->status);
            }
        }
        self::assertCount(6, $db->table('flo_test_homes')->get());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function rejected(): iterable
    {
        yield 'unknown sort' => ['sort=secret', 'sort'];
        yield 'too many sorts' => ['sort=price,title,price,title,price,title', 'sort'];
        yield 'unknown filter' => ['filter[secret]=1', 'filter.secret'];
        yield 'operator not allowed' => ['filter[status][gt]=a', 'filter.status'];
        yield 'array value' => ['filter[status][eq][]=a', 'filter.status'];
        yield 'filter not an array' => ['filter=status', 'filter'];
        yield 'unknown field' => ['fields=id,secret', 'fields'];
        yield 'empty fields' => ['fields=', 'fields'];
        yield 'empty in' => ['filter[status][in]=,', 'filter.status'];
    }

    #[DataProvider('rejected')]
    public function testInvalidParametersAreA422(string $query, string $field): void
    {
        try {
            $this->options('/v1/homes?' . $query);
            self::fail('Expected ValidationException');
        } catch (ValidationException $error) {
            self::assertArrayHasKey($field, $error->errors);
        }
    }

    public function testInvalidWhitelistIsADeveloperError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryOptions::fromRequest($this->request('GET', '/'), sortable: ['price desc']);
    }

    private function options(string $uri): QueryOptions
    {
        return QueryOptions::fromRequest(
            $this->request('GET', $uri),
            sortable: ['price', 'title'],
            filterable: ['status' => ['eq', 'in'], 'price' => ['gte', 'lte']],
            fields: ['id', 'title', 'cost' => 'price'],
            defaultSort: 'title'
        );
    }

    private function seed(string $driver): Database
    {
        $this->pdo = Databases::connect($driver)->pdo();
        $this->pdo->exec('DROP TABLE IF EXISTS flo_test_homes');
        $this->pdo->exec('CREATE TABLE flo_test_homes (id INTEGER PRIMARY KEY, title VARCHAR(10), price INTEGER, status VARCHAR(10), secret VARCHAR(10))');
        $insert = $this->pdo->prepare('INSERT INTO flo_test_homes VALUES (?, ?, ?, ?, ?)');
        foreach ([[1, 'A', 100, 'sale'], [2, 'B', 200, 'rent'], [3, 'C', 300, 'sale'], [4, 'D', 400, 'sale'], [5, 'E', 300, 'sale'], [6, 'F', 50, 'sold']] as $row) {
            $insert->execute([...$row, 'x']);
        }
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

        return new Database($this->pdo);
    }
}
