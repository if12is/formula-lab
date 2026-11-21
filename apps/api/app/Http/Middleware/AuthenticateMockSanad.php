<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer check of the mock Sanad: `sanad.mock.token` may read and write, `sanad.mock.read_only_token`
 * only read (contract section 1: 401 unknown token, 403 missing ability).
 */
class AuthenticateMockSanad
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = (string) $request->bearerToken();
        $canWrite = $token !== '' && hash_equals((string) config('sanad.mock.token'), $token);
        $canRead = $canWrite || ($token !== '' && hash_equals((string) config('sanad.mock.read_only_token'), $token));

        if (! $canRead) {
            return response()->json(['message' => __('sanad.mock.unauthenticated')], 401);
        }
        if ($ability === 'write' && ! $canWrite) {
            return response()->json(['message' => __('sanad.mock.ability_missing')], 403);
        }

        return $next($request);
    }
}
