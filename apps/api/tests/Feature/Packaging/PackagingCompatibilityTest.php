<?php

namespace Tests\Feature\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Models\AuditLog;
use App\Models\PackagingCompatibility;
use App\Models\Source;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** H-02: a closure fits a container only when a person declared that neck finish pair, with its source. Nothing is inferred. */
class PackagingCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = Source::factory()->create(['title' => 'Supplier drawing 12']);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return ['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410 PP', 'source_id' => $this->source->id, 'confidence' => 'L3_standard', ...$override];
    }

    private function item(ItemKind $kind, ?string $neck, string $name): TradeMaterial
    {
        // A recorded spec value is a sourced fact (the table refuses it otherwise), so a finish comes with its source.
        $spec = $neck === null ? [] : ['packaging_source_id' => $this->source->id, 'packaging_confidence' => Confidence::L2SupplierTds];

        return TradeMaterial::factory()->packaging($kind)->create(['trade_name' => $name, 'neck_finish' => $neck, ...$spec]);
    }

    /** @return array<string, mixed> */
    private function check(TradeMaterial ...$items): array
    {
        return $this->postJson(route('packaging.compatibility.check'), ['item_ids' => array_map(fn (TradeMaterial $item): string => $item->id, $items)])->assertOk()->json();
    }

    public function test_a_pair_is_declared_with_its_source_and_confidence_and_trimmed(): void
    {
        $this->post(route('packaging.compatibility.store'), $this->payload(['container_neck_finish' => '  24/410 ', 'note' => 'Page 3']))->assertSessionHasNoErrors()->assertRedirect(route('packaging.compatibility.index'));

        $pair = PackagingCompatibility::query()->sole();
        $this->assertSame('24/410', $pair->container_neck_finish);
        $this->assertSame(Confidence::L3Standard, $pair->confidence);
        $this->assertSame($this->source->id, $pair->source_id);
        $this->assertSame('Page 3', $pair->note);
    }

    public function test_a_pair_without_a_source_a_confidence_or_a_finish_is_refused(): void
    {
        foreach (['container_neck_finish', 'closure_neck_finish', 'source_id', 'confidence'] as $field) {
            $this->post(route('packaging.compatibility.store'), array_diff_key($this->payload(), [$field => 1]))->assertSessionHasErrors($field);
        }

        $this->post(route('packaging.compatibility.store'), $this->payload(['container_neck_finish' => '   ']))->assertSessionHasErrors('container_neck_finish');
        $this->post(route('packaging.compatibility.store'), $this->payload(['confidence' => 'L9']))->assertSessionHasErrors('confidence');
        $this->assertDatabaseCount('packaging_compatibilities', 0);
    }

    public function test_the_same_pair_cannot_be_declared_twice_even_with_other_case_or_spaces_but_the_reverse_is_another_pair(): void
    {
        $this->post(route('packaging.compatibility.store'), $this->payload(['container_neck_finish' => 'PCO 1881', 'closure_neck_finish' => 'PCO 1881']))->assertSessionHasNoErrors();
        $this->post(route('packaging.compatibility.store'), $this->payload(['container_neck_finish' => ' pco  1881', 'closure_neck_finish' => 'pco 1881']))->assertSessionHasErrors('closure_neck_finish');
        $this->post(route('packaging.compatibility.store'), $this->payload(['container_neck_finish' => '28/410', 'closure_neck_finish' => '24/410']))->assertSessionHasNoErrors();
        $this->post(route('packaging.compatibility.store'), $this->payload(['container_neck_finish' => '24/410', 'closure_neck_finish' => '28/410']))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('packaging_compatibilities', 3);
    }

    public function test_the_database_refuses_a_duplicate_pair_and_an_empty_finish(): void
    {
        PackagingCompatibility::factory()->create(['container_neck_finish' => 'A 1', 'closure_neck_finish' => 'B 1']);

        $this->assertThrowsQuery(fn () => PackagingCompatibility::factory()->create(['container_neck_finish' => ' a  1', 'closure_neck_finish' => 'b 1']));
        $this->assertThrowsQuery(fn () => PackagingCompatibility::factory()->create(['container_neck_finish' => '  ']));
    }

    public function test_a_declared_pair_is_withdrawn_and_both_actions_are_audited(): void
    {
        $pair = PackagingCompatibility::factory()->create();

        $this->delete(route('packaging.compatibility.destroy', $pair))->assertRedirect(route('packaging.compatibility.index'))->assertSessionHas('success', __('packaging.compat.withdrawn'));

        $this->assertDatabaseCount('packaging_compatibilities', 0);
        $this->assertSame(['create', 'delete'], AuditLog::query()->where('table_name', 'packaging_compatibilities')->orderBy('id')->pluck('action')->map(fn ($action) => $action->value)->all());
    }

    public function test_the_list_filters_searches_and_sorts_and_nothing_is_preloaded(): void
    {
        $this->get(route('packaging.compatibility.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Packaging/Compatibility')
            ->where('list.meta.total', 0));

        PackagingCompatibility::factory()->create(['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410 PP', 'confidence' => Confidence::L3Standard, 'note' => 'Pump']);
        PackagingCompatibility::factory()->create(['container_neck_finish' => '28/410', 'closure_neck_finish' => '28/410 PP', 'confidence' => Confidence::L2SupplierTds]);
        PackagingCompatibility::factory()->create(['container_neck_finish' => '28/410', 'closure_neck_finish' => '28 ALU', 'confidence' => Confidence::L7Estimate]);

        $total = function (array $query): int {
            $count = -1;
            $this->get(route('packaging.compatibility.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$count): void {
                $count = $page->toArray()['props']['list']['meta']['total'];
            });

            return $count;
        };

        $this->assertSame(3, $total([]));
        $this->assertSame(2, $total(['container_neck_finish' => '28/410']));
        $this->assertSame(1, $total(['closure_neck_finish' => '28 ALU']));
        $this->assertSame(1, $total(['confidence' => 'L2_supplier_tds']));
        $this->assertSame(1, $total(['search' => 'pump']));
        $this->assertSame(0, $total(['container_neck_finish' => '99/999']));

        $this->get(route('packaging.compatibility.index', ['sort' => 'confidence', 'dir' => 'desc']))->assertInertia(fn (Assert $page) => $page->where('list.rows.0.confidence', 'L7_estimate'));
        $this->getJson(route('packaging.compatibility.necks', ['side' => 'closure']))->assertOk()->assertJsonCount(3);
        $this->getJson(route('packaging.compatibility.necks', ['side' => 'container']))->assertOk()->assertJsonCount(2);
        $this->getJson('/packaging/compatibility/necks/other')->assertNotFound();
    }

    public function test_the_page_offers_containers_and_closures_with_their_neck_finish(): void
    {
        $this->item(ItemKind::Container, '24/410', 'Bottle A');
        $this->item(ItemKind::Closure, null, 'Cap A');
        $this->item(ItemKind::PumpSprayer, '24/410 PP', 'Pump A');
        $this->item(ItemKind::Label, null, 'Label A');

        $this->get(route('packaging.compatibility.index'))->assertInertia(fn (Assert $page) => $page
            ->has('containerOptions', 1)
            ->where('containerOptions.0.label', 'Bottle A')
            ->has('closureOptions', 2)
            ->where('urls.check', route('packaging.compatibility.check', [], false)));
    }

    public function test_a_closure_on_a_container_with_a_declared_pair_passes_and_names_the_source(): void
    {
        PackagingCompatibility::factory()->create(['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410 PP', 'source_id' => $this->source->id, 'confidence' => Confidence::L3Standard]);

        $answer = $this->check($this->item(ItemKind::Container, '24/410', 'Bottle A'), $this->item(ItemKind::Closure, '24/410 PP', 'Cap A'));

        $this->assertSame('PASS', $answer['status']);
        $this->assertSame('PASS', $answer['checks'][0]['status']);
        $this->assertSame('Bottle A', $answer['checks'][0]['container']['name']);
        $this->assertSame('Cap A', $answer['checks'][0]['closure']['name']);
        $this->assertSame('Supplier drawing 12', $answer['checks'][0]['source_title']);
        $this->assertSame('L3_standard', $answer['checks'][0]['confidence']);
    }

    public function test_a_closure_with_no_declared_pair_fails_even_when_the_finish_text_is_identical(): void
    {
        $answer = $this->check($this->item(ItemKind::Container, '24/410', 'Bottle A'), $this->item(ItemKind::Closure, '24/410', 'Cap A'));

        $this->assertSame('FAIL', $answer['status']);
        $this->assertSame('FAIL', $answer['checks'][0]['status']);
        $this->assertNull($answer['checks'][0]['source_title']);
    }

    public function test_a_pair_declared_the_other_way_round_does_not_make_this_one_compatible(): void
    {
        PackagingCompatibility::factory()->create(['container_neck_finish' => '28/410', 'closure_neck_finish' => '24/410']);

        $this->assertSame('FAIL', $this->check($this->item(ItemKind::Container, '24/410', 'Bottle A'), $this->item(ItemKind::Closure, '28/410', 'Cap A'))['status']);
    }

    public function test_a_missing_neck_finish_is_unknown_and_names_the_component(): void
    {
        PackagingCompatibility::factory()->create(['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410']);

        $answer = $this->check($this->item(ItemKind::Container, '24/410', 'Bottle A'), $this->item(ItemKind::Closure, null, 'Cap without finish'));

        $this->assertSame('UNKNOWN', $answer['status']);
        $this->assertSame('Cap without finish', $answer['missing'][0]['item']);
        $this->assertSame('packaging_compat.neck_finish_missing', $answer['missing'][0]['reason']);
    }

    public function test_a_pair_that_rests_on_an_estimate_is_refused_by_the_check(): void
    {
        PackagingCompatibility::factory()->create(['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410', 'confidence' => Confidence::L7Estimate]);

        $answer = $this->check($this->item(ItemKind::Container, '24/410', 'Bottle A'), $this->item(ItemKind::Closure, '24/410', 'Cap A'));

        $this->assertSame('UNKNOWN', $answer['status']);
        $this->assertSame('packaging_compat.pair_confidence_L7_rejected_by_policy', $answer['missing'][0]['reason']);
    }

    public function test_a_pump_and_a_cap_are_each_checked_and_one_missing_pair_fails_the_bom(): void
    {
        PackagingCompatibility::factory()->create(['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410 PP']);

        $answer = $this->check($this->item(ItemKind::Container, '24/410', 'Bottle A'), $this->item(ItemKind::PumpSprayer, '24/410 PP', 'Pump A'), $this->item(ItemKind::Closure, '28/410', 'Cap B'));

        $this->assertSame('FAIL', $answer['status']);
        $this->assertSame(['Pump A' => 'PASS', 'Cap B' => 'FAIL'], collect($answer['checks'])->mapWithKeys(fn (array $check): array => [$check['closure']['name'] => $check['status']])->all());
    }

    public function test_the_check_takes_only_packaging_components(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->postJson(route('packaging.compatibility.check'), ['item_ids' => [$material->id]])->assertUnprocessable()->assertJsonValidationErrors('item_ids.0');
        $this->postJson(route('packaging.compatibility.check'), ['item_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('item_ids');
        $this->postJson(route('packaging.compatibility.check'), [])->assertUnprocessable()->assertJsonValidationErrors('item_ids');
    }

    public function test_the_packaging_page_links_to_the_compatibility_screen_and_every_word_has_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = array_keys(Arr::dot(require lang_path("{$locale}/packaging.php")));
            foreach (['compat.title', 'compat.form.submit', 'compat.check.result_pass', 'compat.check.result_fail', 'compat.check.result_unknown', 'errors.pair_declared'] as $key) {
                $this->assertContains($key, $texts, "{$locale} {$key}");
            }

            foreach (['neck_finish_missing', 'no_container', 'no_declared_pair', 'pair_confidence_L7_rejected_by_policy', 'pair_invalid'] as $reason) {
                $this->assertContains("packaging_compat.{$reason}", array_keys(Arr::dot(require lang_path("{$locale}/engine.php"))), "{$locale} {$reason}");
            }
        }
    }

    private function assertThrowsQuery(callable $callback): void
    {
        $this->assertThrowsException(QueryException::class, function () use ($callback): void {
            DB::transaction($callback);
        });
    }
}
