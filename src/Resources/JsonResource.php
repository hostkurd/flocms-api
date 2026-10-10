<?php
declare(strict_types=1);

namespace FloCMS\Api\Resources;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\ResponseFactory;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use Throwable;

/**
 * Shapes one record (a database row as array or object) for output, so
 * internal columns never leak:
 *
 *     final class PropertyResource extends JsonResource
 *     {
 *         protected array $includes = ['images', 'agent'];
 *
 *         public function toArray(Request $request): array
 *         {
 *             return [
 *                 'id'         => (int) $this->id,
 *                 'title'      => $this->title,
 *                 'price'      => (float) $this->price,
 *                 'created_at' => $this->date($this->created_at),
 *                 'url'        => '/properties/' . $this->slug,
 *                 'internal'   => $this->when($request->attribute('identity') !== null, $this->notes),
 *                 'images'     => $this->whenIncluded($request, 'images', fn () => ImageResource::collection(...)),
 *             ];
 *         }
 *     }
 *
 *     return PropertyResource::make($row);            // from a route handler
 *     return PropertyResource::collection($rows);
 */
abstract class JsonResource implements Resolvable
{
    /** @var list<string> relations allowed in ?include= */
    protected array $includes = [];

    final public function __construct(protected readonly mixed $resource)
    {
    }

    /** @return array<string, mixed> */
    abstract public function toArray(Request $request): array;

    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /** @param iterable<mixed> $resources */
    public static function collection(iterable $resources): ResourceCollection
    {
        return new ResourceCollection($resources, static::class);
    }

    public function resolve(Request $request): mixed
    {
        return self::resolveValue($this->toArray($request), $request);
    }

    public function meta(Request $request): array
    {
        return [];
    }

    public function allowedIncludes(): array
    {
        return $this->includes;
    }

    public function response(Request $request, int $status = 200): Response
    {
        self::assertIncludes($request, $this->allowedIncludes());

        return ResponseFactory::success($this->resolve($request), $status, $this->meta($request));
    }

    /** The underlying record. */
    public function resource(): mixed
    {
        return $this->resource;
    }

    public function __get(string $name): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource[$name] ?? null;
        }
        if (is_object($this->resource)) {
            return $this->resource->{$name} ?? null;
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        return $this->__get($name) !== null;
    }

    /**
     * $value when $condition holds; otherwise the field is left out (or
     * $default when given). $value may be a closure, called only when needed.
     */
    protected function when(bool $condition, mixed $value, mixed $default = null): mixed
    {
        if ($condition) {
            return $value instanceof Closure ? $value() : $value;
        }

        return func_num_args() === 3 ? $default : MissingValue::instance();
    }

    /** $value only when the client asked for ?include=$relation. */
    protected function whenIncluded(Request $request, string $relation, mixed $value): mixed
    {
        return $this->when(in_array($relation, self::requestedIncludes($request), true), $value);
    }

    /** Format a date/time value (string, timestamp or DateTimeInterface); null stays null. */
    protected function date(mixed $value, string $format = DateTimeInterface::ATOM): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = match (true) {
                $value instanceof DateTimeInterface => $value,
                is_int($value) => (new DateTimeImmutable())->setTimestamp($value),
                default => new DateTimeImmutable((string) $value),
            };
        } catch (Throwable) {
            return null;
        }

        return $date->format($format);
    }

    /** @return list<string> */
    public static function requestedIncludes(Request $request): array
    {
        $include = $request->queryValue('include');
        if (!is_string($include) || trim($include) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $include)))));
    }

    /**
     * @param list<string> $allowed
     * @throws ApiException 422 for includes that are not allowed
     */
    public static function assertIncludes(Request $request, array $allowed): void
    {
        $unknown = array_values(array_diff(self::requestedIncludes($request), $allowed));
        if ($unknown !== []) {
            throw new ApiException('Unknown include: ' . implode(', ', $unknown) . '.', 422, [
                'include' => ['Allowed: ' . ($allowed === [] ? 'none' : implode(', ', $allowed)) . '.'],
            ]);
        }
    }

    /** Resolve nested resources and drop missing values. */
    public static function resolveValue(mixed $value, Request $request): mixed
    {
        if ($value instanceof Resolvable) {
            return $value->resolve($request);
        }
        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            if ($item instanceof MissingValue) {
                continue;
            }
            $resolved[$key] = self::resolveValue($item, $request);
        }

        return array_is_list($value) ? array_values($resolved) : $resolved;
    }
}
