<?php

namespace App\Http\Middleware;

use Closure;

class RoleMiddleware
{
    public function handle($request, Closure $next, ...$roles)
    {
        $userRole = $request->attributes->get('firebase_role');
        if (!in_array($userRole, $roles)) {
            abort(403, 'Unauthorized action.');
        }
        return $next($request);
    }
}