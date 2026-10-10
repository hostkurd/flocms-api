<?php
declare(strict_types=1);

namespace FloCMS\Api\Query;

use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Core\Database;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;

/**
 * Sorting, filtering and sparse fieldsets for list endpoints, limited to
 * whitelisted columns so client input never becomes an SQL identifier:
 *
 *     $options = QueryOptions::fromRequest(
 *         $request,
 *         sortable:   ['price', 'created_at'],
 *         filterable: ['status' => ['eq', 'in'], 'price' => ['gte', 'lte'], 'city'],
 *         fields:     ['id', 'title', 'price', 'status'],
 *         defaultSort: '-created_at',
 *     );
 *     $result = $options->applyTo($db->table('properties'))->paginate($page->page, $page->perPage);
 *
 *     GET /v1/properties?sort=-price,created_at&filter[status]=sale&filter[price][gte]=100000&fields=id,title
 *
 * Whitelists are lists of names, or maps of public name => column
 * (['price' => 'p.price']). Filter operators: eq (the default), ne, gt, gte,
 * lt, lte, in (comma separated). Anything not whitelisted is a 422.
 */
final class QueryOptions
{
    private const OPERATORS = ['eq' => '=', 'ne' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
    private const MAX_SORTS = 5;
    private const MAX_IN_VALUES = 50;

    /**
     * @param list<array{0: string, 1: 'ASC'|'DESC'}> $sort column, direction
     * @param list<array{0: string, 1: string, 2: string|list<string>}> $filters column, operator, value
     * @param list<string>|null $fields public names, or null when not requested
     * @param array<string, string> $fieldColumns public name => column
     */
    private function __construct(
        public readonly array $sort,
        public readonly array $filters,
        public readonly ?array $fields,
        private readonly array $fieldColumns
    ) {
    }

    /**
     * @param array<int|string, string> $sortable
     * @param array<int|string, string|list<string>> $filterable name, or name => operators
     * @param array<int|string, string> $fields
     */
    public static function fromRequest(
        Request $request,
        array $sortable = [],
        array $filterable = [],
        array $fields = [],
        string $defaultSort = ''
    ): self {
        $errors = [];
        $sortColumns = self::columnMap($sortable);
        $fieldColumns = self::columnMap($fields);

        $sortParam = $request->queryValue('sort');
        $sort = self::parseSort(is_string($sortParam) && $sortParam !== '' ? $sortParam : $defaultSort, $sortColumns, $errors);
        if ($sortParam !== null && !is_string($sortParam)) {
            $errors['sort'] = ['sort must be a comma-separated list.'];
        }

        $filters = self::parseFilters($request->queryValue('filter'), $filterable, $errors);

        $selected = null;
        $fieldsParam = $request->queryValue('fields');
        if ($fieldsParam !== null) {
            $requested = is_string($fieldsParam) ? array_values(array_unique(array_filter(array_map('trim', explode(',', $fieldsParam))))) : null;
            $unknown = $requested === null ? ['(invalid)'] : array_diff($requested, array_keys($fieldColumns));
            if ($requested === [] || $unknown !== []) {
                $errors['fields'] = ['Allowed fields: ' . implode(', ', array_keys($fieldColumns)) . '.'];
            } else {
                $selected = $requested;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($sort, $filters, $selected, $fieldColumns);
    }

    /** Apply filters, sorting and (when ?fields= was given) the column selection. */
    public function applyTo(Database $query): Database
    {
        foreach ($this->filters as [$column, $operator, $value]) {
            if ($operator === 'in') {
                $query->whereIn($column, (array) $value);
            } else {
                $query->where($column, self::OPERATORS[$operator], $value);
            }
        }
        foreach ($this->sort as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }
        if ($this->fields !== null) {
            $query->select(array_map(fn (string $field): string => $this->fieldColumns[$field], $this->fields));
        }

        return $query;
    }

    /**
     * Keep only the requested fields of an output row (array or object).
     * Without ?fields=, the row is returned unchanged.
     *
     * @return array<string, mixed>|object
     */
    public function only(array|object $row): array|object
    {
        if ($this->fields === null) {
            return $row;
        }
        $values = is_array($row) ? $row : get_object_vars($row);

        return array_intersect_key($values, array_flip($this->fields));
    }

    /**
     * @param array<int|string, string|list<string>> $whitelist
     * @return array<string, string> public name => column
     */
    private static function columnMap(array $whitelist): array
    {
        $map = [];
        foreach ($whitelist as $key => $value) {
            [$name, $column] = is_int($key) ? [(string) $value, (string) $value] : [$key, is_string($value) ? $value : $key];
            self::assertIdentifier($column);
            $map[$name] = $column;
        }

        return $map;
    }

    /**
     * @param array<string, string> $columns
     * @param array<string, list<string>> $errors
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    private static function parseSort(string $value, array $columns, array &$errors): array
    {
        $sort = [];
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
        if (count($parts) > self::MAX_SORTS) {
            $errors['sort'] = ['At most ' . self::MAX_SORTS . ' sort fields are allowed.'];

            return [];
        }
        foreach ($parts as $part) {
            $descending = str_starts_with($part, '-');
            $name = ltrim($part, '-+');
            if (!isset($columns[$name])) {
                $errors['sort'] = ['Sortable fields: ' . ($columns === [] ? 'none' : implode(', ', array_keys($columns))) . '.'];

                return [];
            }
            $sort[] = [$columns[$name], $descending ? 'DESC' : 'ASC'];
        }

        return $sort;
    }

    /**
     * @param array<int|string, string|list<string>> $filterable
     * @param array<string, list<string>> $errors
     * @return list<array{0: string, 1: string, 2: string|list<string>}>
     */
    private static function parseFilters(mixed $input, array $filterable, array &$errors): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (!is_array($input)) {
            $errors['filter'] = ['Use filter[field]=value.'];

            return [];
        }

        $allowed = [];
        foreach ($filterable as $key => $value) {
            if (is_int($key)) {
                $allowed[(string) $value] = ['column' => (string) $value, 'operators' => ['eq']];
                continue;
            }
            $operators = is_array($value) ? array_values($value) : ['eq'];
            foreach ($operators as $operator) {
                if ($operator !== 'in' && !isset(self::OPERATORS[$operator])) {
                    throw new InvalidArgumentException('Unknown filter operator: ' . $operator);
                }
            }
            $allowed[$key] = ['column' => $key, 'operators' => $operators];
        }
        foreach ($allowed as $spec) {
            self::assertIdentifier($spec['column']);
        }

        $filters = [];
        foreach ($input as $name => $value) {
            $name = (string) $name;
            $spec = $allowed[$name] ?? null;
            if ($spec === null) {
                $errors['filter.' . $name] = ['Filterable fields: ' . ($allowed === [] ? 'none' : implode(', ', array_keys($allowed))) . '.'];
                continue;
            }

            $conditions = is_array($value) ? $value : ['eq' => $value];
            foreach ($conditions as $operator => $operand) {
                $operator = (string) $operator;
                if (!in_array($operator, $spec['operators'], true)) {
                    $errors['filter.' . $name] = ['Allowed operators for ' . $name . ': ' . implode(', ', $spec['operators']) . '.'];
                    continue;
                }
                if (!is_string($operand) && !is_int($operand) && !is_float($operand)) {
                    $errors['filter.' . $name] = [$name . ' must be a single value.'];
                    continue;
                }
                if ($operator === 'in') {
                    $list = array_values(array_filter(array_map('trim', explode(',', (string) $operand)), static fn (string $v): bool => $v !== ''));
                    if ($list === [] || count($list) > self::MAX_IN_VALUES) {
                        $errors['filter.' . $name] = [$name . ' needs 1 to ' . self::MAX_IN_VALUES . ' comma-separated values.'];
                        continue;
                    }
                    $filters[] = [$spec['column'], 'in', $list];
                    continue;
                }
                $filters[] = [$spec['column'], $operator, (string) $operand];
            }
        }

        return $filters;
    }

    private static function assertIdentifier(string $column): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException('Invalid column in query whitelist: ' . $column);
        }
    }
}
