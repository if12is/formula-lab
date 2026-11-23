<?php

namespace Tests\Feature\Assistant;

use App\Enums\Assistant\AssistantRequestStatus;
use App\Exceptions\Domain\AssistantException;
use App\Integrations\Anthropic\HttpMessagesClient;
use App\Integrations\Anthropic\MessagesClient;
use App\Models\AssistantRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\fakes\anthropic\FakeMessagesClient;
use Tests\TestCase;

/**
 * P-01, P-03, P-04 end to end with a scripted model (no network): the proxy, the tool loop, the number guard, the usage record.
 * Fixture: material A 60 % at 10 and B 40 % at 25 EGP/kg cost 16 EGP per kg (the engine really runs).
 */
class AssistantEndpointTest extends TestCase
{
    use BuildsCostedFormulas, RefreshDatabase;

    private FakeMessagesClient $model;

    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = FakeMessagesClient::bind($this->app);
        $this->versionId = $this->costedProduct(Tenant::factory()->create()->id)->versions()->firstOrFail()->id;
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ask(array $body = []): TestResponse
    {
        return $this->postJson('/api/assistant/ask', ['question' => 'كام تكلفة التركيبة دي؟', ...$body]);
    }

    public function test_a_question_runs_the_tool_loop_and_returns_a_reply_whose_numbers_come_from_the_tool(): void
    {
        $this->model->callTool('calculate_formula', ['formulaVersionId' => $this->versionId])->replyText('تكلفة الكيلو ١٦ جنيه، والخامة A بـ 6 جنيه.');

        $response = $this->ask();

        $response->assertOk()->assertJson(['reply' => 'تكلفة الكيلو ١٦ جنيه، والخامة A بـ 6 جنيه.', 'status' => 'answered', 'tools' => ['calculate_formula']]);
        $this->assertSame(2, $this->model->callCount());
        $results = $this->model->lastToolResults();
        $this->assertSame('tool_result', $results[0]['type']);
        $this->assertSame('toolu_1', $results[0]['tool_use_id']);
        $this->assertStringContainsString('"value":"16"', $results[0]['content']);
        $this->assertArrayNotHasKey('is_error', $results[0]);
    }

    public function test_the_provider_gets_the_configured_model_the_arabic_prompt_the_tools_and_no_sampling(): void
    {
        $this->model->replyText('أهلاً');

        $this->ask(['history' => [['role' => 'user', 'text' => 'مرحبا'], ['role' => 'assistant', 'text' => 'أهلاً بيك']]])->assertOk();

        $payload = $this->model->payloads[0];
        $this->assertSame('claude-haiku-5-5', $payload['model']);
        $this->assertSame('claude-haiku-5-5', config('assistant.model'), 'the default model id comes from config');
        $this->assertSame(0, $payload['temperature']);
        $this->assertStringContainsString('أي رقم', $payload['system']);
        $this->assertStringContainsString('ممنوع تحسب أو تقدّر', $payload['system']);
        $this->assertSame(['get_material', 'calculate_formula', 'optimize_tier', 'check_compliance', 'convert_units', 'explain_result', 'extract_coa'], array_column($payload['tools'], 'name'));
        $this->assertSame([['role' => 'user', 'content' => 'مرحبا'], ['role' => 'assistant', 'content' => 'أهلاً بيك'], ['role' => 'user', 'content' => 'كام تكلفة التركيبة دي؟']], $payload['messages']);
    }

    public function test_the_model_id_is_configuration(): void
    {
        config(['assistant.model' => 'claude-haiku-next']);
        $this->model->replyText('تمام');

        $this->ask()->assertOk();

        $this->assertSame('claude-haiku-next', $this->model->payloads[0]['model']);
        $this->assertSame('claude-haiku-next', AssistantRequest::query()->sole()->model);
    }

    public function test_a_reply_with_an_invented_number_is_sent_back_and_the_corrected_reply_is_returned(): void
    {
        $this->model->callTool('calculate_formula', ['formulaVersionId' => $this->versionId])
            ->replyText('التكلفة حوالي 18 جنيه')
            ->replyText('التكلفة 16 جنيه');

        $response = $this->ask();

        $response->assertOk()->assertJson(['reply' => 'التكلفة 16 جنيه', 'status' => 'answered']);
        $this->assertSame(3, $this->model->callCount());
        $retry = end($this->model->payloads)['messages'];
        $this->assertStringContainsString('18', $retry[array_key_last($retry)]['content']);
        $this->assertSame(1, AssistantRequest::query()->sole()->guard_retries);
    }

