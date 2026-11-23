<?php

namespace App\Services\Assistant;

use App\Actions\Assistant\ExpireAssistantConsentsAction;
use App\Actions\Assistant\RecordAssistantConsentEventAction;
use App\Actions\Assistant\RecordAssistantDisclosureAction;
use App\Actions\Assistant\ResolveAssistantConsentAction;
use App\Enums\Assistant\AssistantConsentEndReason;
use App\Enums\Assistant\AssistantConsentEventType;
use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\Assistant\AssistantDisclosureOutcome;
use App\Exceptions\Domain\AssistantException;
use App\Support\DTOs\AssistantConsentState;

/**
 * The single write path of the assistant's privacy consent (P-10). Formula content, costs and supplier certificates reach the model provider
 * only while the current browser session has an active consent covering their category. Every grant, revoke and every send or refusal is
 * appended to a log that holds categories and times, never the content. A session without a key (no web session) has no consent.
 */
final class AssistantConsentService
{
    /** The key of a send or refusal that happened outside a web session. */
    public const NO_SESSION = 'no-session';

    public function __construct(
        private readonly RecordAssistantConsentEventAction $recordEvent,
        private readonly RecordAssistantDisclosureAction $recordDisclosure,
        private readonly ResolveAssistantConsentAction $resolve,
        private readonly ExpireAssistantConsentsAction $expire,
    ) {}

    /** The session id is only ever stored hashed. */
    public function sessionKey(string $sessionId): string
    {
        return hash('sha256', 'assistant-consent|'.$sessionId);
    }

    public function state(?string $sessionKey): AssistantConsentState
    {
        return ($this->resolve)($sessionKey);
    }

    /** @param  list<AssistantDataCategory>  $categories */
    public function grant(string $sessionKey, array $categories, ?int $userId = null): AssistantConsentState
    {
        $unique = array_values(array_unique($categories, SORT_REGULAR));
        ($this->recordEvent)($sessionKey, AssistantConsentEventType::Granted, $unique, null, $userId);

        return $this->state($sessionKey);
    }

    /** Ends an active consent (a session with none is left alone: no empty rows). */
    public function revoke(?string $sessionKey, AssistantConsentEndReason $reason, ?int $userId = null): void
    {
        if ($sessionKey === null || ! $this->state($sessionKey)->granted()) {
            return;
        }

        ($this->recordEvent)($sessionKey, AssistantConsentEventType::Revoked, [], $reason, $userId);
    }

    /** @param  list<AssistantDataCategory>  $categories */
    public function allows(?string $sessionKey, array $categories): bool
    {
        return $this->state($sessionKey)->allows($categories);
    }

    /**
     * The gate before anything that would send data of these categories to the provider. A refusal is logged first.
     *
     * @param  list<AssistantDataCategory>  $categories
     *
     * @throws AssistantException When the session has not consented to every category.
     */
    public function authorize(?string $sessionKey, string $surface, array $categories): void
    {
        if ($categories === [] || $this->allows($sessionKey, $categories)) {
            return;
        }

        ($this->recordDisclosure)($sessionKey ?? self::NO_SESSION, $surface, $categories, AssistantDisclosureOutcome::Refused);

        throw AssistantException::consentRequired($categories);
    }

    /**
     * Logs that data of these categories was sent through a surface.
     *
     * @param  list<AssistantDataCategory>  $categories
     */
    public function disclosed(?string $sessionKey, string $surface, array $categories): void
    {
        if ($categories !== []) {
            ($this->recordDisclosure)($sessionKey ?? self::NO_SESSION, $surface, $categories, AssistantDisclosureOutcome::Sent);
        }
    }

    /** Closes the consents whose time ran out (scheduled). Returns how many. */
    public function expireStale(): int
    {
        return ($this->expire)();
    }
}
