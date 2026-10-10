<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\Api\Idempotency\DatabaseIdempotencyStore;
use FloCMS\Api\Idempotency\FileIdempotencyStore;
use FloCMS\Api\RateLimit\FileRateLimiter;
use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

final class GcCommand extends ApiCommand
{
    protected string $name = 'api:gc';

    protected string $description = 'Delete stale rate-limit and idempotency state (--database: also the api_idempotency table). Safe to run from cron.';

    protected function configure(Definition $definition): void
    {
        $definition
            ->option('rate-limit-dir', null, Option::VALUE_REQUIRED, 'Rate-limit state directory', 'storage/cache/api-rate-limit')
            ->option('idempotency-dir', null, Option::VALUE_REQUIRED, 'Idempotency state directory', 'storage/cache/api-idempotency')
            ->option('max-age', null, Option::VALUE_REQUIRED, 'Delete rate-limit files older than this many seconds', '86400')
            ->option('database', null, Option::VALUE_NONE, 'Also purge the api_idempotency table');
    }

    protected function handle(): int
    {
        $directory = $this->path($this->stringOption('rate-limit-dir') ?? 'storage/cache/api-rate-limit');
        $removed = (new FileRateLimiter($directory, 0))->gc($this->intOption('max-age') ?? 86400);
        $this->line('Rate limits: ' . $removed . ' stale file(s) removed from ' . $directory . '.');

        $idempotency = $this->path($this->stringOption('idempotency-dir') ?? 'storage/cache/api-idempotency');
        $this->line('Idempotency: ' . (new FileIdempotencyStore($idempotency))->purge() . ' expired file(s) removed from ' . $idempotency . '.');

        if ($this->option('database')) {
            $this->line('Idempotency: ' . (new DatabaseIdempotencyStore($this->connection()))->purge() . ' expired row(s) removed from api_idempotency.');
        }

        return self::SUCCESS;
    }
}
