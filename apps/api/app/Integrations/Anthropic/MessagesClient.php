<?php

namespace App\Integrations\Anthropic;

use App\Exceptions\Domain\AssistantException;

/**
 * The one door to the model provider (Anthropic Messages API). The assistant depends on this interface only, so tests bind a fake
 * and no test ever reaches the network. The payload is the provider's request body as an array; an answer is a MessageResponse.
 */
interface MessagesClient
{
    /**
     * @param  array<string, mixed>  $payload  model, max_tokens, temperature, system, tools, messages.
     *
     * @throws AssistantException When the provider is not configured, cannot be reached or answers with an error.
     */
    public function create(array $payload): MessageResponse;
}
