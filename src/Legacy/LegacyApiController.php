<?php
declare(strict_types=1);

namespace FloCMS\Api\Legacy;

use FloCMS\Core\RateLimit\FileRateLimiter;
use FloCMS\Core\Security\ClientIpResolver;
use FloCMS\Core\ApiController;
use FloCMS\Core\Env;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Modules\Exceptions\ModuleUnavailableException;
use FloCMS\Core\Modules\ModuleSystem;

/**
 * Transitional base for existing api_<action> controllers.
 *
 * New controllers should extend FloCMS\Api\Controller and return Response
 * objects. This class intentionally preserves the terminating legacy helpers.
 */
abstract class LegacyApiController extends ApiController
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        try {
            ModuleSystem::manager()->assertEnabledForController(static::class);
        } catch (ModuleUnavailableException $error) {
            $this->error('This feature is currently unavailable.', 404, [
                'module' => $error->moduleId,
            ]);
        }
    }

    protected function bearerToken(): ?string
    {
        $token = parent::bearerToken();
        if ($token !== null && $token !== '') {
            return $token;
        }

        $header = trim((string) ($_SERVER['HTTP_X_FLOCMS_AUTHORIZATION'] ?? ''));
        if (
            $header !== ''
            && strlen($header) <= 4096
            && preg_match('/^Bearer\s+(.+)$/i', $header, $matches)
        ) {
            $token = trim((string) ($matches[1] ?? ''));

            return $token !== '' ? $token : null;
        }

        return null;
    }

    protected function throttle(
        string $bucket,
        int $maxAttempts = 10,
        int $windowSeconds = 900
    ): void {
        $directory = defined('APP_STORAGE_PATH')
            ? APP_STORAGE_PATH . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'rate-limit'
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flo-rate-limit';
        $result = (new FileRateLimiter($directory))->hit(
            $bucket . '|' . $this->clientIp(),
            $maxAttempts,
            $windowSeconds
        );

        if (!$result->allowed) {
            header('Retry-After: ' . $result->retryAfter);
            $this->error('Too many attempts. Please try again later.', 429);
        }
    }

    protected function clientIp(): string
    {
        $trusted = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) Env::get('TRUSTED_PROXIES', ''))
        )));
        $request = new Request($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);

        return (new ClientIpResolver($trusted))->resolve($request);
    }
}
