# Flo Framework API

`hostkurd/flocms-api` is the optional API runtime for Flo Framework. It depends
on `hostkurd/flocms-core` and provides explicit, method-aware routing instead of
the legacy `api_<action>` convention.

## Features

- routes constrained by HTTP method, with named parameters and automatic 405
  responses;
- route groups, names, middleware, and module ownership;
- a middleware pipeline with CORS, JSON-body validation, security headers,
  authentication, and rate limiting;
- consistent JSON envelopes and production-safe exception rendering;
- request IDs on successful and failed responses;
- dependency-injected controllers and route handlers;
- route loading from the application's `api/` directory and enabled module
  manifests;
- trusted-proxy-aware client IP resolution;
- non-terminating `Response` objects, making controllers testable.

## Minimal setup

```php
use FloCMS\Api\Kernel;
use FloCMS\Api\Router;
use FloCMS\Api\Middleware\CorsMiddleware;
use FloCMS\Api\Middleware\ExceptionMiddleware;
use FloCMS\Api\Middleware\JsonBodyMiddleware;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Modules\ModuleSystem;

$router = new Router();
$router->group('/v1', function (Router $router): void {
    $router->get('/health', fn () => ['status' => 'ok'])->name('health');
});

$kernel = new Kernel(
    router: $router,
    container: ModuleSystem::container(),
    modules: ModuleSystem::manager(),
    debug: false,
);

$kernel->middleware([
    new CorsMiddleware(['https://www.example.com'], allowCredentials: true),
    new ExceptionMiddleware(debug: false),
    new JsonBodyMiddleware(),
]);

$kernel->handle(Request::fromGlobals())->send();
```

Place application route registration in `api/routes.php`. A route file returns
a closure:

```php
<?php

use FloCMS\Api\Router;
use App\Api\NewsController;

return static function (Router $router): void {
    $router->get('/v1/news', [NewsController::class, 'index'])
        ->name('news.index')
        ->module('news');
};
```

Module route files are declared in `module.php`:

```php
'routes' => ['api' => 'routes/api.php'],
```

The route loader marks every route from that file with its owning module.
Requests to a disabled or outdated module receive a 404 without constructing
the controller.

## Errors and validation

Errors use one JSON envelope:

```json
{"success": false, "message": "Validation failed.", "errors": {"email": ["Required."]}, "request_id": "…"}
```

Every response, success or error, carries the request ID in the
`X-Request-ID` header.

### Which ValidationException?

Two classes exist, and both render as `422 Validation failed.` with the field
errors under `errors`:

- `FloCMS\Api\Exceptions\ValidationException` is the one to use in API code.
  Throw it directly or through `Controller::invalid(['field' => ['message']])`.
- `FloCMS\Core\ValidationException` is thrown by core `Validator::validate()`.
  Since 1.0.1 the API renders it the same way, so calling the core validator
  from an API handler is safe. Before 1.0.1 it became a 500.
