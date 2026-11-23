<?php

namespace App\Listeners;

use App\Enums\Assistant\AssistantConsentEndReason;
use App\Services\Assistant\AssistantConsentService;
use App\Support\Assistant\AssistantSessionKey;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/** Signing out ends the session, so the privacy consent that belonged to it ends too (P-10). Discovered by its handle() type hint. */
class RevokeAssistantConsentOnLogout
{
    public function __construct(
        private readonly Request $request,
        private readonly AssistantSessionKey $sessionKey,
        private readonly AssistantConsentService $consent,
    ) {}

    public function handle(Logout $event): void
    {
        $this->consent->revoke($this->sessionKey->for($this->request), AssistantConsentEndReason::SessionEnded, $event->user?->getAuthIdentifier());
    }
}
