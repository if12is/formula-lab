<?php

namespace App\Support\Assistant;

use App\Services\Assistant\AssistantConsentService;
use Illuminate\Http\Request;

/** The consent key of the browser session a request belongs to (the hashed session id), or null when the request has no session. */
class AssistantSessionKey
{
    public function __construct(private readonly AssistantConsentService $consent) {}

    public function for(Request $request): ?string
    {
        return $request->hasSession() ? $this->consent->sessionKey($request->session()->getId()) : null;
    }
}
