<?php
declare(strict_types=1);

namespace FloCMS\Api\OpenApi;

use FloCMS\Core\Http\Response;

/**
 * Serves the generated OpenAPI document. Register it in debug mode only:
 *
 *     if ($debug) {
 *         $router->get('/v1/openapi.json', new OpenApiController(new OpenApiGenerator($router)));
 *     }
 *
 * The document is generated on request, so it includes routes registered
 * after this one.
 */
final class OpenApiController
{
    public function __construct(private readonly OpenApiGenerator $generator)
    {
    }

    public function __invoke(): Response
    {
        return Response::make($this->generator->toJson())
            ->header('Content-Type', 'application/json; charset=utf-8');
    }
}
