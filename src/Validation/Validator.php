<?php
declare(strict_types=1);

namespace FloCMS\Api\Validation;

use Closure;
use DateTimeImmutable;
use FloCMS\Api\Database\Connection;
use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Core\InRule;
use FloCMS\Core\IntegerRule;
use FloCMS\Core\MaxRule;
use FloCMS\Core\MinRule;
use FloCMS\Core\NumericRule;
use FloCMS\Core\RequiredRule;
use FloCMS\Core\StringRule;
use FloCMS\Core\Validator as CoreValidator;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;

/**
 * Validates input and returns the validated subset, or throws a 422
 * ValidationException with per-field errors:
 *
 *     $data = (new Validator())->validate($request->all(), [
 *         'name'  => 'required|string|max:100',
 *         'email' => 'required|email',
 *         'price' => 'nullable|numeric|between:0,1000000',
 *         'type'  => 'required|in:sale,rent',
 *         'code'  => ['required', 'regex:/^[A-Z]{2}\d+$/'],   // array form for patterns with |
 *         'agent' => 'sometimes|integer|exists:users,id',
 *     ]);
 *
 * Rules:
 * - required, string, integer, numeric, min, max, in: the core Validator rules;
 *   min/max/between compare numbers for integer/numeric fields, otherwise
 *   string length (characters) or array size
 * - email, url (http/https), bool, array, date[:format], between:min,max,
 *   regex:pattern
 * - nullable: null or '' passes and is returned as null
 * - sometimes: rules run only when the field is present
 * - exists:table,column and unique:table,column[,ignoreId[,idColumn]]:
 *   prepared queries; table and column names must be plain identifiers
 *
 * Fields that are absent and not required are left out of the result.
 * Values that pass integer, numeric or bool are returned as int, int|float
 * or bool, so '12' from a query string comes back as 12.
 */
final class Validator
{
    /** @var array<string, Closure(mixed, ?string, string, array<string, mixed>): ?string> */
    private array $extensions = [];

    public function __construct(private ?Connection $connection = null)
    {
        // The core rule classes live in core's Validator.php.
        class_exists(CoreValidator::class);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages 'field.rule' or 'rule' => message (:field is replaced)
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $data, array $rules, array $messages = []): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            $field = (string) $field;
            $parsed = self::parseRules($fieldRules);
            $names = array_column($parsed, 0);
            $present = array_key_exists($field, $data);
            $value = $data[$field] ?? null;

            if (in_array('sometimes', $names, true) && !$present) {
                continue;
            }
            if (in_array('nullable', $names, true) && !in_array('required', $names, true)
                && ($value === null || $value === '')) {
                if ($present) {
                    $validated[$field] = null;
                }
                continue;
            }
            if (!in_array('required', $names, true) && (!$present || $value === null)) {
                continue;
            }

