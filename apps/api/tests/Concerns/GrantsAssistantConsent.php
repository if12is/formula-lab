<?php

namespace Tests\Concerns;

use App\Enums\Assistant\AssistantDataCategory;
use App\Services\Assistant\AssistantConsentService;
use App\Support\Assistant\AssistantSessionKey;
use Tests\fakes\assistant\FixedAssistantSessionKey;

/** P-10 in tests: a fixed browser-session key that the requests of the test run under, and a way to consent (or not) for it. */
trait GrantsAssistantConsent
{
    protected string $consentKey = 'test-browser-session';

    /** Makes every request of the test belong to the fixed session (null = a request with no session at all). */
    protected function runUnderConsentSession(?string $key = 'test-browser-session'): void
    {
        $this->consentKey = (string) $key;
        $this->app->instance(AssistantSessionKey::class, new FixedAssistantSessionKey($key, app(AssistantConsentService::class)));
    }

    /** @param  list<AssistantDataCategory>|null  $categories  Every category when null. */
    protected function consentTo(?array $categories = null): void
    {
        app(AssistantConsentService::class)->grant($this->consentKey, $categories ?? AssistantDataCategory::cases());
    }
}