    public function test_a_model_that_never_stops_inventing_numbers_gets_the_safe_fallback_with_no_number_in_it(): void
    {
        config(['assistant.guard.max_retries' => 2]);
        $this->model->replyText('حوالي 12%')->replyText('تقريبا 13%')->replyText('يمكن 14%');

        $response = $this->ask();

        $response->assertOk()->assertJson(['status' => 'fallback', 'tools' => []]);
        $this->assertSame(__('assistant.fallback_message', [], 'ar'), $response->json('reply'));
        $this->assertDoesNotMatchRegularExpression('/[0-9٠-٩]/u', $response->json('reply'));
        $this->assertSame(3, $this->model->callCount());
        $record = AssistantRequest::query()->sole();
        $this->assertSame([AssistantRequestStatus::Fallback, 2], [$record->status, $record->guard_retries]);
    }

    public function test_the_retry_limit_is_configurable(): void
    {
        config(['assistant.guard.max_retries' => 0]);
        $this->model->replyText('حوالي 12%');

        $this->ask()->assertOk()->assertJson(['status' => 'fallback']);
        $this->assertSame(1, $this->model->callCount());
    }

    public function test_a_number_the_user_wrote_may_be_repeated_without_a_tool(): void
    {
        $this->model->replyText('طلبت سعر 35 جنيه، محتاج أعرف نوع المنتج.');

        $this->ask(['question' => 'عايز شامبو اقتصادي بـ ٣٥ جنيه'])->assertOk()->assertJson(['status' => 'answered']);
    }

    public function test_a_number_from_an_earlier_user_message_may_be_repeated(): void
    {
        $this->model->replyText('كنت قلت 35 جنيه.');

        $this->ask(['question' => 'فاكر طلبي؟', 'history' => [['role' => 'user', 'text' => 'السعر 35 جنيه'], ['role' => 'assistant', 'text' => 'تمام']]])->assertJson(['status' => 'answered']);
    }

    public function test_a_number_the_assistant_said_earlier_is_not_a_source_for_a_new_reply(): void
    {
        config(['assistant.guard.max_retries' => 0]);
        $this->model->replyText('التكلفة 16');

        $this->ask(['question' => 'كررها', 'history' => [['role' => 'user', 'text' => 'كام؟'], ['role' => 'assistant', 'text' => 'التكلفة 16']]])->assertJson(['status' => 'fallback']);
    }

