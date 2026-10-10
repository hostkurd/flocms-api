<?php
declare(strict_types=1);

namespace FloCMS\Api\Support;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\DecoratedException;
use FloCMS\Api\ResponseFactory;
use FloCMS\Core\Http\Response;
use FloCMS\Core\HttpException;
use FloCMS\Core\ValidationException as CoreValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an exception into the standard JSON error envelope.
 *
 * Shared by ExceptionMiddleware and the Kernel's last-resort handler, so an
 * ApiException keeps its status even when ExceptionMiddleware is not in the
 * middleware list.
 *
 * @internal
 */
final class ErrorRenderer
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function render(Throwable $error, string $requestId): Response
    {
        if ($error instanceof DecoratedException) {
            $response = $this->render($error->error, $requestId);
            foreach ($error->headers as $name => $value) {
                strcasecmp($name, 'Vary') === 0
                    ? self::appendVary($response, $value)
                    : $response->header($name, $value);
            }

            return $response;
        }

        $requestId = $requestId !== '' ? $requestId : null;

        if ($error instanceof ApiException) {
            $response = ResponseFactory::error(
                $error->getMessage(),
                $error->status,
                $this->safeErrors($error->errors, $error->status),
                $requestId
            );
            foreach ($error->headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        }

        // Thrown by core Validator::validate(); its errors are field => list<string>.
        if ($error instanceof CoreValidationException) {
            return ResponseFactory::error('Validation failed.', 422, $error->getErrors(), $requestId);
        }

        if ($error instanceof HttpException) {
            $message = trim($error->getMessage());
            if ($message === '' || preg_match('/^HTTP\s+\d{3}$/', $message)) {
                $message = self::statusMessage($error->status);
            }

            return ResponseFactory::error($message, $error->status, [], $requestId);
        }

        $this->logger?->error('Unhandled API exception.', [
            'exception' => get_class($error),
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'request_id' => $requestId,
        ]);

        return ResponseFactory::error(
            $this->debug ? $error->getMessage() : 'Internal Server Error',
            500,
            $this->debug ? [
                'exception' => get_class($error),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
            ] : [],
            $requestId
        );
    }

    /** Whether render() can handle $error without logging or debug detail. */
    public static function isClientError(Throwable $error): bool
    {
        if ($error instanceof DecoratedException) {
            return self::isClientError($error->error);
        }

        return ($error instanceof ApiException && $error->status < 500)
            || ($error instanceof HttpException && $error->status < 500)
            || $error instanceof CoreValidationException;
    }

    /** Add $value to the Vary header unless it is already listed. */
    public static function appendVary(Response $response, string $value): Response
    {
        foreach ($response->headers() as $name => $existing) {
            if (strcasecmp($name, 'Vary') !== 0) {
                continue;
            }

            $values = array_map('strtolower', array_map('trim', explode(',', $existing)));
            if (in_array(strtolower($value), $values, true) || in_array('*', $values, true)) {
                return $response;
            }
        }

        return $response->appendHeader('Vary', $value);
    }

    public static function statusMessage(int $status): string
    {
        return match ($status) {
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Endpoint not found.',
            405 => 'Method not allowed.',
            419 => 'Session expired.',
            422 => 'Validation failed.',
            429 => 'Too many requests.',
            default => $status >= 500 ? 'Internal Server Error' : 'Request failed.',
        };
    }

    /**
     * @param array<string, mixed> $errors
     * @return array<string, mixed>
     */
    private function safeErrors(array $errors, int $status): array
    {
        if ($this->debug || $status < 500) {
            return $errors;
        }

        return [];
    }
}
