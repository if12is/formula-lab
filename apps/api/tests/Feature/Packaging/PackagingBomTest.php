<?php

namespace Tests\Feature\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Models\AuditLog;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Models\PackagingCompatibility;
use App\Models\SanadProduct;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Support\DecimalCompare;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** S-12: the packaging BOM of a SKU, its lines, its pack image on a private disk, and the engine's cost preview. */
class PackagingBomTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('packaging.image.disk'));
        $this->source = Source::factory()->create(['title' => 'Supplier quote 7', 'retrieved_at' => '2026-11-01']);
    }

    private function item(ItemKind $kind, string $name, ?string $neck = null): TradeMaterial
    {
        $spec = $neck === null ? [] : ['neck_finish' => $neck, 'packaging_source_id' => $this->source->id, 'packaging_confidence' => Confidence::L2SupplierTds];

        return TradeMaterial::factory()->packaging($kind)->create(['trade_name' => $name, ...$spec]);
    }

    /** @return array<string, mixed> */
    private function line(TradeMaterial $item, array $override = []): array
    {
        return ['trade_material_id' => $item->id, 'level' => 'unit', 'qty_per_unit' => '1', 'reject_pct' => '0', 'price_egp' => '3', 'source_id' => $this->source->id, 'confidence' => 'L2_supplier_tds', ...$override];
    }

    /** @return array<string, mixed> */
    private function cost(PackagingBom $bom): array
    {
        return $this->getJson(route('packaging.boms.cost', $bom))->assertOk()->json();
    }

    private function png(string $name = 'pack.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    public function test_a_bom_is_created_with_its_packing_and_the_empty_fields_stay_null(): void
    {
        $response = $this->post(route('packaging.boms.store'), ['name' => 'Shampoo 400 ml', 'sku_code' => 'SH-400', 'units_per_carton' => '12']);

        $bom = PackagingBom::query()->sole();
        $response->assertSessionHasNoErrors()->assertRedirect(route('packaging.boms.show', $bom))->assertSessionHas('success', __('packaging.bom.created'));
        $this->assertSame('SH-400', $bom->sku_code);
        $this->assertSame(12, $bom->units_per_carton);
        $this->assertNull($bom->cartons_per_pallet);
        $this->assertNull($bom->sanad_product_uuid);
    }

    public function test_a_bom_needs_a_name_and_whole_positive_packing_numbers(): void
    {
        $this->post(route('packaging.boms.store'), [])->assertSessionHasErrors('name');
        $this->post(route('packaging.boms.store'), ['name' => 'A', 'units_per_carton' => '0'])->assertSessionHasErrors('units_per_carton');
        $this->post(route('packaging.boms.store'), ['name' => 'A', 'units_per_carton' => '2.5'])->assertSessionHasErrors('units_per_carton');
        $this->post(route('packaging.boms.store'), ['name' => 'A', 'cartons_per_pallet' => '-4'])->assertSessionHasErrors('cartons_per_pallet');
        $this->post(route('packaging.boms.store'), ['name' => 'A', 'sanad_product_uuid' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('sanad_product_uuid');
        $this->assertDatabaseCount('packaging_boms', 0);
    }

    public function test_a_bom_links_only_to_a_cached_finished_good_of_sanad(): void
    {
        $good = SanadProduct::factory()->create(['item_kind' => 'finished_good', 'name' => 'Shampoo 400']);
        $raw = SanadProduct::factory()->create(['item_kind' => 'raw_material']);

        $this->post(route('packaging.boms.store'), ['name' => 'A', 'sanad_product_uuid' => $raw->uuid])->assertSessionHasErrors('sanad_product_uuid');
        $this->post(route('packaging.boms.store'), ['name' => 'A', 'sanad_product_uuid' => $good->uuid])->assertSessionHasNoErrors();

        $this->assertSame($good->uuid, PackagingBom::query()->sole()->sanad_product_uuid);
    }

    public function test_a_bom_is_updated_and_deleted_with_its_lines_and_all_of_it_is_audited(): void
    {
        $bom = PackagingBom::factory()->create(['name' => 'Old']);
        $bottle = $this->item(ItemKind::Container, 'Bottle');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle))->assertSessionHasNoErrors();

        $this->put(route('packaging.boms.update', $bom), ['name' => 'New', 'units_per_carton' => '6'])->assertSessionHasNoErrors();
        $this->assertSame('New', $bom->fresh()->name);

        $this->delete(route('packaging.boms.destroy', $bom))->assertRedirect(route('packaging.boms.index'));

        $this->assertDatabaseCount('packaging_boms', 0);
        $this->assertDatabaseCount('packaging_bom_lines', 0);
        $this->assertSame(['create', 'delete'], AuditLog::query()->where('table_name', 'packaging_bom_lines')->orderBy('id')->pluck('action')->map(fn ($action) => $action->value)->all());
        $this->assertTrue(AuditLog::query()->where('table_name', 'packaging_boms')->where('action', 'update')->exists());
    }

    public function test_a_line_is_added_updated_and_removed(): void
    {
        $bom = PackagingBom::factory()->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle');
        $label = $this->item(ItemKind::Label, 'Label');

        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle))->assertSessionHasNoErrors();
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($label, ['price_egp' => '0.5']))->assertSessionHasNoErrors();

        $first = $bom->lines()->first();
        $this->assertSame([0, 1], $bom->lines()->pluck('position')->all());

        $this->put(route('packaging.boms.lines.update', [$bom, $first]), $this->line($bottle, ['price_egp' => '4.25', 'reject_pct' => '2.5']))->assertSessionHasNoErrors();
        $this->assertSame('4.250000', $first->fresh()->price_egp);

        $this->delete(route('packaging.boms.lines.destroy', [$bom, $first]))->assertSessionHasNoErrors();
        $this->assertSame(1, $bom->lines()->count());
    }

    public function test_a_line_with_a_figure_needs_its_source_and_confidence_and_a_line_with_none_keeps_no_source(): void
    {
        $bom = PackagingBom::factory()->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle');

        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['source_id' => null]))->assertSessionHasErrors('source_id');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['confidence' => null]))->assertSessionHasErrors('confidence');
        $this->assertDatabaseCount('packaging_bom_lines', 0);

        // Every figure left empty is allowed: the cost then names the gaps. A stray source is not kept.
        $this->post(route('packaging.boms.lines.store', $bom), ['trade_material_id' => $bottle->id, 'level' => 'unit', 'source_id' => $this->source->id, 'confidence' => 'L3_standard'])->assertSessionHasNoErrors();
        $line = PackagingBomLine::query()->sole();
        $this->assertNull($line->qty_per_unit);
        $this->assertNull($line->price_egp);
        $this->assertNull($line->source_id);
    }

    public function test_a_line_refuses_bad_figures_an_ingredient_and_a_repeated_component(): void
    {
        $bom = PackagingBom::factory()->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle');
        $ingredient = TradeMaterial::factory()->create();

        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['qty_per_unit' => '0']))->assertSessionHasErrors('qty_per_unit');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['qty_per_unit' => 'abc']))->assertSessionHasErrors('qty_per_unit');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['reject_pct' => '100']))->assertSessionHasErrors('reject_pct');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['price_egp' => '-1']))->assertSessionHasErrors('price_egp');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['level' => 'truck']))->assertSessionHasErrors('level');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($ingredient))->assertSessionHasErrors('trade_material_id');
        $this->assertDatabaseCount('packaging_bom_lines', 0);

        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle))->assertSessionHasNoErrors();
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle))->assertSessionHasErrors('trade_material_id');
        $this->assertDatabaseCount('packaging_bom_lines', 1);

        // The same component may sit in another SKU's BOM, and a line may be saved again with its own component.
        $other = PackagingBom::factory()->create();
        $this->post(route('packaging.boms.lines.store', $other), $this->line($bottle))->assertSessionHasNoErrors();
        $this->put(route('packaging.boms.lines.update', [$bom, $bom->lines()->first()]), $this->line($bottle))->assertSessionHasNoErrors();
    }

    public function test_a_line_of_another_bom_cannot_be_changed_through_this_one(): void
    {
        $bom = PackagingBom::factory()->create();
        $other = PackagingBom::factory()->create();
        $line = PackagingBomLine::factory()->for($other, 'bom')->create();

        $this->put(route('packaging.boms.lines.update', [$bom, $line]), $this->line($line->tradeMaterial))->assertNotFound();
        $this->delete(route('packaging.boms.lines.destroy', [$bom, $line]))->assertNotFound();
        $this->assertDatabaseCount('packaging_bom_lines', 1);
    }

    public function test_the_database_refuses_an_unsourced_figure_a_reject_of_100_and_a_repeated_component(): void
    {
        $bom = PackagingBom::factory()->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle');
        $line = ['packaging_bom_id' => $bom->id, 'trade_material_id' => $bottle->id];

        $this->assertThrowsQuery(fn () => PackagingBomLine::query()->create([...$line, 'price_egp' => '3']));
        $this->assertThrowsQuery(fn () => PackagingBomLine::query()->create([...$line, 'reject_pct' => '100', 'source_id' => $this->source->id, 'confidence' => Confidence::L2SupplierTds]));
        $this->assertThrowsQuery(fn () => PackagingBomLine::query()->create([...$line, 'qty_per_unit' => '0', 'source_id' => $this->source->id, 'confidence' => Confidence::L2SupplierTds]));
        PackagingBomLine::query()->create($line);
        $this->assertThrowsQuery(fn () => PackagingBomLine::query()->create($line));
    }

    private function assertThrowsQuery(callable $write): void
    {
        // A savepoint, so the refused statement does not abort the test's own transaction.
        $this->assertThrowsException(QueryException::class, function () use ($write): void {
            DB::transaction($write);
        });
    }

    public function test_the_pack_image_is_kept_on_the_private_disk_replaced_shown_and_removed(): void
    {
        $bom = PackagingBom::factory()->create();
        $disk = Storage::disk((string) config('packaging.image.disk'));

        $this->post(route('packaging.boms.image.store', $bom), ['image' => $this->png()])->assertSessionHasNoErrors();
        $first = $bom->fresh();
        $this->assertNotNull($first->image_path);
        $this->assertStringStartsWith((string) config('packaging.image.directory'), $first->image_path);
        $this->assertSame(64, strlen($first->image_sha256));
        $disk->assertExists($first->image_path);

        $response = $this->get(route('packaging.boms.image.show', $bom))->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('image/png', (string) $response->headers->get('Content-Type'));

        $this->post(route('packaging.boms.image.store', $bom), ['image' => UploadedFile::fake()->image('second.jpg', 30, 30)])->assertSessionHasNoErrors();
        $second = $bom->fresh();
        $disk->assertMissing($first->image_path);
        $disk->assertExists($second->image_path);

        $this->delete(route('packaging.boms.image.destroy', $bom))->assertSessionHasNoErrors();
        $disk->assertMissing($second->image_path);
        $this->assertNull($bom->fresh()->image_path);
        $this->get(route('packaging.boms.image.show', $bom))->assertNotFound();
    }

    public function test_the_pack_image_refuses_a_wrong_type_an_svg_a_fake_extension_and_a_big_file(): void
    {
        $bom = PackagingBom::factory()->create();

        $this->post(route('packaging.boms.image.store', $bom), [])->assertSessionHasErrors('image');
        $this->post(route('packaging.boms.image.store', $bom), ['image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');
        $this->post(route('packaging.boms.image.store', $bom), ['image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertSessionHasErrors('image');
        // A real file whose content is not an image, named like one: the type is judged by its content, not its name.
        $disguised = tempnam(sys_get_temp_dir(), 'bom');
        file_put_contents($disguised, '<?php echo 1;');
        $this->post(route('packaging.boms.image.store', $bom), ['image' => new UploadedFile($disguised, 'pack.png', 'image/png', null, true)])->assertSessionHasErrors('image');
        unlink($disguised);
        $this->post(route('packaging.boms.image.store', $bom), ['image' => UploadedFile::fake()->image('big.png')->size((int) config('packaging.image.max_kb') + 1)])->assertSessionHasErrors('image');

        $this->assertNull($bom->fresh()->image_path);
        $this->assertSame([], Storage::disk((string) config('packaging.image.disk'))->allFiles());
    }

    public function test_deleting_a_bom_deletes_its_image_file(): void
    {
        $bom = PackagingBom::factory()->create();
        $this->post(route('packaging.boms.image.store', $bom), ['image' => $this->png()]);
        $path = $bom->fresh()->image_path;

        $this->delete(route('packaging.boms.destroy', $bom));

        Storage::disk((string) config('packaging.image.disk'))->assertMissing($path);
    }

    public function test_the_cost_comes_from_the_engine_with_reject_carton_share_and_the_declared_pair(): void
    {
        $bom = PackagingBom::factory()->packed(12)->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle', '24/410');
        $cap = $this->item(ItemKind::Closure, 'Cap', '24/410 PP');
        $carton = $this->item(ItemKind::ShippingCarton, 'Carton');
        PackagingCompatibility::factory()->create(['container_neck_finish' => '24/410', 'closure_neck_finish' => '24/410 PP', 'source_id' => $this->source->id]);

        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['price_egp' => '3', 'reject_pct' => '20']));
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($cap, ['price_egp' => '1']));
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($carton, ['price_egp' => '24', 'level' => 'carton']));

        $answer = $this->cost($bom);

        $this->assertTrue($answer['ok']);
        $this->assertSame([], $answer['missing']);
        // 3 / 0.8 = 3.75, 1, and 24 / 12 = 2 for the carton share: 6.75 EGP per unit.
        $this->assertSame(0, DecimalCompare::compare($answer['total']['value'], '6.75'));
        $this->assertSame('EGP', $answer['total']['unit']);
        $this->assertNotEmpty($answer['total']['trace']);
        $this->assertCount(3, $answer['lines']);
        $this->assertSame('Bottle', $answer['lines'][0]['name']);
        $this->assertSame(0, DecimalCompare::compare($answer['lines'][0]['per_unit']['value'], '3.75'));
        $this->assertSame('Bottle', $answer['entities'][$bottle->id]['label']);
        $this->assertSame('Supplier quote 7', $answer['sources'][$this->source->id]['title']);
    }

    public function test_a_figure_not_recorded_is_a_gap_and_no_total_is_given(): void
    {
        $bom = PackagingBom::factory()->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle');
        $label = $this->item(ItemKind::Label, 'Label');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle));
        $this->post(route('packaging.boms.lines.store', $bom), ['trade_material_id' => $label->id, 'level' => 'unit']);

        $answer = $this->cost($bom);

        $this->assertFalse($answer['ok']);
        $this->assertNull($answer['total']);
        $this->assertSame([], $answer['lines']);
        $properties = collect($answer['missing'])->where('materialId', 'Label')->pluck('property')->all();
        $this->assertEqualsCanonicalizing(['price', 'qtyPerUnit', 'rejectPct'], $properties);
        $this->assertSame(__('packaging.bom.gap_subjects.price'), collect($answer['missing'])->firstWhere('property', 'price')['subject']);
    }

    public function test_a_carton_line_without_packing_a_closure_without_a_pair_and_an_estimate_are_gaps(): void
    {
        $bom = PackagingBom::factory()->create();
        $bottle = $this->item(ItemKind::Container, 'Bottle', '24/410');
        $cap = $this->item(ItemKind::Closure, 'Cap', '24/410 PP');
        $carton = $this->item(ItemKind::ShippingCarton, 'Carton');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['confidence' => 'L7_estimate']));
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($cap));
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($carton, ['level' => 'carton']));

        $answer = $this->cost($bom);

        $this->assertFalse($answer['ok']);
        $reasons = collect($answer['missing'])->pluck('reason')->all();
        $this->assertContains('packing configuration required', $reasons);
        $this->assertContains('packaging_compat.no_declared_pair', $reasons);
        $this->assertTrue(collect($reasons)->contains(fn (string $reason): bool => str_starts_with($reason, 'confidence.l7')));
        $this->assertTrue(Lang::has('engine.packing configuration required'));
    }

    public function test_a_bom_without_lines_has_no_cost_and_asks_the_engine_nothing(): void
    {
        $answer = $this->cost(PackagingBom::factory()->create());

        $this->assertFalse($answer['ok']);
        $this->assertNull($answer['total']);
        $this->assertSame([], $answer['missing']);
    }

    public function test_the_editor_receives_the_bom_lines_and_lookups(): void
    {
        $bom = PackagingBom::factory()->packed(12, 40)->create(['name' => 'Shampoo']);
        $bottle = $this->item(ItemKind::Container, 'Bottle', '24/410');
        $this->item(ItemKind::RawMaterial, 'Ingredient');
        $this->post(route('packaging.boms.lines.store', $bom), $this->line($bottle, ['price_egp' => '3.500000']));

        $this->get(route('packaging.boms.show', $bom))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Packaging/Boms/Show')
            ->where('bom.name', 'Shampoo')
            ->where('bom.units_per_carton', 12)
            ->where('bom.image', null)
            ->has('lines', 1)
            ->where('lines.0.price_egp', '3.5')
            ->where('lines.0.source_title', 'Supplier quote 7')
            ->has('componentOptions', 1)
            ->where('componentOptions.0.label', 'Bottle')
            ->where('levelOptions.1.value', 'carton')
            ->where('urls.cost', route('packaging.boms.cost', $bom, false)));
    }

    public function test_the_list_filters_searches_and_sorts_and_nothing_is_preloaded(): void
    {
        $this->get(route('packaging.boms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Packaging/Boms/Index')
            ->where('list.meta.total', 0));

        $a = PackagingBom::factory()->packed(12)->create(['name' => 'Alpha shampoo', 'sku_code' => 'AL-1']);
        PackagingBom::factory()->create(['name' => 'Beta soap', 'sku_code' => 'BE-2', 'image_path' => 'x/y.png']);
        PackagingBom::factory()->create(['name' => 'Gamma lotion', 'sanad_product_uuid' => SanadProduct::factory()->create()->uuid]);
        PackagingBomLine::factory()->for($a, 'bom')->create();

        $total = function (array $query): int {
            $count = -1;
            $this->get(route('packaging.boms.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$count): void {
                $count = $page->toArray()['props']['list']['meta']['total'];
            });

            return $count;
        };

        $this->assertSame(3, $total([]));
        $this->assertSame(1, $total(['search' => 'be-2']));
        $this->assertSame(1, $total(['has_image' => '1']));
        $this->assertSame(2, $total(['has_image' => '0']));
        $this->assertSame(1, $total(['has_lines' => '1']));
        $this->assertSame(1, $total(['sanad_linked' => '1']));
        $this->assertSame(1, $total(['units_per_carton_min' => '12']));
        $this->assertSame(0, $total(['search' => 'nothing like this']));

        $this->get(route('packaging.boms.index', ['sort' => 'lines_count', 'dir' => 'desc']))->assertInertia(fn (Assert $page) => $page->where('list.rows.0.name', 'Alpha shampoo')->where('list.rows.0.lines_count', 1));
        $this->get(route('packaging.boms.index', ['sort' => 'name', 'dir' => 'desc']))->assertInertia(fn (Assert $page) => $page->where('list.rows.0.name', 'Gamma lotion'));
    }
}
