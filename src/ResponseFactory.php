<?php
declare(strict_types=1);

namespace FloCMS\Api;

use DateTimeInterface;
use FloCMS\Api\Pagination\Links;
use FloCMS\Api\Resources\JsonResource;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ResponseFactory
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Responses encoded with substituted invalid UTF-8 since the last take. */
    private static int $invalidUtf8 = 0;

    /** Request ID to add to success bodies (Kernel::includeRequestIdInBody()). */
    private static ?string $bodyRequestId = null;

    /** @param array<string, mixed> $meta */
    public static function success(
        mixed $data = null,
        int $status = 200,
        array $meta = []
    ): Response {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        if (self::$bodyRequestId !== null) {
            $payload['request_id'] = self::$bodyRequestId;
        }

        return self::json($payload, $status);
    }

    /** @param array<string, mixed> $errors */
    public static function error(
        string $message,
        int $status,
        array $errors = [],
        ?string $requestId = null
    ): Response {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        if ($requestId !== null) {
            $payload['request_id'] = $requestId;
        }

        return self::json($payload, $status);
    }

    /**
     * A page of core Database::paginate() (or Model) results:
     *
     *     {"success": true, "data": [...],
     *      "meta": {"page": 2, "per_page": 20, "total": 95, "last_page": 5},
     *      "links": {"first": "...?page=1", "prev": "...?page=1", "next": "...?page=3", "last": "...?page=5"}}
     *
     * Links keep the current query string (filters, sort, per_page) and are
     * relative to the host. $map shapes each row: a JsonResource class, a
     * callable, or null for the rows as they are.
     *
     * @param array{data: iterable<mixed>, pagination: array<string, mixed>} $result
     * @param class-string<JsonResource>|callable|null $map
     */
    public static function paginated(array $result, Request $request, string|callable|null $map = null): Response
    {
        $pagination = $result['pagination'] ?? null;
        if (!is_array($pagination) || !array_key_exists('data', $result)) {
            throw new InvalidArgumentException('Expected the array returned by Database::paginate().');
        }

        $page = max(1, (int) ($pagination['cur_page'] ?? 1));
        $perPage = max(1, (int) ($pagination['per_page'] ?? 1));
        $total = max(0, (int) ($pagination['items_count'] ?? 0));
        $lastPage = max(1, (int) ($pagination['last_page'] ?? $pagination['total_pages'] ?? 1));
        $link = static fn (int $target): string => Links::to($request, ['page' => $target]);

        return self::json(self::withRequestId([
            'success' => true,
            'data' => self::mapRows($result['data'], $request, $map),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
            'links' => [
                'first' => $link(1),
                'prev' => $page > 1 ? $link(min($page - 1, $lastPage)) : null,
                'next' => $page < $lastPage ? $link($page + 1) : null,
                'last' => $link($lastPage),
            ],
        ]), 200);
    }

    /**
     * A page from Pagination\CursorPaginator::paginate():
     * {"data": [...], "meta": {"per_page", "next_cursor", "has_more"}, "links": {"next"}}.
     *
     * @param array{data: iterable<mixed>, per_page: int, next_cursor: ?string, has_more: bool} $result
     * @param class-string<JsonResource>|callable|null $map
     */
    public static function cursorPaginated(array $result, Request $request, string|callable|null $map = null): Response
    {
        $next = $result['next_cursor'] ?? null;

        return self::json(self::withRequestId([
            'success' => true,
            'data' => self::mapRows($result['data'] ?? [], $request, $map),
            'meta' => [
                'per_page' => (int) ($result['per_page'] ?? 0),
                'next_cursor' => $next,
                'has_more' => (bool) ($result['has_more'] ?? false),
            ],
            'links' => ['next' => $next !== null ? Links::to($request, ['cursor' => $next]) : null],
        ]), 200);
    }

    /**
     * @param iterable<mixed> $rows
     * @param class-string<JsonResource>|callable|null $map
     * @return list<mixed>
     */
    private static function mapRows(iterable $rows, Request $request, string|callable|null $map): array
    {
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);
        if ($map === null) {
            return $rows;
        }
        if (is_string($map) && is_subclass_of($map, JsonResource::class)) {
            JsonResource::assertIncludes($request, $map::collection([])->allowedIncludes());

            return $map::collection($rows)->resolve($request);
        }
        if (!is_callable($map)) {
            throw new InvalidArgumentException('$map must be a JsonResource class or a callable.');
        }

        return array_map(static fn (mixed $row): mixed => JsonResource::resolveValue($map($row), $request), $rows);
    }

    /**
     * Set Last-Modified (HTTP date, GMT) so ETagMiddleware can answer
     * If-Modified-Since with 304. Accepts a timestamp, a date string (e.g. an
     * updated_at column) or a DateTimeInterface.
     */
    public static function withLastModified(Response $response, DateTimeInterface|int|string $modified): Response
    {
        $timestamp = match (true) {
            $modified instanceof DateTimeInterface => $modified->getTimestamp(),
            is_int($modified) => $modified,
            default => strtotime($modified),
        };
        if ($timestamp === false) {
            throw new InvalidArgumentException('Invalid Last-Modified value: ' . $modified);
        }

        return $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
    }

    /**
     * Set or clear the request ID added to success bodies.
     *
     * @internal called by the Kernel around each request
     */
    public static function setBodyRequestId(?string $requestId): void
    {
        self::$bodyRequestId = $requestId;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function withRequestId(array $payload): array
    {
        if (self::$bodyRequestId !== null) {
            $payload['request_id'] = self::$bodyRequestId;
        }

        return $payload;
    }

    /**
     * Returns how many responses had invalid UTF-8 replaced by U+FFFD since
     * the last call, and resets the count. Used by the Kernel to log a warning.
     *
     * @internal
     */
    public static function takeInvalidUtf8Count(): int
    {
        $count = self::$invalidUtf8;
        self::$invalidUtf8 = 0;

        return $count;
    }

    /**
     * Like core Response::json(), but one bad byte from the database does not
     * turn the response into a 500: invalid UTF-8 is replaced by U+FFFD.
     */
    private static function json(mixed $payload, int $status): Response
    {
        try {
            $body = json_encode($payload, self::JSON_FLAGS);
        } catch (JsonException $error) {
            if ($error->getCode() !== JSON_ERROR_UTF8) {
                throw new RuntimeException('Unable to encode JSON response.', 0, $error);
            }

            try {
                $body = json_encode($payload, self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (JsonException $retryError) {
                throw new RuntimeException('Unable to encode JSON response.', 0, $retryError);
            }
            self::$invalidUtf8++;
        }

        return Response::make($body, $status)
            ->header('Content-Type', 'application/json; charset=utf-8');
    }
}
