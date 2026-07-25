<?php
declare(strict_types=1);

namespace FloCMS\Api\Contracts;

use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

interface RequestHandlerInterface
{
    public function handle(Request $request): Response;
}