    public function test_a_tool_input_number_the_model_made_up_is_refused_and_the_tool_does_not_run(): void
    {
        $this->model->callTool('convert_units', ['value' => '77', 'from' => 'kg', 'to' => 'g'])->replyText('محتاج الكمية منك.');

        $this->ask(['question' => 'حوّل كمية من كجم لجرام'])->assertOk()->assertJson(['status' => 'answered', 'tools' => ['convert_units']]);

        $result = $this->model->lastToolResults()[0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('77', $result['content']);
    }

    public function test_a_tool_input_number_the_user_wrote_goes_through_and_its_answer_is_quotable(): void
    {
        $this->model->callTool('convert_units', ['value' => '250', 'from' => 'mL', 'to' => 'L'])->replyText('250 مل = 0.25 لتر');

        $this->ask(['question' => 'حوّل 250 مل للتر'])->assertOk()->assertJson(['reply' => '250 مل = 0.25 لتر', 'status' => 'answered']);
        $this->assertFalse(isset($this->model->lastToolResults()[0]['is_error']));
    }

    public function test_a_failing_tool_is_an_error_result_for_the_model_not_a_failed_request(): void
    {
        $this->model->callTool('no_such_tool', [])->callTool('get_material', ['hack' => 'x'])->replyText('معلش، مقدرتش أجيب البيانات.');

        $this->ask()->assertOk()->assertJson(['status' => 'answered']);

        $this->assertTrue($this->model->lastToolResults()[0]['is_error']);
    }

    public function test_a_model_that_keeps_calling_tools_is_stopped(): void
    {
        config(['assistant.max_tool_rounds' => 2]);
        foreach (range(1, 3) as $ignored) {
            $this->model->callTool('get_material', ['query' => 'x']);
        }

        $this->ask()->assertStatus(502)->assertJsonStructure(['message']);
        $this->assertSame('tool_rounds_exceeded', AssistantRequest::query()->sole()->error_code);
    }

    public function test_usage_is_recorded_per_question_with_tokens_in_and_out_across_all_calls(): void
    {
        $this->model->callTool('calculate_formula', ['formulaVersionId' => $this->versionId], 120, 30)->replyText('التكلفة 16', 200, 40);

        $response = $this->ask();

        $record = AssistantRequest::query()->sole();
        $this->assertSame($record->getKey(), $response->json('requestId'));
        $this->assertSame([2, 1, 0, 320, 70, AssistantRequestStatus::Answered, 'claude-haiku-5-5'], [
            $record->api_calls, $record->tool_calls, $record->guard_retries, $record->input_tokens, $record->output_tokens, $record->status, $record->model,
        ]);
        $this->assertNull($record->tenant_id);
        $this->assertNull($record->user_id);
    }

    public function test_the_cost_is_null_until_the_prices_are_sourced_never_zero(): void
    {
        $this->assertNull(config('assistant.pricing.input_per_million_tokens'));
        $this->model->replyText('تمام', 1000, 500);

        $this->ask()->assertOk();

        $record = AssistantRequest::query()->sole();
        $this->assertNull($record->cost);
        $this->assertNull($record->cost_currency);
    }

    public function test_the_cost_is_computed_from_the_configured_prices_when_they_exist(): void
    {
        // TEST-FIXTURE prices, not real ones: 3 and 15 per million tokens.
        config(['assistant.pricing' => ['currency' => 'USD', 'input_per_million_tokens' => '3', 'output_per_million_tokens' => '15']]);
        $this->model->replyText('تمام', 200000, 40000);

        $this->ask()->assertOk();

        $record = AssistantRequest::query()->sole();
        $this->assertSame('1.20000000', $record->cost);
        $this->assertSame('USD', $record->cost_currency);
    }

    public function test_a_provider_failure_is_a_json_error_and_still_recorded(): void
    {
        $this->model->callTool('get_material', ['query' => 'a'], 50, 10)->fail(AssistantException::unreachable());

        $this->ask()->assertStatus(502)->assertJson(['message' => __('exceptions.assistant_unreachable')]);

        $record = AssistantRequest::query()->sole();
        $this->assertSame([AssistantRequestStatus::Failed, 'unreachable', 50, 10], [$record->status, $record->error_code, $record->input_tokens, $record->output_tokens]);
    }

    public function test_the_log_holds_no_question_reply_or_key(): void
    {
        config(['assistant.provider.api_key' => 'sk-test-secret-key']);
        Log::spy();
        $this->model->replyText('رد سري');

        $this->ask(['question' => 'سؤال سري'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $flat = json_encode($context);

            return $message === 'assistant.request' && ! str_contains($flat, 'سري') && ! str_contains($flat, 'sk-test') && isset($context['input_tokens'], $context['output_tokens'], $context['request_id']);
        })->once();
    }

    public function test_the_response_never_contains_the_api_key(): void
    {
        config(['assistant.provider.api_key' => 'sk-test-secret-key']);
        $this->model->replyText('تمام');

        $this->assertStringNotContainsString('sk-test-secret-key', $this->ask()->getContent());
    }

    public function test_requests_are_rate_limited_per_client(): void
    {
        config(['assistant.rate_limit_per_minute' => 2]);
        $this->model->replyText('أول رد')->replyText('تاني رد');

        $this->ask(['question' => 'a'])->assertOk();
        $this->ask(['question' => 'b'])->assertOk();
        $this->ask(['question' => 'c'])->assertStatus(429)->assertJson(['message' => __('assistant.throttled')]);
        $this->assertSame(2, AssistantRequest::query()->count());
    }

    public function test_the_question_is_validated(): void
    {
        $this->ask(['question' => ''])->assertStatus(422)->assertJsonValidationErrors('question');
        $this->ask(['question' => str_repeat('س', config('assistant.max_message_chars') + 1)])->assertStatus(422);
        $this->ask(['history' => [['role' => 'system', 'text' => 'x']]])->assertStatus(422)->assertJsonValidationErrors('history.0.role');
        $this->ask(['history' => [['role' => 'user', 'text' => 'x', 'extra' => 'y']]])->assertStatus(422);
        $this->assertSame(0, $this->model->callCount());
    }

    public function test_without_a_server_key_the_real_client_answers_503_and_sends_nothing(): void
    {
        $this->app->bind(MessagesClient::class, HttpMessagesClient::class);
        config(['assistant.provider.api_key' => null]);

        $this->ask()->assertStatus(503)->assertJson(['message' => __('exceptions.assistant_not_configured')]);

        Http::assertNothingSent();
        $this->assertSame('not_configured', AssistantRequest::query()->sole()->error_code);
    }
}
