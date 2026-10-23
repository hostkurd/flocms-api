<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\OpenApi\OpenApiGenerator;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class DocsCommand extends ApiCommand
{
    protected string $name = 'api:docs';

    protected string $description = 'Generate an OpenAPI 3.1 document from the routes (stdout, or --out=file)';

    protected array $examples = ['php flo api:docs --out=public/openapi.json --title="My API"'];

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('out', null, Option::VALUE_REQUIRED, 'Write to this file instead of stdout')
            ->option('title', null, Option::VALUE_REQUIRED, 'The API title', 'FloCMS API')
            ->option('api-version', null, Option::VALUE_REQUIRED, 'The API version', '1.0.0')
            ->option('server', null, Option::VALUE_REQUIRED, 'The server URL')
            ->option('routes', null, Option::VALUE_REQUIRED, 'The routes file', 'api/routes.php')
            ->option('prefix', null, Option::VALUE_REQUIRED, 'The URL prefix', '/api');
    }

    protected function handle(): int
    {
        $router = self::loadRouter($this->project()->root(), $this->routesFile(), $this->stringOption('prefix') ?? '/api');
        $json = (new OpenApiGenerator(
            $router,
            $this->stringOption('title') ?? 'FloCMS API',
            $this->stringOption('api-version') ?? '1.0.0',
            $this->stringOption('server')
        ))->toJson();

        $out = $this->stringOption('out');
        if ($out === null) {
            $this->output->write($json);

            return self::SUCCESS;
        }
        if (@file_put_contents($this->path($out), $json) === false) {
            $this->fail('Unable to write ' . $out . '.');
        }
        $this->line('OpenAPI document written to ' . $out . '.');

        return self::SUCCESS;
    }
}
