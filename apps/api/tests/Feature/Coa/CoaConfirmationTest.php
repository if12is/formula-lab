<?php

namespace Tests\Feature\Coa;

use App\Enums\Coa\CoaExtractionStatus;
use App\Enums\Confidence;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionField;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Coa\CoaExtractionService;
use App\Services\Production\ReferenceValueFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCoaFixtures;
use Tests\TestCase;

/**
 * P-05: a figure becomes a property value only when a person confirms it (L1, the lot as reference); before that it is a draft nothing reads.
 */
class CoaConfirmationTest extends TestCase
{
    use BuildsCoaFixtures, RefreshDatabase;

    private TradeMaterial $material;

    private CoaExtraction $extraction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCoa();
        $this->material = TradeMaterial::factory()->create(['trade_name' => 'Glycerin 99.7']);
        $this->extraction = $this->extractCoa($this->material);
    }

    /**
     * The form body: every field decided as given (default: accepted as extracted).
     *
     * @param  array<string, array<string, mixed>>  $byProperty  Overrides of a field by its property key.
     * @return array<string, mixed>
     */
    private function body(array $byProperty = [], array $header = []): array
    {
        return [
            'lot_no' => 'L240115',
            'valid_from' => '2026-11-01',
            'supplier' => 'Acme Chemicals',
            ...$header,
            'fields' => $this->extraction->fields->map(fn (CoaExtractionField $field): array => [
                'id' => $field->id,
                'decision' => 'accept',
                'property' => $field->property_key,
                'value' => $field->value,
                'min' => $field->min,
                'max' => $field->max,
                'unit' => $field->unit,
                ...($byProperty[$field->property_key] ?? []),
            ])->all(),
        ];
    }

    public function test_confirming_saves_the_accepted_values_as_l1_with_the_lot_and_a_coa_source_through_the_property_service(): void
    {
        $this->assertSame(0, PropertyValue::query()->count());

        $this->post(route('coa.confirm', $this->extraction), $this->body(['purity' => ['decision' => 'reject']]))
            ->assertRedirect(route('coa.show', $this->extraction))
            ->assertSessionHas('success');

        $values = PropertyValue::query()->orderBy('property')->get();
        $this->assertSame(['acid_value', 'density'], $values->pluck('property')->all());
        foreach ($values as $value) {
            $this->assertSame(Confidence::L1CoaBatch, $value->confidence);
            $this->assertSame('L240115', $value->lot_no);
            $this->assertSame('2026-11-01', $value->valid_from->toDateString());
            $this->assertSame('trade_material', $value->entity_type);
            $this->assertSame($this->material->id, $value->entity_id);
        }
        $this->assertSame('1.2610', $values->firstWhere('property', 'density')->value);

        $source = Source::query()->findOrFail($values->first()->source_id);
        $this->assertSame('coa', $source->kind);
        $this->assertStringContainsString('L240115', $source->title);
        $this->assertStringContainsString(substr($this->extraction->file_sha256, 0, 8), $source->title);
        $this->assertSame(route('coa.show', $this->extraction), $source->url);

        // The engine's reference finder now finds the lot's value: confirmed data, not a draft.
        $found = app(ReferenceValueFinder::class)->forLot($this->material, 'density', 'L240115');
        $this->assertSame('1.2610', $found['value']);
        $this->assertSame('L1_coa_batch', $found['confidence']);
    }

    public function test_the_decision_is_an_event_with_who_when_the_choice_per_field_and_the_values_written(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body(['purity' => ['decision' => 'reject'], 'acid_value' => ['value' => '0.06']]));

        $this->extraction->refresh();
        $this->assertSame(CoaExtractionStatus::Confirmed, $this->extraction->status());
        $this->assertCount(2, $this->extraction->events);

        $event = $this->extraction->currentEvent();
        $this->assertNull($event->user_id);
        $this->assertNotNull($event->occurred_at);
        $this->assertCount(2, $event->property_value_ids);
        $this->assertSame(['accept', 'reject', 'accept'], array_column($event->decisions['fields'], 'decision'));

        $acid = collect($event->decisions['fields'])->firstWhere('property', 'acid_value');
        $this->assertTrue($acid['edited']);
        $this->assertSame('0.06', $acid['value']);
        $this->assertFalse(collect($event->decisions['fields'])->firstWhere('property', 'density')['edited']);

        // What the model proposed is untouched next to the person's edit.
        $this->assertSame('0.05', $this->extraction->fields->firstWhere('property_key', 'acid_value')->value);
    }

    public function test_a_person_can_correct_a_value_and_choose_a_range(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body([
            'density' => ['value' => null, 'min' => '1.2600', 'max' => '1.2620'],
            'purity' => ['unit' => '%w/w', 'value' => '99.8'],
            'acid_value' => ['decision' => 'reject'],
        ]))->assertSessionHasNoErrors();

        $density = PropertyValue::query()->where('property', 'density')->firstOrFail();
        $this->assertNull($density->value);
        $this->assertSame('1.2600', $density->min);
        $this->assertSame('1.2620', $density->max);
        $this->assertSame('99.8', PropertyValue::query()->where('property', 'purity')->firstOrFail()->value);
    }

    public function test_a_field_the_checks_emptied_cannot_be_accepted_until_the_person_gives_the_number(): void
    {
        $this->documentText = "Density 1.2610 g/mL\n";
        $this->modelReports([$this->reported(), $this->reported(['propertyAsPrinted' => 'Purity', 'propertyKey' => 'purity', 'value' => '77.7', 'unit' => '%w/w'])]);
        $this->extraction = app(CoaExtractionService::class)->extract($this->pdf(), $this->material, null, $this->consentKey);

        $this->post(route('coa.confirm', $this->extraction), $this->body())
            ->assertSessionHasErrors(['fields.1.value']);
        $this->assertSame(0, PropertyValue::query()->count());

        $this->post(route('coa.confirm', $this->extraction), $this->body(['purity' => ['value' => '99.7']]))->assertSessionHasNoErrors();
        $this->assertSame(2, PropertyValue::query()->count());
    }

    public function test_nothing_is_saved_when_one_field_is_invalid(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body(['purity' => ['unit' => 'g/mL']]))->assertSessionHasErrors('fields.1.unit');
        $this->post(route('coa.confirm', $this->extraction), $this->body(['density' => ['value' => '1,26']]))->assertSessionHasErrors('fields.0.value');
        $this->post(route('coa.confirm', $this->extraction), $this->body(['density' => ['value' => null]]))->assertSessionHasErrors('fields.0.value');
        $this->post(route('coa.confirm', $this->extraction), $this->body(['density' => ['value' => null, 'min' => '2', 'max' => '1']]))->assertSessionHasErrors('fields.0.min');
        $this->post(route('coa.confirm', $this->extraction), $this->body(['density' => ['property' => 'ifra_limit']]))->assertSessionHasErrors('fields.0.property');
        $this->post(route('coa.confirm', $this->extraction), $this->body(['density' => ['property' => 'nonsense']]))->assertSessionHasErrors('fields.0.property');

        $this->assertSame(0, PropertyValue::query()->count());
        $this->assertSame(CoaExtractionStatus::Extracted, $this->extraction->status());
    }

    public function test_the_lot_and_the_date_are_required_and_every_field_needs_one_decision(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body(header: ['lot_no' => '']))->assertSessionHasErrors('lot_no');
        $this->post(route('coa.confirm', $this->extraction), $this->body(header: ['valid_from' => '01/11/2026']))->assertSessionHasErrors('valid_from');

        $missing = $this->body();
        array_pop($missing['fields']);
        $this->post(route('coa.confirm', $this->extraction), $missing)->assertSessionHasErrors('fields');

        $twice = $this->body();
        $twice['fields'][1] = $twice['fields'][0];
        $this->post(route('coa.confirm', $this->extraction), $twice)->assertSessionHasErrors('fields');

        $foreign = $this->body();
        $foreign['fields'][0]['id'] = fake()->uuid();
        $this->post(route('coa.confirm', $this->extraction), $foreign)->assertSessionHasErrors('fields');

        $this->assertSame(0, PropertyValue::query()->count());
    }

    public function test_rejecting_every_field_saves_nothing_and_leaves_the_extraction_open(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body(['density' => ['decision' => 'reject'], 'purity' => ['decision' => 'reject'], 'acid_value' => ['decision' => 'reject']]))
            ->assertSessionHas('error', __('coa.exceptions.nothing_accepted'));

        $this->assertSame(0, PropertyValue::query()->count());
        $this->assertSame(0, Source::query()->where('kind', 'coa')->count());
        $this->assertSame(CoaExtractionStatus::Extracted, $this->extraction->status());
    }

    public function test_an_extraction_is_decided_once(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body());
        $count = PropertyValue::query()->count();

        $this->post(route('coa.confirm', $this->extraction), $this->body())->assertSessionHas('error');
        $this->post(route('coa.reject', $this->extraction), ['reason' => 'late'])->assertSessionHas('error');

        $this->assertSame($count, PropertyValue::query()->count());
        $this->assertCount(2, $this->extraction->events()->get());
    }

    public function test_rejecting_the_certificate_keeps_the_reason_and_saves_no_value(): void
    {
        $this->post(route('coa.reject', $this->extraction), ['reason' => 'Certificate of another material'])
            ->assertRedirect(route('coa.show', $this->extraction))
            ->assertSessionHas('success');

        $this->assertSame(CoaExtractionStatus::Rejected, $this->extraction->status());
        $this->assertSame('Certificate of another material', $this->extraction->currentEvent()->reason);
        $this->assertSame(0, PropertyValue::query()->count());

        $this->post(route('coa.confirm', $this->extraction), $this->body())->assertSessionHas('error');
        $this->assertSame(0, PropertyValue::query()->count());
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $this->post(route('coa.reject', $this->extraction), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(CoaExtractionStatus::Extracted, $this->extraction->status());
    }

    public function test_the_review_page_shows_the_draft_with_the_model_reading_the_checks_and_the_original(): void
    {
        $this->get(route('coa.show', $this->extraction))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Coa/Show')
                ->where('extraction.status', 'extracted')
                ->where('extraction.lot_no', 'L240115')
                ->where('extraction.file.kind', 'pdf')
                ->where('extraction.file.url', route('coa.file', $this->extraction, false))
                ->has('fields', 3)
                ->where('fields.0.level', 'L7_estimate')
                ->where('fields.0.property_key', 'density')
                ->where('fields.0.quote', 'Density at 25 C: 1.2610 g/mL (ASTM D1298)')
                ->where('fields.0.reading_confidence', 'high')
                ->where('fields.0.decision', null)
                ->has('events', 1));
    }

    public function test_after_confirmation_the_page_shows_what_was_decided(): void
    {
        $this->post(route('coa.confirm', $this->extraction), $this->body(['purity' => ['decision' => 'reject'], 'acid_value' => ['value' => '0.06']]));

        $this->get(route('coa.show', $this->extraction))
            ->assertInertia(fn ($page) => $page
                ->where('extraction.status', 'confirmed')
                ->where('fields.1.decision.decision', 'reject')
                ->where('fields.2.decision.value', '0.06')
                ->where('fields.2.decision.edited', true)
                ->where('decided.lot_no', 'L240115')
                ->has('events', 2));
    }

    public function test_the_list_filters_by_status_search_and_material(): void
    {
        $other = TradeMaterial::factory()->create(['trade_name' => 'Zinc Stearate']);
        $second = $this->extractCoa($other);
        $this->post(route('coa.reject', $second), ['reason' => 'x']);

        $this->get(route('coa.index'))->assertInertia(fn ($page) => $page->component('Coa/Index')->where('list.meta.total', 2));
        $this->get(route('coa.index', ['status' => 'rejected']))->assertInertia(fn ($page) => $page->where('list.meta.total', 1)->where('list.rows.0.material_name', 'Zinc Stearate')->where('list.rows.0.status', 'rejected'));
        $this->get(route('coa.index', ['status' => 'extracted']))->assertInertia(fn ($page) => $page->where('list.meta.total', 1)->where('list.rows.0.material_name', 'Glycerin 99.7')->where('list.rows.0.field_count', 3));
        $this->get(route('coa.index', ['search' => 'zinc']))->assertInertia(fn ($page) => $page->where('list.meta.total', 1));
        $this->get(route('coa.index', ['trade_material_id' => $this->material->id]))->assertInertia(fn ($page) => $page->where('list.meta.total', 1));
        $this->get(route('coa.index', ['search' => 'nothing-like-this']))->assertInertia(fn ($page) => $page->where('list.meta.total', 0));
    }
}
