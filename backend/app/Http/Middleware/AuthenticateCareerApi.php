<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Submodule: application-intake (INTAKE-01). Authenticates the career project's API requests. */
class AuthenticateCareerApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('career.api_token');
        $provided = $request->bearerToken();

        if ($expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
