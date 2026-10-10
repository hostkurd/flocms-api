# Changelog

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
