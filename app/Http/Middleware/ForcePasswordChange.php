<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->session()->get('must_change_password') && !$request->routeIs('change-password') && !$request->routeIs('logout')) {
            return redirect()->route('change-password');
        }

        return $next($request);
    }
}
