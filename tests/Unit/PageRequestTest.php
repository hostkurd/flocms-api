<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\Pagination\PageRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageRequestTest extends TestCase
{
    use MakesRequests;

    public function testDefaultsAndClamping(): void
    {
        $default = PageRequest::fromRequest($this->request('GET', '/v1/x'));
        self::assertSame([1, 20, 0], [$default->page, $default->perPage, $default->offset()]);

        $clamped = PageRequest::fromRequest($this->request('GET', '/v1/x?page=3&per_page=5000'), 20, 100);
        self::assertSame([3, 100, 200], [$clamped->page, $clamped->perPage, $clamped->offset()]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidQueries(): iterable
    {
        yield 'text page' => ['page=abc'];
        yield 'zero page' => ['page=0'];
        yield 'negative per_page' => ['per_page=-5'];
        yield 'array page' => ['page[]=1'];
        yield 'huge page' => ['page=99999999999999999999'];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidValuesAreA422(string $query): void
    {
        $this->expectException(ValidationException::class);

        PageRequest::fromRequest($this->request('GET', '/v1/x?' . $query));
    }
}
