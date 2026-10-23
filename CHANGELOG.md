# Changelog

## 1.3.0 - unreleased

Requires hostkurd/flocms-core 2.8. Backward compatible. See UPGRADE.md.

### Added
- `php flo api:install [--minimal]`: adds the API to a site from the
  package's stubs (`stubs/`): `public/api.php`, `api/routes.php`, the example
  `ContactController`, `UsersController` and `UserResource`. A file that
  exists is never changed. It says what to add when `composer.json` does not
  load `App\Api\` from `api/` or `public/.htaccess` lacks the `/api/v1`
  rule. The FloCMS 2.0 skeleton no longer ships these files; its
  `php flo setup` runs this command for the API setups.

### Changed
- `RateLimiterInterface`, `RateLimitResult`, `FileRateLimiter`,
  `ApcuRateLimiter`, `ClientIpResolver` and `IpMatcher` moved to flocms-core
  2.8 (`FloCMS\Core\RateLimit\`, `FloCMS\Core\Security\`). Their tests moved
  with them.
- `api:routes` and `api:docs` without `api/routes.php` say to run
  `php flo api:install` instead of failing with an exception.

### Deprecated
- The `FloCMS\Api\RateLimit\` and `FloCMS\Api\Security\` names of the moved
  classes: aliases of the core classes (`class_alias`, so the same class and
  `instanceof` holds both ways), removed in 2.0.

## 1.2.0 - unreleased

Requires hostkurd/flocms-cli 2.0 (and so flocms-core 2.2). See UPGRADE.md.

### Changed
- The `api:*` commands are flocms-cli commands
  (`FloCMS\Api\Console\Commands\*`), registered in composer.json
  `extra.flocms.commands`. `php flo api:...` runs them on every FloCMS site
  without the skeleton forwarding them, and they get the CLI's help,
  `--help`, "Did you mean" suggestions (`api:create:schema` ->
  `api:install-schema`), exit codes (2 for invalid usage) and errors on
  STDERR. Names, options, messages and output are unchanged (A1).
- `api:install-schema` is a migration
  (`database/migrations/2026_10_10_000000_create_api_tables.php`),
  published through `extra.flocms.migrations`: `php flo migrate` creates
  the API tables with the application's migrations, `migrate:rollback`
  drops them. Existing tables are kept and the migration is recorded (B2).
- The API database for commands comes from the CLI's project settings
  (`.env`, including `DB_TYPE=sqlite` and `DB_DSN`); `FLO_API_DSN` still
  wins when set.
- `php flo route:list` includes `api/routes.php` (route provider) and
  `php flo schedule:run` runs `api:gc` daily.
- `bin/flo-api` finds the project from `vendor/`, not the current directory.

### Deprecated
- `FloCMS\Api\Console\Application`: it keeps the 1.1 API and behavior
  (invalid usage exits with 1) for `vendor/bin/flo-api` and existing code.
  Use `php flo` and flocms-cli's `ApplicationTester` instead.

### Quality
- CI also runs on Windows (PHP 8.1 and 8.4).

## 1.1.0 - unreleased

New features are opt-in and backward compatible. See UPGRADE.md.

### Hardening
- `SecurityHeadersMiddleware` sends `Cache-Control: no-store` only when the
  response has no Cache-Control yet (B1).
- CORS: preflights validate the requested method and headers and echo only
  allowed headers. Allow-Methods/Allow-Headers/Max-Age are sent on
  preflights only. Origin patterns (`https://*.example.com`) are supported.
  `X-RateLimit-Reset` and `Retry-After` are exposed by default (B2).
- `BodySizeMiddleware` (413 for any content type) and
  `RequireJsonMiddleware` (415) (B3).
- Named rate limits (`RateLimiterRegistry`, `'throttle:forms'`), keyed by
  identity when authenticated. Middleware aliases (`Kernel::aliases()`).
  `FileRateLimiter::gc()` with automatic cleanup. Optional `ApcuRateLimiter` (B4).
