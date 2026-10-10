<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Support\ContainerInterface;
use RuntimeException;

final class Pipeline implements RequestHandlerInterface
{
    /** @param list<mixed> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $destination,
        private readonly ContainerInterface $container,
        private readonly ?MiddlewareAliases $aliases = null
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->handleAt(0, $request);
    }

    private function handleAt(int $index, Request $request): Response
    {
        if (!array_key_exists($index, $this->middleware)) {
            return $this->destination->handle($request);
        }

        $middleware = $this->resolve($this->middleware[$index]);
        $next = new class($this, $index + 1) implements RequestHandlerInterface {
            public function __construct(
                private readonly Pipeline $pipeline,
                private readonly int $index
            ) {
            }

            public function handle(Request $request): Response
            {
                return $this->pipeline->continue($this->index, $request);
            }
        };

        if ($middleware instanceof MiddlewareInterface) {
            return $middleware->process($request, $next);
        }

        $response = $middleware($request, $next);
        if (!$response instanceof Response) {
            throw new RuntimeException('Callable middleware must return a Response.');
        }

        return $response;
    }

    public function continue(int $index, Request $request): Response
    {
        return $this->handleAt($index, $request);
    }

    private function resolve(mixed $middleware): mixed
    {
        if (is_string($middleware) && class_exists($middleware)) {
            $middleware = $this->container->get($middleware);
        } elseif (is_string($middleware) && $this->aliases?->has($middleware)) {
            $middleware = $this->aliases->resolve($middleware);
        }

        if ($middleware instanceof MiddlewareInterface || is_callable($middleware)) {
            return $middleware;
        }

        throw new RuntimeException('Invalid API middleware.');
    }
}
