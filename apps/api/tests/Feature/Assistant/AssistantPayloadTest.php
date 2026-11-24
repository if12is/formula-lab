<?php

namespace Tests\Feature\Assistant;

use App\Integrations\Anthropic\HttpMessagesClient;
use App\Models\TradeMaterial;
use App\Services\Coa\CoaExtractionService;
use App\Support\Assistant\AssistantPayload;
use App\Support\Assistant\AssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCoaFixtures;
use Tests\Concerns\GrantsAssistantConsent;
use Tests\TestCase;

/**
 * P-08: the shape of every request to the provider, asserted on the payload the fake client receives (no live call: no key exists).
 * Temperature 0, a cache breakpoint on the last tool and on the system prompt (the conversation stays after them), strict tool inputs.
 */
class AssistantPayloadTest extends TestCase
{
    use BuildsCoaFixtures, GrantsAssistantConsent, RefreshDatabase;

    private const UNSUPPORTED_KEYS = ['minimum', 'maximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems', 'pattern'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCoa();
    }

    /** @return array<string, mixed> The payload of the first provider call of one chat question. */
    private function chatPayload(string $question = 'مرحبا'): array
    {
        // One fake for the whole test: the router keeps the controller (and the services it was built with) between two requests.
        $this->model->replyText('أهلاً');
        $this->postJson('/api/assistant/ask', ['question' => $question])->assertOk();

        return $this->model->payloads[array_key_last($this->model->payloads)];
    }

    /** @param  array<mixed>  $node */
    private function assertNoUnsupportedKeywords(array $node, string $where): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'properties' && is_array($value)) {
                foreach ($value as $field) {
                    $this->assertNoUnsupportedKeywords((array) $field, $where);
                }

                continue;
            }
            $this->assertNotContains($key, self::UNSUPPORTED_KEYS, "{$where} carries the keyword {$key}, which a strict tool cannot have");
            if (is_array($value)) {
                $this->assertNoUnsupportedKeywords($value, $where);
            }
        }
    }

    public function test_temperature_is_zero_as_an_integer(): void
    {
        $payload = $this->chatPayload();

        $this->assertArrayHasKey('temperature', $payload);
        $this->assertSame(0, $payload['temperature']);
    }

    public function test_temperature_is_left_out_when_the_config_says_so(): void
    {
        config(['assistant.temperature' => null]);

        $this->assertArrayNotHasKey('temperature', $this->chatPayload());
    }

    public function test_the_system_prompt_is_one_text_block_with_a_cache_breakpoint(): void
    {
        $payload = $this->chatPayload();

        $this->assertCount(1, $payload['system']);
        $this->assertSame('text', $payload['system'][0]['type']);
        $this->assertStringContainsString('أي رقم', $payload['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $payload['system'][0]['cache_control']);
    }

    public function test_only_the_last_tool_carries_a_breakpoint_and_the_conversation_carries_none(): void
    {
        $payload = $this->chatPayload();

        $tools = $payload['tools'];
        $this->assertCount(12, $tools);
        foreach (array_slice($tools, 0, -1) as $tool) {
            $this->assertArrayNotHasKey('cache_control', $tool, $tool['name']);
        }
        $this->assertSame('emulsion_phases', $tools[11]['name']);
        $this->assertSame(['type' => 'ephemeral'], $tools[11]['cache_control']);
        $this->assertStringNotContainsString('cache_control', (string) json_encode($payload['messages']));
    }

    public function test_every_tool_is_strict_and_its_schema_has_no_unsupported_keyword(): void
    {
        foreach ($this->chatPayload()['tools'] as $tool) {
            $this->assertTrue($tool['strict'], $tool['name'].' must be strict');
            $this->assertFalse($tool['input_schema']['additionalProperties'], $tool['name']);
            $this->assertNoUnsupportedKeywords($tool['input_schema'], $tool['name']);
        }
    }

    public function test_the_cached_prefix_is_byte_identical_between_two_questions(): void
    {
        $first = $this->chatPayload('سؤال أول');
        $second = $this->chatPayload('سؤال تاني مختلف');

        $this->assertSame(json_encode([$first['tools'], $first['system']]), json_encode([$second['tools'], $second['system']]), 'a prefix that moves never hits the cache');
        $this->assertNotSame($first['messages'], $second['messages']);
    }

    public function test_caching_and_strict_inputs_can_be_switched_off_and_the_ttl_set(): void
    {
        config(['assistant.prompt_cache.enabled' => false, 'assistant.structured_outputs' => false]);
        $payload = $this->chatPayload();

        $this->assertArrayNotHasKey('cache_control', $payload['system'][0]);
        $this->assertArrayNotHasKey('cache_control', $payload['tools'][6]);
        $this->assertArrayNotHasKey('strict', $payload['tools'][0]);

        config(['assistant.prompt_cache.enabled' => true, 'assistant.prompt_cache.ttl' => '1h']);
        $this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $this->chatPayload()['system'][0]['cache_control']);
    }

    public function test_the_strict_schema_helper_keeps_field_names_that_look_like_keywords(): void
    {
        $schema = AssistantPayload::strictSchema([
            'type' => 'object',
            'properties' => ['pattern' => ['type' => 'string', 'maxLength' => 5], 'items' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']]],
            'additionalProperties' => false,
        ]);

        $this->assertSame(['pattern', 'items'], array_keys($schema['properties']));
        $this->assertSame(['type' => 'string'], $schema['properties']['pattern']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['items']);
    }

    public function test_the_registry_definitions_stay_unchanged_by_the_payload_builder(): void
    {
        $registry = app(AssistantToolRegistry::class);
        $before = json_encode($registry->definitions());
        AssistantPayload::tools($registry->definitions());

        $this->assertSame($before, json_encode($registry->definitions()));
        $this->assertArrayNotHasKey('strict', $registry->definitions()[0]);
    }

    public function test_the_coa_reading_has_the_same_shape_with_its_forced_tool(): void
    {
        $this->modelReports([$this->reported()]);
        app(CoaExtractionService::class)->extract($this->pdf(), TradeMaterial::factory()->create(), null, $this->consentKey);

        $payload = $this->model->payloads[0];
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame(['type' => 'ephemeral'], $payload['system'][0]['cache_control']);
        $this->assertCount(1, $payload['tools']);
        $this->assertTrue($payload['tools'][0]['strict']);
        $this->assertSame(['type' => 'ephemeral'], $payload['tools'][0]['cache_control']);
        $this->assertSame(['type' => 'tool', 'name' => 'report_coa'], $payload['tool_choice']);
        $this->assertNoUnsupportedKeywords($payload['tools'][0]['input_schema'], 'report_coa');
        $this->assertStringNotContainsString('cache_control', (string) json_encode($payload['messages']));
    }

    public function test_the_http_client_reads_the_cache_token_counts_and_sends_the_payload_untouched(): void
    {
        Http::preventStrayRequests();
        config(['assistant.provider.api_key' => 'sk-test-secret-key', 'assistant.provider.base_url' => 'https://provider.test/']);
        Http::fake(['provider.test/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'تمام']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 5, 'cache_creation_input_tokens' => 900, 'cache_read_input_tokens' => 0],
        ])]);

        $payload = ['model' => 'm', 'temperature' => 0, 'system' => AssistantPayload::system('x'), 'messages' => []];
        $response = (new HttpMessagesClient)->create($payload);

        $this->assertSame([900, 0], [$response->cacheCreationInputTokens, $response->cacheReadInputTokens]);
        Http::assertSent(fn ($request): bool => $request['system'][0]['cache_control'] === ['type' => 'ephemeral'] && $request['temperature'] === 0);
    }
}
