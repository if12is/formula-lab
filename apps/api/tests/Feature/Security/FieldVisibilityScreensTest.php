<?php

namespace Tests\Feature\Security;

use App\Enums\Production\ProductionOrderStatus;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/**
 * U-07: the server never sends a role the fields of a group it may not see, on the main screens, in the first page load AND in the
 * Inertia JSON. The check reads the response itself: key names, and the seeded values (the unit cost 18.4573, the 12 % surfactant).
 * The key lists below are written out here on purpose, separately from config/field_visibility.php, so the test is not the policy
 * grading itself.
 */
class FieldVisibilityScreensTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private const COST_KEYS = ['unit_cost', 'cost', 'unit_cost_source', 'unit_cost_confidence', 'actual_conversion_egp', 'cost_per_kg', 'total_cost', 'cost_cell'];

    private const PRICE_KEYS = ['sell_price', 'price', 'wholesale_price', 'margin', 'margin_pct', 'tax_rate'];

    private const PERCENT_KEYS = ['percent_ww', 'percentWW'];

    private ProductionOrder $order;

    private ProductionBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $version = $this->productionProduct();
        $this->order = app(ProductionOrderService::class)->create($version, $this->orderData());
        $this->order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $this->batch = app(ProductionBatchService::class)->start($this->order, 'Operator A', '2');
        $this->batch->update(['actual_conversion_egp' => '777.5']);
    }

    /** @return list<string> Every key at every depth. */
    private function keysOf(mixed $node): array
    {
        $keys = [];
        foreach (is_array($node) ? $node : [] as $key => $value) {
            $keys[] = (string) $key;
            $keys = [...$keys, ...$this->keysOf($value)];
        }

        return $keys;
    }

    /** @return array<string, array{0: string}> */
    public static function screens(): array
    {
        return [
            'formulas list' => ['formulas.index'],
            'production orders list' => ['production.orders.index'],
            'production order' => ['production.orders.show'],
            'weighing sheet' => ['production.orders.weighing'],
            'batch' => ['production.batches.show'],
            'lab' => ['lab.trials.index'],
            'materials' => ['materials.index'],
        ];
    }

    private function url(string $route): string
    {
        return match ($route) {
            'production.orders.show', 'production.orders.weighing' => route($route, $this->order),
            'production.batches.show' => route($route, $this->batch),
            default => route($route),
        };
    }

    #[DataProvider('screens')]
    public function test_the_production_role_receives_no_cost_price_or_percentage_key_or_value_on_any_main_screen(string $route): void
    {
        $operator = User::factory()->create(['role' => 'production']);
        $url = $this->url($route);

        $first = $this->actingAs($operator)->get($url)->assertOk();
        $version = $first->original->getData()['page']['version'];
        $json = $this->actingAs($operator)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk();

        $found = [
            'first load (view data)' => $this->keysOf($this->pageProps($first)),
            'inertia json' => $this->keysOf($json->json('props')),
        ];
        foreach ($found as $source => $keys) {
            $this->assertSame([], array_values(array_intersect($keys, [...self::COST_KEYS, ...self::PRICE_KEYS, ...self::PERCENT_KEYS])), "{$route}: {$source} carries a restricted key");
        }
        foreach ([$first->getContent(), $json->getContent()] as $body) {
            $this->assertStringNotContainsString('18.4573', $body, "{$route}: the unit cost is in the response");
            $this->assertStringNotContainsString('777.5', $body, "{$route}: the conversion cost is in the response");
        }
    }

    public function test_the_operator_screens_still_work_and_show_weights_lots_and_the_order(): void
    {
        $operator = User::factory()->create(['role' => 'production']);

        $order = $this->pageProps($this->actingAs($operator)->get(route('production.orders.show', $this->order))->assertOk());
        $this->assertSame($this->order->code, $order['order']['code']);
        $this->assertSame(['sees_prices' => false, 'sees_percentages' => false], $order['audience']);
        $this->assertTrue($order['sheet']['ok']);
        $this->assertSame(['2162', '300', '38'], array_column($order['sheet']['sheet']['lines'], 'weighG'));
        $this->assertSame(['Water', 'Surfactant X', 'Salt'], array_column($order['sheet']['sheet']['lines'], 'material'));
        $this->assertArrayNotHasKey('percent_ww', $order['snapshot']['lines'][0]);

        $batch = $this->pageProps($this->actingAs($operator)->get(route('production.batches.show', $this->batch))->assertOk());
        $this->assertSame($this->batch->batch_number, $batch['batch']['number']);
        $this->assertArrayNotHasKey('actual_conversion_egp', $batch['batch']);
        $this->assertSame(['Water', 'Surfactant X', 'Salt'], array_column($batch['lines'], 'material'));
    }

    public function test_the_owner_and_the_chemist_still_receive_everything(): void
    {
        foreach (['owner', 'chemist'] as $role) {
            $props = $this->pageProps($this->actingAs(User::factory()->create(['role' => $role]))->get(route('production.orders.show', $this->order))->assertOk());
            $this->assertSame('18.4573', $props['cost']['unit_cost'], $role);
            $this->assertSame('12', $props['snapshot']['lines'][0]['percent_ww'], $role);
            $this->assertSame(['sees_prices' => true, 'sees_percentages' => true], $props['audience']);
        }
        $batch = $this->pageProps($this->actingAs(User::factory()->create(['role' => 'owner']))->get(route('production.batches.show', $this->batch))->assertOk());
        $this->assertSame('777.5', $batch['batch']['actual_conversion_egp']);
    }

    public function test_the_accountant_gets_cost_but_not_the_percentages_and_not_the_price_group(): void
    {
        $props = $this->pageProps($this->actingAs(User::factory()->create(['role' => 'accountant']))->get(route('production.orders.show', $this->order))->assertOk());

        $this->assertArrayHasKey('cost', $props);
        $this->assertSame('18.4573', $props['cost']['unit_cost']);
        $keys = $this->keysOf($props);
        $this->assertSame([], array_values(array_intersect($keys, [...self::PERCENT_KEYS, ...self::PRICE_KEYS])));
    }

    public function test_the_production_role_is_refused_the_screens_that_exist_only_for_costs_prices_and_the_recipe(): void
    {
        $operator = User::factory()->create(['role' => 'production']);
        $formula = $this->formula;

        foreach ([
            route('formulas.cost-report', $formula),
            route('formulas.versions.edit', [$formula, 1]),
            route('formulas.compare', $formula),
            route('formulas.publish', $formula),
            route('price-lists.index'),
            route('price-ladder.index'),
            route('tiers.index'),
        ] as $url) {
            $this->actingAs($operator)->get($url)->assertForbidden();
        }
        // The same screens answer for the owner: the refusal is the role, not a broken page.
        $this->actingAs(User::factory()->create(['role' => 'owner']))->get(route('price-lists.index'))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'accountant']))->get(route('formulas.cost-report', $formula))->assertOk();
    }

    public function test_a_refused_page_visit_is_a_403_screen_in_the_app_with_the_translated_reason(): void
    {
        $operator = User::factory()->create(['role' => 'production']);

        $page = $this->actingAs($operator)->get(route('price-lists.index'));

        $page->assertForbidden();
        $this->assertSame('Errors/Forbidden', $page->original->getData()['page']['component']);
        $this->assertStringNotContainsString('exceptions.', $page->original->getData()['page']['props']['message']);
    }

    public function test_a_refused_json_request_is_a_403_with_a_translated_message(): void
    {
        $operator = User::factory()->create(['role' => 'production']);

        $response = $this->actingAs($operator)->getJson(route('price-lists.index'));

        $response->assertForbidden();
        $this->assertStringNotContainsString('exceptions.', (string) $response->json('message'));
        $this->assertNotSame('', (string) $response->json('message'));
    }

    public function test_the_shared_audience_flags_tell_the_page_what_it_was_not_sent(): void
    {
        $flags = fn (string $role): array => $this->actingAs(User::factory()->create(['role' => $role]))->get(route('formulas.index'))->original->getData()['page']['props']['fieldAudience'];

        $this->assertSame(['sees_cost' => false, 'sees_price' => false, 'sees_percentage' => false], $flags('production'));
        $this->assertSame(['sees_cost' => true, 'sees_price' => false, 'sees_percentage' => false], $flags('accountant'));
        $this->assertSame(['sees_cost' => true, 'sees_price' => true, 'sees_percentage' => true], $flags('owner'));
    }

    public function test_the_middleware_strips_a_payload_no_controller_cleaned_in_the_first_load_and_in_the_json(): void
    {
        Route::middleware('web')->get('/__u07/probe', fn () => Inertia::render('Dashboard', [
            'title' => 'kept',
            'unit_cost' => '18.4573',
            'rows' => [['id' => 1, 'sellPrice' => '25', 'percent_ww' => '12', 'grams' => '300']],
            'audience' => ['sees_cost' => false],
        ]))->name('u07.probe');
        $operator = User::factory()->create(['role' => 'production']);

        $first = $this->actingAs($operator)->get('/__u07/probe')->assertOk();
        $json = $this->actingAs($operator)->get('/__u07/probe', ['X-Inertia' => 'true', 'X-Inertia-Version' => $first->original->getData()['page']['version']])->assertOk();

        foreach ([$this->pageProps($first), $json->json('props')] as $props) {
            $this->assertSame('kept', $props['title']);
            $this->assertSame([['id' => 1, 'grams' => '300']], $props['rows']);
            $this->assertArrayNotHasKey('unit_cost', $props);
            $this->assertSame(['sees_cost' => false], $props['audience']);
        }
        $this->assertStringNotContainsString('18.4573', $first->getContent());
        $this->assertStringNotContainsString('18.4573', $json->getContent());

        $owner = $this->actingAs(User::factory()->create(['role' => 'owner']))->get('/__u07/probe')->original->getData()['page']['props'];
        $this->assertSame('18.4573', $owner['unit_cost']);
        $this->assertSame('25', $owner['rows'][0]['sellPrice']);
    }

    public function test_the_translation_dictionary_is_not_stripped_for_a_restricted_role(): void
    {
        $props = $this->actingAs(User::factory()->create(['role' => 'production']))->get(route('formulas.index'))->original->getData()['page']['props'];

        $this->assertArrayHasKey('dashboard.kpi.approvals_caption_no_cost', $props['translations']);
    }
}
