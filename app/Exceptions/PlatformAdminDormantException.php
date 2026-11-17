<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The platform admin API while it is switched off: a 404 with the very message the router gives a route that does not exist, so that the
 * answer, for a person or a scanner, is the one a missing route has. Nothing about it says the route is there.
 */
class PlatformAdminDormantException extends NotFoundHttpException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf('The route %s could not be found.', $path));
    }
}
