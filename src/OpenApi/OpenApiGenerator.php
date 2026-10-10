<?php
declare(strict_types=1);

namespace FloCMS\Api\OpenApi;

use Closure;
use FloCMS\Api\Middleware\Deprecated;
use FloCMS\Api\Route;
use FloCMS\Api\Router;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Builds an OpenAPI 3.1 document from the registered routes: methods, paths,
 * path parameters (typed from constraints and handler signatures), names as
 * operationIds and deprecation, enriched by the Summary, Tag, RequestBody and
 * ResponseSchema attributes on handlers. Handlers are inspected by
 * reflection only; nothing is instantiated or run.
 */
final class OpenApiGenerator
{
    private const ENVELOPE_ERROR = [
        'type' => 'object',
        'required' => ['success', 'message'],
        'properties' => [
            'success' => ['type' => 'boolean', 'const' => false],
            'message' => ['type' => 'string'],
            'errors' => ['type' => 'object'],
            'request_id' => ['type' => 'string'],
        ],
    ];

    public function __construct(
        private readonly Router $router,
        private readonly string $title = 'FloCMS API',
        private readonly string $version = '1.0.0',
        private readonly ?string $serverUrl = null,
        private readonly ?string $description = null
    ) {
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];
        foreach ($this->router->routes() as $route) {
            $path = self::openApiPath($route);
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }
                $paths[$path][strtolower($method)] = $this->operation($route, $method);
            }
        }
        ksort($paths);

        $info = ['title' => $this->title, 'version' => $this->version];
        if ($this->description !== null) {
            $info['description'] = $this->description;
        }
        $document = [
            'openapi' => '3.1.0',
            'info' => $info,
            'paths' => (object) $paths,
            'components' => ['schemas' => ['Error' => self::ENVELOPE_ERROR]],
        ];
        if ($this->serverUrl !== null) {
            $document['servers'] = [['url' => $this->serverUrl]];
        }

        return $document;
    }

    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method): array
    {
        $reflection = self::reflect($route->handler());
        $attributes = static fn (string $class): array => $reflection === null ? [] : self::attributes($reflection, $class);

        $operation = [];
        $name = $route->name();
        $operation['operationId'] = is_string($name) ? $name . ($this->hasSiblingMethods($route) ? '.' . strtolower($method) : '')
            : strtolower($method) . str_replace(['/', '{', '}', '-', '.'], ['_', '', '', '_', '_'], self::openApiPath($route));

        $summary = $attributes(Summary::class)[0] ?? null;
        if ($summary instanceof Summary) {
            $operation['summary'] = $summary->summary;
            if ($summary->description !== null) {
                $operation['description'] = $summary->description;
            }
        }

        $tags = array_map(static fn (Tag $tag): string => $tag->name, $attributes(Tag::class));
        $operation['tags'] = $tags !== [] ? array_values(array_unique($tags)) : [self::defaultTag($route)];

        $parameters = $this->parameters($route, $reflection);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $body = $attributes(RequestBody::class)[0] ?? null;
        if ($body instanceof RequestBody) {
            $operation['requestBody'] = array_filter([
                'description' => $body->description,
                'required' => $body->required,
                'content' => [$body->contentType => ['schema' => $body->schema]],
            ], static fn (mixed $value): bool => $value !== null);
        }

        $responses = [];
        foreach ($attributes(ResponseSchema::class) as $response) {
            $responses[(string) $response->status] = self::response($response->status, $response->schema, $response->description);
        }
        if (!array_filter(array_keys($responses), static fn (string $status): bool => $status[0] === '2')) {
            $default = $method === 'POST' ? 201 : 200;
            $responses[(string) $default] = self::response($default, [], null);
        }
        if ($route->segments() !== [] && array_filter($route->segments(), static fn (array $s): bool => $s[0] === 'param') !== []) {
            $responses['404'] ??= self::response(404, [], 'Not found');
        }
        $responses['default'] = ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        ksort($responses, SORT_STRING);
        $operation['responses'] = $responses;

        foreach ($route->middlewareStack() as $middleware) {
            if ($middleware instanceof Deprecated) {
                $operation['deprecated'] = true;
            }
        }

        return $operation;
    }

    /** @return list<array<string, mixed>> */
    private function parameters(Route $route, ?ReflectionFunctionAbstract $reflection): array
    {
        $types = [];
        foreach ($reflection?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                $types[$parameter->getName()] = $type->getName();
            }
        }

        $parameters = [];
        foreach ($route->segments() as $segment) {
            if ($segment[0] !== 'param') {
                continue;
            }
            [, $name, $constraint] = $segment;
            $schema = match (true) {
                ($types[$name] ?? null) === 'int', $constraint === '\d+', $constraint === '[0-9]+' => ['type' => 'integer'],
                ($types[$name] ?? null) === 'float' => ['type' => 'number'],
                ($types[$name] ?? null) === 'bool' => ['type' => 'boolean'],
                default => ['type' => 'string'],
            };
            if ($constraint !== null && $schema['type'] === 'string') {
                $schema['pattern'] = '^(?:' . $constraint . ')$';
            }
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function response(int $status, array $schema, ?string $description): array
    {
        $description ??= match (true) {
            $status === 201 => 'Created',
            $status === 204 => 'No content',
            $status >= 200 && $status < 300 => 'Successful response',
            default => 'Error',
        };
        if ($status === 204) {
            return ['description' => $description];
        }

        $body = $status >= 200 && $status < 300
            ? [
                'type' => 'object',
                'required' => ['success', 'data'],
                'properties' => ['success' => ['type' => 'boolean', 'const' => true], 'data' => $schema === [] ? new \stdClass() : $schema],
            ]
            : ($schema === [] ? ['$ref' => '#/components/schemas/Error'] : $schema);

        return ['description' => $description, 'content' => ['application/json' => ['schema' => $body]]];
    }

    private function hasSiblingMethods(Route $route): bool
    {
        return count(array_diff($route->methods(), ['HEAD', 'OPTIONS'])) > 1;
    }

    private static function openApiPath(Route $route): string
    {
        $path = '';
        foreach ($route->segments() as $segment) {
            $path .= '/' . ($segment[0] === 'param' ? '{' . $segment[1] . '}' : $segment[1]);
        }

        return $path === '' ? '/' : $path;
    }

    private static function defaultTag(Route $route): string
    {
        $static = array_values(array_filter(
            $route->segments(),
            static fn (array $segment): bool => $segment[0] === 'static' && preg_match('/^(api|v\d+(\.\d+)?)$/', $segment[1]) !== 1
        ));

        return $static[0][1] ?? 'default';
    }

    private static function reflect(mixed $handler): ?ReflectionFunctionAbstract
    {
        try {
            if (is_string($handler) && str_contains($handler, '@')) {
                $handler = explode('@', $handler, 2);
            }
            if (is_array($handler) && count($handler) === 2) {
                [$target, $method] = array_values($handler);

                return is_string($method) && (is_object($target) || (is_string($target) && class_exists($target)))
                    ? new ReflectionMethod($target, $method)
                    : null;
            }
            if ($handler instanceof Closure) {
                return new ReflectionFunction($handler);
            }
            if (is_object($handler) && method_exists($handler, '__invoke')) {
                return new ReflectionMethod($handler, '__invoke');
            }
            if (is_string($handler) && class_exists($handler) && method_exists($handler, '__invoke')) {
                return new ReflectionMethod($handler, '__invoke');
            }
            if (is_string($handler) && function_exists($handler)) {
                return new ReflectionFunction($handler);
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Attributes of $class on the handler, then on its class (for Tag/Summary).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function attributes(ReflectionFunctionAbstract $reflection, string $class): array
    {
        $found = array_map(static fn ($attribute) => $attribute->newInstance(), $reflection->getAttributes($class));
        if ($found === [] && $reflection instanceof ReflectionMethod && in_array($class, [Tag::class, Summary::class], true)) {
            $found = array_map(
                static fn ($attribute) => $attribute->newInstance(),
                (new ReflectionClass($reflection->class))->getAttributes($class)
            );
        }

        return array_values($found);
    }
}
