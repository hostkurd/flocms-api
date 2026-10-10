<?php
declare(strict_types=1);

namespace FloCMS\Api\Http;

use FloCMS\Api\Database\Connection;
use FloCMS\Api\ResponseFactory;
use FloCMS\Core\App;
use FloCMS\Core\Http\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Health check endpoint for uptime monitors:
 *
 *     $router->get('/v1/health', new HealthController())->name('health');
 *
 * 200 {"status": "ok", "db": "ok"} when the database answers, "db":
 * "not_configured" when the site has no database, and 503 with "db": "down"
 * when it does not answer. Driver errors are logged, never returned.
 */
final class HealthController
{
    public function __construct(
        private readonly bool $checkDatabase = true,
        private readonly ?Connection $connection = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function __invoke(): Response
    {
        if (!$this->checkDatabase) {
            return ResponseFactory::success(['status' => 'ok']);
        }

        if ($this->connection === null && !App::hasDbConfig()) {
            return ResponseFactory::success(['status' => 'ok', 'db' => 'not_configured']);
        }

        try {
            ($this->connection ?? new Connection())->first('SELECT 1 AS ok');
        } catch (Throwable $error) {
            $this->logger?->error('Health check: database unavailable.', [
                'exception' => get_class($error),
                'message' => $error->getMessage(),
            ]);

            return ResponseFactory::error('Service unavailable.', 503, ['status' => 'fail', 'db' => 'down'])
                ->header('Retry-After', '30');
        }

        return ResponseFactory::success(['status' => 'ok', 'db' => 'ok']);
    }
}
