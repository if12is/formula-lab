<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The assistant's routes do not exist while the master switch (config assistant.enabled) is off: production's default (P-10). */
class EnsureAssistantEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('assistant.enabled'), 404);

        return $next($request);
    }
}
