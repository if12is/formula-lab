<?php

namespace App\Http\Middleware;

use App\Services\Terms\TermsAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S-16: until the person has accepted the current terms of use, every page sends them to the terms screen and returns them to where they were
 * after accepting. Off when config terms.enforce is false. Only web pages pass through here; the machine endpoints (Sanad, assistant API) do not.
 */
class RequireTermsAcceptance
{
    public const INTENDED_KEY = 'terms.intended';

    public function __construct(private TermsAcceptanceService $termsService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('terms.enforce') || $this->isOpen($request)) {
            return $next($request);
        }

        if ($this->termsService->hasAccepted($request->user(), $request->session()->getId())) {
            return $next($request);
        }

        // Only a page the person was reading is worth returning to; a failed form post is not.
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put(self::INTENDED_KEY, $request->getRequestUri());
        } else {
            $request->session()->put(self::INTENDED_KEY, route('home', [], false));
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, __('terms.required_json'));
        }

        return redirect()->route('terms.show', [], 303);
    }

    /** The path to return to (a local path only), and forget it. */
    public static function pullIntended(Request $request): string
    {
        $intended = (string) $request->session()->pull(self::INTENDED_KEY, '');

        return str_starts_with($intended, '/') && ! str_starts_with($intended, '//') ? $intended : route('home', [], false);
    }

    private function isOpen(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && in_array($route->getName(), (array) config('terms.open_routes'), true);
    }
}
