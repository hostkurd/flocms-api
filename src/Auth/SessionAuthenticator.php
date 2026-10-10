<?php
declare(strict_types=1);

namespace FloCMS\Api\Auth;

use Closure;
use FloCMS\Api\Contracts\AuthenticatorInterface;
use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Security\Identity;
use FloCMS\Core\Auth;
use FloCMS\Core\Csrf;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Session;

/**
 * Lets the FloCMS admin call /api/v1 endpoints from same-origin JavaScript
 * with the admin session cookie.
 *
 * - The session is opened read-only, and only when the request carries the
 *   session cookie, so anonymous API calls never create sessions.
 * - The user is reloaded through Auth::refresh() (Config 'auth.user_loader'),
 *   so suspended or demoted admins lose access immediately.
 * - POST, PUT, PATCH and DELETE need the session's CSRF token in the
 *   X-CSRF-TOKEN header (public/themes/default/js/csrf.js sends it); a
 *   missing or wrong token is a 419.
 */
final class SessionAuthenticator implements AuthenticatorInterface
{
    private readonly ?Closure $sessionStarter;

    /**
     * @param bool $requireAdmin only users with admin access (Config 'admin_access_roles')
     * @param null|callable(Request): bool $sessionStarter opens the session; for tests
     */
    public function __construct(
        private readonly bool $requireAdmin = true,
        ?callable $sessionStarter = null
    ) {
        $this->sessionStarter = $sessionStarter !== null ? Closure::fromCallable($sessionStarter) : null;
    }

    public function authenticate(Request $request, string $guard = 'default'): ?Identity
    {
        if (!$this->startSession($request)) {
            return null;
        }

        if (!Session::get('isloggedin') || !Auth::refresh()) {
            return null;
        }
        if ($this->requireAdmin && !Session::get('admin_access')) {
            return null;
        }

        $userId = Session::get('user_id');
        if ($userId === null || $userId === '' || !is_numeric($userId)) {
            return null;
        }

        if ($request->isStateChanging() && !Csrf::validate($request->header('X-CSRF-TOKEN'))) {
            throw new ApiException('CSRF token mismatch.', 419);
        }

        $role = Auth::role();

        return new Identity(
            (int) $userId,
            $role === null ? [] : [(string) $role],
            [],
            ['credential' => 'session']
        );
    }

    private function startSession(Request $request): bool
    {
        if ($this->sessionStarter !== null) {
            return ($this->sessionStarter)($request);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            return false;
        }

        $id = $request->cookie(session_name());
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{22,256}$/', $id)) {
            return false;
        }

        // Read-only: no session lock is held while the endpoint runs, and an
        // unknown id does not create a new session.
        return @session_start([
            'read_and_close' => true,
            'use_strict_mode' => true,
            'use_only_cookies' => true,
        ]);
    }
}
