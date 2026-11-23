<?php

namespace Tests\Feature\Assistant;

use App\Exceptions\Domain\AssistantException;
use App\Integrations\Anthropic\HttpMessagesClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The real client against Http::fake: the request it builds and how it reads answers and errors. No network. */
class HttpMessagesClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['assistant.provider.api_key' => 'sk-test-secret-key', 'assistant.provider.base_url' => 'https://provider.test/']);
    }

    public function test_it_posts_the_payload_with_the_key_in_the_header_only_and_reads_the_answer(): void
    {
        Http::fake(['provider.test/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'مرحبا'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_material', 'input' => ['query' => 'a']]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
        ])]);

        $response = (new HttpMessagesClient)->create(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']]]);

        $this->assertSame(['مرحبا', 'tool_use', 12, 7], [$response->text(), $response->stopReason, $response->inputTokens, $response->outputTokens]);
        $this->assertSame([['id' => 'toolu_1', 'name' => 'get_material', 'input' => ['query' => 'a']]], $response->toolUses());
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://provider.test/v1/messages'
                && $request->hasHeader('x-api-key', 'sk-test-secret-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && ! str_contains($request->body(), 'sk-test-secret-key')
                && $request['model'] === 'm';
        });
    }

    public function test_a_missing_key_stops_before_anything_is_sent(): void
    {
        config(['assistant.provider.api_key' => '']);
        Http::fake();

        $this->assertThrowsException(AssistantException::class, fn () => (new HttpMessagesClient)->create([]), __('exceptions.assistant_not_configured'));
        Http::assertNothingSent();
    }

    public function test_an_error_answer_becomes_a_domain_exception_without_the_body_or_the_key(): void
    {
        Http::fake(['provider.test/*' => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'secret detail sk-test-secret-key']], 429)]);

        try {
            (new HttpMessagesClient)->create([]);
            $this->fail('An error answer must throw.');
        } catch (AssistantException $exception) {
            $this->assertSame('provider_429', $exception->reason);
            $this->assertSame(502, $exception->status);
            $this->assertStringContainsString('rate_limit_error', $exception->getMessage());
            $this->assertStringNotContainsString('sk-test', $exception->getMessage());
            $this->assertStringNotContainsString('secret detail', $exception->getMessage());
        }
    }

    public function test_an_answer_that_is_not_a_message_is_refused(): void
    {
        Http::fake(['provider.test/*' => Http::response(['unexpected' => true])]);

        $this->assertThrowsException(AssistantException::class, fn () => (new HttpMessagesClient)->create([]), __('exceptions.assistant_invalid_answer'));
    }

    public function test_a_connection_failure_is_a_domain_exception(): void
    {
        Http::fake(['provider.test/*' => fn () => throw new ConnectionException('boom sk-test-secret-key')]);

        try {
            (new HttpMessagesClient)->create([]);
            $this->fail('A connection failure must throw.');
        } catch (AssistantException $exception) {
            $this->assertSame('unreachable', $exception->reason);
            $this->assertStringNotContainsString('sk-test', $exception->getMessage());
        }
    }
}
