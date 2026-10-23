<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * The rate limiters and the client IP tools moved to flocms-core 2.8 (their
 * tests are there). The old names keep working as aliases of the same classes.
 */
final class MovedClassesTest extends TestCase
{
    public function testTheOldNamesAreTheCoreClasses(): void
    {
        $moved = [
            'Security\ClientIpResolver', 'Security\IpMatcher',
            'RateLimit\RateLimiterInterface', 'RateLimit\RateLimitResult',
            'RateLimit\FileRateLimiter', 'RateLimit\ApcuRateLimiter',
        ];
        foreach ($moved as $name) {
            $old = new \ReflectionClass('FloCMS\Api\\' . $name);
            self::assertSame('FloCMS\Core\\' . $name, $old->getName(), $name);
        }
    }

    public function testCodeWrittenForTheOldNamesStillRuns(): void
    {
        $directory = sys_get_temp_dir() . '/flo-api-moved-' . bin2hex(random_bytes(4));
        $limiter = new \FloCMS\Api\RateLimit\FileRateLimiter($directory);
        $clientIp = new \FloCMS\Api\Security\ClientIpResolver(['10.0.0.0/8']);

        // A limiter built with the old name fits a core type, and the reverse.
        self::assertInstanceOf(\FloCMS\Core\RateLimit\RateLimiterInterface::class, $limiter);
        self::assertInstanceOf(RateLimitMiddleware::class, new RateLimitMiddleware($limiter, $clientIp, 'moved', 2, 60));

        $result = $limiter->hit('moved-test', 2, 60);
        self::assertInstanceOf(\FloCMS\Api\RateLimit\RateLimitResult::class, $result);
        self::assertTrue($result->allowed);
        self::assertTrue(\FloCMS\Api\Security\IpMatcher::matches('10.1.2.3', '10.0.0.0/8'));

        foreach (glob($directory . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($directory);
    }

    /**
     * PHP doesn't autoload a class named only in a parameter type, so the old
     * names must exist before anything uses them (a fresh process proves it).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testAnOldParameterTypeAcceptsACoreObjectInAFreshProcess(): void
    {
        self::assertTrue(interface_exists('FloCMS\Api\RateLimit\RateLimiterInterface', false), 'the alias exists before first use');
        $takesOld = static fn (\FloCMS\Api\RateLimit\RateLimiterInterface $limiter, \FloCMS\Api\Security\ClientIpResolver $ip): bool => true;

        self::assertTrue($takesOld(new \FloCMS\Core\RateLimit\FileRateLimiter(sys_get_temp_dir() . '/flo-api-moved'), new \FloCMS\Core\Security\ClientIpResolver()));
    }

    public function testAClassImplementingTheOldInterfaceStillFits(): void
    {
        $custom = new class implements \FloCMS\Api\RateLimit\RateLimiterInterface {
            public function hit(string $key, int $maxAttempts, int $windowSeconds): \FloCMS\Api\RateLimit\RateLimitResult
            {
                return new \FloCMS\Core\RateLimit\RateLimitResult(true, $maxAttempts, $maxAttempts - 1, time() + $windowSeconds, 0);
            }

            public function clear(string $key): void
            {
            }
        };
        self::assertInstanceOf(\FloCMS\Core\RateLimit\RateLimiterInterface::class, $custom);
        self::assertInstanceOf(RateLimitMiddleware::class, new RateLimitMiddleware($custom, new \FloCMS\Core\Security\ClientIpResolver(), 'custom', 1, 60));
    }
}
