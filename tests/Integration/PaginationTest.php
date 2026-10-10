<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Integration;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Pagination\CursorPaginator;
use FloCMS\Api\Pagination\PageRequest;
use FloCMS\Api\Resources\JsonResource;
use FloCMS\Api\ResponseFactory;
use FloCMS\Api\Tests\Support\Databases;
use FloCMS\Api\Tests\Unit\MakesRequests;
use FloCMS\Core\Database;
use FloCMS\Core\Http\Request;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    use MakesRequests;

    private ?Database $database = null;

    protected function tearDown(): void
    {
        $this->database?->pdo()->exec('DROP TABLE IF EXISTS flo_test_listings');
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testPaginatedEnvelopeFromCorePaginate(string $driver): void
    {
        $db = $this->seed($driver, 45);
        $request = $this->request('GET', '/api/v1/listings?status=sale&page=2&per_page=10');
        $page = PageRequest::fromRequest($request);

        $result = $db->table('flo_test_listings')->where('status', '=', 'sale')->orderBy('id')->paginate($page->page, $page->perPage);
        $payload = $this->payload(ResponseFactory::paginated($result, $request, ListingResource::class));

        self::assertSame(['page' => 2, 'per_page' => 10, 'total' => 23, 'last_page' => 3], $payload['meta']);
        self::assertCount(10, $payload['data']);
        self::assertSame(['id', 'title'], array_keys($payload['data'][0]));
        self::assertSame([
            'first' => '/api/v1/listings?page=1&per_page=10&status=sale',
            'prev' => '/api/v1/listings?page=1&per_page=10&status=sale',
            'next' => '/api/v1/listings?page=3&per_page=10&status=sale',
            'last' => '/api/v1/listings?page=3&per_page=10&status=sale',
        ], $payload['links']);
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testLastPageAndEmptyResults(string $driver): void
    {
        $db = $this->seed($driver, 3);
        $request = $this->request('GET', '/v1/listings?page=1');

        $payload = $this->payload(ResponseFactory::paginated($db->table('flo_test_listings')->paginate(1, 20), $request));
        self::assertNull($payload['links']['prev']);
        self::assertNull($payload['links']['next']);
        self::assertSame(3, $payload['meta']['total']);

        $empty = $this->payload(ResponseFactory::paginated(
            $db->table('flo_test_listings')->where('status', '=', 'none')->paginate(1, 20),
            $request,
            static fn (object $row): array => ['id' => (int) $row->id]
        ));
        self::assertSame([], $empty['data']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1], $empty['meta']);
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testCursorPagination(string $driver): void
    {
        $db = $this->seed($driver, 25);
        $seen = [];
        $uri = '/v1/listings?per_page=10';

        for ($pages = 0; $pages < 5 && $uri !== null; $pages++) {
            $request = $this->request('GET', $uri);
            $page = CursorPaginator::paginate($db->table('flo_test_listings')->select(['id', 'title']), $request, direction: 'desc');
            $payload = $this->payload(ResponseFactory::cursorPaginated($page, $request, ListingResource::class));
            array_push($seen, ...array_column($payload['data'], 'id'));
            $uri = $payload['links']['next'];
        }

        self::assertSame(3, $pages);
        self::assertSame(range(25, 1), $seen);
    }

    #[DataProviderExternal(Databases::class, 'drivers')]
    public function testTamperedCursorIsA422(string $driver): void
    {
        $db = $this->seed($driver, 3);

        $this->expectException(ValidationException::class);
        CursorPaginator::paginate($db->table('flo_test_listings'), $this->request('GET', '/v1/listings?cursor=' . rawurlencode("1' OR 1=1")));
    }

    private function seed(string $driver, int $count): Database
    {
        $pdo = Databases::connect($driver)->pdo();
        $pdo->exec('DROP TABLE IF EXISTS flo_test_listings');
        $pdo->exec('CREATE TABLE flo_test_listings (id INTEGER PRIMARY KEY, title VARCHAR(50), status VARCHAR(10), secret VARCHAR(10))');
        $insert = $pdo->prepare('INSERT INTO flo_test_listings (id, title, status, secret) VALUES (?, ?, ?, ?)');
        for ($i = 1; $i <= $count; $i++) {
            $insert->execute([$i, 'Listing ' . $i, $i % 2 === 1 ? 'sale' : 'rent', 'x']);
        }
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);

        return $this->database = new Database($pdo);
    }
}

final class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'title' => $this->title];
    }
}
