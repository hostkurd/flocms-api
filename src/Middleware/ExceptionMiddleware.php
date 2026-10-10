<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Support\ErrorRenderer;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use Psr\Log\LoggerInterface;
use Throwable;

final class ExceptionMiddleware implements MiddlewareInterface
{
    private readonly ErrorRenderer $renderer;

    public function __construct(
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->renderer = new ErrorRenderer($debug, $logger);
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            return $this->renderer->render($error, (string) $request->attribute('request_id', ''));
        }
    }
}
