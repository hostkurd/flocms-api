<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\DecoratedException;
use FloCMS\Api\Security\ClientIpResolver;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\HttpException;
use FloCMS\Core\ValidationException as CoreValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One line per request: method, path (without query string), status,
 * duration in ms, request ID and client IP. Bodies, query strings and headers
 * (tokens, cookies) are never logged.
 *
 * Pass a PSR-3 logger, or a file path such as storage/logs/api.log. List it
 * first in the middleware list so it sees the final status of every request.
 */
final class AccessLogMiddleware implements MiddlewareInterface
{
    private readonly ClientIpResolver $clientIp;

    public function __construct(
        private readonly LoggerInterface|string $target,
        ?ClientIpResolver $clientIp = null
    ) {
        $this->clientIp = $clientIp ?? new ClientIpResolver();
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $started = hrtime(true);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $error) {
            $this->write($request, self::statusOf($error), $started);
            throw $error;
        }

        $this->write($request, $response->status(), $started);

        return $response;
    }

    private function write(Request $request, int $status, int|float $started): void
    {
        $entry = [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
            'request_id' => (string) $request->attribute('request_id', ''),
            'ip' => $this->clientIp->resolve($request),
        ];

        if ($this->target instanceof LoggerInterface) {
            $this->target->info('API request', $entry);

            return;
        }

        $line = sprintf(
            "[%s] %s %s %d %.1fms request_id=%s ip=%s\n",
            date('Y-m-d H:i:s'),
            $entry['method'],
            // Paths come from the client; keep one entry per line.
            str_replace(["\r", "\n", ' '], ['%0D', '%0A', '%20'], $entry['path']),
            $entry['status'],
            $entry['duration_ms'],
            $entry['request_id'] !== '' ? $entry['request_id'] : '-',
            $entry['ip']
        );
        $directory = dirname($this->target);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
        if (@file_put_contents($this->target, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    private static function statusOf(Throwable $error): int
    {
        if ($error instanceof DecoratedException) {
            return self::statusOf($error->error);
        }

        return match (true) {
            $error instanceof ApiException => $error->status,
            $error instanceof HttpException => $error->status,
            $error instanceof CoreValidationException => 422,
            default => 500,
        };
    }
}
