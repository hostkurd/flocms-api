<?php
declare(strict_types=1);

namespace FloCMS\Api\Pagination;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Core\Database;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;

/**
 * Cursor ("keyset") pagination for large lists: instead of OFFSET, each page
 * continues after the last row of the previous one (?cursor=...), so deep
 * pages stay fast and rows are not skipped when new ones are inserted.
 *
 *     $page = CursorPaginator::paginate(
 *         $db->table('inquiries')->where('status', '=', 'new'),
 *         $request,
 *         column: 'id',
 *         direction: 'desc',
 *     );
 *     return ResponseFactory::cursorPaginated($page, $request, InquiryResource::class);
 *
 * $column must be unique and non-null (normally the primary key) and must be
 * selected by the query. The cursor is opaque to clients.
 */
final class CursorPaginator
{
    /**
     * @return array{data: list<mixed>, per_page: int, next_cursor: ?string, has_more: bool}
     */
    public static function paginate(
        Database $query,
        Request $request,
        string $column = 'id',
        string $direction = 'asc',
        int $defaultPerPage = 20,
        int $maxPerPage = 100,
        string $cursorKey = 'cursor'
    ): array {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException('Invalid cursor column: ' . $column);
        }
        $direction = strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException('Cursor direction must be asc or desc.');
        }

        $perPage = PageRequest::fromRequest($request, $defaultPerPage, $maxPerPage)->perPage;
        $after = self::decode($request->queryValue($cursorKey), $cursorKey);
        if ($after !== null) {
            $query->where($column, $direction === 'asc' ? '>' : '<', $after);
        }

        $rows = $query->orderBy($column, strtoupper($direction))->limit($perPage + 1)->get();
        $hasMore = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[count($rows) - 1];
            $field = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
            $value = is_array($last) ? ($last[$field] ?? null) : ($last->{$field} ?? null);
            if ($value === null) {
                throw new InvalidArgumentException('The cursor column "' . $field . '" must be selected and not null.');
            }
            $next = self::encode($value);
        }

        return ['data' => $rows, 'per_page' => $perPage, 'next_cursor' => $next, 'has_more' => $hasMore];
    }

    public static function encode(int|float|string $value): string
    {
        return rtrim(strtr(base64_encode(json_encode(['v' => $value], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private static function decode(mixed $cursor, string $key): int|float|string|null
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = is_string($cursor) && strlen($cursor) <= 512
            ? base64_decode(strtr($cursor, '-_', '+/'), true)
            : false;
        $data = $decoded !== false ? json_decode($decoded, true) : null;
        $value = is_array($data) ? ($data['v'] ?? null) : null;
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new ValidationException([$key => ['The cursor is invalid.']]);
        }

        return $value;
    }
}
