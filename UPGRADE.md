# Upgrade guide

## 1.2 to 1.3

1.3 requires flocms-core 2.8. Nothing breaks:

- **Moved classes.** The rate limiters and the client IP tools moved to
  flocms-core. Change the imports when convenient; the old names are aliases
  of the same classes until 2.0:

  | 1.2 | 1.3 |
  |---|---|
  | `FloCMS\Api\RateLimit\RateLimiterInterface` | `FloCMS\Core\RateLimit\RateLimiterInterface` |
  | `FloCMS\Api\RateLimit\RateLimitResult` | `FloCMS\Core\RateLimit\RateLimitResult` |
  | `FloCMS\Api\RateLimit\FileRateLimiter` | `FloCMS\Core\RateLimit\FileRateLimiter` |
  | `FloCMS\Api\RateLimit\ApcuRateLimiter` | `FloCMS\Core\RateLimit\ApcuRateLimiter` |
  | `FloCMS\Api\Security\ClientIpResolver` | `FloCMS\Core\Security\ClientIpResolver` |
  | `FloCMS\Api\Security\IpMatcher` | `FloCMS\Core\Security\IpMatcher` |

  `RateLimiterRegistry` and the middleware stay in flocms-api. The APCu keys
  and the rate-limit files are unchanged, so counts carry over.
- **`php flo api:install`** is new: it writes the API's files. A site that
  already has `public/api.php` doesn't need it (it leaves existing files
  alone). In 1.2, `php flo api:install` was accepted as short for
  `api:install-schema`; a script that relied on that must now call
  `php flo api:install-schema` (or `php flo migrate`) in full.

## 1.1 to 1.2

1.2 moves the `api:*` commands into the FloCMS CLI. It requires
`hostkurd/flocms-cli` 2.0 (PHP 8.1, flocms-core 2.2). Update the pins
(FloCMS 1.7 does this for you):

```json
"hostkurd/flocms-cli": "2.0.0",
"hostkurd/flocms-api": "1.2.0"
```

and follow flocms-cli's UPGRADE.md for the one-time replacement of the root
`flo` file.

What changes for you:

- **Commands.** `php flo api:*` commands have the same names, options and
  output. Invalid usage (a missing argument) now exits with 2 instead of 1
  when run through `php flo`. `vendor/bin/flo-api` and
  `Console\Application` keep 1.1's exit codes.
- **Schema.** The schema is a migration. Run `php flo migrate` (or
  `php flo api:install-schema`). If you installed the schema with 1.0/1.1,
  nothing is changed: the migration is only recorded.
- **Custom commands.** Code that added commands with
  `Console\Application::register()` keeps working. New commands should be
  flocms-cli commands, in your `commands/` folder.
- **Route list.** `php flo route:list` now includes the API routes.

## 1.0 to 1.1

1.1 is backward compatible: no public class, method or argument was removed or
renamed, and every new feature is opt-in. Update the pin:

```json
"hostkurd/flocms-api": "1.1.0"
```

### Behaviour changes to know about

These follow the HTTP and CORS specifications and should not need code
changes, but check them if you test exact headers:

- **Cache-Control.** `SecurityHeadersMiddleware` now keeps a `Cache-Control`
  header the route already set. Without one, responses still get `no-store`.
- **CORS headers.** `Access-Control-Allow-Methods`, `Access-Control-Allow-Headers`
  and `Access-Control-Max-Age` are now sent on preflight responses only.
  Preflights that ask for a method or header you have not allowed get no
  `Access-Control-Allow-Origin`, so the browser blocks the request (before,
  any preflight from an allowed origin was approved).
  `X-RateLimit-Reset` and `Retry-After` are added to the default exposed
  headers (only when you did not pass your own list).
- **Route warnings.** Routes that can never match (e.g. `/p/{slug}` registered
  after `/p/{id}`) and duplicate route names are logged as warnings when you
  give the router a logger. Nothing throws unless you enable strict mode.

### Recommended changes

1. **Middleware order.** Put `SecurityHeadersMiddleware` first, so error
   responses get the security headers too:

   ```php
   $kernel->middleware([
       new SecurityHeadersMiddleware(),
       new ExceptionMiddleware($debug, $logger),
       new CorsMiddleware($origins),
       new LocaleMiddleware($languages, $default),
       new RateLimitMiddleware(...),
       new BodySizeMiddleware(),
       new JsonBodyMiddleware(),
   ]);
   ```

2. **Strict routes in development.** `new Router($logger, strict: $debug)`
   turns shadowed routes and duplicate names into exceptions while you
   develop. Strict mode will be the default in 2.0.

3. **Database tables.** If you use API keys, personal access tokens or the
   database idempotency store, create the tables once:

   ```bash
   php flo api:install-schema
   ```

   (or import `vendor/hostkurd/flocms-api/database/schema/mysql.sql`).

4. **Cron.** `php flo api:gc` removes stale rate-limit and idempotency files
   (`--database` also purges the `api_idempotency` table). Rate-limit files are
   also cleaned up automatically, so this is optional.

5. **Validation in controllers.** Add `use ValidatesRequests;` to get
   `$this->validate()` and `$this->validateQuery()`. It is a trait rather than
   a `Controller` method so that controllers with their own `validate()` keep
   working.

### FloCMS skeleton

The skeleton 1.6.0 wires all of this into `public/api.php` and forwards
`php flo api:*` to `vendor/bin/flo-api`. Existing sites can copy its
`public/api.php`, `api/routes.php` and `flo`.

## 1.0.0 to 1.0.1

Bug fixes only. Error responses keep their status, CORS headers and security
headers in every middleware order, preflights are not rate limited, routes with
unconstrained parameters work, and invalid UTF-8 no longer causes 500s. See
the CHANGELOG.
