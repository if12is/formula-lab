<?php

namespace App\Http\Controllers\Api\Assistant;

use App\Enums\Assistant\AssistantConsentEndReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\GrantAssistantConsentRequest;
use App\Services\Assistant\AssistantConsentService;
use App\Support\Assistant\AssistantSessionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The privacy consent of the current browser session (P-10): read it, give it for chosen categories, take it back. */
class AssistantConsentController extends Controller
{
    public function __construct(private AssistantConsentService $consent, private AssistantSessionKey $sessionKey) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->consent->state($this->sessionKey->for($request))->toArray());
    }

    public function grant(GrantAssistantConsentRequest $request): JsonResponse
    {
        $state = $this->consent->grant($this->sessionKey->for($request) ?? '', $request->categories(), $request->user()?->getAuthIdentifier());

        return response()->json($state->toArray());
    }

    public function revoke(Request $request): JsonResponse
    {
        $key = $this->sessionKey->for($request);
        $this->consent->revoke($key, AssistantConsentEndReason::User, $request->user()?->getAuthIdentifier());

        return response()->json($this->consent->state($key)->toArray());
    }
}
