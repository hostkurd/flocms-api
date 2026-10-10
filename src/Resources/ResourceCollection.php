<?php
declare(strict_types=1);

namespace FloCMS\Api\Resources;

use FloCMS\Api\ResponseFactory;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;

/**
 * A list of records, each shaped by the same JsonResource class.
 */
final class ResourceCollection implements Resolvable
{
    /** @var list<mixed> */
    private readonly array $items;

    /** @var array<string, mixed> */
    private array $meta = [];

    /**
     * @param iterable<mixed> $items
     * @param class-string<JsonResource> $resourceClass
     */
    public function __construct(iterable $items, private readonly string $resourceClass)
    {
        if (!is_subclass_of($resourceClass, JsonResource::class)) {
            throw new InvalidArgumentException($resourceClass . ' must extend ' . JsonResource::class . '.');
        }
        $this->items = is_array($items) ? array_values($items) : iterator_to_array($items, false);
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): self
    {
        $clone = clone $this;
        $clone->meta = $meta + $this->meta;

        return $clone;
    }

    public function resolve(Request $request): mixed
    {
        $class = $this->resourceClass;

        return array_map(
            static fn (mixed $item): mixed => ($item instanceof JsonResource ? $item : new $class($item))->resolve($request),
            $this->items
        );
    }

    public function meta(Request $request): array
    {
        return $this->meta;
    }

    public function allowedIncludes(): array
    {
        $class = $this->resourceClass;

        return (new $class(null))->allowedIncludes();
    }

    public function response(Request $request, int $status = 200): Response
    {
        JsonResource::assertIncludes($request, $this->allowedIncludes());

        return ResponseFactory::success($this->resolve($request), $status, $this->meta($request));
    }

    /** @return list<mixed> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return class-string<JsonResource> */
    public function resourceClass(): string
    {
        return $this->resourceClass;
    }
}
