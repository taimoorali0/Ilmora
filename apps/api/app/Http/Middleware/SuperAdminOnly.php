<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->is_super_admin && $user->school_id === null && $user->tokenCan('platform'), 403);

        return $next($request);
    }
}
