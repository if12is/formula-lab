<?php

namespace App\Integrations\Anthropic;

use App\Exceptions\Domain\AssistantException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API over HTTP. The key comes from config (env) at call time and travels only in the `x-api-key` header; it is
 * never put in an exception, a log line or a stored row. An error answer is reduced to its status code and the provider's error type.
 */
final class HttpMessagesClient implements MessagesClient
{
    public function create(array $payload): MessageResponse
    {
        $key = config('assistant.provider.api_key');
        if (! is_string($key) || $key === '') {
            throw AssistantException::notConfigured();
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => (string) config('assistant.provider.api_version')])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('assistant.provider.timeout_seconds'))
                ->post(rtrim((string) config('assistant.provider.base_url'), '/').'/v1/messages', $payload);
        } catch (ConnectionException) {
            throw AssistantException::unreachable();
        }

        if ($response->failed()) {
            $type = $response->json('error.type');

            throw AssistantException::providerError($response->status(), is_string($type) ? $type : 'unknown');
        }

        $content = $response->json('content');
        if (! is_array($content) || ! is_string($response->json('stop_reason'))) {
            throw AssistantException::invalidAnswer();
        }

        return new MessageResponse(
            array_values($content),
            (string) $response->json('stop_reason'),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            (int) $response->json('usage.cache_creation_input_tokens', 0),
            (int) $response->json('usage.cache_read_input_tokens', 0),
        );
    }
}
