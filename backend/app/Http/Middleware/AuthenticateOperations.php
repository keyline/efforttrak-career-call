<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Submodule: operations (OPERATIONS-01). Rejects requests without the separate operator token. */
class AuthenticateOperations
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('operations.api_token');
        $provided = $request->bearerToken();
        if ($expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
