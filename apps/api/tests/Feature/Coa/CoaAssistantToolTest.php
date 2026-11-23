<?php

namespace Tests\Feature\Coa;

use App\Enums\Assistant\AssistantRequestStatus;
use App\Exceptions\Domain\AssistantException;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Services\Assistant\AssistantService;
use App\Services\Coa\CoaExtractionService;
use App\Support\Assistant\AssistantToolRegistry;
use App\Support\Assistant\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCoaFixtures;
use Tests\TestCase;

/**
 * P-02/P-05: the extract_coa tool reports the draft as an UNCONFIRMED reading; the number guard still holds the reply to what the tool said.
 */
class CoaAssistantToolTest extends TestCase
{
    use BuildsCoaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCoa();
    }

    /** @return array{ok: bool, data: array<string, mixed>, missing: list<array<string, mixed>>, warnings: list<string>} */
    private function tool(array $input): array
    {
        return app(AssistantToolRegistry::class)->get('extract_coa')($input, new ToolContext);
    }

    public function test_a_draft_is_reported_as_l7_unconfirmed_with_the_warning(): void
    {
        $extraction = $this->extractCoa(TradeMaterial::factory()->create(['trade_name' => 'Glycerin 99.7']));

        $answer = $this->tool(['extractionId' => $extraction->id]);

        $this->assertTrue($answer['ok']);
        $this->assertFalse($answer['data']['confirmed']);
        $this->assertSame('extracted', $answer['data']['status']);
        $this->assertSame(['coa.draft_unconfirmed'], $answer['warnings']);
        $this->assertSame('L240115', $answer['data']['lotNo']);
        $this->assertCount(3, $answer['data']['fields']);
        foreach ($answer['data']['fields'] as $field) {
            $this->assertSame('L7_estimate', $field['confidence']);
        }
        $this->assertSame('1.2610', $answer['data']['fields'][0]['value']);
        $this->assertSame([], $answer['missing']);
    }

    public function test_what_the_document_did_not_give_is_missing_not_zero(): void
    {
        $this->modelReports([$this->reported(['value' => null, 'quote' => null])], ['lotNo' => null]);
        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), TradeMaterial::factory()->create(), null, $this->consentKey);

        $answer = $this->tool(['extractionId' => $extraction->id]);

        $this->assertNull($answer['data']['fields'][0]['value']);
        $this->assertEqualsCanonicalizing(['lot_no', 'density'], array_column($answer['missing'], 'property'));
    }

    public function test_after_confirmation_it_reports_what_was_saved_at_l1_with_the_persons_edit(): void
    {
        $extraction = $this->extractCoa(TradeMaterial::factory()->create());
        $fields = $extraction->fields->map(fn ($field): array => ['id' => $field->id, 'decision' => $field->property_key === 'purity' ? 'reject' : 'accept', 'property' => $field->property_key, 'value' => $field->property_key === 'density' ? '1.2611' : $field->value, 'min' => null, 'max' => null, 'unit' => $field->unit])->all();
        app(CoaExtractionService::class)->confirm($extraction, ['lot_no' => 'L240115', 'valid_from' => '2026-11-01'], $fields, null);

        $answer = $this->tool(['extractionId' => $extraction->id]);

        $this->assertTrue($answer['data']['confirmed']);
        $this->assertSame([], $answer['warnings']);
        $this->assertSame(['density', 'acid_value'], array_column($answer['data']['fields'], 'property'));
        $this->assertSame('1.2611', $answer['data']['fields'][0]['value']);
        $this->assertSame('L1_coa_batch', $answer['data']['fields'][0]['confidence']);
    }

    public function test_an_unknown_extraction_is_missing_and_a_bad_input_is_refused(): void
    {
        $this->assertSame('coa.not_found', $this->tool(['extractionId' => fake()->uuid()])['missing'][0]['reason']);
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool(['extractionId' => 'x']));
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool(['extractionId' => fake()->uuid(), 'values' => []]), 'values');
    }

    public function test_the_tool_never_writes_anything(): void
    {
        $extraction = $this->extractCoa(TradeMaterial::factory()->create());
        $events = $extraction->events()->count();

        $this->tool(['extractionId' => $extraction->id]);

        $this->assertSame($events, $extraction->events()->count());
        $this->assertSame(0, PropertyValue::query()->count());
    }

    public function test_a_chat_reply_may_quote_a_draft_value_only_as_the_tool_gave_it(): void
    {
        $extraction = $this->extractCoa(TradeMaterial::factory()->create());
        $assistant = app(AssistantService::class);

        // The model quotes the tool's 1.2610 and says it is unconfirmed: passes the guard.
        $this->model->callTool('extract_coa', ['extractionId' => $extraction->id])->replyText('الكثافة المقروءة 1.2610 g/mL وهي مسودة غير مؤكدة (L7).');
        $ok = $assistant->ask('إيه اللي اتقرا من الشهادة؟', consentKey: $this->consentKey);
        $this->assertSame(AssistantRequestStatus::Answered, $ok['reply']->status);

        // The model invents 1.3: the guard sends it back, and it never comes clean: the safe fallback.
        $this->model->callTool('extract_coa', ['extractionId' => $extraction->id]);
        foreach (range(1, 3) as $ignored) {
            $this->model->replyText('الكثافة 1.3 g/mL.');
        }
        $bad = $assistant->ask('إيه الكثافة؟', consentKey: $this->consentKey);
        $this->assertSame(AssistantRequestStatus::Fallback, $bad['reply']->status);
        $this->assertStringNotContainsString('1.3', $bad['reply']->text);

        // The model is told, in the tool's own description and the system prompt, that a draft is not data.
        $this->assertStringContainsString('UNCONFIRMED', collect($this->model->payloads[1]['tools'])->firstWhere('name', 'extract_coa')['description']);
        $this->assertStringContainsString('extract_coa', $this->model->payloads[1]['system'][0]['text']);
    }
}