            $numeric = (bool) array_intersect($names, ['integer', 'numeric']);
            $fieldErrors = [];
            foreach ($parsed as [$rule, $parameter]) {
                if ($rule === 'nullable' || $rule === 'sometimes') {
                    continue;
                }
                $error = $this->check($rule, $parameter, $value, $field, $data, $numeric);
                if ($error !== null) {
                    $fieldErrors[] = $messages[$field . '.' . $rule]
                        ?? (isset($messages[$rule]) ? str_replace(':field', $field, $messages[$rule]) : $error);
                    if ($rule === 'required') {
                        break;
                    }
                }
            }

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
                continue;
            }
            $validated[$field] = self::cast($value, $names);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * Validate the request input (query string, form fields and JSON body).
     *
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validateRequest(Request $request, array $rules, array $messages = []): array
    {
        return $this->validate($request->all(), $rules, $messages);
    }

    /**
     * Validate only the query string, e.g. list filters: ?min_price=abc is a
     * 422 instead of silently becoming 0.
     *
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    public function validateQuery(Request $request, array $rules, array $messages = []): array
    {
        return $this->validate($request->query(), $rules, $messages);
    }

    /**
     * Add a rule. The callback returns an error message, or null when valid.
     *
     * @param callable(mixed $value, ?string $parameter, string $field, array<string, mixed> $data): ?string $rule
     */
    public function extend(string $name, callable $rule): self
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Invalid rule name: ' . $name);
        }
        $this->extensions[$name] = Closure::fromCallable($rule);

        return $this;
    }

    /**
     * @param string|list<string> $rules
     * @return list<array{0: string, 1: ?string}>
     */
    private static function parseRules(string|array $rules): array
    {
        $list = is_array($rules) ? $rules : explode('|', $rules);
        $parsed = [];
        foreach ($list as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            $parts = explode(':', $rule, 2);
            $parsed[] = [strtolower($parts[0]), $parts[1] ?? null];
        }

        return $parsed;
    }

    /** @param array<string, mixed> $data */
    private function check(string $rule, ?string $parameter, mixed $value, string $field, array $data, bool $numeric): ?string
    {
        if (isset($this->extensions[$rule])) {
            return ($this->extensions[$rule])($value, $parameter, $field, $data);
        }

        return match ($rule) {
            'required' => (new RequiredRule())->passes($value) && $value !== []
                ? null : $field . ' is required.',
            'string' => (new StringRule())->passes($value) ? null : $field . ' must be a string.',
            'integer' => (new IntegerRule())->passes($value) ? null : $field . ' must be an integer.',
            'numeric' => (new NumericRule())->passes($value) ? null : $field . ' must be a number.',
            'min' => (new MinRule(self::required($rule, $parameter), $numeric))->passes($value)
                ? null : $field . ' must be at least ' . $parameter . ($numeric ? '.' : self::unit($value) . '.'),
            'max' => (new MaxRule(self::required($rule, $parameter), $numeric))->passes($value)
                ? null : $field . ' must not be greater than ' . $parameter . ($numeric ? '.' : self::unit($value) . '.'),
            'between' => $this->between($parameter, $value, $field, $numeric),
            'in' => is_scalar($value) && (new InRule(self::required($rule, $parameter)))->passes($value)
                    && in_array((string) $value, explode(',', (string) $parameter), true)
                ? null : $field . ' must be one of: ' . str_replace(',', ', ', (string) $parameter) . '.',
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null : $field . ' must be a valid email address.',
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                ? null : $field . ' must be a valid http or https URL.',
            'bool', 'boolean' => self::toBool($value) !== null ? null : $field . ' must be true or false.',
            'array' => is_array($value) ? null : $field . ' must be an array.',
            'date' => self::validDate($value, $parameter) ? null : $field . ' must be a valid date'
                . ($parameter !== null ? ' (' . $parameter . ')' : '') . '.',
            'regex' => $this->matchesRegex($parameter, $value) ? null : $field . ' has an invalid format.',
            'exists' => $this->exists($parameter, $value) ? null : 'The selected ' . $field . ' is invalid.',
            'unique' => $this->unique($parameter, $value) ? null : $field . ' has already been taken.',
            default => throw new InvalidArgumentException('Unknown validation rule: ' . $rule),
        };
    }

    private function between(?string $parameter, mixed $value, string $field, bool $numeric): ?string
    {
        $bounds = explode(',', self::required('between', $parameter));
        if (count($bounds) !== 2 || !is_numeric($bounds[0]) || !is_numeric($bounds[1])) {
            throw new InvalidArgumentException('between needs two numbers: between:min,max');
        }
        $passes = (new MinRule($bounds[0], $numeric))->passes($value) && (new MaxRule($bounds[1], $numeric))->passes($value);

        return $passes ? null : $field . ' must be between ' . $bounds[0] . ' and ' . $bounds[1]
            . ($numeric ? '.' : self::unit($value) . '.');
    }

    private function matchesRegex(?string $pattern, mixed $value): bool
    {
        $pattern = self::required('regex', $pattern);
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }
        $result = @preg_match($pattern, (string) $value);
        if ($result === false) {
            throw new InvalidArgumentException('Invalid regex rule pattern: ' . $pattern);
        }

        return $result === 1;
    }

    private function exists(?string $parameter, mixed $value): bool
    {
        [$table, $column] = self::tableAndColumn('exists', $parameter);
        if (!is_scalar($value)) {
            return false;
        }

        return $this->db()->first(
            'SELECT 1 AS found FROM ' . $table . ' WHERE ' . $column . ' = ? LIMIT 1',
            [is_bool($value) ? (int) $value : $value]
        ) !== null;
    }

    private function unique(?string $parameter, mixed $value): bool
    {
        $parts = array_map('trim', explode(',', self::required('unique', $parameter)));
        [$table, $column] = self::tableAndColumn('unique', implode(',', array_slice($parts, 0, 2)));
        if (!is_scalar($value)) {
            return false;
        }

        $sql = 'SELECT 1 AS found FROM ' . $table . ' WHERE ' . $column . ' = ?';
        $params = [is_bool($value) ? (int) $value : $value];
        $ignore = $parts[2] ?? '';
        if ($ignore !== '' && strtolower($ignore) !== 'null') {
            $idColumn = self::identifier($parts[3] ?? 'id');
            $sql .= ' AND ' . $idColumn . ' <> ?';
            $params[] = $ignore;
        }

        return $this->db()->first($sql . ' LIMIT 1', $params) === null;
    }

    private function db(): Connection
    {
        return $this->connection ??= new Connection();
    }

    /** @return array{0: string, 1: string} */
    private static function tableAndColumn(string $rule, ?string $parameter): array
    {
        $parts = array_map('trim', explode(',', self::required($rule, $parameter)));
        if (count($parts) !== 2) {
            throw new InvalidArgumentException($rule . ' needs a table and a column: ' . $rule . ':table,column');
        }

        return [self::identifier($parts[0]), self::identifier($parts[1])];
    }

    /** Table and column names come from code, never from input; still, allow plain identifiers only. */
    private static function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Invalid table or column name in validation rule: ' . $name);
        }

        return $name;
    }

    private static function required(string $rule, ?string $parameter): string
    {
        if ($parameter === null || $parameter === '') {
            throw new InvalidArgumentException('The ' . $rule . ' rule needs a parameter.');
        }

        return $parameter;
    }

    private static function unit(mixed $value): string
    {
        return is_array($value) ? ' items' : ' characters';
    }

    private static function validDate(mixed $value, ?string $format): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        foreach ($format !== null ? [$format] : ['Y-m-d', 'Y-m-d H:i:s', DATE_ATOM, 'Y-m-d\TH:i:s\Z'] as $candidate) {
            $date = DateTimeImmutable::createFromFormat('!' . $candidate, $value);
            $warnings = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($warnings === false || ($warnings['warning_count'] === 0 && $warnings['error_count'] === 0))
                && $date->format($candidate) === $value) {
                return true;
            }
        }

        return false;
    }

    private static function toBool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }

    /** @param list<string> $rules */
    private static function cast(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true) && is_string($value)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true) && is_string($value)) {
            return $value + 0;
        }
        if (in_array('bool', $rules, true) || in_array('boolean', $rules, true)) {
            return self::toBool($value);
        }

        return $value;
    }
}