- The router reports shadowed routes and duplicate route names (warnings, or
  exceptions in strict mode) (B5).
- `AccessLogMiddleware` (B6).

### Features
- Authentication: `ApiKeyAuthenticator`, `BearerTokenAuthenticator`
  (personal access tokens), `SessionAuthenticator` (FloCMS admin session +
  CSRF), `JwtAuthenticator` (HS256), `FirstMatchAuthenticator`; schema files
  and `flo api:*` commands; `Identity::can()` with the core permission map;
  `AuthenticateMiddleware` permissions (C1).
- Request validation: `Validation\Validator` with the core rules plus
  email, url, bool, array, date, between, regex, nullable, sometimes, exists,
  unique; `ValidatesRequests` trait with `validate()` / `validateQuery()` (C2).
- `JsonResource` and `ResourceCollection`, with `?include=` (C3).
- `ResponseFactory::paginated()`, `PageRequest`, cursor pagination (C4).
- `CacheControl` and `ETagMiddleware` (304), `ResponseFactory::withLastModified()` (C5).
- `LocaleMiddleware` (C6).
- `Router::url()` (C7).
- `QueryOptions`: whitelisted sorting, filtering and sparse fieldsets (C8).
- `Uploads\HandlesUploads` for flocms-uploader, including chunked uploads (C9).
- `Router::version()` and the `Deprecated` middleware (C10).
- `HealthController` and `MaintenanceMiddleware` (C11).
- `IdempotencyMiddleware` with file and database stores (C12).
- OpenAPI 3.1 generation with handler attributes, `OpenApiController`,
  `flo api:docs` and `flo api:routes` (C13).
- `Kernel::includeRequestIdInBody()` (A7).
- `Testing\TestClient`, `TestResponse` and `StaticAuthenticator` (D1).
- `vendor/bin/flo-api` command line; the FloCMS skeleton forwards `php flo api:*` to it.

### Other
- 326 tests (5 in 1.0.0), CI on PHP 8.1 to 8.4 plus MariaDB 10.11 and MySQL 8.0.
- README rewritten as a full guide.

## 1.0.1 - 2026-10-10

Bug fixes only. No API changes.

- Fix routes with a parameter that has no constraint (`/v1/properties/{slug}`).
  They threw during registration, so the whole API returned 500 (A1).
- Keep the real status of 404, 405, 422 and 429 errors when the middleware
  list passed to `Kernel::middleware()` has no `ExceptionMiddleware`. They
  became 500 (A2).
- Keep CORS headers on error responses thrown inside `CorsMiddleware`, so a
  browser on another origin can read 404/422/429 errors (A3).
- `CorsMiddleware` answers CORS preflight requests itself, and
  `RateLimitMiddleware` no longer counts `OPTIONS` requests (A4).
- Invalid UTF-8 in response data is replaced with U+FFFD instead of causing a
  500. The Kernel logs a warning with the request ID (A5).
- Error responses rendered by the Kernel's fallback get the security headers
  (`nosniff` etc.) (A6).
- Core `FloCMS\Core\ValidationException` renders as a 422 with field errors
  instead of a 500 (A7).
- Test suite runs on PHP 8.1 to 8.4 in GitHub Actions.

Upgrade note: list `SecurityHeadersMiddleware` before `ExceptionMiddleware`,
so error responses rendered by `ExceptionMiddleware` also get the security
headers. See "Middleware order" in the README.

## 1.0.0 - 2026-07-25

- Add explicit HTTP method routing, typed route parameters, groups, names, and
  module-owned routes.
- Add middleware for JSON validation, CORS, authentication, security headers,
  exception rendering, and rate limiting.
- Add request IDs, consistent JSON envelopes, trusted-proxy client IP
  resolution, and dependency-injected handlers.
- Add automatic HEAD/OPTIONS behavior, 404/405 handling, and explicit route
  overrides.
- Add a transitional base controller for existing `api_*` actions.
