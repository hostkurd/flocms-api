# Flo Framework API

`hostkurd/flocms-api` is the optional API runtime for Flo Framework (FloCMS).
It provides explicit, method-aware routing, a middleware pipeline and JSON
responses, plus the pieces most APIs need: authentication, validation,
resources, pagination, HTTP caching, CORS, rate limiting, OpenAPI docs and a
test client.

It runs on ordinary shared hosting: PHP 8.1+, PDO, mbstring and fileinfo.
No daemons, no Node, no extra extensions (APCu is used when available).

- [Installation](#installation)
- [Setup](#setup)
- [Routing](#routing)
- [Handlers and controllers](#handlers-and-controllers)
- [Middleware](#middleware)
- [Errors](#errors)
- [Authentication](#authentication)
- [Validation](#validation)
- [Resources](#resources)
- [Pagination](#pagination)
- [Sorting, filtering and fields](#sorting-filtering-and-fields)
- [HTTP caching](#http-caching)
- [CORS](#cors)
- [Rate limiting](#rate-limiting)
- [Other middleware](#other-middleware)
- [Uploads](#uploads)
- [Versioning and deprecation](#versioning-and-deprecation)
- [Health checks and maintenance mode](#health-checks-and-maintenance-mode)
- [Idempotency](#idempotency)
- [OpenAPI documentation](#openapi-documentation)
- [Command line](#command-line)
- [Testing your API](#testing-your-api)

## Installation

From FloCMS 2.0 the skeleton no longer ships the API: a website that doesn't
need one stays lighter. Add it to a site (or pick an API setup in
`php flo setup`, which does the same):

```bash
composer require hostkurd/flocms-api:^1.3
php flo api:install   # public/api.php, api/routes.php and example controllers
php flo migrate       # the API tables (tokens, API keys)
```

`api:install` never changes a file that already exists, so it is safe to run
again; `--minimal` writes only `public/api.php` and a routes file with the
health check. It also tells you if `composer.json` does not load `App\Api\`
from `api/`, or if `public/.htaccess` lacks the `/api/v1` rule.

1.3 requires flocms-core 2.8 and [flocms-cli](https://github.com/hostkurd/flocms-cli) 2.0,
which runs the `php flo api:*` commands.

The database features (API keys, tokens, database idempotency store) need
their tables. They are a migration, so they are created with the
application's own migrations:

```bash
php flo migrate               # every pending migration, the API's included
php flo api:install-schema    # or only the API's
```

You can also import `vendor/hostkurd/flocms-api/database/schema/mysql.sql` in
phpMyAdmin.

## Setup

```php
use FloCMS\Api\Kernel;
use FloCMS\Api\Router;
use FloCMS\Api\Middleware\{CorsMiddleware, ExceptionMiddleware, JsonBodyMiddleware, SecurityHeadersMiddleware};
use FloCMS\Core\Http\Request;

$router = new Router();
$router->version('v1', function (Router $router): void {
    $router->get('/health', fn () => ['status' => 'ok'])->name('health');
});

$kernel = new Kernel(router: $router, debug: false);
$kernel->middleware([
    new SecurityHeadersMiddleware(),
    new ExceptionMiddleware(debug: false),
    new CorsMiddleware(['https://www.example.com']),
    new JsonBodyMiddleware(),
]);

$kernel->handle(Request::fromGlobals())->send();
```

Route files return a closure. `RouteLoader` loads them, and module route files
declared in `module.php` (`'routes' => ['api' => 'routes/api.php']`):

```php
// api/routes.php
use FloCMS\Api\Router;
use App\Api\NewsController;

return static function (Router $router): void {
    $router->get('/v1/news', [NewsController::class, 'index'])->name('news.index')->module('news');
};
```

```php
(new RouteLoader($router, $container))->load(ROOT . '/api/routes.php');
(new RouteLoader($router, $container))->loadModules($registry, $manager);
```

Routes owned by a disabled module answer 404 without building the controller.

## Routing

```php
$router->get('/v1/properties', $handler);
$router->post('/v1/properties', $handler);
$router->put(...); $router->patch(...); $router->delete(...); $router->head(...); $router->options(...);
$router->map(['GET', 'POST'], '/v1/search', $handler);
```

**Parameters** match one path segment. Add a regular expression after a
colon to constrain them:

```php
$router->get('/v1/properties/{slug}', ...);           // any single segment
$router->get('/v1/agents/{id:\d+}', ...);             // digits only
$router->get('/v1/{country:[a-z]{2}}/cities', ...);
```

Constraints cannot span segments (`{path:.+/.+}` is rejected). Values reach
handlers URL-decoded.

**Groups** share a prefix, middleware, module and name prefix, and nest:

```php
$router->group('/v1', function (Router $router): void {
    $router->get('/properties', ...)->name('properties.index');   // v1.properties.index
}, middleware: [$auth], namePrefix: 'v1.');
```

**Matching order.** Routes match in registration order, and the first match
wins. Register specific routes before general ones:

```php
$router->get('/v1/p/featured', ...);    // before {slug}
$router->get('/v1/p/{id:\d+}', ...);    // before {slug}
$router->get('/v1/p/{slug}', ...);
```

The router reports routes that can never match (`/p/{id}` after `/p/{slug}`)
and duplicate route names: as PSR-3 warnings (`new Router($logger)`, also
listed by `$router->warnings()`), or as exceptions with
`new Router(strict: true)` / `$router->strict()`. Strict mode is recommended
in development; it will be the default in 2.0.

**Automatic responses:** a wrong method gives `405` with `Allow`; `HEAD` runs
the `GET` route without a body; `OPTIONS` answers `204` with `Allow`. Explicit
`head()`/`options()` routes take precedence.

**URLs from route names:**

```php
$router->url('v1.properties.show', ['slug' => 'sea-villa'], ['page' => 2]);
// "/api/v1/properties/sea-villa?page=2"
```

## Handlers and controllers

A handler is a closure, `[Controller::class, 'method']`, `'Controller@method'`,
an invokable class name or object. Arguments are resolved by name and type:

- route parameters by name, cast to `int`, `float` or `bool` when typed
  (a value that does not fit is a 422);
- `Request` (or a parameter named `$request`);
- other classes from the container;
- defaults and nullable parameters.

Return a `Response`, an array or scalar (wrapped in
`{"success": true, "data": ...}`), a resource, or `null` (204).

```php
use FloCMS\Api\Controller;
use FloCMS\Api\Validation\ValidatesRequests;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

final class PropertiesController extends Controller
{
    use ValidatesRequests;

    public function __construct(private readonly PropertiesModel $properties)
    {
    }

    public function show(Request $request, string $slug): Response
    {
        $row = $this->properties->findBySlug($slug) ?? $this->fail('Property not found.', 404);

        return PropertyResource::make($row)->response($request);
    }
}
```

`Controller` helpers: `success()`, `created()`, `noContent()`, `fail()`,
`invalid()`, `routeId()`.

## Middleware

Middleware implements `MiddlewareInterface::process($request, $handler)`, or
is a callable `fn (Request $request, $next): Response`. Class names are built
from the container. Kernel middleware runs for every request (also for 404s);
route middleware runs after a route matched.

**Order.** Lists are outermost first. The recommended global order:

```php
$kernel->middleware([
    new AccessLogMiddleware(ROOT . '/storage/logs/api.log'), // optional: sees every final status
    new SecurityHeadersMiddleware(),   // nosniff etc. on every response, errors included
    new ExceptionMiddleware($debug),   // errors as JSON
    new CorsMiddleware($origins),      // answers preflights; CORS headers on errors too
    new MaintenanceMiddleware(),       // optional
    new LocaleMiddleware($languages, $default),
    new RateLimitMiddleware(...),      // OPTIONS is never counted
    new BodySizeMiddleware(),
    new JsonBodyMiddleware(),
]);
```

Per route: authentication, `throttle:<name>`, validation of content types,
caching, idempotency, deprecation.

**Aliases** let route middleware be written as strings:

```php
$kernel->aliases(['throttle' => $limiters->aliasFactory()]);
$router->post('/v1/inquiries', ...)->middleware('throttle:forms');
```

## Errors

Every error uses one envelope:

```json
{"success": false, "message": "Validation failed.", "errors": {"email": ["email is required."]}, "request_id": "8f3c..."}
```

- Throw `ApiException($message, $status, $errors, $headers)` for any status,
  or `ValidationException($errors)` for 422.
- Core `HttpException` and core `ValidationException` render with their status
  (403, 404, 422...).
- Anything else is a logged 500. With `debug: true` the message, class, file
  and line are shown; in production only "Internal Server Error". Error
  details of 5xx `ApiException`s are hidden in production too.
- Every response carries `X-Request-ID` (a valid incoming one is reused). For
  the ID in success bodies as well: `$kernel->includeRequestIdInBody()`.
- Invalid UTF-8 in response data is replaced with U+FFFD and logged, instead
  of failing (only for responses built by `ResponseFactory`, which includes
  everything handlers return as arrays).
- The Kernel renders errors correctly even without `ExceptionMiddleware`.

**Which ValidationException?** Throw `FloCMS\Api\Exceptions\ValidationException`
in API code. `FloCMS\Core\ValidationException` (thrown by core
`Validator::validate()`) renders the same way; convert it explicitly with
`ValidationException::fromCore($e)` if needed.

## Authentication

All authenticators implement `AuthenticatorInterface` and are used through
`AuthenticateMiddleware`, which puts an `Identity` in the `identity` request
attribute:

```php
$auth = new AuthenticateMiddleware(
    $authenticator,
    roles: [],                        // any of these roles
    scopes: ['properties.write'],     // all of these scopes
    permissions: ['content.edit'],    // all of these permissions
);
$router->post('/v1/properties', ...)->middleware($auth);
```

No credentials: 401. Not allowed: 403.

| Authenticator | Credential | Identity |
|---|---|---|
| `ApiKeyAuthenticator($keys)` | `X-API-Key: flk_<id>_<secret>` | the key's user, or `api-key:<id>` for service keys |
| `BearerTokenAuthenticator($tokens)` | `Authorization: Bearer flt_<id>_<secret>` (or `X-FloCMS-Authorization`) | the token's user |
| `SessionAuthenticator()` | the FloCMS admin session cookie + `X-CSRF-TOKEN` | the logged-in admin |
| `JwtAuthenticator($secret)` | `Authorization: Bearer <HS256 JWT>` | `sub` |
| `FirstMatchAuthenticator(...$list)` | tries each in turn | |

**API keys and tokens** are random, shown once, and stored as SHA-256 hashes
(tables `api_keys` and `api_tokens`). Verification is constant-time, and
expiry and revocation are checked on every request.

```php
use FloCMS\Api\Auth\{ApiKeyRepository, TokenRepository};
use FloCMS\Api\Database\Connection;

$db = new Connection();                     // the FloCMS database (App::db())
$issued = (new TokenRepository($db))->issueFor($userId, 'Mobile app', 30 * 86400, ['inquiries.write']);
return ['token' => $issued->plaintext];      // only time it is visible

(new TokenRepository($db))->revokeAllForUser($userId);   // e.g. after a password change
```

From the command line: `php flo api:key:create "Partner feed" --scopes=properties.read`.

**Users and permissions.** Keys and tokens tied to a user are checked with the
same loader core `Auth` uses (`Config::set('auth.user_loader', ...)`): deleted
or suspended users lose access immediately, and the user's role drives
`$identity->can('users.manage')`, which reads the core `permissions` map with
the same wildcard rules as `Auth::can()`.

**Admin session.** `SessionAuthenticator` lets admin pages call `/api/v1`
with `fetch()`: it opens the session read-only and only when the cookie is
present, reloads the user through `Auth::refresh()`, requires admin access,
and checks `X-CSRF-TOKEN` (sent by `csrf.js`) on POST/PUT/PATCH/DELETE (419
on mismatch).

**JWT.** HS256 only; tokens naming any other algorithm are rejected. Use a
secret of at least 32 random bytes from `.env`. `Jwt::encode($claims, $secret)`
issues tokens; `exp`, `nbf`, `iat`, and optionally `iss`/`aud` are checked.

## Validation

```php
use FloCMS\Api\Validation\ValidatesRequests;

$data = $this->validate($request, [
    'name'    => 'required|string|max:100',
    'email'   => 'required|email',
    'phone'   => 'nullable|string|max:30',
    'type'    => 'required|in:sale,rent',
    'price'   => 'sometimes|numeric|between:0,10000000',
    'agent'   => 'sometimes|integer|exists:users,id',
    'email2'  => 'required|email|unique:subscribers,email',
    'code'    => ['required', 'regex:/^[A-Z]{2}\d+$/'],   // array form when a pattern contains |
    'from'    => 'nullable|date',
    'tags'    => 'sometimes|array|max:5',
    'agree'   => 'required|bool',
]);
```

The result holds only validated fields; `integer`, `numeric` and `bool`
values come back typed. Failures are a 422 with per-field messages.
`$this->validateQuery($request, $rules)` validates only the query string, so
`?min_price=abc` is a 422 instead of silently becoming 0.

Rules: `required`, `string`, `integer`, `numeric`, `min`, `max`, `in` (the
core rules), `email`, `url` (http/https), `bool`, `array`, `date[:format]`,
`between:min,max`, `regex:pattern`, `nullable`, `sometimes`,
`exists:table,column`, `unique:table,column[,ignoreId[,idColumn]]`.
Outside controllers: `(new Validator())->validate($data, $rules)`. Custom
rules: `$validator->extend('even', fn ($value) => $value % 2 ? 'Must be even.' : null)`.

## Resources

Resources decide what a record looks like in the API, so database columns
never leak:

```php
use FloCMS\Api\Resources\JsonResource;

final class PropertyResource extends JsonResource
{
    protected array $includes = ['images', 'agent'];      // allowed in ?include=

    public function toArray(Request $request): array
    {
        return [
            'id'         => (int) $this->id,
            'title'      => $this->title,
            'price'      => (float) $this->price,
            'url'        => '/properties/' . $this->slug,
            'created_at' => $this->date($this->created_at),          // ISO 8601
            'notes'      => $this->when($request->attribute('identity') !== null, fn () => $this->notes),
            'images'     => $this->whenIncluded($request, 'images', fn () => ImageResource::collection($this->images)),
        ];
    }
}

return PropertyResource::make($row);
return PropertyResource::collection($rows);
```

`?include=` values that are not allowed give a 422.

## Pagination

```php
use FloCMS\Api\Pagination\PageRequest;
use FloCMS\Api\ResponseFactory;

$page = PageRequest::fromRequest($request, defaultPerPage: 20, maxPerPage: 100);
$result = $db->table('properties')->where('status', '=', 'sale')->paginate($page->page, $page->perPage);

return ResponseFactory::paginated($result, $request, PropertyResource::class);
```

```json
{"success": true, "data": [...],
 "meta": {"page": 2, "per_page": 20, "total": 95, "last_page": 5},
 "links": {"first": "/api/v1/properties?page=1", "prev": "...", "next": "...", "last": "..."}}
```

`per_page` is clamped to the maximum; invalid values are a 422. Links keep the
query string and are relative to the host. For large or fast-changing lists,
`CursorPaginator::paginate($query, $request, 'id', 'desc')` with
`ResponseFactory::cursorPaginated()` pages by an opaque cursor instead of an
offset.

## Sorting, filtering and fields

```php
use FloCMS\Api\Query\QueryOptions;

$options = QueryOptions::fromRequest(
    $request,
    sortable:   ['price', 'created_at'],
    filterable: ['status' => ['eq', 'in'], 'price' => ['gte', 'lte'], 'city'],
    fields:     ['id', 'title', 'price'],
    defaultSort: '-created_at',
);
$result = $options->applyTo($db->table('properties'))->paginate($page->page, $page->perPage);
```

`GET /v1/properties?sort=-price&filter[status][in]=sale,rent&filter[price][gte]=100000&fields=id,title`

Only whitelisted names reach the query (map public names to columns with
`['price' => 'p.price']`); everything else is a 422, and values are always
bound parameters.

## HTTP caching

`SecurityHeadersMiddleware` sends `Cache-Control: no-store` unless a route
sets its own policy:

```php
use FloCMS\Api\Middleware\{CacheControl, ETagMiddleware};

$router->get('/v1/pages', ...)->middleware(CacheControl::public(300), new ETagMiddleware());
```

`CacheControl::public($maxAge, $sharedMaxAge)`, `private()`, `noCache()`,
`noStore()` apply to successful GET/HEAD responses; authenticated requests
are never cached publicly. `ETagMiddleware` adds an ETag and answers `304 Not
Modified` to `If-None-Match`, or to `If-Modified-Since` when the handler set
`ResponseFactory::withLastModified($response, $row->updated_at)`.

## CORS

```php
new CorsMiddleware(
    ['https://www.example.com', 'https://*.example.com'],   // or ['*'] without credentials
    allowCredentials: true,
);
```

- Preflights are answered by the middleware itself (204), before rate limits
  and authentication. The requested method and headers must be allowed;
  only allowed headers are echoed.
- Error responses keep their CORS headers, so browsers can read 422/429
  messages.
- `https://*.example.com` matches exactly one subdomain label, same scheme and
  port (not `example.com`, not `a.b.example.com`).
- `X-Request-ID`, `X-RateLimit-*` and `Retry-After` are exposed by default.

## Rate limiting

```php
use FloCMS\Api\RateLimit\RateLimiterRegistry;
use FloCMS\Core\RateLimit\{ApcuRateLimiter, FileRateLimiter};

$store = ApcuRateLimiter::isAvailable() ? new ApcuRateLimiter() : new FileRateLimiter(ROOT . '/storage/cache/api-rate-limit');
$limiters = new RateLimiterRegistry($store, $clientIp, [
    'public' => [60, 60],      // 60 per minute
    'forms'  => [5, 60],
    'auth'   => [10, 900],     // 10 per 15 minutes
]);
$kernel->aliases(['throttle' => $limiters->aliasFactory()]);

$router->post('/v1/inquiries', ...)->middleware('throttle:forms');
```

Named limits key requests by identity when authenticated (list the throttle
after `AuthenticateMiddleware`) and by client IP otherwise. Over the limit:
429 with `Retry-After` and `X-RateLimit-*` headers. A global
`RateLimitMiddleware` works as before. `FileRateLimiter` removes stale state
on about 1 in 100 hits, and `php flo api:gc` does it on demand.

`ClientIpResolver(['10.0.0.0/8'])` only trusts `X-Forwarded-For` from the
listed proxies (IPs or CIDR ranges).

The limiters (`RateLimiterInterface`, `RateLimitResult`, `FileRateLimiter`,
`ApcuRateLimiter`) and the IP tools (`ClientIpResolver`, `IpMatcher`) are in
flocms-core 2.8 (`FloCMS\Core\RateLimit\`, `FloCMS\Core\Security\`), so a
website can use them without the API. The old `FloCMS\Api\...` names still
work: they are aliases of the same classes, deprecated, and removed in 2.0.

## Other middleware

- `BodySizeMiddleware($maxBytes)`: 413 for any body over the limit (checks
  `Content-Length` first).
- `RequireJsonMiddleware(allowMultipart: false)`: 415 for non-JSON
  POST/PUT/PATCH bodies.
- `JsonBodyMiddleware($maxBytes)`: 400 for malformed JSON, 413 for large JSON.
- `LocaleMiddleware($languages, $default)`: picks the language from `?lang=`,
  then `Accept-Language`, then the default; sets the `locale` attribute,
  loads the language file, sends `Content-Language`.
- `AccessLogMiddleware($loggerOrFile)`: method, path, status, duration,
  request ID and IP per request; never bodies or tokens.
- `SecurityHeadersMiddleware`: `nosniff`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, default `no-store`.

## Uploads

With `hostkurd/flocms-uploader` installed, `Uploads\HandlesUploads` handles
multipart uploads and the uploader's chunked, resumable protocol on API
routes:

```php
final class MediaController extends Controller
{
    use HandlesUploads;

    public function store(Request $request): Response
    {
        return $this->storeUpload($request, Uploader::image()->to('properties'), 'image');   // 201
    }

    public function start(Request $request): Response    // POST {"filename", "total_bytes"}
    {
        return $this->startChunkedUpload($request, $this->chunks(), $this->owner($request));
    }

    public function append(Request $request, string $id): Response   // PATCH, raw chunk, Upload-Offset header
    {
        return $this->appendChunk($request, $this->chunks(), $id, $this->owner($request));
    }
    // chunkedUploadStatus(), completeChunkedUpload(), abortChunkedUpload()
}
```

Uploader validation errors are 422s, a chunk at the wrong offset is a 409
with the expected `Upload-Offset`, and server paths are never returned.

## Versioning and deprecation

```php
$router->version('v1', $v1Routes, [new Deprecated(sunset: '2027-06-30', successor: 'https://example.com/api/v2')]);
$router->version('v2', $v2Routes);
```

`Deprecated` adds `Deprecation`, `Sunset` and `Link` headers to every response
of those routes.

## Health checks and maintenance mode

```php
$router->get('/v1/health', new HealthController())->name('health');
// 200 {"status":"ok","db":"ok"} or 503 with "db":"down"

new MaintenanceMiddleware(allowedIps: ['203.0.113.7'], clientIp: $clientIp)
// 503 + Retry-After while the FloCMS offline_mode setting is on
```

## Idempotency

```php
$router->post('/v1/inquiries', ...)->middleware(
    new IdempotencyMiddleware(new FileIdempotencyStore(ROOT . '/storage/cache/api-idempotency'))
);
```

A client that sends `Idempotency-Key: <uuid>` can retry safely: a repeated
request gets the stored response (`Idempotent-Replayed: true`) instead of
creating a duplicate. Reusing a key with a different body is a 422; a repeat
while the first is still running is a 409. Failed requests are not stored.
`DatabaseIdempotencyStore` keeps records in the `api_idempotency` table
instead of files.

## OpenAPI documentation

```php
if ($debug) {
    $router->get('/v1/openapi.json', new OpenApiController(new OpenApiGenerator($router, 'My API', '1.0.0')));
}
```

The document lists every route with its parameters. Attributes on handlers add
detail:

```php
#[Tag('Properties')]
final class PropertiesController extends Controller
{
    #[Summary('Show a property')]
    #[ResponseSchema(200, ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]])]
    #[ResponseSchema(404, description: 'Property not found')]
    public function show(string $slug): Response { ... }

    #[RequestBody(['type' => 'object', 'required' => ['email'], 'properties' => ['email' => ['type' => 'string']]])]
    public function inquire(Request $request): Response { ... }
}
```

`php flo api:docs --out=public/openapi.json` writes the file.

## Command line

The API commands are [flocms-cli](https://github.com/hostkurd/flocms-cli)
commands. flocms-api registers them in its composer.json
(`"extra": {"flocms": {"commands": [...]}}`), so `php flo` finds them on any
FloCMS site, whatever the age of its root `flo` file. List them with
`php flo list api`. The database settings come from `.env`, or from
`FLO_API_DSN` (plus `FLO_API_DB_USER` / `FLO_API_DB_PASS`) when set.

| Command | |
|---|---|
| `api:install [--minimal]` | add the API to the site: `public/api.php`, `api/routes.php`, example controllers (never overwrites) |
| `api:install-schema` | run the API's migrations (create the API tables) |
| `api:key:create <name> [--scopes=a,b] [--user=ID] [--expires=YYYY-MM-DD]` | create an API key (shown once) |
| `api:key:list`, `api:key:revoke <id>` | |
| `api:token:create <user-id> <name> [--scopes=] [--expires=]` | issue a personal access token |
| `api:token:list [--user=ID]`, `api:token:revoke <id> \| --user=ID` | |
| `api:routes` | list routes, names and middleware |
| `api:docs [--out=file]` | generate the OpenAPI document |
| `api:gc [--database]` | delete stale rate-limit and idempotency state (cron-safe) |
| `api:list` | the API commands |

Some flocms-cli commands include the API automatically:

- `php flo route:list` shows the routes of `api/routes.php` next to the
  controller routes.
- `php flo migrate` creates the API tables.
- `php flo schedule:run` runs `api:gc` daily.

`vendor/bin/flo-api <command>` still runs the API commands on their own.
`FloCMS\Api\Console\Application` remains as a deprecated wrapper with the
1.1 behavior.

## Testing your API

```php
use FloCMS\Api\Testing\{TestClient, StaticAuthenticator};

$client = new TestClient($kernel);

$client->get('/api/v1/properties', ['filter' => ['status' => 'sale']])
    ->assertOk()
    ->assertJsonPath('meta.total', 3)
    ->assertJsonPath('data.0.title', 'Sea villa');

$client->withToken($token)
    ->postJson('/api/v1/inquiries', ['name' => 'Sara'])
    ->assertValidationError('email');
```

`TestResponse` assertions: `assertStatus`, `assertOk`, `assertCreated`,
`assertNoContent`, `assertUnauthorized`, `assertForbidden`, `assertNotFound`,
`assertUnprocessable`, `assertHeader`, `assertHeaderMissing`,
`assertJsonPath`, `assertJsonMissingPath`, `assertJsonCount`,
`assertValidationError`. `StaticAuthenticator` replaces real credentials in
tests.

Run this package's own tests with `composer test`. The database tests use
SQLite, and MySQL/MariaDB too when `FLO_TEST_MYSQL_HOST` (plus `_PORT`, `_USER`,
`_PASS`, `_NAME`) is set.

## Upgrading

See [UPGRADE.md](UPGRADE.md) and [CHANGELOG.md](CHANGELOG.md).
