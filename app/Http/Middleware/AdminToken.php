<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('vk.admin_token');
        $given = (string) $request->header('X-Admin-Token', '');

        if ($expected === '' || !hash_equals($expected, $given)) {
            return response()->json(['message' => 'Нужен заголовок X-Admin-Token.'], 401);
        }

        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
