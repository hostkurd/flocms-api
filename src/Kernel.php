<?php
declare(strict_types=1);

namespace FloCMS\Api;

use Closure;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\Pipeline;
use FloCMS\Api\Middleware\SecurityHeadersMiddleware;
use FloCMS\Api\Support\ErrorRenderer;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Modules\ModuleManager;
use FloCMS\Core\Modules\Exceptions\ModuleUnavailableException;
use FloCMS\Core\Support\Container;
use FloCMS\Core\Support\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

final class Kernel implements RequestHandlerInterface
{
    /** @var list<mixed> */
    private array $middleware = [];

    public function __construct(
        private readonly Router $router,
        private readonly ContainerInterface $container = new Container(),
        private readonly ?ModuleManager $modules = null,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->middleware = [new ExceptionMiddleware($debug, $logger)];
    }

    /** @param list<mixed> $middleware */
    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    public function pipe(mixed $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function handle(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $request = $request->withAttribute('request_id', $requestId);
        ResponseFactory::takeInvalidUtf8Count();

        $destination = new class($this) implements RequestHandlerInterface {
            public function __construct(private readonly Kernel $kernel)
            {
            }

            public function handle(Request $request): Response
            {
                return $this->kernel->dispatch($request);
            }
        };

        try {
            $response = (new Pipeline(
                $this->middleware,
                $destination,
                $this->container
            ))->handle($request);
        } catch (Throwable $error) {
            // Reached when the middleware list has no ExceptionMiddleware, or
            // when middleware outside it throws.
            $response = (new ErrorRenderer($this->debug, $this->logger))->render($error, $requestId);
            $this->addMissingSecurityHeaders($response);
        }

        if (ResponseFactory::takeInvalidUtf8Count() > 0) {
            $this->logger?->warning('API response contained invalid UTF-8; replaced with U+FFFD.', [
                'request_id' => $requestId,
                'path' => $request->path(),
            ]);
        }

        return $response->header('X-Request-ID', $requestId);
    }

    public function dispatch(Request $request): Response
    {
        if (
            $request->method() === 'OPTIONS'
            && !$this->router->hasExplicitRoute('OPTIONS', $request->path())
        ) {
            $allowed = $this->router->allowedMethods($request->path());
            if ($allowed === []) {
                throw new ApiException('Endpoint not found.', 404);
            }

            return Response::noContent()->header('Allow', implode(', ', $allowed));
        }

        $match = $this->router->match($request);
        $route = $match->route;
        $moduleId = $route->module();
        if (is_string($moduleId) && $moduleId !== '') {
            try {
                $this->modules?->assertEnabled($moduleId);
            } catch (ModuleUnavailableException) {
                throw new ApiException('Endpoint not found.', 404);
            }
        }

        $request = $request
            ->withRouteParams($match->params)
            ->withAttribute('route', $route)
            ->withAttribute('route_name', $route->name())
            ->withAttribute('module_id', $moduleId);

        $destination = new class($this, $route) implements RequestHandlerInterface {
            public function __construct(
                private readonly Kernel $kernel,
                private readonly Route $route
            ) {
            }

            public function handle(Request $request): Response
            {
                return $this->kernel->invokeRoute($this->route, $request);
            }
        };

        $response = (new Pipeline(
            $route->middlewareStack(),
            $destination,
            $this->container
        ))->handle($request);

        return $request->method() === 'HEAD' ? $response->withoutBody() : $response;
    }

    public function invokeRoute(Route $route, Request $request): Response
    {
        [$callable, $reflection] = $this->resolveHandler($route->handler());
        $arguments = $this->resolveArguments($reflection, $request);
        $result = $callable(...$arguments);

        return $this->normalizeResponse($result);
    }

    /** @return array{0: callable, 1: ReflectionFunctionAbstract} */
    private function resolveHandler(mixed $handler): array
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $handler = [$class, $method];
        }

        if (is_array($handler) && count($handler) === 2) {
            [$target, $method] = array_values($handler);
            if (is_string($target)) {
                $target = $this->container->get($target);
            }
            if (!is_object($target) || !is_string($method) || !is_callable([$target, $method])) {
                throw new RuntimeException('Invalid controller route handler.');
            }

            return [[ $target, $method ], new ReflectionMethod($target, $method)];
        }

        if (is_string($handler) && class_exists($handler)) {
            $handler = $this->container->get($handler);
        }

        if ($handler instanceof Closure) {
            return [$handler, new ReflectionFunction($handler)];
        }

        if (is_object($handler) && is_callable($handler)) {
            return [$handler, new ReflectionMethod($handler, '__invoke')];
        }

        if (is_string($handler) && function_exists($handler)) {
            return [$handler, new ReflectionFunction($handler)];
        }

        throw new RuntimeException('Route handler is not callable.');
    }

    /** @return list<mixed> */
    private function resolveArguments(
        ReflectionFunctionAbstract $reflection,
        Request $request
    ): array {
        $arguments = [];
        $routeParams = $request->routeParams();

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();
                if (is_a($request, $class)) {
                    $arguments[] = $request;
                } else {
                    $arguments[] = $this->container->get($class);
                }
                continue;
            }

            $name = $parameter->getName();
            if (array_key_exists($name, $routeParams)) {
                $arguments[] = $this->castRouteValue(
                    $routeParams[$name],
                    $type instanceof ReflectionNamedType ? $type->getName() : 'string',
                    $name
                );
                continue;
            }

            if ($name === 'request') {
                $arguments[] = $request;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new RuntimeException(
                'Unable to resolve route-handler parameter $' . $name . '.'
            );
        }

        return $arguments;
    }

    private function castRouteValue(string $value, string $type, string $name): mixed
    {
        return match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value
                : throw new ApiException('Invalid route parameter: ' . $name, 422),
            'float' => is_numeric($value)
                ? (float) $value
                : throw new ApiException('Invalid route parameter: ' . $name, 422),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? throw new ApiException('Invalid route parameter: ' . $name, 422),
            default => $value,
        };
    }

    private function normalizeResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if ($result === null) {
            return Response::noContent();
        }

        return ResponseFactory::success($result);
    }

    /** Error responses that bypassed SecurityHeadersMiddleware still get its headers. */
    private function addMissingSecurityHeaders(Response $response): void
    {
        $present = array_map('strtolower', array_keys($response->headers()));
        foreach (SecurityHeadersMiddleware::HEADERS as $name => $value) {
            if (!in_array(strtolower($name), $present, true)) {
                $response->header($name, $value);
            }
        }
    }

    private function requestId(Request $request): string
    {
        $incoming = trim((string) $request->header('X-Request-ID', ''));
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming)) {
            return $incoming;
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return str_replace('.', '', uniqid('flo-', true));
        }
    }
}
