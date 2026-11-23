<?php

namespace App\Http\Middleware;

use App\Exceptions\Domain\FieldGroupForbiddenException;
use App\Services\Security\FieldVisibilityService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * U-07: the one place field visibility is enforced. It runs inside the Inertia middleware, so it sees every page the server sends:
 * the first load (the `page` the root view embeds), later visits and partial or deferred reloads (the Inertia JSON). Keys of a group
 * the signed-in role may not receive are removed from the props before the response leaves; a screen that exists only to show such a
 * group answers 403. A user who may see everything costs one config read and no response rewrite.
 */
class ApplyFieldVisibility
{
    public function __construct(private readonly FieldVisibilityService $visibility) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $user = $request->user();

        try {
            $this->visibility->assertRouteAllowed($user, $request->route()?->getName());
        } catch (FieldGroupForbiddenException $exception) {
            return $this->refuse($request, $exception);
        }

        $response = $next($request);
        if ($this->visibility->hiddenGroups($user) === []) {
            return $response;
        }

        if ($response instanceof JsonResponse && $request->header('X-Inertia')) {
            $page = $response->getData(true);
            if (is_array($page) && isset($page['props']) && is_array($page['props'])) {
                $page['props'] = $this->visibility->strip($page['props'], $user);
                $response->setData($page);
            }

            return $response;
        }

        // The first load: the view was rendered already, so its data is rewritten and the view rendered again.
        $original = $response instanceof Response ? $response->getOriginalContent() : null;
        if ($original instanceof View && is_array($original->getData()['page'] ?? null)) {
            $page = $original->getData()['page'];
            $page['props'] = $this->visibility->strip($page['props'] ?? [], $user);
            $response->setContent($original->with('page', $page));
        }

        return $response;
    }

    /** A page visit (first load or Inertia visit) gets the refusal as a screen in the app's own look; a data request gets JSON. Both are 403. */
    private function refuse(Request $request, FieldGroupForbiddenException $exception): SymfonyResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        }

        return Inertia::render('Errors/Forbidden', ['message' => $exception->getMessage()])->toResponse($request)->setStatusCode(403);
    }
}
