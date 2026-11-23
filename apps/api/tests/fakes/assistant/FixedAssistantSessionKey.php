<?php

namespace Tests\fakes\assistant;

use App\Services\Assistant\AssistantConsentService;
use App\Support\Assistant\AssistantSessionKey;
use Illuminate\Http\Request;

/** A session key that is always the same, so a test can say which "browser session" has consented without cookies. */
final class FixedAssistantSessionKey extends AssistantSessionKey
{
    public function __construct(private readonly ?string $key, AssistantConsentService $consent)
    {
        parent::__construct($consent);
    }

    public function for(Request $request): ?string
    {
        return $this->key;
    }
}
