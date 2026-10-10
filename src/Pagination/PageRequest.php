<?php
declare(strict_types=1);

namespace FloCMS\Api\Pagination;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;

/**
 * Page and page size from the query string (?page=2&per_page=50).
 *
 * per_page is clamped to $maxPerPage, so clients cannot ask for a million
 * rows. Values that are not positive integers are a 422.
 */
final class PageRequest
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage
    ) {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and page size must be positive.');
        }
    }

    public static function fromRequest(
        Request $request,
        int $defaultPerPage = 20,
        int $maxPerPage = 100,
        string $pageKey = 'page',
        string $perPageKey = 'per_page'
    ): self {
        $errors = [];
        $page = self::positiveInt($request->queryValue($pageKey), 1, $pageKey, $errors);
        $perPage = self::positiveInt($request->queryValue($perPageKey), $defaultPerPage, $perPageKey, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($page, min($perPage, max(1, $maxPerPage)));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @param array<string, list<string>> $errors */
    private static function positiveInt(mixed $value, int $default, string $key, array &$errors): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1 || strlen($value) > 9) {
            $errors[$key] = [$key . ' must be a positive integer.'];

            return $default;
        }

        return (int) $value;
    }
}
