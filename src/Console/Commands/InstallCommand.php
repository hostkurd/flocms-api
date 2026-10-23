<?php
declare(strict_types=1);

namespace FloCMS\Api\Console\Commands;

use FloCMS\CLI\Console\Input\Definition;
use FloCMS\CLI\Console\Input\Option;

/**
 * php flo api:install (1.3): adds the API to a FloCMS site. Writes
 * public/api.php, api/routes.php and the example controllers from the
 * package's stubs. A file that already exists is never changed.
 */
final class InstallCommand extends ApiCommand
{
    /** Project path => stub, in the order they are written. */
    public const FILES = [
        'public/api.php' => 'public/api.php.stub',
        'api/routes.php' => 'api/routes.php.stub',
        'api/Controllers/ContactController.php' => 'api/Controllers/ContactController.php.stub',
        'api/Controllers/UsersController.php' => 'api/Controllers/UsersController.php.stub',
        'api/Resources/UserResource.php' => 'api/Resources/UserResource.php.stub',
    ];

    /** Only these with --minimal: the front controller and a routes file with the health check. */
    private const MINIMAL = ['public/api.php', 'api/routes.php'];

    protected string $name = 'api:install';

    protected string $description = 'Add the API to this site: public/api.php, api/routes.php and example controllers (never overwrites)';

    protected string $help = <<<'TXT'
Writes public/api.php (the API front controller, served under /api/v1),
api/routes.php and two example controllers with a resource in api/
(namespace App\Api\). A file that already exists is left as it is, so
running it again only adds what is missing.

With --minimal only public/api.php and an api/routes.php with the health
check are written.

Then run `php flo migrate` to create the API tables (tokens, API keys).
TXT;

    protected array $examples = [
        'php flo api:install',
        'php flo api:install --minimal',
    ];

    protected function configure(Definition $definition): void
    {
        $definition->option('minimal', null, Option::VALUE_NONE, 'Only public/api.php and a routes file with the health check');
    }

    protected function handle(): int
    {
        $project = $this->project();
        $minimal = (bool) $this->option('minimal');
        $written = 0;

        foreach (self::FILES as $target => $stub) {
            if ($minimal && !in_array($target, self::MINIMAL, true)) {
                continue;
            }
            $path = $project->path($target);
            if (is_file($path)) {
                $this->note($target . ' already exists: left as it is.');
                continue;
            }
            $contents = $minimal && $target === 'api/routes.php' ? self::minimalRoutes() : self::stub($stub);
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                $this->fail('Could not create ' . dirname($target) . '/: check the folder permissions.');
            }
            if (@file_put_contents($path, $contents, LOCK_EX) === false) {
                $this->fail('Could not write ' . $target . ': check the folder permissions.');
            }
            $this->success('Created ' . $target);
            $written++;
        }

        if ($written === 0) {
            $this->line('Nothing to add: the API is already installed.');
        }

        $this->checkAutoload($project->path('composer.json'));
        $this->checkRewrite($project->path('public/.htaccess'));

        $this->line('');
        $this->line('Next: run `php flo migrate` to create the API tables, then open /api/v1/health.');

        return self::SUCCESS;
    }

    /** The contents of a stub shipped with the package. */
    public static function stub(string $stub): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/stubs/' . $stub);
        if ($contents === false) {
            throw new \RuntimeException('Missing stub: ' . $stub);
        }

        return $contents;
    }

    private static function minimalRoutes(): string
    {
        return <<<'PHP'
<?php

use FloCMS\Api\Http\HealthController;
use FloCMS\Api\Router;

/*
 * API routes, served by public/api.php under /api (e.g. GET /api/v1/health).
 * List them with `php flo api:routes`. See the flocms-api README for
 * controllers, validation, resources and the 'auth' and 'throttle' middleware.
 */
return static function (Router $router): void {
    $router->version('v1', static function (Router $router): void {
        // GET /api/v1/health -> {"status":"ok","db":"ok"}
        $router->get('/health', new HealthController())->name('health');
    });
};

PHP;
    }

    /** The controllers are App\Api\ classes: composer.json must map that namespace to api/. */
    private function checkAutoload(string $composerFile): void
    {
        $composer = is_file($composerFile) ? json_decode((string) file_get_contents($composerFile), true) : null;
        $map = is_array($composer) ? ($composer['autoload']['psr-4']['App\\Api\\'] ?? null) : null;
        $dirs = array_map(static fn ($dir): string => rtrim(str_replace('\\', '/', (string) $dir), '/'), (array) $map);
        if (in_array('api', $dirs, true)) {
            return;
        }
        $this->warn('composer.json does not load App\\Api\\ from api/. Add it, then run `composer dump-autoload`:');
        $this->line('    "autoload": { "psr-4": { "App\\\\Api\\\\": "api/" } }');
    }

    /** Apache sends /api/v1 to api.php only with the rule in public/.htaccess (`php flo serve` always does). */
    private function checkRewrite(string $htaccess): void
    {
        if (!is_file($htaccess) || preg_match('/^\s*RewriteRule\s+\S*api\/v1\S*\s+api\.php/m', (string) file_get_contents($htaccess)) === 1) {
            return;
        }
        $this->warn('public/.htaccess has no rule for /api/v1. Add it before the index.php rule:');
        $this->line('    RewriteRule ^api/v1(/.*)?$ api.php [L,QSA]');
    }
}
