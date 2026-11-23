<?php

namespace Tests\Feature\Coa;

use App\Enums\Assistant\AssistantRequestStatus;
use App\Enums\Coa\CoaExtractionStatus;
use App\Exceptions\Domain\AssistantException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\AssistantRequest;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionField;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Services\Coa\CoaExtractionService;
use App\Services\Material\MaterialDetailService;
use App\Services\Production\ReferenceValueFinder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCoaFixtures;
use Tests\TestCase;

/**
 * P-05: the model reads a COA (a scripted fake, no network) and the server checks what it returned. Until a person confirms, nothing is a property value.
 * Figures are fixtures, not data of a real certificate.
 */
class CoaExtractionTest extends TestCase
{
    use BuildsCoaFixtures, RefreshDatabase;

    private TradeMaterial $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCoa();
        Http::preventStrayRequests();
        $this->material = TradeMaterial::factory()->create(['trade_name' => 'Glycerin 99.7']);
    }

    /** @return array<string, CoaExtractionField> */
    private function fieldsByProperty(CoaExtraction $extraction): array
    {
        return $extraction->fields->keyBy(fn (CoaExtractionField $field): string => (string) $field->property_key)->all();
    }

    public function test_an_upload_stores_the_file_has_the_model_read_it_and_saves_only_a_draft(): void
    {
        $this->modelReadsTheCertificate();

        $response = $this->post(route('coa.store'), ['trade_material_id' => $this->material->id, 'file' => $this->pdf()]);

        $extraction = CoaExtraction::query()->firstOrFail();
        $response->assertRedirect(route('coa.show', $extraction));

        $this->assertSame($this->material->id, $extraction->trade_material_id);
        $this->assertSame('Acme Chemicals', $extraction->supplier);
        $this->assertSame('L240115', $extraction->lot_no);
        $this->assertSame('2026-11-01', $extraction->document_date->toDateString());
        $this->assertSame('verified', $extraction->text_check);
        $this->assertSame(CoaExtractionStatus::Extracted, $extraction->status());
        $this->assertCount(1, $extraction->events);
        $this->assertCount(3, $extraction->fields);

        // The file is on the private disk and its hash is the file's.
        Storage::disk('local')->assertExists($extraction->file_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($extraction->file_path)), $extraction->file_sha256);

        // The draft is not data: no property value exists, so nothing can read it.
        $this->assertSame(0, PropertyValue::query()->count());
        $this->assertNull(app(ReferenceValueFinder::class)->forLot($this->material, 'density', 'L240115'));
        $this->assertSame([], app(MaterialDetailService::class)->properties($this->material));

        $density = $this->fieldsByProperty($extraction)['density'];
        $this->assertSame('1.2610', $density->value);
        $this->assertSame('g/mL', $density->unit);
        $this->assertSame('ASTM D1298', $density->method);
        $this->assertSame([], $density->issues);
    }

    public function test_the_model_gets_the_document_a_forced_tool_no_sampling_and_the_egyptian_arabic_instruction(): void
    {
        $this->modelReadsTheCertificate();
        app(CoaExtractionService::class)->extract($this->pdf(), $this->material);

        $payload = $this->model->payloads[0];
        $this->assertSame(config('assistant.model'), $payload['model']);
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame(['type' => 'tool', 'name' => 'report_coa'], $payload['tool_choice']);
        $this->assertSame('report_coa', $payload['tools'][0]['name']);
        $this->assertFalse($payload['tools'][0]['input_schema']['additionalProperties']);
        $this->assertContains('density', $payload['tools'][0]['input_schema']['properties']['fields']['items']['properties']['propertyKey']['enum']);
        $this->assertNotContains('ifra_limit', $payload['tools'][0]['input_schema']['properties']['fields']['items']['properties']['propertyKey']['enum']);

        $block = $payload['messages'][0]['content'][0];
        $this->assertSame('document', $block['type']);
        $this->assertSame('application/pdf', $block['source']['media_type']);
        $this->assertStringStartsWith('%PDF', base64_decode($block['source']['data']));
        $this->assertStringContainsString('null', $payload['system']);
        $this->assertStringContainsString('report_coa', $payload['system']);
    }

    public function test_an_image_is_sent_as_an_image_block(): void
    {
        $this->documentText = null;
        $this->modelReports([$this->reported(['quote' => null])]);

        app(CoaExtractionService::class)->extract(UploadedFile::fake()->image('scan.png', 20, 20), $this->material);

        $this->assertSame('image', $this->model->payloads[0]['messages'][0]['content'][0]['type']);
        $this->assertSame('image/png', $this->model->payloads[0]['messages'][0]['content'][0]['source']['media_type']);
    }

    public function test_a_value_that_is_not_in_the_document_text_is_removed_and_flagged(): void
    {
        $this->modelReports([
            $this->reported(),
            // 1.3 is nowhere on the certificate: the model made it up.
            $this->reported(['propertyAsPrinted' => 'Purity', 'propertyKey' => 'purity', 'value' => '1.3', 'unit' => '%w/w', 'quote' => 'Purity: 99.7 %w/w']),
        ]);

        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material);
        $fields = $this->fieldsByProperty($extraction);

        $this->assertSame('1.2610', $fields['density']->value);
        $this->assertNull($fields['purity']->value);
        $this->assertNull($fields['purity']->min);
        $this->assertContains('value_not_in_document', $fields['purity']->issues);
        // What the model said is kept as printed, for the review and the audit.
        $this->assertSame('1.3', $fields['purity']->as_printed['value']);
        $this->assertSame('1.3', $extraction->raw_answer['fields'][1]['value']);
    }

    public function test_a_range_and_a_value_together_a_reversed_range_and_text_are_flagged_and_emptied_of_nothing_invented(): void
    {
        $this->documentText = "Density 1.2610\nPurity 99.7\nAcid value 0.05 0.10\nHLB 8";
        $this->modelReports([
            $this->reported(['value' => '1.2610', 'min' => '99.7']),
            $this->reported(['propertyAsPrinted' => 'Purity', 'propertyKey' => 'purity', 'value' => null, 'min' => '99.7', 'max' => '0.05', 'unit' => '%w/w', 'quote' => null]),
            $this->reported(['propertyAsPrinted' => 'Acid value', 'propertyKey' => 'acid_value', 'value' => 'about 0.05', 'unit' => 'mgKOH/g', 'quote' => null]),
            $this->reported(['propertyAsPrinted' => 'HLB', 'propertyKey' => 'hlb', 'value' => null, 'unit' => null, 'quote' => null]),
        ]);

        $fields = $this->fieldsByProperty(app(CoaExtractionService::class)->extract($this->pdf(), $this->material));

        $this->assertContains('value_shape', $fields['density']->issues);
        $this->assertContains('range_order', $fields['purity']->issues);
        $this->assertContains('not_numeric', $fields['acid_value']->issues);
        $this->assertNull($fields['acid_value']->value);
        // A missing value stays null; the unit comes from the property only where it has one unit and no dimension (HLB).
        $this->assertNull($fields['hlb']->value);
        $this->assertContains('value_shape', $fields['hlb']->issues);
        $this->assertSame('HLB', $fields['hlb']->unit);
    }

    public function test_an_unknown_property_and_an_unaccepted_unit_are_flagged_not_guessed(): void
    {
        $this->modelReports([
            $this->reported(['propertyAsPrinted' => 'Colour', 'propertyKey' => 'colour', 'value' => '1.2610', 'unit' => 'g/mL']),
            $this->reported(['unit' => 'g/cm3', 'quote' => null]),
            $this->reported(['propertyAsPrinted' => 'Purity', 'propertyKey' => 'purity', 'value' => '99.7', 'unit' => '%', 'quote' => null]),
            $this->reported(['propertyAsPrinted' => 'IFRA', 'propertyKey' => 'ifra_limit', 'value' => '99.7', 'unit' => '%w/w', 'quote' => null]),
        ]);

        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material);
        [$unknown, $alias, $percent, $ifra] = $extraction->fields->all();

        $this->assertNull($unknown->property_key);
        $this->assertContains('unknown_property', $unknown->issues);
        // g/cm3 is only a spelling of g/mL (config coa.unit_aliases): accepted, the printed one kept.
        $this->assertSame('g/mL', $alias->unit);
        $this->assertSame('g/cm3', $alias->as_printed['unit']);
        // A plain % is not %w/w: no guess.
        $this->assertNull($percent->unit);
        $this->assertContains('unit_not_allowed', $percent->issues);
        $this->assertContains('unknown_property', $ifra->issues);
    }

    public function test_a_certificate_without_a_text_layer_is_stored_unverified_and_every_field_says_so(): void
    {
        $this->documentText = null;
        $this->modelReports([$this->reported()], ['supplier' => 'Not On The Page']);

        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material);

        $this->assertSame('unverified', $extraction->text_check);
        $this->assertContains('unverifiable', $extraction->fields->first()->issues);
        // Nothing could be checked, so the model's header is kept as read: the person compares it with the original.
        $this->assertSame('Not On The Page', $extraction->supplier);
    }

    public function test_a_supplier_and_lot_the_text_does_not_contain_are_not_kept(): void
    {
        $this->modelReports([$this->reported()], ['supplier' => 'Invented Corp', 'lotNo' => 'ZZ999']);

        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material);

        $this->assertNull($extraction->supplier);
        $this->assertNull($extraction->lot_no);
    }

    public function test_a_quote_that_is_not_in_the_text_lowers_the_reading_confidence(): void
    {
        $this->modelReports([$this->reported(['quote' => 'Density 1.2610 measured at the plant'])]);

        $field = app(CoaExtractionService::class)->extract($this->pdf(), $this->material)->fields->first();

        $this->assertContains('quote_not_in_document', $field->issues);
        $this->assertSame('low', $field->reading_confidence->value);
    }

    public function test_arabic_indic_digits_and_a_decimal_comma_on_the_document_are_read_as_the_same_numbers(): void
    {
        $this->documentText = "الكثافة ١٫٢٦١٠\nالنقاء 99,7";
        $this->modelReports([
            $this->reported(['value' => '١٫٢٦١٠', 'quote' => null]),
            $this->reported(['propertyAsPrinted' => 'Purity', 'propertyKey' => 'purity', 'value' => '99,7', 'unit' => '%w/w', 'quote' => null]),
        ]);

        $fields = $this->fieldsByProperty(app(CoaExtractionService::class)->extract($this->pdf(), $this->material));

        $this->assertSame('1.2610', $fields['density']->value);
        $this->assertSame('99.7', $fields['purity']->value);
    }

    public function test_an_ambiguous_thousands_comma_is_not_guessed(): void
    {
        $this->documentText = 'Viscosity 1,234';
        $this->modelReports([$this->reported(['propertyAsPrinted' => 'Viscosity', 'propertyKey' => 'viscosity', 'value' => '1,234', 'unit' => 'mPa.s', 'quote' => null])]);

        $field = app(CoaExtractionService::class)->extract($this->pdf(), $this->material)->fields->first();

        $this->assertNull($field->value);
        $this->assertContains('not_numeric', $field->issues);
        $this->assertSame('mPa·s', $field->unit);
        $this->assertNotContains('converted', $field->issues, 'a spelling alias is not a conversion');
    }

    public function test_malformed_json_from_the_model_saves_nothing_and_removes_the_file(): void
    {
        $this->model->replyText('{"fields": [ {"propertyAsPrinted": "Density", ');

        $response = $this->post(route('coa.store'), ['trade_material_id' => $this->material->id, 'file' => $this->pdf()]);

        $response->assertSessionHas('error', __('coa.exceptions.unreadable_answer'));
        $this->assertSame(0, CoaExtraction::query()->count());
        $this->assertSame(0, CoaExtractionField::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        // The failed attempt still counts as usage.
        $this->assertSame(AssistantRequestStatus::Failed, AssistantRequest::query()->firstOrFail()->status);
    }

    public function test_an_answer_without_a_fields_list_or_with_a_field_that_is_not_an_object_is_unreadable(): void
    {
        foreach ([['supplier' => 'x'], ['fields' => 'none'], ['fields' => ['text']], ['fields' => [['value' => '1']]]] as $input) {
            $this->model->callTool('report_coa', $input);

            $this->post(route('coa.store'), ['trade_material_id' => $this->material->id, 'file' => $this->pdf()])
                ->assertSessionHas('error', __('coa.exceptions.unreadable_answer'));
        }

        $this->assertSame(0, CoaExtraction::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_json_written_as_text_with_a_code_fence_is_accepted_when_it_has_the_structure(): void
    {
        $this->model->replyText("```json\n".json_encode(['supplier' => null, 'lotNo' => null, 'documentDate' => null, 'fields' => [$this->reported()]])."\n```");

        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material);

        $this->assertCount(1, $extraction->fields);
        $this->assertNull($extraction->lot_no);
    }

    public function test_a_provider_failure_saves_nothing_and_says_so(): void
    {
        $this->model->fail(AssistantException::unreachable());

        $this->post(route('coa.store'), ['trade_material_id' => $this->material->id, 'file' => $this->pdf()])
            ->assertSessionHas('error', __('exceptions.assistant_unreachable'));

        $this->assertSame(0, CoaExtraction::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame('unreachable', AssistantRequest::query()->firstOrFail()->error_code);
    }

    public function test_a_successful_reading_is_recorded_as_usage_with_its_tokens(): void
    {
        $this->modelReadsTheCertificate();
        $extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material);

        $usage = AssistantRequest::query()->firstOrFail();
        $this->assertSame(AssistantRequestStatus::Answered, $usage->status);
        $this->assertSame(100, $usage->input_tokens);
        $this->assertSame(100, $extraction->input_tokens);
    }

    public function test_the_upload_refuses_a_wrong_type_a_too_big_file_and_an_unknown_material(): void
    {
        config(['coa.file.max_kb' => 1]);

        $this->post(route('coa.store'), ['trade_material_id' => $this->material->id, 'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])
            ->assertSessionHasErrors('file');
        $this->post(route('coa.store'), ['trade_material_id' => $this->material->id, 'file' => UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('a', 4096))])
            ->assertSessionHasErrors('file');
        $this->post(route('coa.store'), ['trade_material_id' => fake()->uuid(), 'file' => $this->pdf()])
            ->assertSessionHasErrors('trade_material_id');
        $this->post(route('coa.store'), [])->assertSessionHasErrors(['file', 'trade_material_id']);

        $this->assertSame(0, $this->model->callCount());
    }

    public function test_the_extraction_records_cannot_be_changed_or_deleted(): void
    {
        $extraction = $this->extractCoa($this->material);

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $extraction->update(['lot_no' => 'X']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $extraction->fields->first()->update(['value' => '9']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $extraction->events->first()->delete());

        // Not only through the model: the database refuses it as well.
        foreach (['coa_extractions', 'coa_extraction_fields', 'coa_extraction_events'] as $table) {
            try {
                DB::table($table)->update(['id' => DB::raw('id')]);
                $this->fail("{$table} accepted an update");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_original_streams_from_the_private_disk_and_a_missing_file_is_404(): void
    {
        $extraction = $this->extractCoa($this->material);

        $this->get(route('coa.file', $extraction))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Storage::disk('local')->delete($extraction->file_path);
        $this->get(route('coa.file', $extraction))->assertNotFound();
    }
}
