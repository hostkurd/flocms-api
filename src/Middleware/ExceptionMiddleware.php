<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\ResponseFactory;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\HttpException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $error) {
            $response = ResponseFactory::error(
                $error->getMessage(),
                $error->status,
                $this->safeErrors($error->errors, $error->status),
                (string) $request->attribute('request_id', '')
            );
            foreach ($error->headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        } catch (HttpException $error) {
            $message = trim($error->getMessage());
            if ($message === '' || preg_match('/^HTTP\s+\d{3}$/', $message)) {
                $message = $this->statusMessage($error->status);
            }

            return ResponseFactory::error(
                $message,
                $error->status,
                [],
                (string) $request->attribute('request_id', '')
            );
        } catch (Throwable $error) {
            $this->logger?->error('Unhandled API exception.', [
                'exception' => get_class($error),
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'request_id' => $request->attribute('request_id'),
            ]);

            return ResponseFactory::error(
                $this->debug ? $error->getMessage() : 'Internal Server Error',
                500,
                $this->debug ? [
                    'exception' => get_class($error),
                    'file' => $error->getFile(),
                    'line' => $error->getLine(),
                ] : [],
                (string) $request->attribute('request_id', '')
            );
        }
    }

    /** @param array<string, mixed> $errors @return array<string, mixed> */
    private function safeErrors(array $errors, int $status): array
    {
        if ($this->debug || $status < 500) {
            return $errors;
        }

        return [];
    }

    private function statusMessage(int $status): string
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
}
