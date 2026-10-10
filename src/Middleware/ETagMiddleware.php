<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

/**
 * Conditional GET: adds an ETag (a hash of the body) to 200 responses for
 * GET/HEAD and answers 304 Not Modified when If-None-Match matches, or, when
 * the handler set Last-Modified, when If-Modified-Since is not older.
 *
 * The handler still runs; the saving is bandwidth and client parsing. Set
 * Last-Modified with ResponseFactory::withLastModified().
 */
final class ETagMiddleware implements MiddlewareInterface
{
    /** Headers a 304 must repeat (RFC 9110 15.4.5). */
    private const KEEP_ON_304 = ['Cache-Control', 'Content-Location', 'Date', 'ETag', 'Expires', 'Vary', 'Last-Modified'];

    public function __construct(private readonly bool $weak = true)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);
        if (!$request->isMethod('GET', 'HEAD') || $response->status() !== 200) {
            return $response;
        }

        $etag = self::header($response, 'ETag');
        if ($etag === null && $response->body() !== '') {
            $hash = '"' . hash('sha256', $response->body()) . '"';
            $etag = $this->weak ? 'W/' . $hash : $hash;
            $response->header('ETag', $etag);
        }

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null) {
            return $etag !== null && self::matches($ifNoneMatch, $etag) ? self::notModified($response) : $response;
        }

        $lastModified = self::header($response, 'Last-Modified');
        $ifModifiedSince = $request->header('If-Modified-Since');
        if ($lastModified !== null && $ifModifiedSince !== null) {
            $modified = strtotime($lastModified);
            $since = strtotime($ifModifiedSince);
            if ($modified !== false && $since !== false && $modified <= $since) {
                return self::notModified($response);
            }
        }

        return $response;
    }

    /** Weak comparison (RFC 9110 13.1.2): W/"x" matches "x". */
    private static function matches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        $normalize = static fn (string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '';
        foreach (explode(',', $header) as $candidate) {
            if ($normalize($candidate) === $normalize($etag)) {
                return true;
            }
        }

        return false;
    }

    private static function notModified(Response $response): Response
    {
        $notModified = Response::noContent(304);
        foreach ($response->headers() as $name => $value) {
            foreach (self::KEEP_ON_304 as $keep) {
                if (strcasecmp($name, $keep) === 0) {
                    $notModified->header($name, $value);
                }
            }
        }

        return $notModified;
    }

    private static function header(Response $response, string $name): ?string
    {
        foreach ($response->headers() as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
