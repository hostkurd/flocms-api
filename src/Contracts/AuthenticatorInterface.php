<?php
declare(strict_types=1);

namespace FloCMS\Api\Contracts;

use FloCMS\Api\Security\Identity;
use FloCMS\Core\Http\Request;

interface AuthenticatorInterface
{
    public function authenticate(Request $request, string $guard = 'default'): ?Identity;
}
